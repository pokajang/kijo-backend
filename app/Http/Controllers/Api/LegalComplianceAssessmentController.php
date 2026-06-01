<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\LegalComplianceAssessmentReportPdfService;
use App\Services\LegalComplianceAssessmentSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegalComplianceAssessmentController extends Controller
{
    private function reportPdfService(): LegalComplianceAssessmentReportPdfService
    {
        return app(LegalComplianceAssessmentReportPdfService::class);
    }

    private function auditLog(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    private function snapshotService(): LegalComplianceAssessmentSnapshotService
    {
        return app(LegalComplianceAssessmentSnapshotService::class);
    }

    public function index(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $recordsQuery = DB::table('legal_compliance_assessments as assessments')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'assessments.staff_id')
            ->leftJoin('legal_compliance_templates as templates', 'templates.id', '=', 'assessments.template_id')
            ->leftJoin('legal_compliance_template_versions as versions', 'versions.id', '=', 'assessments.template_version_id')
            ->select([
                'assessments.id',
                'assessments.staff_id',
                'assessments.template_id',
                'assessments.template_version_id',
                'assessments.template_version',
                'assessments.template_snapshot',
                'assessments.stage',
                'assessments.parent_assessment_id',
                'assessments.revision_number',
                'assessments.superseded_by_assessment_id',
                'assessments.company_name',
                'assessments.site_location',
                'assessments.client_company_id',
                'assessments.client_branch_id',
                'assessments.client_pic_id',
                'assessments.client_pic_name',
                'assessments.client_pic_email',
                'assessments.project_id',
                'assessments.project_name',
                'assessments.assessment_date',
                'assessments.assessor_name',
                'assessments.assessor_email',
                'assessments.nature_of_company',
                'assessments.clause_responses',
                'assessments.created_at',
                'assessments.updated_at',
                'assessments.submitted_at',
                'assessments.submitted_by_staff_id',
                'staff.full_name as created_by_name',
                'staff.name_code as created_by_code',
                'templates.name as template_name',
                'versions.version_number as published_version_number',
            ])
            ->whereNull('assessments.deleted_at')
            ->whereNull('assessments.superseded_by_assessment_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('legal_compliance_assessments as child')
                    ->whereColumn('child.parent_assessment_id', 'assessments.id')
                    ->whereNull('child.deleted_at')
                    ->whereNull('child.superseded_by_assessment_id');
            });

        if (! $this->hasAnyRole($request, ['Manager', 'System Admin'])) {
            $recordsQuery->where('assessments.staff_id', $staffId);
        }

        $records = $recordsQuery
            ->orderByDesc('assessments.updated_at')
            ->limit(200)
            ->get()
            ->map(function ($record) {
                $responses = json_decode((string) $record->clause_responses, true) ?: [];
                $findingCount = collect($responses)
                    ->filter(fn ($response) => trim((string) ($response['finding'] ?? '')) !== '')
                    ->count();
                $snapshot = json_decode((string) $record->template_snapshot, true) ?: [];
                $hasStoredSnapshot = ! empty($snapshot['groups']) && is_array($snapshot['groups']);
                $snapshotClauses = collect($snapshot['groups'] ?? [])->flatMap(
                    fn ($group) => $group['clauses'] ?? []
                );
                $completedCount = $snapshotClauses->isNotEmpty()
                    ? $snapshotClauses
                        ->filter(function ($clause) use ($responses) {
                            $requiredFields = collect($clause['fields'] ?? [])->filter(
                                fn ($field) => (bool) ($field['required'] ?? false)
                            );

                            if ($requiredFields->isEmpty()) {
                                return false;
                            }

                            $response = $responses[$clause['id'] ?? ''] ?? [];

                            return $requiredFields->every(
                                fn ($field) => trim((string) ($response[$field['key'] ?? ''] ?? '')) !== ''
                            );
                        })
                        ->count()
                    : collect($responses)
                        ->filter(function ($response) {
                            $status = (string) ($response['complianceStatus'] ?? '');
                            $finding = trim((string) ($response['finding'] ?? ''));

                            return in_array($status, ['comply', 'not_comply'], true) && $finding !== '';
                        })
                        ->count();
                $totalClauseCount = collect($snapshot['groups'] ?? [])
                    ->sum(fn ($group) => count($group['clauses'] ?? []));
                $assessmentTier = $hasStoredSnapshot
                    ? (strtolower(trim((string) ($snapshot['assessment_tier'] ?? 'free'))) === 'paid' ? 'paid' : 'free')
                    : null;

                unset($record->clause_responses);
                unset($record->template_snapshot);
                if ($record->stage === 'details') {
                    $record->stage = 'details_saved';
                }

                $record->finding_count = $findingCount;
                $record->completed_count = $completedCount;
                $record->total_clause_count = $totalClauseCount ?: null;
                $record->assessment_tier = $assessmentTier;

                return $record;
            });

        return response()->json([
            'status' => 'success',
            'records' => $records,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $recordQuery = DB::table('legal_compliance_assessments as assessments')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'assessments.staff_id')
            ->leftJoin('legal_compliance_templates as templates', 'templates.id', '=', 'assessments.template_id')
            ->leftJoin('legal_compliance_template_versions as versions', 'versions.id', '=', 'assessments.template_version_id')
            ->where('assessments.id', $id)
            ->select([
                'assessments.id',
                'assessments.staff_id',
                'assessments.template_id',
                'assessments.template_version_id',
                'assessments.template_version',
                'assessments.template_snapshot',
                'assessments.stage',
                'assessments.parent_assessment_id',
                'assessments.revision_number',
                'assessments.superseded_by_assessment_id',
                'assessments.company_name',
                'assessments.site_location',
                'assessments.client_company_id',
                'assessments.client_branch_id',
                'assessments.client_pic_id',
                'assessments.client_pic_name',
                'assessments.client_pic_email',
                'assessments.project_id',
                'assessments.project_name',
                'assessments.assessment_date',
                'assessments.assessor_name',
                'assessments.assessor_email',
                'assessments.nature_of_company',
                'assessments.selected_assessors',
                'assessments.clause_responses',
                'assessments.created_at',
                'assessments.updated_at',
                'assessments.submitted_at',
                'assessments.submitted_by_staff_id',
                'staff.full_name as created_by_name',
                'staff.name_code as created_by_code',
                'templates.name as template_name',
                'templates.slug as template_slug',
                'templates.description as template_description',
                'templates.is_default as template_is_default',
                'versions.version_number as published_version_number',
            ])
            ->whereNull('assessments.deleted_at');

        if (! $this->hasAnyRole($request, ['Manager', 'System Admin'])) {
            $recordQuery->where('assessments.staff_id', $staffId);
        }

        $record = $recordQuery->first();
        if (! $record) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assessment record not found.',
            ], 404);
        }

        $record->stage = $record->stage === 'details' ? 'details_saved' : $record->stage;
        $snapshotResolution = $this->snapshotService()->resolve($record);
        $record->template_snapshot = $snapshotResolution['snapshot'];
        $record->template_snapshot_unresolved = (bool) $snapshotResolution['unresolved'];
        $record->template_snapshot_resolution_source = $snapshotResolution['source'];
        $record->selected_assessors = json_decode((string) $record->selected_assessors, true) ?: [];
        $record->clause_responses = json_decode((string) $record->clause_responses, true) ?: [];

        return response()->json([
            'status' => 'success',
            'record' => $record,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'stage' => ['required', 'string', 'in:details_saved,review_ready,submitted'],
            'autosave' => ['nullable', 'boolean'],
            'templateVersion' => ['nullable', 'string', 'max:50'],
            'templateId' => ['nullable', 'integer'],
            'templateVersionId' => ['nullable', 'integer'],
            'assessmentDetails' => ['required', 'array'],
            'assessmentDetails.companyName' => ['nullable', 'string', 'max:255'],
            'assessmentDetails.siteLocation' => ['nullable', 'string'],
            'assessmentDetails.clientCompanyId' => ['nullable', 'integer'],
            'assessmentDetails.clientBranchId' => ['nullable', 'integer'],
            'assessmentDetails.clientPicId' => ['nullable', 'integer'],
            'assessmentDetails.clientPicName' => ['nullable', 'string', 'max:255'],
            'assessmentDetails.clientPicEmail' => ['nullable', 'string', 'max:255'],
            'assessmentDetails.projectId' => ['nullable', 'integer'],
            'assessmentDetails.projectName' => ['nullable', 'string', 'max:255'],
            'assessmentDetails.assessmentDate' => ['nullable', 'date'],
            'assessmentDetails.assessorName' => ['nullable', 'string'],
            'assessmentDetails.assessorEmail' => ['nullable', 'string'],
            'assessmentDetails.scopeRemarks' => ['nullable', 'string'],
            'selectedAssessors' => ['nullable', 'array'],
            'clauseResponses' => ['required', 'array'],
            'clauseResponses.*.complianceStatus' => ['nullable', 'string'],
            'clauseResponses.*.finding' => ['nullable', 'string'],
        ]);

        $details = $validated['assessmentDetails'];
        $validated['clauseResponses'] = is_array($request->input('clauseResponses'))
            ? $request->input('clauseResponses')
            : [];
        $assessmentId = (int) ($validated['id'] ?? 0);
        $now = now();

        if ($assessmentId > 0) {
            $existingAssessment = DB::table('legal_compliance_assessments')
                ->where('id', $assessmentId)
                ->first();

            if (! $existingAssessment) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assessment record not found.',
                ], 404);
            }

            if (! empty($existingAssessment->deleted_at)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assessment record not found.',
                ], 404);
            }

            if (! $this->canUpdateAssessment($request, $existingAssessment, $staffId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to update this assessment record.',
                ], 403);
            }

            if ((string) $existingAssessment->stage === 'submitted') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Submitted assessments are locked. Create a revision before editing.',
                ], 409);
            }

            if (
                (bool) ($validated['autosave'] ?? false)
                && $this->stageRank($validated['stage']) < $this->stageRank((string) $existingAssessment->stage)
            ) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Assessment saved.',
                    'data' => ['id' => $assessmentId],
                ]);
            }

            $snapshotResolution = $this->snapshotService()->resolve($existingAssessment);
            if ($snapshotResolution['unresolved']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assessment template snapshot could not be resolved. Run the legacy snapshot backfill before editing this record.',
                ], 422);
            }

            $templateSnapshot = $snapshotResolution['snapshot'];
            $templateFields = $this->templateFieldsFromSnapshotResolution($existingAssessment, $snapshotResolution);

            $validationError = $this->validateClauseResponses(
                $templateSnapshot,
                $validated['clauseResponses'],
                in_array($validated['stage'], ['review_ready', 'submitted'], true)
            );
            if ($validationError) {
                return response()->json([
                    'status' => 'error',
                    'message' => $validationError,
                ], 422);
            }

            $projectValidation = $this->validateProjectForTemplate($templateSnapshot, $details);
            if ($projectValidation['error']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $projectValidation['error'],
                ], $projectValidation['status']);
            }

            DB::table('legal_compliance_assessments')
                ->where('id', $assessmentId)
                ->update([
                    ...$templateFields,
                    ...$this->assessmentRecordPayload($validated, $details, $now, $staffId, $projectValidation['project']),
                ]);

            if ($validated['stage'] === 'submitted' && ! empty($existingAssessment->parent_assessment_id)) {
                DB::table('legal_compliance_assessments')
                    ->where('id', $existingAssessment->parent_assessment_id)
                    ->whereNull('superseded_by_assessment_id')
                    ->update([
                        'superseded_by_assessment_id' => $assessmentId,
                        'updated_at' => $now,
                    ]);
            }

            $this->auditLog()->log(
                $request,
                "Saved legal compliance assessment #{$assessmentId} as {$validated['stage']}"
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment saved.',
                'data' => ['id' => $assessmentId],
            ]);
        }

        $templateVersion = $this->resolvePublishedTemplateVersion($validated);
        if (! $templateVersion) {
            return response()->json([
                'status' => 'error',
                'message' => 'Published template version could not be verified.',
            ], 422);
        }

        $templateSnapshot = json_decode((string) $templateVersion->content, true) ?: [];
        $validationError = $this->validateClauseResponses(
            $templateSnapshot,
            $validated['clauseResponses'],
            in_array($validated['stage'], ['review_ready', 'submitted'], true)
        );
        if ($validationError) {
            return response()->json([
                'status' => 'error',
                'message' => $validationError,
            ], 422);
        }

        $projectValidation = $this->validateProjectForTemplate($templateSnapshot, $details);
        if ($projectValidation['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $projectValidation['error'],
            ], $projectValidation['status']);
        }

        $record = [
            'staff_id' => $staffId,
            'template_id' => $templateVersion->template_id,
            'template_version_id' => $templateVersion->id,
            'template_version' => 'v'.$templateVersion->version_number,
            'template_snapshot' => json_encode($templateSnapshot),
            'revision_number' => 1,
            ...$this->assessmentRecordPayload($validated, $details, $now, $staffId, $projectValidation['project']),
        ];

        $assessmentId = DB::table('legal_compliance_assessments')->insertGetId([
            ...$record,
            'created_at' => $now,
        ]);

        $this->auditLog()->log(
            $request,
            "Created legal compliance assessment #{$assessmentId} as {$validated['stage']}"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Assessment saved.',
            'data' => ['id' => $assessmentId],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $record = DB::table('legal_compliance_assessments')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (! $record) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assessment record not found.',
            ], 404);
        }

        if (! $this->canUpdateAssessment($request, $record, $staffId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to delete this assessment record.',
            ], 403);
        }

        $now = now();
        DB::table('legal_compliance_assessments')
            ->where('id', $id)
            ->update([
                'deleted_at' => $now,
                'deleted_by_staff_id' => $staffId,
                'updated_at' => $now,
            ]);

        $this->auditLog()->log($request, "Soft deleted legal compliance assessment #{$id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Assessment record deleted.',
        ]);
    }

    public function createRevision(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $revisionResult = DB::transaction(function () use ($request, $id, $staffId) {
            $record = DB::table('legal_compliance_assessments')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                return ['status' => 404, 'message' => 'Assessment record not found.'];
            }

            if (! $this->canUpdateAssessment($request, $record, $staffId)) {
                return [
                    'status' => 403,
                    'message' => 'You do not have permission to revise this assessment record.',
                ];
            }

            if ((string) $record->stage !== 'submitted') {
                return [
                    'status' => 422,
                    'message' => 'Only submitted assessment reports can be revised.',
                ];
            }

            if (! empty($record->superseded_by_assessment_id)) {
                return [
                    'status' => 409,
                    'message' => 'This report has already been superseded by a later revision.',
                ];
            }

            $activeChild = DB::table('legal_compliance_assessments')
                ->where('parent_assessment_id', $record->id)
                ->whereNull('deleted_at')
                ->whereNull('superseded_by_assessment_id')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($activeChild) {
                return [
                    'status' => 200,
                    'message' => 'Assessment revision already exists.',
                    'id' => $activeChild->id,
                    'created' => false,
                ];
            }

            $snapshotResolution = $this->snapshotService()->resolve($record);
            if ($snapshotResolution['unresolved']) {
                return [
                    'status' => 422,
                    'message' => 'Assessment template snapshot could not be resolved. Run the legacy snapshot backfill before revising this report.',
                ];
            }

            $templateVersion = $snapshotResolution['template_version'];
            $templateId = ! empty($record->template_id)
                ? $record->template_id
                : ($templateVersion->template_id ?? null);
            $templateVersionId = ! empty($record->template_version_id)
                ? $record->template_version_id
                : ($templateVersion->id ?? null);
            $templateVersionLabel = trim((string) ($record->template_version ?? '')) !== ''
                ? $record->template_version
                : ($templateVersion ? 'v'.$templateVersion->version_number : null);

            $now = now();
            $revisionId = DB::table('legal_compliance_assessments')->insertGetId([
                'staff_id' => $staffId,
                'template_id' => $templateId,
                'template_version_id' => $templateVersionId,
                'template_version' => $templateVersionLabel,
                'template_snapshot' => json_encode($snapshotResolution['snapshot']),
                'stage' => 'details_saved',
                'parent_assessment_id' => $record->id,
                'revision_number' => ((int) ($record->revision_number ?? 1)) + 1,
                'superseded_by_assessment_id' => null,
                'company_name' => $record->company_name,
                'site_location' => $record->site_location,
                'client_company_id' => $record->client_company_id,
                'client_branch_id' => $record->client_branch_id,
                'client_pic_id' => $record->client_pic_id,
                'client_pic_name' => $record->client_pic_name,
                'client_pic_email' => $record->client_pic_email,
                'project_id' => $record->project_id ?? null,
                'project_name' => $record->project_name ?? null,
                'assessment_date' => $record->assessment_date,
                'assessor_name' => $record->assessor_name,
                'assessor_email' => $record->assessor_email,
                'nature_of_company' => $record->nature_of_company,
                'selected_assessors' => $record->selected_assessors,
                'clause_responses' => $record->clause_responses,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'status' => 201,
                'message' => 'Assessment revision created.',
                'id' => $revisionId,
                'created' => true,
            ];
        });

        if (empty($revisionResult['id'])) {
            return response()->json([
                'status' => 'error',
                'message' => $revisionResult['message'],
            ], $revisionResult['status']);
        }

        if ($revisionResult['created']) {
            $this->auditLog()->log(
                $request,
                "Created legal compliance assessment revision #{$revisionResult['id']} from #{$id}"
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => $revisionResult['message'],
            'data' => ['id' => $revisionResult['id']],
        ], $revisionResult['status']);
    }

    public function pdf(Request $request, int $id)
    {
        return $this->reportPdfService()->export($request, $id);
    }

    private function assessmentRecordPayload(
        array $validated,
        array $details,
        $now,
        int $staffId,
        ?object $project = null
    ): array {
        $projectAddress = $project ? $this->formatProjectAddress($project) : null;
        $payload = [
            'stage' => $validated['stage'],
            'company_name' => $project ? (($project->client_name ?: $project->project_name) ?: null) : (($details['companyName'] ?? '') ?: null),
            'site_location' => $project ? ($projectAddress ?: null) : (($details['siteLocation'] ?? '') ?: null),
            'client_company_id' => $project ? ($project->client_id ?: null) : (($details['clientCompanyId'] ?? null) ?: null),
            'client_branch_id' => ($details['clientBranchId'] ?? null) ?: null,
            'client_pic_id' => ($details['clientPicId'] ?? null) ?: null,
            'client_pic_name' => $project ? (($project->pic_name ?? '') ?: null) : (($details['clientPicName'] ?? '') ?: null),
            'client_pic_email' => $project ? (($project->pic_email ?? '') ?: null) : (($details['clientPicEmail'] ?? '') ?: null),
            'project_id' => $project?->id,
            'project_name' => $project?->project_name ?: (($details['projectName'] ?? '') ?: null),
            'assessment_date' => ($details['assessmentDate'] ?? '') ?: null,
            'assessor_name' => ($details['assessorName'] ?? '') ?: null,
            'assessor_email' => ($details['assessorEmail'] ?? '') ?: null,
            'nature_of_company' => ($details['scopeRemarks'] ?? '') ?: null,
            'selected_assessors' => json_encode($validated['selectedAssessors'] ?? []),
            'clause_responses' => json_encode($validated['clauseResponses']),
            'updated_at' => $now,
        ];

        if ($validated['stage'] === 'submitted') {
            $payload['submitted_at'] = $now;
            $payload['submitted_by_staff_id'] = $staffId;
        }

        return $payload;
    }

    private function formatProjectAddress(object $project): string
    {
        return collect([
            $project->client_address ?? '',
            trim(implode(' ', array_filter([
                $project->client_zip ?? '',
                $project->client_city ?? '',
            ]))),
            $project->client_state ?? '',
        ])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');
    }

    private function resolvePublishedTemplateVersion(array $validated): ?object
    {
        if (! empty($validated['templateVersionId'])) {
            return DB::table('legal_compliance_template_versions as versions')
                ->join('legal_compliance_templates as templates', 'templates.id', '=', 'versions.template_id')
                ->where('versions.id', $validated['templateVersionId'])
                ->when(! empty($validated['templateId']), fn ($query) => $query->where('templates.id', $validated['templateId']))
                ->select('versions.*')
                ->first();
        }

        if (! empty($validated['templateId'])) {
            return DB::table('legal_compliance_templates as templates')
                ->join('legal_compliance_template_versions as versions', 'versions.id', '=', 'templates.active_version_id')
                ->where('templates.id', $validated['templateId'])
                ->select('versions.*')
                ->first();
        }

        return DB::table('legal_compliance_templates as templates')
            ->join('legal_compliance_template_versions as versions', 'versions.id', '=', 'templates.active_version_id')
            ->where('templates.is_default', true)
            ->select('versions.*')
            ->first();
    }

    private function templateFieldsFromSnapshotResolution(object $record, array $snapshotResolution): array
    {
        if (($snapshotResolution['source'] ?? '') === 'existing_valid') {
            return [];
        }

        $templateVersion = $snapshotResolution['template_version'] ?? null;
        $fields = [
            'template_snapshot' => json_encode($snapshotResolution['snapshot'] ?? []),
        ];

        if ($templateVersion) {
            if (empty($record->template_id)) {
                $fields['template_id'] = $templateVersion->template_id;
            }

            if (empty($record->template_version_id)) {
                $fields['template_version_id'] = $templateVersion->id;
            }

            if (trim((string) ($record->template_version ?? '')) === '') {
                $fields['template_version'] = 'v'.$templateVersion->version_number;
            }
        }

        return $fields;
    }

    private function validateProjectForTemplate(
        array $templateSnapshot,
        array $details,
    ): array {
        $tier = strtolower(trim((string) ($templateSnapshot['assessment_tier'] ?? 'free'))) === 'paid'
            ? 'paid'
            : 'free';

        if ($tier !== 'paid') {
            return ['error' => null, 'status' => 200, 'project' => null];
        }

        $projectId = (int) ($details['projectId'] ?? 0);
        if ($projectId <= 0) {
            return ['error' => null, 'status' => 200, 'project' => null];
        }

        $project = DB::table('projects_main as p')
            ->leftJoin('quotes_training as qt', function ($join) {
                $join->on('qt.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Training');
            })
            ->leftJoin('quotes_ih as qh', function ($join) {
                $join->on('qh.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Industrial Hygiene');
            })
            ->leftJoin('quotes_manpower as qm', function ($join) {
                $join->on('qm.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Manpower Supply');
            })
            ->leftJoin('quotes_special as qs', function ($join) {
                $join->on('qs.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Special Service');
            })
            ->leftJoin('quotes_equipment as qe', function ($join) {
                $join->on('qe.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Equipment Supply');
            })
            ->leftJoin('client_company as cc', 'cc.company_id', '=', 'p.client_id')
            ->where('p.id', $projectId)
            ->selectRaw('
                p.id,
                p.project_name,
                p.status,
                p.client_id,
                COALESCE(qt.client_name, qh.client_name, qm.client_name, qs.client_name, qe.client_name, cc.company_name) AS client_name,
                COALESCE(qt.client_address, qh.client_address, qm.client_address, qs.client_address, qe.client_address, cc.address) AS client_address,
                COALESCE(qt.client_city, qh.client_city, qm.client_city, qs.client_city, qe.client_city, cc.city) AS client_city,
                COALESCE(qt.client_state, qh.client_state, qm.client_state, qs.client_state, qe.client_state, cc.state) AS client_state,
                COALESCE(qt.client_zip, qh.client_zip, qm.client_zip, qs.client_zip, qe.client_zip, cc.zip) AS client_zip,
                COALESCE(qt.pic_name, qh.pic_name, qm.pic_name, qs.pic_name, qe.pic_name) AS pic_name,
                COALESCE(qt.pic_email, qh.pic_email, qm.pic_email, qs.pic_email, qe.pic_email) AS pic_email
            ')
            ->first();

        if (! $project) {
            return [
                'error' => 'Selected project could not be found.',
                'status' => 404,
                'project' => null,
            ];
        }

        $projectStatus = strtolower(trim((string) ($project->status ?? '')));
        if (in_array($projectStatus, ['terminated', 'deleted', 'cancelled', 'canceled'], true)) {
            return [
                'error' => 'Selected project is not available for paid assessments.',
                'status' => 422,
                'project' => null,
            ];
        }

        return ['error' => null, 'status' => 200, 'project' => $project];
    }

    private function validateClauseResponses(array $templateSnapshot, array $responses, bool $requireComplete): ?string
    {
        $clauses = collect($templateSnapshot['groups'] ?? [])->flatMap(
            fn ($group) => $group['clauses'] ?? []
        );

        foreach ($clauses as $clause) {
            $clauseId = (string) ($clause['id'] ?? '');
            if ($clauseId === '') {
                continue;
            }

            $response = $responses[$clauseId] ?? [];
            foreach (($clause['fields'] ?? []) as $field) {
                $fieldKey = (string) ($field['key'] ?? '');
                if ($fieldKey === '') {
                    continue;
                }

                $value = $response[$fieldKey] ?? null;
                $isRequired = (bool) ($field['required'] ?? false);
                if ($requireComplete && $isRequired && trim((string) $value) === '') {
                    return 'Complete all required clause fields before saving.';
                }

                if (($field['type'] ?? '') === 'radio' && trim((string) $value) !== '') {
                    $allowed = collect($field['options'] ?? [])
                        ->map(fn ($option) => (string) ($option['value'] ?? ''))
                        ->filter()
                        ->values()
                        ->all();

                    if (! in_array((string) $value, $allowed, true)) {
                        return 'One or more clause responses contain an invalid option.';
                    }
                }
            }
        }

        return null;
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

    private function canUpdateAssessment(Request $request, object $assessment, int $staffId): bool
    {
        return (int) $assessment->staff_id === $staffId
            || $this->hasAnyRole($request, ['Manager', 'System Admin']);
    }

    private function stageRank(string $stage): int
    {
        return match ($stage) {
            'submitted' => 3,
            'review_ready' => 2,
            'details_saved' => 1,
            default => 0,
        };
    }
}
