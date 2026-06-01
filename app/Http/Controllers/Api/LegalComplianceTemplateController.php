<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegalComplianceTemplateController extends Controller
{
    private const FREE_DISCLAIMER = 'This free assessment report is provided as a preliminary compliance review based on the information available during the assessment. It does not constitute legal advice or a full statutory audit. Further verification may be required before relying on this report for regulatory, contractual, or enforcement purposes.';

    private const PAID_REPORT_TITLE = 'Occupational Safety and Health Legal Compliance Assessment Report';

    private const PAID_DISCLAIMER = 'This report presents the findings of a legal compliance assessment based on the scope, information, documents, and site observations available at the time of assessment. It reflects the assessor\'s professional opinion on the applicable requirements reviewed and does not constitute legal advice or a regulatory determination.';

    public function default(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $template = $this->activeTemplateQuery()
            ->where('templates.is_default', true)
            ->first();

        if (! $template) {
            $template = $this->activeTemplateQuery()->orderBy('templates.id')->first();
        }

        if (! $template) {
            return response()->json([
                'status' => 'error',
                'message' => 'No published legal compliance template is available.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'template' => $this->formatTemplate($template),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $canManageTemplates = $this->hasAnyRole($request, ['Manager', 'System Admin']);
        $records = DB::table('legal_compliance_templates as templates')
            ->leftJoin('legal_compliance_template_versions as versions', 'versions.id', '=', 'templates.active_version_id')
            ->when(! $canManageTemplates, fn ($query) => $query->whereNotNull('templates.active_version_id'))
            ->select([
                'templates.id',
                'templates.name',
                'templates.slug',
                'templates.description',
                'templates.assessment_tier',
                'templates.report_title',
                'templates.disclaimer_text',
                'templates.is_default',
                'templates.active_version_id',
                'templates.updated_at',
                'versions.version_number',
            ])
            ->orderByDesc('templates.is_default')
            ->orderBy('templates.name')
            ->get();

        return response()->json([
            'status' => 'success',
            'templates' => $records,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $validated = $this->validateDraftPayload($request, false);
        $now = now();
        $slug = $this->uniqueSlug($validated['name']);
        $isDefault = (bool) ($validated['is_default'] ?? false);
        $assessmentTier = $this->normalizeAssessmentTier($validated['assessment_tier'] ?? 'free');
        $reportTitle = $this->defaultReportTitle($validated['report_title'] ?? '', $validated['name'], $assessmentTier);
        $disclaimerText = $this->defaultDisclaimerText(
            $validated['disclaimer_text'] ?? '',
            $assessmentTier
        );

        if ($isDefault) {
            DB::table('legal_compliance_templates')->update(['is_default' => false]);
        }

        $templateId = DB::table('legal_compliance_templates')->insertGetId([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'assessment_tier' => $assessmentTier,
            'report_title' => $reportTitle,
            'disclaimer_text' => $disclaimerText,
            'draft_content' => json_encode($this->contentWithTemplateMetadata(
                (object) [
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'assessment_tier' => $assessmentTier,
                    'report_title' => $reportTitle,
                    'disclaimer_text' => $disclaimerText,
                ],
                $validated['draft_content'] ?? $this->emptyTemplateContent($validated['name'])
            )),
            'is_default' => $isDefault,
            'created_by' => $staffId ?: null,
            'updated_by' => $staffId ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Template created.',
            'data' => [
                'id' => $templateId,
                'slug' => $slug,
            ],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $canManageTemplates = $this->hasAnyRole($request, ['Manager', 'System Admin']);
        $template = DB::table('legal_compliance_templates as templates')
            ->leftJoin('legal_compliance_template_versions as versions', 'versions.id', '=', 'templates.active_version_id')
            ->where('templates.id', $id)
            ->select([
                'templates.*',
                'versions.version_number',
                'versions.content as active_content',
            ])
            ->first();

        if (! $template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found.'], 404);
        }

        if (! $canManageTemplates && ! $template->active_version_id) {
            return response()->json(['status' => 'error', 'message' => 'Template not found.'], 404);
        }

        $activeContent = $template->active_content
            ? $this->contentWithTemplateMetadata(
                $template,
                json_decode((string) $template->active_content, true) ?: []
            )
            : null;

        return response()->json([
            'status' => 'success',
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'slug' => $template->slug,
                'description' => $template->description,
                'assessment_tier' => $this->normalizeAssessmentTier($template->assessment_tier ?? 'free'),
                'report_title' => $this->defaultReportTitle(
                    $template->report_title ?? '',
                    $template->name,
                    $template->assessment_tier ?? 'free'
                ),
                'disclaimer_text' => $this->defaultDisclaimerText(
                    $template->disclaimer_text ?? '',
                    $template->assessment_tier ?? 'free'
                ),
                'is_default' => (bool) $template->is_default,
                'active_version_id' => $template->active_version_id,
                'version_number' => $template->version_number,
                'draft_content' => $canManageTemplates
                    ? $this->contentWithTemplateMetadata(
                        $template,
                        json_decode((string) $template->draft_content, true) ?: $this->emptyTemplateContent($template->name)
                    )
                    : $activeContent,
                'active_content' => $activeContent,
                'versions' => $canManageTemplates ? $this->templateVersions($template->id) : [],
                'updated_at' => $template->updated_at,
            ],
        ]);
    }

    public function updateDraft(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $template = DB::table('legal_compliance_templates')->where('id', $id)->first();
        if (! $template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found.'], 404);
        }

        $validated = $this->validateDraftPayload($request, true);
        if (! empty($validated['updated_at']) && ! Carbon::parse($validated['updated_at'])->equalTo(Carbon::parse($template->updated_at))) {
            return response()->json([
                'status' => 'error',
                'message' => 'This template was updated by someone else. Reload before saving.',
            ], 409);
        }

        $isDefault = (bool) ($validated['is_default'] ?? $template->is_default);
        $assessmentTier = $this->normalizeAssessmentTier($validated['assessment_tier'] ?? $template->assessment_tier ?? 'free');
        $reportTitle = $this->defaultReportTitle(
            $validated['report_title'] ?? $template->report_title ?? '',
            $validated['name'],
            $assessmentTier
        );
        $disclaimerText = $this->defaultDisclaimerText(
            $validated['disclaimer_text'] ?? $template->disclaimer_text ?? '',
            $assessmentTier
        );
        $now = now();

        DB::transaction(function () use ($id, $validated, $isDefault, $assessmentTier, $reportTitle, $disclaimerText, $staffId, $now) {
            if ($isDefault) {
                DB::table('legal_compliance_templates')
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            DB::table('legal_compliance_templates')
                ->where('id', $id)
                ->update([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'assessment_tier' => $assessmentTier,
                    'report_title' => $reportTitle,
                    'disclaimer_text' => $disclaimerText,
                    'draft_content' => json_encode($this->contentWithTemplateMetadata(
                        (object) [
                            'name' => $validated['name'],
                            'description' => $validated['description'] ?? null,
                            'assessment_tier' => $assessmentTier,
                            'report_title' => $reportTitle,
                            'disclaimer_text' => $disclaimerText,
                        ],
                        $validated['draft_content']
                    )),
                    'is_default' => $isDefault,
                    'updated_by' => $staffId ?: null,
                    'updated_at' => $now,
                ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Template draft saved.',
            'data' => [
                'id' => $id,
                'updated_at' => $now->toDateTimeString(),
            ],
        ]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $validated = $request->validate([
            'change_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $template = DB::table('legal_compliance_templates')->where('id', $id)->first();
        if (! $template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found.'], 404);
        }

        $content = $this->contentWithTemplateMetadata(
            $template,
            json_decode((string) $template->draft_content, true) ?: []
        );
        $publishIssues = $this->templatePublishIssues($content);
        if (! empty($publishIssues)) {
            return response()->json([
                'status' => 'error',
                'message' => implode(' ', $publishIssues),
            ], 422);
        }

        $changeNote = trim((string) ($validated['change_note'] ?? ''));
        $metadata = [
            'change_note' => $changeNote,
            'changed_by_staff_id' => $staffId ?: null,
            'changed_by_name' => $this->resolveStaffDisplayName($request, $staffId),
        ];

        $publishResult = DB::transaction(function () use ($id, $staffId, $content, $metadata) {
            $lockedTemplate = DB::table('legal_compliance_templates')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            $nextVersion = ((int) DB::table('legal_compliance_template_versions')
                ->where('template_id', $id)
                ->lockForUpdate()
                ->max('version_number')) + 1;
            $now = now();

            $versionId = DB::table('legal_compliance_template_versions')->insertGetId([
                'template_id' => $id,
                'version_number' => $nextVersion,
                'content' => json_encode($content),
                'published_by' => $staffId ?: null,
                'metadata' => json_encode($metadata),
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('legal_compliance_templates')
                ->where('id', $lockedTemplate->id)
                ->update([
                    'active_version_id' => $versionId,
                    'updated_by' => $staffId ?: null,
                    'updated_at' => $now,
                ]);

            return [
                'version_id' => $versionId,
                'version_number' => $nextVersion,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Template published.',
            'data' => [
                'id' => $id,
                'version_id' => $publishResult['version_id'],
                'version_number' => $publishResult['version_number'],
            ],
        ]);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $template = DB::table('legal_compliance_templates')->where('id', $id)->first();
        if (! $template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found.'], 404);
        }

        if (! $template->active_version_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Publish this template before setting it as default.',
            ], 422);
        }

        DB::transaction(function () use ($id, $staffId) {
            DB::table('legal_compliance_templates')->update(['is_default' => false]);
            DB::table('legal_compliance_templates')
                ->where('id', $id)
                ->update([
                    'is_default' => true,
                    'updated_by' => $staffId ?: null,
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Default template updated.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $template = DB::table('legal_compliance_templates')->where('id', $id)->first();
        if (! $template) {
            return response()->json(['status' => 'error', 'message' => 'Template not found.'], 404);
        }

        if ((bool) $template->is_default) {
            return response()->json([
                'status' => 'error',
                'message' => 'Default template cannot be deleted. Set another template as default first.',
            ], 422);
        }

        $hasAssessments = DB::table('legal_compliance_assessments')
            ->where('template_id', $id)
            ->orWhereIn('template_version_id', function ($query) use ($id) {
                $query->select('id')
                    ->from('legal_compliance_template_versions')
                    ->where('template_id', $id);
            })
            ->exists();

        if ($hasAssessments) {
            return response()->json([
                'status' => 'error',
                'message' => 'Template cannot be deleted because assessment records already use it.',
            ], 422);
        }

        DB::transaction(function () use ($id) {
            DB::table('legal_compliance_template_versions')
                ->where('template_id', $id)
                ->delete();

            DB::table('legal_compliance_templates')
                ->where('id', $id)
                ->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Template deleted.',
        ]);
    }

    private function activeTemplateQuery()
    {
        return DB::table('legal_compliance_templates as templates')
            ->join('legal_compliance_template_versions as versions', 'versions.id', '=', 'templates.active_version_id')
            ->select([
                'templates.id',
                'templates.name',
                'templates.slug',
                'templates.description',
                'templates.assessment_tier',
                'templates.report_title',
                'templates.disclaimer_text',
                'templates.is_default',
                'templates.active_version_id',
                'versions.version_number',
                'versions.content',
            ]);
    }

    private function hasAnyRole(Request $request, array $allowedRoles): bool
    {
        $roles = $request->attributes->get('auth.roles', $request->session()->get('roles', []));
        $roles = is_array($roles) ? $roles : [$roles];
        $normalizedRoles = array_map(static fn ($role) => strtolower(trim((string) $role)), $roles);
        if (in_array('system admin', $normalizedRoles, true)) {
            return true;
        }

        $normalizedAllowed = array_map(static fn ($role) => strtolower(trim((string) $role)), $allowedRoles);

        return ! empty(array_intersect($normalizedRoles, $normalizedAllowed));
    }

    private function formatTemplate(object $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'assessment_tier' => $this->normalizeAssessmentTier($template->assessment_tier ?? 'free'),
            'report_title' => $this->defaultReportTitle(
                $template->report_title ?? '',
                $template->name,
                $template->assessment_tier ?? 'free'
            ),
            'disclaimer_text' => $this->defaultDisclaimerText(
                $template->disclaimer_text ?? '',
                $template->assessment_tier ?? 'free'
            ),
            'is_default' => (bool) $template->is_default,
            'version_id' => $template->active_version_id,
            'version_number' => $template->version_number,
            'content' => $this->contentWithTemplateMetadata(
                $template,
                json_decode((string) $template->content, true) ?: $this->emptyTemplateContent($template->name)
            ),
        ];
    }

    private function templateVersions(int $templateId): array
    {
        return DB::table('legal_compliance_template_versions as versions')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'versions.published_by')
            ->where('versions.template_id', $templateId)
            ->select([
                'versions.id',
                'versions.version_number',
                'versions.published_by',
                'versions.published_at',
                'versions.created_at',
                'versions.metadata',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
            ])
            ->orderByDesc('versions.version_number')
            ->get()
            ->map(function ($version) {
                $metadata = json_decode((string) ($version->metadata ?? ''), true) ?: [];
                $changedBy = trim((string) ($metadata['changed_by_name'] ?? ''));

                if ($changedBy === '') {
                    $staffName = trim((string) ($version->staff_name ?? ''));
                    $staffCode = trim((string) ($version->staff_code ?? ''));
                    $changedBy = $staffName !== ''
                        ? $staffName
                        : ($staffCode !== '' ? $staffCode : 'System');
                }

                return [
                    'id' => $version->id,
                    'version_number' => (int) $version->version_number,
                    'changed_by' => $changedBy,
                    'published_at' => $version->published_at ?: $version->created_at,
                    'change_note' => trim((string) ($metadata['change_note'] ?? '')),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveStaffDisplayName(Request $request, int $staffId): string
    {
        $sessionName = trim((string) $request->session()->get('full_name', ''));
        $sessionCode = trim((string) $request->session()->get('name_code', ''));

        if ($sessionName !== '') {
            return $sessionName;
        }

        if ($sessionCode !== '') {
            return $sessionCode;
        }

        if ($staffId > 0) {
            $staff = DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->select(['full_name', 'name_code'])
                ->first();

            if ($staff) {
                $staffName = trim((string) ($staff->full_name ?? ''));
                $staffCode = trim((string) ($staff->name_code ?? ''));

                if ($staffName !== '') {
                    return $staffName;
                }

                if ($staffCode !== '') {
                    return $staffCode;
                }
            }
        }

        return 'System';
    }

    private function validateDraftPayload(Request $request, bool $requireContent): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assessment_tier' => ['nullable', 'string', 'in:free,paid'],
            'report_title' => ['nullable', 'string', 'max:255'],
            'disclaimer_text' => ['nullable', 'string'],
            'updated_at' => ['nullable', 'date'],
            'is_default' => ['nullable', 'boolean'],
            'draft_content' => [$requireContent ? 'required' : 'nullable', 'array'],
            'draft_content.title' => ['nullable', 'string', 'max:255'],
            'draft_content.description' => ['nullable', 'string'],
            'draft_content.groups' => [$requireContent ? 'required' : 'nullable', 'array'],
            'draft_content.groups.*.id' => ['required_with:draft_content.groups', 'string', 'max:100'],
            'draft_content.groups.*.title' => ['nullable', 'string', 'max:255'],
            'draft_content.groups.*.clauses' => ['nullable', 'array'],
            'draft_content.groups.*.clauses.*.id' => ['required_with:draft_content.groups.*.clauses', 'string', 'max:100'],
            'draft_content.groups.*.clauses.*.reference' => ['nullable', 'string', 'max:255'],
            'draft_content.groups.*.clauses.*.title' => ['nullable', 'string', 'max:255'],
            'draft_content.groups.*.clauses.*.excerpt' => ['nullable', 'string'],
            'draft_content.groups.*.clauses.*.fields' => ['nullable', 'array'],
            'draft_content.groups.*.clauses.*.fields.*.key' => ['required_with:draft_content.groups.*.clauses.*.fields', 'string', 'max:100'],
            'draft_content.groups.*.clauses.*.fields.*.label' => ['required_with:draft_content.groups.*.clauses.*.fields', 'string', 'max:255'],
            'draft_content.groups.*.clauses.*.fields.*.type' => ['required_with:draft_content.groups.*.clauses.*.fields', 'string', 'in:text,textarea,radio,date'],
            'draft_content.groups.*.clauses.*.fields.*.required' => ['nullable', 'boolean'],
            'draft_content.groups.*.clauses.*.fields.*.rows' => ['nullable', 'integer', 'min:1', 'max:10'],
            'draft_content.groups.*.clauses.*.fields.*.options' => ['nullable', 'array'],
            'draft_content.groups.*.clauses.*.fields.*.options.*.value' => ['required_with:draft_content.groups.*.clauses.*.fields.*.options', 'string', 'max:100'],
            'draft_content.groups.*.clauses.*.fields.*.options.*.label' => ['required_with:draft_content.groups.*.clauses.*.fields.*.options', 'string', 'max:255'],
        ]);
    }

    private function emptyTemplateContent(string $name): array
    {
        return [
            'title' => $name,
            'description' => '',
            'assessment_tier' => 'free',
            'report_title' => $this->defaultReportTitle('', $name, 'free'),
            'disclaimer_text' => self::FREE_DISCLAIMER,
            'groups' => [],
        ];
    }

    private function normalizeAssessmentTier(mixed $value): string
    {
        $tier = Str::lower(trim((string) $value));

        return in_array($tier, ['free', 'paid'], true) ? $tier : 'free';
    }

    private function defaultReportTitle(mixed $value, string $templateName, mixed $assessmentTier = 'free'): string
    {
        $title = trim((string) $value);
        if ($title !== '') {
            return $title;
        }

        if ($this->normalizeAssessmentTier($assessmentTier) === 'paid') {
            return self::PAID_REPORT_TITLE;
        }

        if (trim($templateName) === 'Free Legal Compliance Assessment') {
            return 'Free Legal Compliance Assessment Report';
        }

        return trim($templateName) !== '' ? trim($templateName).' Report' : 'Legal Compliance Assessment Report';
    }

    private function defaultDisclaimerText(mixed $value, mixed $assessmentTier): string
    {
        $disclaimer = trim((string) $value);
        if ($disclaimer !== '') {
            return $disclaimer;
        }

        return $this->normalizeAssessmentTier($assessmentTier) === 'paid'
            ? self::PAID_DISCLAIMER
            : self::FREE_DISCLAIMER;
    }

    private function contentWithTemplateMetadata(object $template, array $content): array
    {
        $templateName = (string) ($template->name ?? ($content['title'] ?? 'Legal Compliance Assessment'));
        $assessmentTier = $this->normalizeAssessmentTier($content['assessment_tier'] ?? $template->assessment_tier ?? 'free');

        return [
            ...$content,
            'title' => $content['title'] ?? $templateName,
            'description' => $content['description'] ?? ($template->description ?? ''),
            'assessment_tier' => $assessmentTier,
            'report_title' => $this->defaultReportTitle(
                $content['report_title'] ?? $template->report_title ?? '',
                $templateName,
                $assessmentTier
            ),
            'disclaimer_text' => $this->defaultDisclaimerText(
                $content['disclaimer_text'] ?? $template->disclaimer_text ?? '',
                $assessmentTier
            ),
        ];
    }

    private function templatePublishIssues(?array $content): array
    {
        $issues = [];

        if (! $content || empty($content['groups']) || ! is_array($content['groups'])) {
            return ['Add at least one legislation.'];
        }

        $titledClauseCount = 0;
        foreach ($content['groups'] as $groupIndex => $group) {
            $groupName = $this->normalizePublishTitle($group['title'] ?? '');
            $groupLabel = $groupName !== '' ? $groupName : 'Legislation '.($groupIndex + 1);

            if ($groupName === '') {
                $issues[] = "{$groupLabel} needs a legislation name.";
            }

            $clauses = is_array($group['clauses'] ?? null) ? $group['clauses'] : [];
            if (empty($clauses)) {
                $issues[] = "{$groupLabel} needs at least one clause.";
                continue;
            }

            $clauseTitleCounts = [];
            foreach ($clauses as $clauseIndex => $clause) {
                $clauseTitle = $this->normalizePublishTitle($clause['title'] ?? '');
                $clauseExcerpt = trim((string) ($clause['excerpt'] ?? ''));

                if ($clauseTitle === '') {
                    $issues[] = "{$groupLabel} has an untitled clause at position ".($clauseIndex + 1).'.';
                } else {
                    $titledClauseCount++;
                    $clauseTitleKey = Str::lower($clauseTitle);
                    $clauseTitleCounts[$clauseTitleKey] = [
                        'label' => $clauseTitle,
                        'count' => ($clauseTitleCounts[$clauseTitleKey]['count'] ?? 0) + 1,
                    ];
                }

                if ($clauseExcerpt === '') {
                    $clauseLabel = $clauseTitle !== '' ? $clauseTitle : 'Clause '.($clauseIndex + 1);
                    $issues[] = "{$groupLabel} - {$clauseLabel} needs a description.";
                }
            }

            foreach ($clauseTitleCounts as $clauseTitle) {
                if ($clauseTitle['count'] > 1) {
                    $issues[] = "{$groupLabel} has duplicate clause title: {$clauseTitle['label']}.";
                }
            }
        }

        if ($titledClauseCount === 0 && empty($issues)) {
            $issues[] = 'Add at least one titled clause.';
        }

        return $issues;
    }

    private function normalizePublishTitle(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'legal-compliance-template';
        $slug = $base;
        $counter = 2;

        while (DB::table('legal_compliance_templates')->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
