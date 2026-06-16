<?php

namespace App\Services\Projects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectValueService
{
    public const SOURCE_PROJECT_MANAGEMENT = 'project_management';

    public const SOURCE_AWARDED_QUOTE_EDIT = 'awarded_quote_edit';

    public const SOURCE_AWARD_MODAL = 'award_modal';

    public const SOURCE_COMMERCIAL_RESYNC = 'commercial_resync';

    public const DECISION_SYNC = 'sync';

    public const DECISION_KEEP = 'keep';

    public const DECISION_ADJUSTED = 'adjusted';

    private function commercialImpactService(): ProjectValueCommercialImpactService
    {
        return app(ProjectValueCommercialImpactService::class);
    }

    public function resolvedProjectValueExpression(string $alias = 'p'): string
    {
        $quoteValue = Schema::hasColumn('projects_main', 'quote_value') ? "{$alias}.quote_value" : '0';
        if (Schema::hasColumn('projects_main', 'current_project_value')) {
            return "COALESCE({$alias}.current_project_value, {$quoteValue}, 0)";
        }

        return "COALESCE({$quoteValue}, 0)";
    }

    public function selectColumns(string $alias = 'p'): array
    {
        $columns = [
            "{$alias}.quote_value",
            DB::raw($this->resolvedProjectValueExpression($alias).' as resolved_project_value'),
        ];

        $columns[] = Schema::hasColumn('projects_main', 'current_project_value')
            ? "{$alias}.current_project_value"
            : DB::raw('NULL as current_project_value');

        return $columns;
    }

    public function handleAwardedQuoteValueDecision(
        Request $request,
        string $quoteType,
        int $quoteId,
        object $quote,
        float $newGrandTotal
    ): ?JsonResponse {
        if (! $this->supportsCurrentProjectValue()) {
            return null;
        }

        $oldGrandTotal = round((float) ($quote->grand_total ?? 0), 2);
        $newGrandTotal = round($newGrandTotal, 2);
        $status = strtolower(trim((string) ($quote->status ?? '')));
        if ($status !== 'awarded' || abs($oldGrandTotal - $newGrandTotal) < 0.01) {
            return null;
        }

        $project = $this->linkedProjectForQuote($quoteType, $quoteId);
        if (! $project) {
            return null;
        }

        $invoiceCount = $this->invoiceCountForProject((int) $project->id);
        $syncAllowed = $invoiceCount < 1;
        $decision = strtolower(trim((string) $request->input('project_value_sync_decision', '')));

        if ($decision === '') {
            return response()->json([
                'status' => 'project_value_decision_required',
                'message' => 'This awarded quotation total changed. Choose whether to update the linked project current value.',
                'project_value_decision' => $this->decisionPayload($project, $quoteType, $quoteId, $oldGrandTotal, $newGrandTotal, $syncAllowed, $invoiceCount),
            ], 409);
        }

        if (! in_array($decision, [self::DECISION_SYNC, self::DECISION_KEEP], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid project value sync decision.',
            ], 422);
        }

        if ($decision === self::DECISION_SYNC && ! $syncAllowed) {
            return response()->json([
                'status' => 'error',
                'message' => 'Project current value cannot be updated from an awarded quote edit because the linked project already has invoice records.',
                'project_value_decision' => $this->decisionPayload($project, $quoteType, $quoteId, $oldGrandTotal, $newGrandTotal, false, $invoiceCount),
            ], 422);
        }

        if ($decision === self::DECISION_SYNC) {
            $reason = trim((string) $request->input(
                'project_value_sync_reason',
                'Project current value updated after awarded quotation total changed.'
            ));
            $this->updateProjectCurrentValue(
                (int) $project->id,
                $newGrandTotal,
                $reason,
                self::SOURCE_AWARDED_QUOTE_EDIT,
                $request,
                $quoteType,
                $quoteId
            );
        }

