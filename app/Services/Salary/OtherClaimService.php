<?php

namespace App\Services\Salary;

use App\Services\Pdf\PdfRenderer;
use App\Services\Quotes\Pdf\PdfMergeService;
use App\Services\Salary\OtherClaims\ClaimAttachmentData;
use App\Services\Salary\OtherClaims\OtherClaimValidator;
use App\Services\Workflows\WorkflowService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtherClaimService extends PdfRenderer
{
    private const CLAIM_TYPES = ['Allowance', 'Expense', 'Mileage', 'Medical'];

    private const FINANCIAL_ACTIONS = ['check', 'approve', 'reject'];

    private const STAFF_MUTABLE_STATUSES = ['Draft', 'Submitted', 'Prepared', 'Rejected'];

    private const REVIEWED_MUTABLE_STATUSES = ['Checked', 'Approved'];

    private const PAID_STATUSES = ['Paid'];

    private const CANCELLED_STATUS = 'Cancelled';

    private const SUBJECT_TYPE = 'other_claim_application';

    public function __construct(
        private WorkflowService $workflowService,
        private SalaryCalculator $salaryCalculator,
        private SalaryWorkflowNotificationService $workflowNotifications,
        private OtherClaimValidator $claimValidator,
    ) {}

    public function records(Request $request): JsonResponse
    {
        $records = DB::table('hr_other_claim_applications')
            ->where('staff_id', $this->staffId($request))
            ->when(
                Schema::hasColumn('hr_other_claim_applications', 'superseded_at'),
                fn ($query) => $query->whereNull('superseded_at'),
            )
            ->orderByDesc('claim_month')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $record): array => $this->recordPayload($record, includeClaims: false, request: $request))
            ->all();

        return response()->json(['status' => 'success', 'records' => $records]);
    }

    public function financialRecords(Request $request): JsonResponse
    {
        $records = DB::table('hr_other_claim_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->leftJoin('staff_general as checker', 'checker.staff_id', '=', 'application.checked_by')
            ->leftJoin('staff_general as approver', 'approver.staff_id', '=', 'application.approved_by')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'checker.full_name as checker_name',
                'checker.name_code as checker_code',
                'approver.full_name as approver_name',
                'approver.name_code as approver_code',
            ])
            ->whereNotIn('application.status', ['Draft', self::CANCELLED_STATUS])
            ->when(
                Schema::hasColumn('hr_other_claim_applications', 'superseded_at'),
                fn ($query) => $query->whereNull('application.superseded_at'),
            )
            ->orderByDesc('application.claim_month')
            ->orderByDesc('application.submitted_at')
            ->orderByDesc('application.id')
            ->get();

        foreach ($records as $record) {
            $this->workflowService->ensureOtherClaimWorkflowForExistingRecord($record);
        }

        $workflowPayloads = $this->workflowService->otherClaimWorkflowPayloads(
            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $request,
        );

        return response()->json([
            'status' => 'success',
            'records' => $records
                ->filter(fn (object $record): bool => $this->canViewFinancialRecord($request, $record))
                ->map(fn (object $record): array => $this->recordPayload(
                    $record,
                    includeClaims: false,
                    request: $request,
                    workflowPayload: $workflowPayloads[(int) $record->id] ?? null,
                ))
                ->all(),
        ]);
    }

    public function financialRecord(Request $request, int $id): JsonResponse
    {
        $record = $this->financialRecordQuery()->where('application.id', $id)->first();
        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404);
        }

        $this->workflowService->ensureOtherClaimWorkflowForExistingRecord($record);
        if (! $this->canViewFinancialRecord($request, $record)) {
            return response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'record' => $this->recordPayload(
                $record,
                includeClaims: true,
                request: $request,
                financialView: true,
            ),
        ]);
    }

    public function financialRecordAction(Request $request, int $id): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'action' => ['required', 'string', 'in:'.implode(',', self::FINANCIAL_ACTIONS)],
            'remarks' => ['nullable', 'string', 'max:1000', 'required_if:action,reject'],
            'record_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        $record = DB::table('hr_other_claim_applications')->where('id', $id)->first();
        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404);
        }
        $this->workflowService->ensureOtherClaimWorkflowForExistingRecord($record);
        if ((string) $record->status === self::CANCELLED_STATUS || ! $this->canViewFinancialRecord($request, $record)) {
            return response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404);
        }
        if ((string) $record->status === 'Draft') {
            return response()->json(['status' => 'error', 'message' => 'Other claim draft is not ready for financial action.'], 422);
        }

        $this->workflowService->ensureOtherClaimWorkflowForExistingRecord($record);
        $instanceId = $this->workflowService->otherClaimInstanceId($id);
        if (! $instanceId) {
            return response()->json(['status' => 'error', 'message' => 'Other claim workflow instance not found.'], 404);
        }

        $request->merge($data);

        return $this->workflowService->action($request, $instanceId);
    }

    public function record(Request $request, int $id): JsonResponse
    {
        $record = DB::table('hr_other_claim_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('id', $id)
            ->first();

        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'record' => $this->recordPayload($record, includeClaims: true, request: $request),
        ]);
    }

    public function claimsPdf(Request $request, int $id)
    {
        $record = $this->pdfRecordQuery()
            ->where('application.staff_id', $this->staffId($request))
            ->where('application.id', $id)
            ->whereNotIn('application.status', ['Draft', self::CANCELLED_STATUS])
            ->first();

        if (! $record) {
            abort(404, 'Other claim record not found.');
        }

        return $this->claimPdfResponse($request, $record);
    }

    public function financialClaimsPdf(Request $request, int $id)
    {
        $record = $this->financialRecordQuery()
            ->where('application.id', $id)
            ->whereNotIn('application.status', ['Draft', self::CANCELLED_STATUS])
            ->first();

        if (! $record || ! $this->canViewFinancialRecord($request, $record)) {
            abort(404, 'Other claim record not found.');
        }

        return $this->claimPdfResponse($request, $record);
    }

    public function destroyRecord(Request $request, int $id): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ])->validate();
        $staffId = $this->staffId($request);
        $record = null;
        $recipientIds = [];
        $draftDeleted = false;
        $withdrawReason = '';

        DB::transaction(function () use ($data, $id, $staffId, &$record, &$recipientIds, &$draftDeleted, &$withdrawReason): void {
            $instances = DB::table('workflow_instances')
                ->where('subject_type', self::SUBJECT_TYPE)
                ->where('subject_id', $id)
                ->lockForUpdate()
                ->get(['id', 'current_step_id', 'status']);
            $record = DB::table('hr_other_claim_applications')
                ->where('staff_id', $staffId)
                ->where('id', $id)
                ->where('status', '<>', self::CANCELLED_STATUS)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                abort(response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404));
            }
            if (
                Schema::hasColumn('hr_other_claim_applications', 'record_version')
                && (int) $data['record_version'] !== (int) ($record->record_version ?? 1)
            ) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'This claim changed or was already withdrawn. Refresh before continuing.',
                ], 409));
            }
            if ($this->isPaidStatus((string) $record->status)) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Paid other claim records cannot be changed.',
                ], 422));
            }
            if ((string) $record->status === 'Draft') {
                $this->deleteApplicationClaims($id);
                $workflowInstanceIds = $instances
                    ->pluck('id')
                    ->map(fn ($workflowInstanceId): int => (int) $workflowInstanceId)
                    ->all();
                if ($workflowInstanceIds !== []) {
                    DB::table('workflow_actions')->whereIn('instance_id', $workflowInstanceIds)->delete();
                    DB::table('workflow_instances')->whereIn('id', $workflowInstanceIds)->delete();
                }
                DB::table('hr_other_claim_applications')->where('id', $id)->delete();
                $draftDeleted = true;

                return;
            }
            if (! in_array((string) $record->status, ['Submitted', 'Prepared', 'Checked', 'Approved', 'Rejected'], true)) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'This other claim cannot be withdrawn in its current state.',
                ], 422));
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'Enter a reason before withdrawing a submitted other claim.',
                    'errors' => ['reason' => ['Enter a reason before withdrawing this other claim.']],
                ], 422));
            }
            $withdrawReason = $reason;

            $recipientIds = $this->workflowParticipantIds($record, self::SUBJECT_TYPE, [$staffId]);
            $this->recordWorkflowEvent(self::SUBJECT_TYPE, $id, 'withdraw', $staffId, (string) $record->status, self::CANCELLED_STATUS, $reason, $this->snapshotRecord($record, includeClaims: true));
            foreach ($instances as $instance) {
                DB::table('workflow_actions')->insert([
                    'instance_id' => $instance->id,
                    'step_id' => $instance->current_step_id,
                    'action' => 'withdraw',
                    'status_from' => (string) ($instance->status ?? $record->status),
                    'status_to' => self::CANCELLED_STATUS,
                    'actor_staff_id' => $staffId,
                    'remarks' => $reason,
                    'acted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $update = [
                'status' => self::CANCELLED_STATUS,
                'cancelled_at' => now(),
                'cancelled_by' => $staffId,
                'cancel_reason' => $reason,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('hr_other_claim_applications', 'record_version')) {
                $update['record_version'] = DB::raw('record_version + 1');
            }
            DB::table('hr_other_claim_applications')->where('id', $id)->update($update);
            DB::table('workflow_instances')->where('subject_type', self::SUBJECT_TYPE)->where('subject_id', $id)->update([
                'current_step_id' => null,
                'status' => self::CANCELLED_STATUS,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if ($draftDeleted) {
            return response()->json(['status' => 'success', 'message' => 'Other claim draft deleted.']);
        }

        try {
            $this->workflowNotifications->notifyRecordCancelled($request, self::SUBJECT_TYPE, $id, $recipientIds, $withdrawReason);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['status' => 'success', 'message' => 'Other claim withdrawn.']);
    }

    public function draftApplication(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'claim_month' => ['required', 'date_format:Y-m'],
        ])->validate();

        $record = DB::table('hr_other_claim_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('claim_month', $data['claim_month'])
            ->where('status', 'Draft')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'status' => 'success',
            'record' => $record ? $this->recordPayload($record, includeClaims: true, request: $request) : null,
        ]);
    }

    public function storeDraftApplication(Request $request): JsonResponse
    {
        $data = $this->validatedDraftPayload($request);
        $staffId = $this->staffId($request);
        $mileageRate = $this->mileageRate($staffId);
        $savedRecord = null;

        DB::transaction(function () use ($request, $data, $staffId, $mileageRate, &$savedRecord): void {
            $existing = DB::table('hr_other_claim_applications')
                ->where('staff_id', $staffId)
                ->where('claim_month', $data['claim_month'])
                ->where('status', 'Draft')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $files = $request->file('attachments', []);
            $preservedAttachments = $existing
                ? $this->preservedClaimAttachments((int) $existing->id, $data['claims'], $staffId)
                : collect();
            $claims = $this->normalizeTravelClaims($data['claims']);
            $claims = $this->applyMileageRateSnapshots(
                $claims,
                $mileageRate,
                $existing ? $this->existingMileageRateSnapshots((int) $existing->id) : [],
            );
            $claims = $this->salaryCalculator->prepareClaims($claims, $mileageRate);
            $claimsTotal = $this->claimsTotal($claims);

            if ($existing) {
                $this->deleteApplicationClaims(
                    (int) $existing->id,
                    collect($preservedAttachments)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            }

            $payload = [
                'staff_id' => $staffId,
                'claim_month' => $data['claim_month'],
                'claim_month_label' => $this->formatClaimMonth($data['claim_month']),
                'claims_total' => $this->money($claimsTotal),
                'status' => 'Draft',
                'draft_payload_json' => json_encode($data['draft_payload'], JSON_THROW_ON_ERROR),
                'draft_saved_at' => now(),
                'submitted_at' => null,
                'checked_by' => null,
                'checked_at' => null,
                'checked_status' => null,
                'checked_remarks' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approved_status' => null,
                'approved_remarks' => null,
                'updated_at' => now(),
            ];

            $applicationId = $existing
                ? (int) $existing->id
                : (int) DB::table('hr_other_claim_applications')->insertGetId([...$payload, 'created_at' => now()]);
            if ($existing) {
                DB::table('hr_other_claim_applications')->where('id', $applicationId)->update($payload);
            }

            $this->storeClaims($claims, $files, $preservedAttachments, $applicationId, $staffId, $data['claim_month']);
            $record = DB::table('hr_other_claim_applications')->where('id', $applicationId)->first();
            $savedRecord = $this->recordPayload($record, includeClaims: true, request: $request);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Other claim draft saved.',
            'record' => $savedRecord,
        ]);
    }

    public function destroyDraftApplication(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'claim_month' => ['required', 'date_format:Y-m'],
        ])->validate();

        $record = DB::table('hr_other_claim_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('claim_month', $data['claim_month'])
            ->where('status', 'Draft')
            ->orderByDesc('id')
            ->first();

        if ($record) {
            DB::transaction(function () use ($record): void {
                $this->deleteApplicationClaims((int) $record->id);
                DB::table('hr_other_claim_applications')->where('id', $record->id)->delete();
            });
        }

        return response()->json(['status' => 'success', 'message' => 'Other claim draft cleared.']);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        $data = $this->validatedApplicationPayload($request);
        $staffId = $this->staffId($request);
        $settings = $this->submissionSettings($staffId);
        $mileageRate = $settings['mileageRate'];
        $savedRecord = null;
        $amendmentNotification = null;

        DB::transaction(function () use ($request, $data, $staffId, $settings, $mileageRate, &$savedRecord, &$amendmentNotification): void {
            $applicationIdForEdit = (int) ($data['application_id'] ?? 0);
            $existing = $applicationIdForEdit > 0
                ? DB::table('hr_other_claim_applications')
                    ->where('staff_id', $staffId)
                    ->where('id', $applicationIdForEdit)
                    ->where('status', '<>', self::CANCELLED_STATUS)
                    ->lockForUpdate()
                    ->first()
                : null;
            if ($applicationIdForEdit > 0 && ! $existing) {
                throw ValidationException::withMessages([
                    'application_id' => ['Other claim record not found.'],
                ]);
            }
            $isRevision = false;
            if ($existing) {
                $existingStatus = (string) $existing->status;
                $expectedVersion = (int) ($data['record_version'] ?? 0);
                if ($expectedVersion > 0 && Schema::hasColumn('hr_other_claim_applications', 'record_version') && $expectedVersion !== (int) $existing->record_version) {
                    abort(response()->json([
                        'status' => 'error',
                        'message' => 'This other claim changed or was withdrawn. Refresh before submitting.',
                    ], 409));
                }
                if ($this->isPaidStatus($existingStatus)) {
                    abort(response()->json([
                        'status' => 'error',
                        'message' => 'Paid other claim records cannot be changed.',
                        'errors' => ['application_id' => ['Paid other claim records cannot be changed.']],
                    ], 422));
                }
                if ($existingStatus === 'Rejected') {
                    $isRevision = true;
                } elseif ($existingStatus !== 'Draft') {
                    throw ValidationException::withMessages([
                        'application_id' => ['Withdraw this submitted claim before changing it. Rejected claims can be revised and resubmitted.'],
                    ]);
                }
                if ($isRevision) {
                    $reason = trim((string) ($data['amendment_reason'] ?? ''));
                    if ($reason === '') {
                        throw ValidationException::withMessages([
                            'amendment_reason' => ['Enter a reason before creating a revised other claim.'],
                        ]);
                    }
                    $amendmentNotification = [
                        'subjectType' => self::SUBJECT_TYPE,
                        'applicationId' => (int) $existing->id,
                        'recipientIds' => $this->workflowParticipantIds($existing, self::SUBJECT_TYPE, [$staffId]),
                        'reason' => $reason,
                    ];
                    $this->recordWorkflowEvent(
                        self::SUBJECT_TYPE,
                        (int) $existing->id,
                        'amend',
                        $staffId,
                        $existingStatus,
                        'Revised',
                        $reason,
                        $this->snapshotRecord($existing, includeClaims: true),
                    );
                }
            }

            $files = $request->file('attachments', []);
            $preservedAttachments = $existing
                ? $this->preservedClaimAttachments((int) $existing->id, $data['claims'], $staffId)
                : collect();
            $claims = $this->normalizeTravelClaims($data['claims']);
            $claims = $this->applyMileageRateSnapshots(
                $claims,
                $mileageRate,
                $existing ? $this->existingMileageRateSnapshots((int) $existing->id) : [],
            );
            $this->assertTravelProjectsAreValid($claims);
            $this->claimValidator->assertBusinessRules($claims, $files, $preservedAttachments);
            $claims = $this->salaryCalculator->prepareClaims($claims, $mileageRate);
            $claimsTotal = $this->claimsTotal($claims);
            if ($claimsTotal <= 0) {
                throw ValidationException::withMessages(['claims' => ['Add at least one claim before submitting.']]);
            }
            $this->assertMedicalClaimLimit(
                $staffId,
                $data['claim_month'],
                $claims,
                $settings['yearlyMedicalClaim'],
                $existing ? (int) $existing->id : null,
            );

            if ($existing && ! $isRevision) {
                $this->deleteApplicationClaims(
                    (int) $existing->id,
                    collect($preservedAttachments)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            }

            $payload = [
                'staff_id' => $staffId,
                'claim_month' => $data['claim_month'],
                'claim_month_label' => $this->formatClaimMonth($data['claim_month']),
                'claims_total' => $this->money($claimsTotal),
                'status' => 'Submitted',
                'draft_payload_json' => null,
                'draft_saved_at' => null,
                'submitted_at' => now(),
                'checked_by' => null,
                'checked_at' => null,
                'checked_status' => null,
                'checked_remarks' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approved_status' => null,
                'approved_remarks' => null,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('hr_other_claim_applications', 'record_version')) {
                $payload['record_version'] = $existing && ! $isRevision
                    ? DB::raw('record_version + 1')
                    : 1;
            }
            if (Schema::hasColumn('hr_other_claim_applications', 'claim_reference')) {
                $payload['claim_reference'] = $existing?->claim_reference ?: null;
                $payload['revision_no'] = $isRevision ? (int) ($existing->revision_no ?? 1) + 1 : (int) ($existing->revision_no ?? 1);
                $payload['parent_application_id'] = $isRevision
                    ? (int) ($existing->parent_application_id ?: $existing->id)
                    : ($existing->parent_application_id ?? null);
                $payload['superseded_by_application_id'] = null;
                $payload['superseded_at'] = null;
            }

            $applicationId = $existing && ! $isRevision
                ? (int) $existing->id
                : (int) DB::table('hr_other_claim_applications')->insertGetId([...$payload, 'created_at' => now()]);
            if ($existing && ! $isRevision) {
                DB::table('hr_other_claim_applications')->where('id', $applicationId)->update($payload);
            }
            if (Schema::hasColumn('hr_other_claim_applications', 'claim_reference')
                && empty($payload['claim_reference'])) {
                $referenceApplicationId = $isRevision
                    ? (int) ($existing->parent_application_id ?: $existing->id)
                    : $applicationId;
                DB::table('hr_other_claim_applications')->where('id', $applicationId)->update([
                    'claim_reference' => sprintf('OC-%06d', $referenceApplicationId),
                ]);
            }
            if ($existing && $isRevision && Schema::hasColumn('hr_other_claim_applications', 'superseded_at')) {
                DB::table('hr_other_claim_applications')->where('id', $existing->id)->update([
                    'superseded_by_application_id' => $applicationId,
                    'superseded_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->storeClaims($claims, $files, $preservedAttachments, $applicationId, $staffId, $data['claim_month']);
            $record = DB::table('hr_other_claim_applications')->where('id', $applicationId)->first();
            $this->workflowService->createOrResetOtherClaimWorkflow($applicationId, $staffId);
            $savedRecord = $this->recordPayload($record, includeClaims: true, request: $request);
        });

        $mailSent = false;
        if ($savedRecord && isset($savedRecord['id'])) {
            try {
                if ($amendmentNotification) {
                    $this->workflowNotifications->notifyRecordAmended(
                        $request,
                        $amendmentNotification['subjectType'],
                        $amendmentNotification['applicationId'],
                        $amendmentNotification['recipientIds'],
                        $amendmentNotification['reason'],
                    );
                }
                $mailSent = $this->workflowNotifications->notifySubmittedOtherClaim($request, (int) $savedRecord['id']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Other claim was submitted for review.',
            'record' => $savedRecord,
            'mail_sent' => null,
            'mail_status' => $mailSent ? 'digest' : 'notification_missing',
            'mail_message' => $mailSent
                ? 'Other claim submitted for review. Reviewers were notified in-app and will receive the daily pending-work digest when applicable.'
                : 'Other claim submitted for review. No workflow recipient notification was created; check workflow recipient settings.',
        ]);
    }

    public function attachment(Request $request, int $id)
    {
        $attachment = DB::table('hr_other_claim_attachments as attachment')
            ->join('hr_other_claim_items as claim', 'claim.id', '=', 'attachment.claim_id')
            ->join('hr_other_claim_applications as application', 'application.id', '=', 'claim.application_id')
            ->where('attachment.id', $id)
            ->where('application.staff_id', $this->staffId($request))
            ->select('attachment.*')
            ->first();

        if (! $attachment) {
            abort(404);
        }

        return AppFilePaths::storedPathResponse(
            (string) $attachment->stored_path,
            (string) $attachment->original_name,
        );
    }

    public function financialAttachment(Request $request, int $applicationId, int $attachmentId)
    {
        $record = $this->financialRecordQuery()->where('application.id', $applicationId)->first();
        if (! $record) {
            abort(404);
        }
        $this->workflowService->ensureOtherClaimWorkflowForExistingRecord($record);
        if (! $this->canViewFinancialRecord($request, $record)) {
            abort(404);
        }

        $attachment = DB::table('hr_other_claim_attachments as attachment')
            ->join('hr_other_claim_items as claim', 'claim.id', '=', 'attachment.claim_id')
            ->where('attachment.id', $attachmentId)
            ->where('claim.application_id', $applicationId)
            ->select('attachment.*')
            ->first();
        if (! $attachment) {
            abort(404);
        }

        return AppFilePaths::storedPathResponse(
            (string) $attachment->stored_path,
            (string) $attachment->original_name,
        );
    }

    private function validatedApplicationPayload(Request $request): array
    {
        return $this->validatedClaimPayload($request, false);
    }

    private function validatedDraftPayload(Request $request): array
    {
        $jsonErrors = [];
        $claims = $this->decodeJsonField($request, 'claims', [], $jsonErrors);
        $draftPayload = $this->decodeJsonField($request, 'draft_payload', [], $jsonErrors);
        $claims = $this->completeDraftClaims(is_array($claims) ? $claims : []);

        $payload = [
            ...$request->only(['claim_month']),
            'claims' => $claims,
            'draft_payload' => is_array($draftPayload) ? $draftPayload : [],
        ];

        $validator = Validator::make($payload, [
            'claim_month' => ['required', 'date_format:Y-m'],
            'claims' => ['array'],
            'claims.*.id' => ['nullable', 'string', 'max:191'],
            'claims.*.type' => ['nullable', 'string', 'in:'.implode(',', self::CLAIM_TYPES)],
            'claims.*.date' => ['nullable', 'date_format:Y-m-d'],
            'claims.*.description' => ['nullable', 'string', 'max:255'],
            'claims.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'claims.*.meta' => ['nullable', 'string', 'max:255'],
            'claims.*.km' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'claims.*.startLocation' => ['nullable', 'string', 'max:255'],
            'claims.*.endLocation' => ['nullable', 'string', 'max:255'],
            'claims.*.source' => ['nullable', 'string', 'max:64'],
            'claims.*.sourceLabel' => ['nullable', 'string', 'max:255'],
            'claims.*.tripMode' => ['nullable', 'string', 'in:one_way,return'],
            'claims.*.travelGroupId' => ['nullable', 'string', 'max:64'],
            'claims.*.expenseCategory' => ['nullable', 'string', 'in:combined,parking,toll,taxi,other'],
            'claims.*.travelCategory' => ['nullable', 'string', 'in:mileage,taxi,toll,parking,other,legacy_combined'],
            'claims.*.distanceMethod' => ['nullable', 'string', 'in:one_way,return_same_route,total_distance'],
            'claims.*.mileageRate' => ['nullable', 'numeric', 'min:0', 'max:999.9999'],
            'claims.*.chargeToProjectId' => ['nullable', 'integer', 'min:1'],
            'claims.*.locationDetail' => ['nullable', 'string', 'max:255'],
            'claims.*.expenseType' => ['nullable', 'string', 'max:120'],
            'claims.*.attachmentId' => ['nullable', 'integer'],
            'claims.*.attachments' => ['nullable', 'array'],
            'claims.*.attachments.*.id' => ['nullable', 'integer', 'min:1'],
            'claims.*.attachments.*.clientId' => ['nullable', 'string', 'max:191'],
            'claims.*.attachments.*.purpose' => ['nullable', 'string', 'max:32'],
            'draft_payload' => ['array'],
        ]);

        $validator->after(function ($validator) use ($jsonErrors): void {
            foreach ($jsonErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });

        return $validator->validate();
    }

    private function validatedClaimPayload(Request $request, bool $isDraft): array
    {
        $jsonErrors = [];
        $claims = $this->decodeJsonField($request, 'claims', [], $jsonErrors);
        $draftPayload = $this->decodeJsonField($request, 'draft_payload', [], $jsonErrors);
        $payload = [
            ...$request->only(['application_id', 'claim_month', 'amendment_reason', 'record_version']),
            'claims' => is_array($claims) ? $claims : [],
            'draft_payload' => is_array($draftPayload) ? $draftPayload : [],
        ];

        $rules = [
            'application_id' => ['nullable', 'integer', 'min:1'],
            'record_version' => ['required_with:application_id', 'integer', 'min:1'],
            'claim_month' => ['required', 'date_format:Y-m'],
            'amendment_reason' => ['nullable', 'string', 'max:1000'],
            'claims' => [$isDraft ? 'nullable' : 'required', 'array'],
            'claims.*.id' => [$isDraft ? 'nullable' : 'required', 'string', 'max:191'],
            'claims.*.type' => [$isDraft ? 'nullable' : 'required', 'string', 'in:'.implode(',', self::CLAIM_TYPES)],
            'claims.*.date' => ['nullable', 'date_format:Y-m-d'],
            'claims.*.description' => [$isDraft ? 'nullable' : 'required', 'string', 'max:255'],
            'claims.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'claims.*.meta' => ['nullable', 'string', 'max:255'],
            'claims.*.km' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'claims.*.startLocation' => ['nullable', 'string', 'max:255'],
            'claims.*.endLocation' => ['nullable', 'string', 'max:255'],
            'claims.*.source' => ['nullable', 'string', 'max:64'],
            'claims.*.sourceLabel' => ['nullable', 'string', 'max:255'],
            'claims.*.tripMode' => ['nullable', 'string', 'in:one_way,return'],
            'claims.*.travelGroupId' => ['nullable', 'string', 'max:64'],
            'claims.*.expenseCategory' => ['nullable', 'string', 'in:combined,parking,toll,taxi,other'],
            'claims.*.travelCategory' => ['nullable', 'string', 'in:mileage,taxi,toll,parking,other,legacy_combined'],
            'claims.*.distanceMethod' => ['nullable', 'string', 'in:one_way,return_same_route,total_distance'],
            'claims.*.mileageRate' => ['nullable', 'numeric', 'min:0', 'max:999.9999'],
            'claims.*.chargeToProjectId' => ['nullable', 'integer', 'min:1'],
            'claims.*.locationDetail' => ['nullable', 'string', 'max:255'],
            'claims.*.expenseType' => ['nullable', 'string', 'max:120'],
            'claims.*.attachmentId' => ['nullable', 'integer', 'min:1'],
            'claims.*.attachments' => ['nullable', 'array'],
            'claims.*.attachments.*.id' => ['nullable', 'integer', 'min:1'],
            'claims.*.attachments.*.clientId' => ['nullable', 'string', 'max:191'],
            'claims.*.attachments.*.purpose' => ['nullable', 'string', 'max:32'],
            'draft_payload' => ['nullable', 'array'],
        ];
        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($request, $payload, $jsonErrors): void {
            foreach ($jsonErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
            $claimIds = collect($payload['claims'] ?? [])->pluck('id')->map(fn ($id): string => (string) $id)->all();
            foreach ($request->file('attachments', []) as $key => $files) {
                if (! in_array((string) $key, $claimIds, true)) {
                    $validator->errors()->add("attachments.{$key}", 'Attachment does not match a claim row.');

                    continue;
                }
                foreach (ClaimAttachmentData::filesForClaim([$key => $files], (string) $key) as $clientId => $file) {
                    if (! $this->isClaimAttachmentFile($file)) {
                        $validator->errors()->add("attachments.{$key}.{$clientId}", 'Upload a PDF, JPG, JPEG, or PNG file up to 5 MB.');
                    }
                }
            }
        });

        return $validator->validate();
    }

    private function completeDraftClaims(array $claims): array
    {
        return collect($claims)
            ->filter(function (array $claim): bool {
                $type = (string) ($claim['type'] ?? '');
                $date = (string) ($claim['date'] ?? '');
                $amount = (float) ($claim['amount'] ?? 0);
                $km = (float) ($claim['km'] ?? 0);
                if (! in_array($type, self::CLAIM_TYPES, true) || trim((string) ($claim['id'] ?? '')) === '') {
                    return false;
                }
                if (trim((string) ($claim['description'] ?? '')) === '') {
                    return false;
                }
                if (strlen((string) ($claim['description'] ?? '')) > 255) {
                    return false;
                }
                if (strlen((string) ($claim['meta'] ?? '')) > 255) {
                    return false;
                }
                if (strlen((string) ($claim['source'] ?? '')) > 64) {
                    return false;
                }
                if (strlen((string) ($claim['sourceLabel'] ?? '')) > 255) {
                    return false;
                }
                if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    return false;
                }
                if ($amount < 0 || $amount > 9999999.99) {
                    return false;
                }
                if (isset($claim['km']) && ($km < 0 || $km > 999999.99)) {
                    return false;
                }
                if ($type === 'Mileage') {
                    return ! empty($claim['date'])
                        && trim((string) ($claim['startLocation'] ?? '')) !== ''
                        && trim((string) ($claim['endLocation'] ?? '')) !== ''
                        && $km > 0;
                }

                return $amount > 0;
            })
            ->values()
            ->all();
    }

    private function storeClaims(array $claims, array $files, $preservedAttachments, int $applicationId, int $staffId, string $claimMonth): void
    {
        foreach ($claims as $index => $claim) {
            $claimId = (int) DB::table('hr_other_claim_items')->insertGetId([
                'application_id' => $applicationId,
                'client_claim_id' => $claim['id'] ?? null,
                'type' => $claim['type'],
                'claim_date' => $claim['date'] ?? null,
                'description' => trim((string) $claim['description']),
                'amount' => $this->money($claim['amount']),
                'meta' => $claim['meta'] ?? null,
                'km' => isset($claim['km']) ? $this->money($claim['km']) : null,
                'start_location' => $claim['startLocation'] ?? null,
                'end_location' => $claim['endLocation'] ?? null,
                'travel_group_id' => $claim['travelGroupId'] ?? null,
                'trip_mode' => $claim['type'] === 'Mileage' ? ($claim['tripMode'] ?? 'return') : null,
                'expense_category' => $claim['type'] === 'Expense' ? ($claim['expenseCategory'] ?? null) : null,
                'travel_category' => ClaimAttachmentData::travelCategory($claim) ?: null,
                'distance_method' => $claim['type'] === 'Mileage' ? ($claim['distanceMethod'] ?? null) : null,
                'mileage_rate' => $claim['type'] === 'Mileage' && isset($claim['mileageRate'])
                    ? $this->rate($claim['mileageRate'])
                    : null,
                'charge_to_project_id' => isset($claim['chargeToProjectId']) && $claim['chargeToProjectId'] !== ''
                    ? (int) $claim['chargeToProjectId']
                    : null,
                'location_detail' => $claim['locationDetail'] ?? null,
                'expense_type' => $claim['expenseType'] ?? null,
                'source' => $claim['source'] ?? null,
                'source_label' => $claim['sourceLabel'] ?? null,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $clientClaimId = (string) ($claim['id'] ?? '');
            $claimFiles = ClaimAttachmentData::filesForClaim($files, $clientClaimId);
            $definitions = ClaimAttachmentData::definitions($claim);

            if ($definitions === [] && $claimFiles !== []) {
                foreach ($claimFiles as $clientId => $file) {
                    $definitions[] = [
                        'clientId' => (string) $clientId,
                        'id' => null,
                        'purpose' => ClaimAttachmentData::purpose($claim),
                    ];
                }
            }

            foreach ($definitions as $attachment) {
                $clientId = (string) $attachment['clientId'];
                if (isset($claimFiles[$clientId]) && $this->isClaimAttachmentFile($claimFiles[$clientId])) {
                    $this->storeClaimAttachment(
                        $claimFiles[$clientId],
                        $claimId,
                        $staffId,
                        $claimMonth,
                        (string) $attachment['purpose'],
                    );
                } elseif ($attachment['id'] !== null && $preservedAttachments->has((int) $attachment['id'])) {
                    $this->copyClaimAttachment($preservedAttachments->get((int) $attachment['id']), $claimId);
                }
            }
        }
    }

    private function recordPayload(
        object $record,
        bool $includeClaims,
        ?Request $request = null,
        ?array $workflowPayload = null,
        bool $financialView = false,
    ): array {
        $payload = [
            'id' => (int) $record->id,
            'staffId' => (int) $record->staff_id,
            'claimMonth' => (string) $record->claim_month_label,
            'claimMonthValue' => (string) $record->claim_month,
            'claimsTotal' => (float) $record->claims_total,
            'medicalClaimsTotal' => property_exists($record, 'medical_claims_total')
                ? (float) $record->medical_claims_total
                : $this->medicalClaimsTotalForApplication((int) $record->id),
            'status' => $this->displayStatus((string) $record->status),
            'draftPayload' => $this->decodeJson($record->draft_payload_json ?? null),
            'draftSavedAt' => $record->draft_saved_at ?? null,
            'submittedAt' => $record->submitted_at,
            'checkedBy' => isset($record->checked_by) ? (int) $record->checked_by : null,
            'checkedAt' => $record->checked_at ?? null,
            'checkedStatus' => (string) ($record->checked_status ?? ''),
            'checkedRemarks' => (string) ($record->checked_remarks ?? ''),
            'approvedBy' => isset($record->approved_by) ? (int) $record->approved_by : null,
            'approvedAt' => $record->approved_at ?? null,
            'approvedStatus' => (string) ($record->approved_status ?? ''),
            'approvedRemarks' => (string) ($record->approved_remarks ?? ''),
            'cancelledAt' => $record->cancelled_at ?? null,
            'cancelledBy' => isset($record->cancelled_by) ? (int) $record->cancelled_by : null,
            'cancelReason' => (string) ($record->cancel_reason ?? ''),
            'claimReference' => (string) ($record->claim_reference ?? sprintf('OC-%06d', (int) $record->id)),
            'revisionNo' => max(1, (int) ($record->revision_no ?? 1)),
            'parentApplicationId' => $record->parent_application_id ?? null,
            'supersededByApplicationId' => $record->superseded_by_application_id ?? null,
            'supersededAt' => $record->superseded_at ?? null,
            'recordVersion' => max(1, (int) ($record->record_version ?? 1)),
        ];
        foreach ([
            'staffName' => 'staff_name',
            'staffCode' => 'staff_code',
            'staffPosition' => 'staff_position',
            'staffDepartment' => 'staff_department',
            'checkerName' => 'checker_name',
            'checkerCode' => 'checker_code',
            'approverName' => 'approver_name',
            'approverCode' => 'approver_code',
        ] as $payloadKey => $recordKey) {
            if (property_exists($record, $recordKey)) {
                $payload[$payloadKey] = (string) ($record->{$recordKey} ?? '');
            }
        }
        if ($includeClaims) {
            $payload['claims'] = $this->claimsForApplication((int) $record->id, $financialView);
            $payload['paymentHistory'] = $this->paymentHistoryForApplication((int) $record->id, $financialView);
        }
        $payload['workflow'] = (string) $record->status === 'Draft'
            ? null
            : ($workflowPayload ?? $this->workflowService->otherClaimWorkflowPayload((int) $record->id, $request));

        return $payload;
    }

    private function claimsForApplication(int $applicationId, bool $financialView = false): array
    {
        $claims = DB::table('hr_other_claim_items')
            ->where('application_id', $applicationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $attachments = DB::table('hr_other_claim_attachments')
            ->whereIn('claim_id', $claims->pluck('id')->all())
            ->get()
            ->groupBy('claim_id');

        return $claims->map(function (object $claim) use ($attachments, $applicationId, $financialView): array {
            $claimAttachments = $attachments->get($claim->id, collect())
                ->map(fn (object $attachment): array => [
                    'id' => (int) $attachment->id,
                    'name' => (string) $attachment->original_name,
                    'size' => (int) $attachment->size,
                    'type' => (string) ($attachment->mime_type ?? ''),
                    'purpose' => (string) ($attachment->purpose ?? ''),
                    'url' => $financialView
                        ? url("hr/salary/other-claims/financial-records/{$applicationId}/attachments/{$attachment->id}")
                        : url("hr/salary/other-claim-attachments/{$attachment->id}"),
                    'downloadUrl' => $financialView
                        ? url("hr/salary/other-claims/financial-records/{$applicationId}/attachments/{$attachment->id}")
                        : url("hr/salary/other-claim-attachments/{$attachment->id}"),
                ])
                ->values()
                ->all();

            return [
                'id' => (string) ($claim->client_claim_id ?: $claim->id),
                'recordItemId' => (int) $claim->id,
                'type' => (string) $claim->type,
                'date' => (string) ($claim->claim_date ?? ''),
                'description' => (string) $claim->description,
                'amount' => (float) $claim->amount,
                'meta' => (string) ($claim->meta ?? ''),
                'km' => $claim->km !== null ? (float) $claim->km : null,
                'startLocation' => (string) ($claim->start_location ?? ''),
                'endLocation' => (string) ($claim->end_location ?? ''),
                'travelGroupId' => (string) ($claim->travel_group_id ?? ''),
                'tripMode' => (string) ($claim->trip_mode ?? (($claim->type ?? '') === 'Mileage' ? 'return' : '')),
                'expenseCategory' => (string) ($claim->expense_category ?? ''),
                'travelCategory' => (string) ($claim->travel_category ?? ''),
                'distanceMethod' => (string) ($claim->distance_method ?? ''),
                'mileageRate' => $claim->mileage_rate !== null ? (float) $claim->mileage_rate : null,
                'chargeToProjectId' => $claim->charge_to_project_id !== null ? (int) $claim->charge_to_project_id : null,
                'locationDetail' => (string) ($claim->location_detail ?? ''),
                'expenseType' => (string) ($claim->expense_type ?? ''),
                'source' => (string) ($claim->source ?? ''),
                'sourceLabel' => (string) ($claim->source_label ?? ''),
                'attachments' => $claimAttachments,
                // Kept for older frontend consumers while they migrate to the attachment list.
                'attachment' => $claimAttachments[0] ?? null,
            ];
        })->all();
    }

    private function paymentHistoryForApplication(int $applicationId, bool $financialView): array
    {
        if (
            ! Schema::hasTable('hr_salary_payment_runs')
            || ! Schema::hasTable('hr_salary_payment_run_items')
        ) {
            return [];
        }

        $hasVoids = Schema::hasColumn('hr_salary_payment_runs', 'voided_at')
            && Schema::hasColumn('hr_salary_payment_run_items', 'voided_at');
        $query = DB::table('hr_salary_payment_run_items as item')
            ->join('hr_salary_payment_runs as run', 'run.id', '=', 'item.payment_run_id')
            ->leftJoin('staff_general as paid_actor', 'paid_actor.staff_id', '=', 'run.actor_staff_id')
            ->where('item.subject_type', self::SUBJECT_TYPE)
            ->where('item.subject_id', $applicationId)
            ->orderByDesc('run.paid_at')
            ->orderByDesc('run.id')
            ->select([
                'run.id',
                'run.payment_date',
                'run.payment_reference',
                'run.payment_method',
                'run.remarks',
                'run.actor_staff_id',
                'run.paid_at',
                'item.amount_paid',
                'paid_actor.full_name as paid_actor_name',
                'paid_actor.name_code as paid_actor_code',
            ]);
        if ($hasVoids) {
            $query->leftJoin('staff_general as void_actor', 'void_actor.staff_id', '=', 'run.voided_by')
                ->addSelect([
                    'run.voided_at',
                    'run.voided_by',
                    'run.void_reason',
                    'item.voided_at as item_voided_at',
                    'void_actor.full_name as void_actor_name',
                    'void_actor.name_code as void_actor_code',
                ]);
        }

        return $query->get()
            ->map(function (object $payment) use ($financialView, $hasVoids): array {
                $reversedAt = $hasVoids
                    ? ($payment->item_voided_at ?? $payment->voided_at ?? null)
                    : null;

                return [
                    'id' => (int) $payment->id,
                    'status' => $reversedAt ? 'Reversed' : 'Paid',
                    'amount' => (float) $payment->amount_paid,
                    'paymentDate' => $payment->payment_date,
                    'paymentReference' => (string) ($payment->payment_reference ?? ''),
                    'paymentMethod' => (string) ($payment->payment_method ?? ''),
                    'remarks' => $financialView ? (string) ($payment->remarks ?? '') : '',
                    'paidAt' => $payment->paid_at,
                    'paidBy' => $this->staffLabel(
                        $payment->paid_actor_name ?? '',
                        $payment->paid_actor_code ?? '',
                        $payment->actor_staff_id ?? null,
                    ),
                    'reversedAt' => $reversedAt,
                    'reversedBy' => $reversedAt && $financialView
                        ? $this->staffLabel(
                            $payment->void_actor_name ?? '',
                            $payment->void_actor_code ?? '',
                            $payment->voided_by ?? null,
                        )
                        : '',
                    'reversalReason' => $reversedAt
                        ? ($financialView
                            ? (string) ($payment->void_reason ?? '')
                            : 'Payment reversal recorded. Contact Finance if you need more information.')
                        : '',
                ];
            })
            ->all();
    }

    private function claimPdfResponse(Request $request, object $record)
    {
        $claims = $this->claimsForApplication((int) $record->id);
        $generatedAt = now();
        $generatorCode = $this->generatorCode($request);
        $generatorId = (string) $request->session()->get('user_id', '-');
        $profile = DB::table('hr_salary_profiles')->where('staff_id', (int) $record->staff_id)->first();
        $approver = ! empty($record->approved_by)
            ? DB::table('staff_general')->where('staff_id', (int) $record->approved_by)->first()
            : null;
        $checker = ! empty($record->checked_by)
            ? DB::table('staff_general')->where('staff_id', (int) $record->checked_by)->first()
            : null;
        $year = substr((string) $record->claim_month, 0, 4) ?: $generatedAt->format('Y');
        $yearlyMedicalClaim = (float) $this->money($profile->yearly_medical_claim ?? 0);
        $medicalClaimTotal = (float) $this->money(collect($claims)
            ->filter(fn (array $claim): bool => ($claim['type'] ?? '') === 'Medical')
            ->sum(fn (array $claim): float => (float) ($claim['amount'] ?? 0)));
        $medicalCurrentLeft = (float) $this->money(max(
            0,
            $yearlyMedicalClaim - $this->usedMedicalClaimsForYear(
                (int) $record->staff_id,
                $year,
                (int) $record->id,
            ),
        ));
        $html = view('pdf.other-claims-report', [
            'record' => $this->recordPayload($record, includeClaims: false, request: $request),
            'claims' => $claims,
            'generatedAt' => $generatedAt,
            'claimDate' => $record->submitted_at ?: $record->updated_at ?: $generatedAt,
            'applicantSignature' => [
                'dataUri' => $this->staffSignatureDataUri((int) $record->staff_id, (string) ($record->staff_code ?? '')),
                'name' => (string) ($record->staff_name ?? ''),
                'code' => (string) ($record->staff_code ?? ''),
                'signedAt' => $record->submitted_at ?: $record->updated_at ?: null,
            ],
            'approverSignature' => [
                'dataUri' => $approver
                    ? $this->staffSignatureDataUri((int) $approver->staff_id, (string) ($approver->name_code ?? ''))
                    : null,
                'name' => (string) ($approver->full_name ?? ''),
                'code' => (string) ($approver->name_code ?? ''),
                'signedAt' => $record->approved_at ?? null,
            ],
            'checkerSignature' => [
                'dataUri' => $checker
                    ? $this->staffSignatureDataUri((int) $checker->staff_id, (string) ($checker->name_code ?? ''))
                    : null,
                'name' => (string) ($checker->full_name ?? ''),
                'code' => (string) ($checker->name_code ?? ''),
                'signedAt' => $record->checked_at ?? null,
            ],
            'mileageRate' => (float) ($profile->default_mileage_rate ?? 0.6),
            'vehicle' => (string) ($profile->vehicle ?? ''),
            'medicalBalance' => [
                'yearlyLimit' => $yearlyMedicalClaim,
                'currentLeft' => $medicalCurrentLeft,
                'thisClaim' => $medicalClaimTotal,
                'afterClaim' => (float) $this->money(max(0, $medicalCurrentLeft - $medicalClaimTotal)),
            ],
            'logoDataUri' => $this->companyLogoDataUri(),
        ])->render();

        $mainPdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId)->output();
        $sources = [$mainPdf, ...$this->claimAttachmentPdfSources($record, $claims, $generatedAt, $generatorCode, $generatorId)];
        $mergedPdf = count($sources) > 1 ? app(PdfMergeService::class)->mergeSequence($sources) : null;
        $pdfBytes = $mergedPdf ?: $mainPdf;
        $safeMonth = Str::slug((string) $record->claim_month_label) ?: (string) $record->claim_month;

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"other-claims-{$safeMonth}.pdf\"",
        ]);
    }

    private function staffSignatureDataUri(int $staffId, string $nameCode): ?string
    {
        $nameCode = trim($nameCode);
        if ($staffId <= 0 || $nameCode === '') {
            return null;
        }

        foreach (['jpg' => 'image/jpeg', 'png' => 'image/png'] as $ext => $mimeType) {
            $path = "signatures/{$staffId}-{$nameCode}.{$ext}";
            $localPath = AppFilePaths::storedPathLocalPath($path);
            if ($localPath === null || ! is_file($localPath) || ! is_readable($localPath)) {
                continue;
            }

            $bytes = @file_get_contents($localPath);
            if ($bytes === false) {
                continue;
            }

            return 'data:'.$mimeType.';base64,'.base64_encode($bytes);
        }

        return null;
    }

    private function pdfRecordQuery()
    {
        return DB::table('hr_other_claim_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'staff.email as staff_email',
                'staff.position as staff_position',
                'staff.department as staff_department',
            ]);
    }

    private function financialRecordQuery()
    {
        return DB::table('hr_other_claim_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->leftJoin('staff_general as checker', 'checker.staff_id', '=', 'application.checked_by')
            ->leftJoin('staff_general as approver', 'approver.staff_id', '=', 'application.approved_by')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'staff.position as staff_position',
                'staff.department as staff_department',
                'checker.full_name as checker_name',
                'checker.name_code as checker_code',
                'approver.full_name as approver_name',
                'approver.name_code as approver_code',
            ]);
    }

    private function canViewFinancialRecord(Request $request, object $record): bool
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0 || $actorId === (int) $record->staff_id) {
            return false;
        }
        if (in_array($actorId, [(int) ($record->checked_by ?? 0), (int) ($record->approved_by ?? 0)], true)) {
            return true;
        }

        $instance = DB::table('workflow_instances')
            ->where('subject_type', self::SUBJECT_TYPE)
            ->where('subject_id', (int) $record->id)
            ->first();
        if (! $instance) {
            return false;
        }
        if (DB::table('workflow_actions')
            ->where('instance_id', (int) $instance->id)
            ->where('actor_staff_id', $actorId)
            ->exists()) {
            return true;
        }

        $workflow = $this->workflowService->otherClaimWorkflowPayload((int) $record->id, $request);

        return ! empty($workflow['availableActions']);
    }

    private function claimAttachmentPdfSources(object $record, array $claims, Carbon $generatedAt, string $generatorCode, string $generatorId): array
    {
        $claimIds = collect($claims)
            ->pluck('recordItemId')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($claimIds === []) {
            return [];
        }

        $claimsById = collect($claims)->keyBy('recordItemId');
        $attachments = DB::table('hr_other_claim_attachments')->whereIn('claim_id', $claimIds)->orderBy('id')->get();
        $mergeableAttachments = $attachments
            ->map(function (object $attachment) use ($claimsById): ?array {
                $path = AppFilePaths::storedPathLocalPath((string) $attachment->stored_path);
                if ($path === null || ! is_file($path) || ! is_readable($path)) {
                    return null;
                }

                $mimeType = strtolower((string) ($attachment->mime_type ?: @mime_content_type($path) ?: ''));
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (str_contains($mimeType, 'pdf') || $extension === 'pdf') {
                    return [
                        'attachment' => $attachment,
                        'path' => $path,
                        'mimeType' => $mimeType,
                        'claim' => $claimsById->get((int) $attachment->claim_id, []),
                        'kind' => 'pdf',
                    ];
                }
                if (
                    str_contains($mimeType, 'jpeg') ||
                    str_contains($mimeType, 'jpg') ||
                    str_contains($mimeType, 'png') ||
                    in_array($extension, ['jpg', 'jpeg', 'png'], true)
                ) {
                    return [
                        'attachment' => $attachment,
                        'path' => $path,
                        'mimeType' => $mimeType,
                        'claim' => $claimsById->get((int) $attachment->claim_id, []),
                        'kind' => 'image',
                    ];
                }

                return null;
            })
            ->filter()
            ->values();

        $sources = [];
        $attachmentCount = $mergeableAttachments->count();
        $attachmentIndex = 0;
        foreach ($mergeableAttachments as $item) {
            if ($item['kind'] === 'pdf') {
                $attachmentIndex++;
                $sources[] = $item['path'];

                continue;
            }

            $attachmentIndex++;
            try {
                $sources[] = $this->renderClaimAttachmentImagePdf(
                    $record,
                    $item['claim'],
                    $item['attachment'],
                    $item['path'],
                    $item['mimeType'],
                    $generatedAt,
                    $generatorCode,
                    $generatorId,
                    $attachmentIndex,
                    $attachmentCount,
                );
            } catch (\Throwable) {
                // Skip unreadable image attachments in the combined PDF.
            }
        }

        return array_values(array_filter($sources));
    }

    private function renderClaimAttachmentImagePdf(object $record, array $claim, object $attachment, string $path, string $mimeType, Carbon $generatedAt, string $generatorCode, string $generatorId, int $attachmentIndex, int $attachmentCount): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $imageMime = str_contains($mimeType, 'png') ? 'image/png' : 'image/jpeg';
        $claimDate = $record->submitted_at ?: $record->updated_at ?: $generatedAt;
        $claimDateLabel = $claimDate instanceof Carbon
            ? $claimDate->format('d-M-Y h:i A')
            : Carbon::parse($claimDate)->format('d-M-Y h:i A');
        $html = view('pdf.salary-claim-attachment-image', [
            'documentTitle' => 'Other Claim Attachment',
            'periodLabel' => 'Claim Date',
            'periodValue' => $claimDateLabel,
            'record' => [
                'salaryMonth' => (string) $record->claim_month_label,
                'staffName' => (string) ($record->staff_name ?? ''),
                'staffCode' => (string) ($record->staff_code ?? ''),
            ],
            'claim' => $claim,
            'attachment' => ['name' => (string) $attachment->original_name],
            'attachmentIndex' => $attachmentIndex,
            'attachmentCount' => $attachmentCount,
            'imageDataUri' => 'data:'.$imageMime.';base64,'.base64_encode($bytes),
            'logoDataUri' => $this->companyLogoDataUri(),
        ])->render();

        return $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId)->output();
    }

    private function preservedClaimAttachments(int $applicationId, array $claims, int $staffId)
    {
        $attachmentIds = collect($claims)
            ->flatMap(fn (array $claim) => ClaimAttachmentData::definitions($claim))
            ->pluck('id')
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($attachmentIds === []) {
            return collect();
        }

        return DB::table('hr_other_claim_attachments as attachment')
            ->join('hr_other_claim_items as claim', 'claim.id', '=', 'attachment.claim_id')
            ->where('claim.application_id', $applicationId)
            ->where('attachment.staff_id', $staffId)
            ->whereIn('attachment.id', $attachmentIds)
            ->select('attachment.*')
            ->get()
            ->keyBy('id');
    }

    private function deleteApplicationClaims(int $applicationId, array $preserveAttachmentIds = []): void
    {
        $preserveAttachmentIds = array_map('intval', $preserveAttachmentIds);
        $attachments = DB::table('hr_other_claim_attachments as attachment')
            ->join('hr_other_claim_items as claim', 'claim.id', '=', 'attachment.claim_id')
            ->where('claim.application_id', $applicationId)
            ->select('attachment.id', 'attachment.stored_path')
            ->get();
        foreach ($attachments as $attachment) {
            if (! in_array((int) $attachment->id, $preserveAttachmentIds, true)) {
                AppFilePaths::deleteStoredPath((string) $attachment->stored_path);
            }
        }

        DB::table('hr_other_claim_items')->where('application_id', $applicationId)->delete();
    }

    private function isClaimAttachmentFile($file): bool
    {
        return ! Validator::make(
            ['attachment' => $file],
            ['attachment' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']],
        )->fails();
    }

    private function storeClaimAttachment($file, int $claimId, int $staffId, string $claimMonth, string $purpose = 'receipt'): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedName = Str::uuid()->toString().'.'.$extension;
        $storedPath = AppFilePaths::storeFileAs("other-claims/{$staffId}/{$claimMonth}", $file, $storedName);
        if (! AppFilePaths::storedPathExists($storedPath)) {
            throw ValidationException::withMessages([
                'attachments' => ['The supporting document could not be stored. Try again or contact an administrator.'],
            ]);
        }

        DB::table('hr_other_claim_attachments')->insert([
            'claim_id' => $claimId,
            'staff_id' => $staffId,
            'stored_path' => $storedPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'purpose' => $purpose,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function copyClaimAttachment(object $attachment, int $claimId): void
    {
        $payload = [
            'claim_id' => $claimId,
            'staff_id' => (int) $attachment->staff_id,
            'stored_path' => (string) $attachment->stored_path,
            'original_name' => (string) $attachment->original_name,
            'mime_type' => (string) ($attachment->mime_type ?? ''),
            'size' => (int) ($attachment->size ?? 0),
            'purpose' => (string) ($attachment->purpose ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('hr_other_claim_attachments', 'source_attachment_id')) {
            $payload['source_attachment_id'] = (int) $attachment->id;
        }

        DB::table('hr_other_claim_attachments')->insert($payload);
    }

    private function claimsTotal(array $claims): float
    {
        return (float) $this->money(collect($claims)->sum(fn (array $claim): float => (float) ($claim['amount'] ?? 0)));
    }

    private function medicalClaimsTotalForApplication(int $applicationId): float
    {
        return (float) $this->money(DB::table('hr_other_claim_items')
            ->where('application_id', $applicationId)
            ->where('type', 'Medical')
            ->sum('amount'));
    }

    private function submissionSettings(int $staffId): array
    {
        $profile = DB::table('hr_salary_profiles')->where('staff_id', $staffId)->first();

        return [
            'mileageRate' => (float) ($profile->default_mileage_rate ?? 0.6),
            'yearlyMedicalClaim' => (float) ($profile->yearly_medical_claim ?? 0),
        ];
    }

    private function mileageRate(int $staffId): float
    {
        return $this->submissionSettings($staffId)['mileageRate'];
    }

    private function rate(mixed $value): string
    {
        return number_format(round((float) $value + PHP_FLOAT_EPSILON, 4), 4, '.', '');
    }

    private function existingMileageRateSnapshots(int $applicationId): array
    {
        return DB::table('hr_other_claim_items')
            ->where('application_id', $applicationId)
            ->where('type', 'Mileage')
            ->whereNotNull('client_claim_id')
            ->whereNotNull('mileage_rate')
            ->pluck('mileage_rate', 'client_claim_id')
            ->map(fn ($rate): float => (float) $rate)
            ->all();
    }

    private function applyMileageRateSnapshots(array $claims, float $currentRate, array $existingSnapshots): array
    {
        $errors = [];

        foreach ($claims as $index => &$claim) {
            if (($claim['type'] ?? '') !== 'Mileage') {
                continue;
            }

            $clientClaimId = (string) ($claim['id'] ?? '');
            if ($clientClaimId !== '' && array_key_exists($clientClaimId, $existingSnapshots)) {
                $claim['mileageRate'] = $existingSnapshots[$clientClaimId];

                continue;
            }

            if (array_key_exists('mileageRate', $claim) && $claim['mileageRate'] !== null && $claim['mileageRate'] !== '') {
                $previewRate = round((float) $claim['mileageRate'], 4);
                if (abs($previewRate - round($currentRate, 4)) > 0.00001) {
                    $errors["claims.{$index}.mileageRate"][] = sprintf(
                        'The mileage rate changed to RM %.4f per KM. Review the updated amount before submitting.',
                        $currentRate,
                    );

                    continue;
                }
            }

            $claim['mileageRate'] = $currentRate;
        }
        unset($claim);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $claims;
    }

    private function normalizeTravelClaims(array $claims): array
    {
        $mileageByGroup = [];
        $legacyExpenseGroups = [];

        foreach ($claims as $claim) {
            $groupId = trim((string) ($claim['travelGroupId'] ?? ''));
            if ($groupId === '') {
                continue;
            }
            if (($claim['type'] ?? '') === 'Mileage') {
                $mileageByGroup[$groupId] = $claim;
            }
            if (($claim['type'] ?? '') === 'Expense' && (float) ($claim['amount'] ?? 0) > 0) {
                $legacyExpenseGroups[$groupId] = true;
            }
        }

        $normalized = [];
        foreach ($claims as $claim) {
            $type = (string) ($claim['type'] ?? '');
            $groupId = trim((string) ($claim['travelGroupId'] ?? ''));

            if ($type === 'Mileage') {
                if ((float) ($claim['km'] ?? 0) <= 0 && $groupId !== '' && isset($legacyExpenseGroups[$groupId])) {
                    // Older drafts represented an expense-only journey with a synthetic zero-value Mileage row.
                    continue;
                }
                $claim['travelCategory'] = 'mileage';
                $claim['distanceMethod'] = $claim['distanceMethod'] ?? (
                    ($claim['tripMode'] ?? null) === 'one_way' ? 'one_way' : 'return_same_route'
                );
            } elseif ($type === 'Expense') {
                $legacyCategory = trim((string) ($claim['expenseCategory'] ?? ''));
                if (trim((string) ($claim['travelCategory'] ?? '')) === '' && $legacyCategory !== '') {
                    $claim['travelCategory'] = $legacyCategory === 'combined' ? 'legacy_combined' : $legacyCategory;
                }
                if ($groupId !== '' && isset($mileageByGroup[$groupId])) {
                    $linkedMileage = $mileageByGroup[$groupId];
                    if (trim((string) ($claim['startLocation'] ?? '')) === '') {
                        $claim['startLocation'] = $linkedMileage['startLocation'] ?? '';
                    }
                    if (trim((string) ($claim['endLocation'] ?? '')) === '') {
                        $claim['endLocation'] = $linkedMileage['endLocation'] ?? '';
                    }
                    if (trim((string) ($claim['source'] ?? '')) === '') {
                        $claim['source'] = $linkedMileage['source'] ?? '';
                    }
                    if (trim((string) ($claim['sourceLabel'] ?? '')) === '') {
                        $claim['sourceLabel'] = $linkedMileage['sourceLabel'] ?? '';
                    }
                }
            }

            $normalized[] = $claim;
        }

        return $normalized;
    }

    private function assertTravelProjectsAreValid(array $claims): void
    {
        $projectIds = collect($claims)
            ->pluck('chargeToProjectId')
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($projectIds->isEmpty()) {
            return;
        }

        $validIds = DB::table('projects_main')
            ->whereIn('id', $projectIds->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $validIdMap = array_fill_keys($validIds, true);
        $errors = [];

        foreach ($claims as $index => $claim) {
            $projectId = (int) ($claim['chargeToProjectId'] ?? 0);
            if ($projectId > 0 && ! isset($validIdMap[$projectId])) {
                $errors["claims.{$index}.chargeToProjectId"][] = 'Select a valid project before saving this travel claim.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertMedicalClaimLimit(
        int $staffId,
        string $claimMonth,
        array $claims,
        float $yearlyMedicalClaim,
        ?int $excludeOtherClaimApplicationId = null,
    ): void {
        $medicalTotal = (float) $this->money(collect($claims)
            ->filter(fn (array $claim): bool => ($claim['type'] ?? '') === 'Medical')
            ->sum(fn (array $claim): float => (float) ($claim['amount'] ?? 0)));
        if ($medicalTotal <= 0) {
            return;
        }
        if ($yearlyMedicalClaim <= 0) {
            throw ValidationException::withMessages([
                'claims' => [
                    'A medical claim is included, but no annual medical entitlement is configured. Remove the medical claim if it was added by mistake, or contact HR.',
                ],
            ]);
        }

        $available = (float) $this->money($yearlyMedicalClaim - $this->usedMedicalClaimsForYear(
            $staffId,
            substr($claimMonth, 0, 4),
            $excludeOtherClaimApplicationId,
        ));

        if ($medicalTotal > $available) {
            throw ValidationException::withMessages([
                'claims' => [
                    'Medical claims exceed the annual medical claim balance of '.$this->money(max(0, $available)).'.',
                ],
            ]);
        }
    }

    private function usedMedicalClaimsForYear(int $staffId, string $year, ?int $excludeOtherClaimApplicationId = null): float
    {
        $salaryUsed = DB::table('hr_salary_claims as claim')
            ->join('hr_salary_applications as application', 'application.id', '=', 'claim.application_id')
            ->where('application.staff_id', $staffId)
            ->where('application.salary_month', 'like', $year.'-%')
            ->where('claim.type', 'Medical')
            ->whereNotIn('application.status', ['Draft', 'Rejected', self::CANCELLED_STATUS])
            ->sum('claim.amount');

        $otherClaimUsed = DB::table('hr_other_claim_items as claim')
            ->join('hr_other_claim_applications as application', 'application.id', '=', 'claim.application_id')
            ->where('application.staff_id', $staffId)
            ->where('application.claim_month', 'like', $year.'-%')
            ->where('claim.type', 'Medical')
            ->whereNotIn('application.status', ['Draft', 'Rejected', self::CANCELLED_STATUS])
            ->when(
                $excludeOtherClaimApplicationId,
                fn ($query) => $query->where('application.id', '<>', $excludeOtherClaimApplicationId),
            )
            ->sum('claim.amount');

        return (float) $this->money((float) $salaryUsed + (float) $otherClaimUsed);
    }

    private function decodeJsonField(Request $request, string $field, mixed $default, array &$errors = []): mixed
    {
        $raw = $request->input($field);
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return $default;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $errors[$field] = 'The '.$field.' field must contain valid JSON.';

            return $default;
        }
    }

    private function decodeJson(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function formatClaimMonth(string $claimMonth): string
    {
        return Carbon::createFromFormat('Y-m', $claimMonth)->format('F Y');
    }

    private function displayStatus(string $status): string
    {
        return match ($status) {
            'Prepared' => 'Submitted',
            default => $status,
        };
    }

    private function staffLabel(mixed $name, mixed $code, mixed $staffId): string
    {
        $name = trim((string) $name);
        $code = trim((string) $code);
        if ($name !== '' && $code !== '') {
            return "{$name} ({$code})";
        }

        return $name !== '' ? $name : ($code !== '' ? $code : ((int) $staffId > 0 ? 'Staff #'.(int) $staffId : ''));
    }

    private function isStaffEditableStatus(string $status): bool
    {
        return in_array($status, [...self::STAFF_MUTABLE_STATUSES, ...self::REVIEWED_MUTABLE_STATUSES], true);
    }

    private function isReviewedMutableStatus(string $status): bool
    {
        return in_array($status, self::REVIEWED_MUTABLE_STATUSES, true);
    }

    private function isPaidStatus(string $status): bool
    {
        return in_array($status, self::PAID_STATUSES, true);
    }

    private function workflowParticipantIds(object $record, string $subjectType, array $excludeStaffIds = []): array
    {
        $ids = [
            (int) ($record->checked_by ?? 0),
            (int) ($record->approved_by ?? 0),
        ];

        $instanceIds = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', (int) $record->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($instanceIds !== []) {
            $ids = [
                ...$ids,
                ...DB::table('workflow_actions')
                    ->whereIn('instance_id', $instanceIds)
                    ->pluck('actor_staff_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            ];
        }

        $ids = [
            ...$ids,
            ...app(SalaryWorkflowRecipientResolver::class)->currentStepRecipientIds(
                $subjectType,
                (int) $record->id,
                $excludeStaffIds,
            ),
        ];

        $exclude = array_values(array_unique(array_filter(array_map('intval', $excludeStaffIds))));

        return array_values(array_diff(array_unique(array_filter($ids)), $exclude));
    }

    private function snapshotRecord(object $record, bool $includeClaims = true): array
    {
        $snapshot = [
            'id' => (int) $record->id,
            'staffId' => (int) $record->staff_id,
            'claimMonth' => (string) $record->claim_month_label,
            'claimMonthValue' => (string) $record->claim_month,
            'claimsTotal' => (float) $record->claims_total,
            'status' => (string) $record->status,
            'submittedAt' => $record->submitted_at ?? null,
            'checkedBy' => isset($record->checked_by) ? (int) $record->checked_by : null,
            'checkedAt' => $record->checked_at ?? null,
            'approvedBy' => isset($record->approved_by) ? (int) $record->approved_by : null,
            'approvedAt' => $record->approved_at ?? null,
        ];

        if ($includeClaims) {
            $snapshot['claims'] = $this->claimsForApplication((int) $record->id);
        }

        return $snapshot;
    }

    private function recordWorkflowEvent(
        string $subjectType,
        int $subjectId,
        string $action,
        int $actorStaffId,
        ?string $statusFrom,
        ?string $statusTo,
        string $reason,
        array $previousSnapshot,
    ): void {
        if (! Schema::hasTable('hr_salary_workflow_events')) {
            return;
        }

        DB::table('hr_salary_workflow_events')->insert([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'actor_staff_id' => $actorStaffId > 0 ? $actorStaffId : null,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'reason' => $reason,
            'previous_snapshot_json' => json_encode($previousSnapshot, JSON_THROW_ON_ERROR),
            'acted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function generatorCode(Request $request): string
    {
        $staffId = $this->staffId($request);
        if ($staffId <= 0) {
            return '';
        }

        return (string) (DB::table('staff_general')
            ->where('staff_id', $staffId)
            ->value('name_code') ?? '');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