        return null;
    }

    public function updateProjectValueFromRequest(Request $request, int $projectId): JsonResponse
    {
        if (! $this->supportsCurrentProjectValue()) {
            return response()->json(['status' => 'error', 'message' => 'Project current value is not available.'], 422);
        }

        $validated = validator($request->all(), [
            'current_project_value' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:5000'],
            'acknowledgement' => ['nullable', 'boolean'],
            'sync' => ['nullable', 'array'],
            'sync.invoices' => ['nullable', 'array'],
            'sync.invoices.*' => ['integer', 'min:1'],
            'sync.delivery_orders' => ['nullable', 'array'],
            'sync.delivery_orders.*' => ['integer', 'min:1'],
            'sync.payment_adjustments' => ['nullable', 'array'],
            'sync.payment_adjustments.*' => ['integer', 'min:1'],
        ])->validate();

        DB::beginTransaction();
        try {
            $project = DB::table('projects_main')->where('id', $projectId)->lockForUpdate()->first();
            if (! $project) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Project not found.'], 404);
            }

            $newValue = round((float) $validated['current_project_value'], 2);
            $oldValue = $this->resolvedValue($project);
            $impactPreview = $this->commercialImpactService()->preview($projectId, $oldValue, $newValue);
            $hasAffectedDocuments = $this->commercialImpactService()->hasAffectedDocuments($impactPreview);
            $acknowledged = filter_var($validated['acknowledgement'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $sync = (array) ($validated['sync'] ?? []);
            $hasSelectedSync = $this->hasSelectedSync($sync);
            if (abs($oldValue - $newValue) < 0.01) {
                if ($hasSelectedSync) {
                    if ($hasAffectedDocuments && ! $acknowledged) {
                        DB::rollBack();

                        return response()->json([
                            'status' => 'commercial_impact_acknowledgement_required',
                            'message' => 'Review affected commercial documents before syncing commercial records.',
                            'impact' => $impactPreview,
                        ], 409);
                    }

                    $revisionId = $this->createCommercialResyncRevision(
                        $projectId,
                        $oldValue,
                        trim((string) $validated['reason']),
                        $request,
                        (string) ($project->quote_type ?? ''),
                        (int) ($project->quote_id ?? 0) ?: null,
                        $project
                    );

                    $commercialSync = [
                        'applied' => [],
                        'skipped' => [],
                    ];
                    if ($revisionId !== null) {
                        $commercialSync = $this->commercialImpactService()->applySync(
                            $projectId,
                            $revisionId,
                            $newValue,
                            $sync,
                            (int) $request->session()->get('staff_id', 0),
                            true
                        );
                    }

                    DB::commit();

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Commercial documents resynced successfully.',
                        'data' => [
                            'project_id' => $projectId,
                            'quote_value' => (float) ($project->quote_value ?? 0),
                            'current_project_value' => $project->current_project_value !== null ? (float) $project->current_project_value : null,
                            'resolved_project_value' => $oldValue,
                            'revision_id' => $revisionId,
                            'commercial_impact' => $impactPreview,
                            'commercial_sync' => $commercialSync,
                        ],
                    ]);
                }

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Project current value is unchanged.',
                    'data' => [
                        'project_id' => $projectId,
                        'quote_value' => (float) ($project->quote_value ?? 0),
                        'current_project_value' => $project->current_project_value !== null ? (float) $project->current_project_value : null,
                        'resolved_project_value' => $oldValue,
                        'revision_id' => null,
                        'commercial_impact' => $impactPreview,
                        'commercial_sync' => [
                            'applied' => [],
                            'skipped' => [],
                        ],
                    ],
                ]);
            }

            if ($hasAffectedDocuments && ! $acknowledged) {
                DB::rollBack();

                return response()->json([
                    'status' => 'commercial_impact_acknowledgement_required',
                    'message' => 'Review affected commercial documents before updating project value.',
                    'impact' => $impactPreview,
                ], 409);
            }

            $revisionId = $this->updateProjectCurrentValue(
                $projectId,
                $newValue,
                trim((string) $validated['reason']),
                self::SOURCE_PROJECT_MANAGEMENT,
                $request,
                (string) ($project->quote_type ?? ''),
                (int) ($project->quote_id ?? 0) ?: null,
                $project
            );

            $commercialSync = [
                'applied' => [],
                'skipped' => [],
            ];
            if ($revisionId !== null) {
                $commercialSync = $this->commercialImpactService()->applySync(
                    $projectId,
                    $revisionId,
                    $newValue,
                    $sync,
                    (int) $request->session()->get('staff_id', 0),
                    false
                );
            }

            $updated = DB::table('projects_main')->where('id', $projectId)->first();
            DB::commit();
        } catch (\RuntimeException $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'commercial_sync_failed',
                'message' => $e->getMessage(),
            ], 409);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Failed to update project current value.'], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Project current value updated successfully.',
            'data' => [
                'project_id' => $projectId,
                'quote_value' => (float) ($updated->quote_value ?? 0),
                'current_project_value' => $updated->current_project_value !== null ? (float) $updated->current_project_value : null,
                'resolved_project_value' => $this->resolvedValue($updated),
                'revision_id' => $revisionId,
                'commercial_impact' => $impactPreview,
                'commercial_sync' => $commercialSync,
            ],
        ]);
    }

    public function previewProjectValueImpact(Request $request, int $projectId): JsonResponse
    {
        if (! $this->supportsCurrentProjectValue()) {
            return response()->json(['status' => 'error', 'message' => 'Project current value is not available.'], 422);
        }

        $validated = validator($request->all(), [
            'current_project_value' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:5000'],
        ])->validate();

        $project = DB::table('projects_main')->where('id', $projectId)->first();
        if (! $project) {
            return response()->json(['status' => 'error', 'message' => 'Project not found.'], 404);
        }

        $newValue = round((float) $validated['current_project_value'], 2);
        $oldValue = $this->resolvedValue($project);

        return response()->json([
            'status' => 'success',
            'data' => $this->commercialImpactService()->preview($projectId, $oldValue, $newValue),
        ]);
    }

    public function applyAwardModalAdjustment(
        int $projectId,
        Request $request,
        ?string $quoteType = null,
        ?int $quoteId = null
    ): void {
        if (! $this->supportsCurrentProjectValue()) {
            return;
        }

        $decision = strtolower(trim((string) $request->input('project_value_decision', 'default')));
        if ($decision !== self::DECISION_ADJUSTED) {
            return;
        }

        $rawValue = $request->input('current_project_value');
        $reason = trim((string) $request->input('project_value_reason', ''));
        if ($rawValue === null || $rawValue === '' || $reason === '') {
            throw new \InvalidArgumentException('Project value and reason are required when awarding with an adjusted project value.');
        }

        $newValue = round((float) $rawValue, 2);
        if ($newValue < 0) {
            throw new \InvalidArgumentException('Project current value cannot be negative.');
        }

        $this->updateProjectCurrentValue(
            $projectId,
            $newValue,
            $reason,
            self::SOURCE_AWARD_MODAL,
            $request,
            $quoteType,
            $quoteId
        );
    }

    public function updateProjectCurrentValue(
        int $projectId,
        float $newValue,
        string $reason,
        string $source,
        Request $request,
        ?string $quoteType = null,
        ?int $quoteId = null,
        ?object $lockedProject = null
    ): ?int {
        if (! $this->supportsCurrentProjectValue()) {
            return null;
        }

        $project = $lockedProject ?: DB::table('projects_main')->where('id', $projectId)->lockForUpdate()->first();
        if (! $project) {
            return null;
        }

        $oldValue = $this->resolvedValue($project);
        $newValue = round($newValue, 2);
        if (abs($oldValue - $newValue) < 0.01) {
            return null;
        }

        $projectUpdates = [
            'current_project_value' => $newValue,
        ];
        if (Schema::hasColumn('projects_main', 'updated_at')) {
            $projectUpdates['updated_at'] = now();
        }
        if (Schema::hasColumn('projects_main', 'updated_by')) {
            $projectUpdates['updated_by'] = (int) $request->session()->get('staff_id', 0) ?: null;
        }

        DB::table('projects_main')->where('id', $projectId)->update($projectUpdates);

        $revisionId = null;
        if (Schema::hasTable('project_value_revisions')) {
            $revisionId = (int) DB::table('project_value_revisions')->insertGetId([
                'project_id' => $projectId,
                'quote_id' => $quoteId ?: ($project->quote_id ?? null),
                'quote_type' => $quoteType ?: ($project->quote_type ?? null),
                'source' => $source,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'awarded_value' => $project->quote_value ?? null,
                'reason' => $reason,
                'changed_by' => (int) $request->session()->get('staff_id', 0) ?: null,
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->insertProgress($projectId, sprintf(
            'Project current value updated from RM %s to RM %s. Reason: %s',
            number_format($oldValue, 2),
            number_format($newValue, 2),
            $reason !== '' ? $reason : '-'
        ), $request);

        return $revisionId;
    }

    private function createCommercialResyncRevision(
        int $projectId,
        float $resolvedValue,
        string $reason,
        Request $request,
        ?string $quoteType = null,
        ?int $quoteId = null,
        ?object $lockedProject = null
    ): ?int {
        if (! $this->supportsCurrentProjectValue() || ! Schema::hasTable('project_value_revisions')) {
            return null;
        }

        $project = $lockedProject ?: DB::table('projects_main')->where('id', $projectId)->lockForUpdate()->first();
        if (! $project) {
            return null;
        }

        $resolvedValue = round($resolvedValue, 2);
        $revisionId = (int) DB::table('project_value_revisions')->insertGetId([
            'project_id' => $projectId,
            'quote_id' => $quoteId ?: ($project->quote_id ?? null),
            'quote_type' => $quoteType ?: ($project->quote_type ?? null),
            'source' => self::SOURCE_COMMERCIAL_RESYNC,
            'old_value' => $resolvedValue,
            'new_value' => $resolvedValue,
            'awarded_value' => $project->quote_value ?? null,
            'reason' => $reason,
            'changed_by' => (int) $request->session()->get('staff_id', 0) ?: null,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertProgress($projectId, sprintf(
            'Commercial documents resynced to current project value RM %s. Reason: %s',
            number_format($resolvedValue, 2),
            $reason !== '' ? $reason : '-'
        ), $request);

        return $revisionId;
    }

    public function resolvedValue(?object $project): float
    {
        if (! $project) {
            return 0.0;
        }

        $current = $project->current_project_value ?? null;
        if ($current !== null && $current !== '') {
            return round((float) $current, 2);
        }

        return round((float) ($project->quote_value ?? 0), 2);
    }

    private function linkedProjectForQuote(string $quoteType, int $quoteId): ?object
    {
        $quoteType = strtolower(trim($quoteType));
        $projectTypePredicate = $this->projectTypePredicate($quoteType);
        if (! $projectTypePredicate || ! Schema::hasTable('projects_main')) {
            return null;
        }

        return DB::table('projects_main')
            ->where('quote_id', $quoteId)
            ->where(function ($query) use ($quoteType, $projectTypePredicate): void {
                if (Schema::hasColumn('projects_main', 'quote_type')) {
                    $query->whereRaw("LOWER(COALESCE(quote_type, '')) = ?", [$quoteType])
                        ->orWhereRaw($projectTypePredicate['sql'], $projectTypePredicate['bindings']);

                    return;
                }

                $query->whereRaw($projectTypePredicate['sql'], $projectTypePredicate['bindings']);
            })
            ->whereRaw("LOWER(COALESCE(status, '')) IN (?, ?)", ['active', 'completed'])
            ->orderByRaw("CASE WHEN LOWER(COALESCE(status, '')) = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function projectTypePredicate(string $quoteType): ?array
    {
        $projectType = "LOWER(REPLACE(COALESCE(project_type, ''), '_', ' '))";

        return match ($quoteType) {
            'training' => [
                'sql' => "{$projectType} LIKE ?",
                'bindings' => ['%training%'],
            ],
            'ih' => [
                'sql' => "({$projectType} LIKE ? OR {$projectType} LIKE ? OR {$projectType} = ?)",
                'bindings' => ['%industrial%', '%hygiene%', 'ih'],
            ],
            'manpower' => [
                'sql' => "({$projectType} LIKE ? OR {$projectType} LIKE ?)",
                'bindings' => ['%manpower%', '%man power%'],
            ],
            'equipment' => [
                'sql' => "{$projectType} LIKE ?",
                'bindings' => ['%equipment%'],
            ],
            'special' => [
                'sql' => "{$projectType} LIKE ?",
                'bindings' => ['%special%'],
            ],
            default => null,
        };
    }

    private function invoiceCountForProject(int $projectId): int
    {
        if (! Schema::hasTable('invoices')) {
            return 0;
        }

        return (int) DB::table('invoices')->where('project_id', $projectId)->count();
    }

    private function hasSelectedSync(array $sync): bool
    {
        foreach (['invoices', 'payment_adjustments'] as $key) {
            foreach ((array) ($sync[$key] ?? []) as $id) {
                if ((int) $id > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function decisionPayload(
        object $project,
        string $quoteType,
        int $quoteId,
        float $oldGrandTotal,
        float $newGrandTotal,
        bool $syncAllowed,
        int $invoiceCount
    ): array {
        return [
            'quote_type' => $quoteType,
            'quote_id' => $quoteId,
            'old_quote_total' => $oldGrandTotal,
            'new_quote_total' => $newGrandTotal,
            'project_id' => (int) $project->id,
            'project_name' => (string) ($project->project_name ?? ''),
            'awarded_value' => (float) ($project->quote_value ?? 0),
            'current_project_value' => $project->current_project_value !== null ? (float) $project->current_project_value : null,
            'resolved_project_value' => $this->resolvedValue($project),
            'sync_allowed' => $syncAllowed,
            'invoice_count' => $invoiceCount,
            'block_reason' => $syncAllowed ? null : 'Linked project already has invoice records.',
        ];
    }

    private function supportsCurrentProjectValue(): bool
    {
        return Schema::hasTable('projects_main') && Schema::hasColumn('projects_main', 'current_project_value');
    }

    private function insertProgress(int $projectId, string $activity, Request $request): void
    {
        if (! Schema::hasTable('project_progress') || $activity === '') {
            return;
        }

        DB::table('project_progress')->insert([
            'project_id' => $projectId,
            'progress_date' => now()->format('Y-m-d'),
            'progress_text' => $activity,
            'updated_by' => (int) $request->session()->get('staff_id', 0) ?: null,
            'updated_on' => now(),
        ]);
    }
}
