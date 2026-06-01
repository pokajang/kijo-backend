<?php

namespace App\Services\Salary;

use App\Services\Pdf\PdfRenderer;
use App\Services\Quotes\Pdf\PdfMergeService;
use App\Services\Workflows\WorkflowService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtherClaimService extends PdfRenderer
{
    private const CLAIM_TYPES = ['Allowance', 'Expense', 'Mileage', 'Medical'];
    private const FINANCIAL_ACTIONS = ['check', 'approve', 'reject'];
    private const STAFF_MUTABLE_STATUSES = ['Draft', 'Submitted', 'Prepared', 'Rejected'];

    public function __construct(
        private WorkflowService $workflowService,
        private SalaryCalculator $salaryCalculator,
        private SalaryWorkflowNotificationService $workflowNotifications,
    ) {}

    public function records(Request $request): JsonResponse
    {
        $records = DB::table('hr_other_claim_applications')
            ->where('staff_id', $this->staffId($request))
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
            ->where('application.status', '<>', 'Draft')
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
                ->map(fn (object $record): array => $this->recordPayload(
                    $record,
                    includeClaims: false,
                    request: $request,
                    workflowPayload: $workflowPayloads[(int) $record->id] ?? null,
                ))
                ->all(),
        ]);
    }

    public function financialRecordAction(Request $request, int $id): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'action' => ['required', 'string', 'in:'.implode(',', self::FINANCIAL_ACTIONS)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $record = DB::table('hr_other_claim_applications')->where('id', $id)->first();
        if (! $record) {
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
            ->where('application.status', '<>', 'Draft')
            ->first();

        if (! $record) {
            abort(404, 'Other claim record not found.');
        }

        return $this->claimPdfResponse($request, $record);
    }

    public function financialClaimsPdf(Request $request, int $id)
    {
        $record = $this->pdfRecordQuery()
            ->where('application.id', $id)
            ->where('application.status', '<>', 'Draft')
            ->first();

        if (! $record) {
            abort(404, 'Other claim record not found.');
        }

        return $this->claimPdfResponse($request, $record);
    }

    public function destroyRecord(Request $request, int $id): JsonResponse
    {
        $record = DB::table('hr_other_claim_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('id', $id)
            ->first();

        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Other claim record not found.'], 404);
        }
        if (! in_array((string) $record->status, self::STAFF_MUTABLE_STATUSES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only draft, submitted/prepared, or rejected other claims can be deleted by staff.',
            ], 422);
        }

        DB::transaction(function () use ($id): void {
            $this->deleteApplicationClaims($id);
            $workflowInstanceIds = DB::table('workflow_instances')
                ->where('subject_type', 'other_claim_application')
                ->where('subject_id', $id)
                ->pluck('id')
                ->map(fn ($workflowInstanceId): int => (int) $workflowInstanceId)
                ->all();
            if ($workflowInstanceIds !== []) {
                DB::table('workflow_actions')->whereIn('instance_id', $workflowInstanceIds)->delete();
                DB::table('workflow_instances')
                    ->whereIn('id', $workflowInstanceIds)
                    ->delete();
            }
            DB::table('hr_other_claim_applications')->where('id', $id)->delete();
        });

        return response()->json(['status' => 'success', 'message' => 'Other claim deleted.']);
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
            $claims = $this->salaryCalculator->prepareClaims($data['claims'], $mileageRate);
            $claimsTotal = $this->claimsTotal($claims);

            if ($existing) {
                $this->deleteApplicationClaims(
                    (int) $existing->id,
                    $preservedAttachments->pluck('id')->map(fn ($id): int => (int) $id)->all(),
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

        DB::transaction(function () use ($request, $data, $staffId, $settings, $mileageRate, &$savedRecord): void {
            $applicationIdForEdit = (int) ($data['application_id'] ?? 0);
            $existing = $applicationIdForEdit > 0
                ? DB::table('hr_other_claim_applications')
                    ->where('staff_id', $staffId)
                    ->where('id', $applicationIdForEdit)
                    ->lockForUpdate()
                    ->first()
                : null;
            if ($applicationIdForEdit > 0 && ! $existing) {
                throw ValidationException::withMessages([
                    'application_id' => ['Other claim record not found.'],
                ]);
            }
            if ($existing && ! in_array((string) $existing->status, self::STAFF_MUTABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'application_id' => ['This other claim has already moved into financial review and cannot be edited.'],
                ]);
            }

            $files = $request->file('attachments', []);
            $preservedAttachments = $existing
                ? $this->preservedClaimAttachments((int) $existing->id, $data['claims'], $staffId)
                : collect();
            $this->assertClaimRules($data['claims'], $files, $preservedAttachments);
            $claims = $this->salaryCalculator->prepareClaims($data['claims'], $mileageRate);
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

            if ($existing) {
                $this->deleteApplicationClaims(
                    (int) $existing->id,
                    $preservedAttachments->pluck('id')->map(fn ($id): int => (int) $id)->all(),
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

            $applicationId = $existing
                ? (int) $existing->id
                : (int) DB::table('hr_other_claim_applications')->insertGetId([...$payload, 'created_at' => now()]);
            if ($existing) {
                DB::table('hr_other_claim_applications')->where('id', $applicationId)->update($payload);
            }

            $this->storeClaims($claims, $files, $preservedAttachments, $applicationId, $staffId, $data['claim_month']);
            $record = DB::table('hr_other_claim_applications')->where('id', $applicationId)->first();
            $this->workflowService->createOrResetOtherClaimWorkflow($applicationId, $staffId);
            $savedRecord = $this->recordPayload($record, includeClaims: true, request: $request);
        });

        $mailSent = false;
        if ($savedRecord && isset($savedRecord['id'])) {
            $mailSent = $this->workflowNotifications->notifySubmittedOtherClaim($request, (int) $savedRecord['id']);
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
            'claims.*.attachmentId' => ['nullable', 'integer'],
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
            ...$request->only(['application_id', 'claim_month']),
            'claims' => is_array($claims) ? $claims : [],
            'draft_payload' => is_array($draftPayload) ? $draftPayload : [],
        ];

        $rules = [
            'application_id' => ['nullable', 'integer', 'min:1'],
            'claim_month' => ['required', 'date_format:Y-m'],
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
            'claims.*.attachmentId' => ['nullable', 'integer', 'min:1'],
            'draft_payload' => ['nullable', 'array'],
        ];
        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($request, $payload, $jsonErrors): void {
            foreach ($jsonErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }
            $claimIds = collect($payload['claims'] ?? [])->pluck('id')->map(fn ($id): string => (string) $id)->all();
            foreach ($request->file('attachments', []) as $key => $file) {
                if (! in_array((string) $key, $claimIds, true)) {
                    $validator->errors()->add("attachments.{$key}", 'Attachment does not match a claim row.');

                    continue;
                }
                $matchedClaim = collect($payload['claims'])->first(fn ($claim): bool => (string) ($claim['id'] ?? '') === (string) $key);
                if (is_array($matchedClaim) && ($matchedClaim['type'] ?? null) === 'Mileage') {
                    $validator->errors()->add("attachments.{$key}", 'Mileage claims cannot include attachments.');

                    continue;
                }
                if (! $this->isClaimAttachmentFile($file)) {
                    $validator->errors()->add("attachments.{$key}", 'Upload a PDF, JPG, JPEG, or PNG file up to 5 MB.');
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

    private function assertClaimRules(array $claims, array $files, $preservedAttachments): void
    {
        $errors = [];
        foreach ($claims as $index => $claim) {
            $type = (string) ($claim['type'] ?? '');
            $claimId = (string) ($claim['id'] ?? '');
            $hasNewAttachment = $claimId !== '' && isset($files[$claimId]);
            $attachmentId = isset($claim['attachmentId']) && is_numeric($claim['attachmentId'])
                ? (int) $claim['attachmentId']
                : null;
            $hasPreservedAttachment = $attachmentId !== null && $preservedAttachments->has($attachmentId);

            if ($type === 'Mileage') {
                if (empty($claim['date']) || trim((string) ($claim['startLocation'] ?? '')) === '' || trim((string) ($claim['endLocation'] ?? '')) === '' || (float) ($claim['km'] ?? 0) <= 0) {
                    $errors["claims.{$index}.km"][] = 'Mileage claims require date, from, to, and one-way KM.';
                }
                if ($hasNewAttachment || $attachmentId !== null) {
                    $errors["claims.{$index}.attachment"][] = 'Mileage claims cannot include attachments.';
                }
            } elseif ((float) ($claim['amount'] ?? 0) <= 0) {
                $errors["claims.{$index}.amount"][] = "{$type} claims require a valid amount.";
            }

            if (in_array($type, ['Expense', 'Medical'], true) && ! $hasNewAttachment && ! $hasPreservedAttachment) {
                $errors["claims.{$index}.attachment"][] = "{$type} claims require an attachment.";
            }
            if ($attachmentId !== null && ! $hasPreservedAttachment) {
                $errors["claims.{$index}.attachmentId"][] = 'Attachment does not belong to this editable other claim record.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
                'source' => $claim['source'] ?? null,
                'source_label' => $claim['sourceLabel'] ?? null,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $clientClaimId = (string) ($claim['id'] ?? '');
            if ($clientClaimId !== '' && isset($files[$clientClaimId]) && $this->isClaimAttachmentFile($files[$clientClaimId])) {
                $this->storeClaimAttachment($files[$clientClaimId], $claimId, $staffId, $claimMonth);
            } elseif (isset($claim['attachmentId']) && $preservedAttachments->has((int) $claim['attachmentId'])) {
                $this->copyClaimAttachment($preservedAttachments->get((int) $claim['attachmentId']), $claimId);
            }
        }
    }

    private function recordPayload(
        object $record,
        bool $includeClaims,
        ?Request $request = null,
        ?array $workflowPayload = null,
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
        ];
        foreach ([
            'staffName' => 'staff_name',
            'staffCode' => 'staff_code',
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
            $payload['claims'] = $this->claimsForApplication((int) $record->id);
        }
        $payload['workflow'] = (string) $record->status === 'Draft'
            ? null
            : ($workflowPayload ?? $this->workflowService->otherClaimWorkflowPayload((int) $record->id, $request));

        return $payload;
    }

    private function claimsForApplication(int $applicationId): array
    {
        $claims = DB::table('hr_other_claim_items')
            ->where('application_id', $applicationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $attachments = DB::table('hr_other_claim_attachments')
            ->whereIn('claim_id', $claims->pluck('id')->all())
            ->get()
            ->keyBy('claim_id');

        return $claims->map(function (object $claim) use ($attachments): array {
            $attachment = $attachments->get($claim->id);

            return [
                'id' => (int) $claim->id,
                'type' => (string) $claim->type,
                'date' => (string) ($claim->claim_date ?? ''),
                'description' => (string) $claim->description,
                'amount' => (float) $claim->amount,
                'meta' => (string) ($claim->meta ?? ''),
                'km' => $claim->km !== null ? (float) $claim->km : null,
                'startLocation' => (string) ($claim->start_location ?? ''),
                'endLocation' => (string) ($claim->end_location ?? ''),
                'source' => (string) ($claim->source ?? ''),
                'sourceLabel' => (string) ($claim->source_label ?? ''),
                'attachment' => $attachment ? [
                    'id' => (int) $attachment->id,
                    'name' => (string) $attachment->original_name,
                    'size' => (int) $attachment->size,
                    'type' => (string) ($attachment->mime_type ?? ''),
                    'url' => url("hr/salary/other-claim-attachments/{$attachment->id}"),
                    'downloadUrl' => url("hr/salary/other-claim-attachments/{$attachment->id}"),
                ] : null,
            ];
        })->all();
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
            ]);
    }

    private function claimAttachmentPdfSources(object $record, array $claims, Carbon $generatedAt, string $generatorCode, string $generatorId): array
    {
        $claimIds = collect($claims)->pluck('id')->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->all();
        if ($claimIds === []) {
            return [];
        }

        $claimsById = collect($claims)->keyBy('id');
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
            ->pluck('attachmentId')
            ->filter(fn ($id): bool => is_numeric($id))
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

    private function storeClaimAttachment($file, int $claimId, int $staffId, string $claimMonth): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedName = Str::uuid()->toString().'.'.$extension;
        $storedPath = AppFilePaths::storeFileAs("other-claims/{$staffId}/{$claimMonth}", $file, $storedName);

        DB::table('hr_other_claim_attachments')->insert([
            'claim_id' => $claimId,
            'staff_id' => $staffId,
            'stored_path' => $storedPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function copyClaimAttachment(object $attachment, int $claimId): void
    {
        DB::table('hr_other_claim_attachments')->insert([
            'claim_id' => $claimId,
            'staff_id' => (int) $attachment->staff_id,
            'stored_path' => (string) $attachment->stored_path,
            'original_name' => (string) $attachment->original_name,
            'mime_type' => (string) ($attachment->mime_type ?? ''),
            'size' => (int) ($attachment->size ?? 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            ->whereNotIn('application.status', ['Draft', 'Rejected'])
            ->sum('claim.amount');

        $otherClaimUsed = DB::table('hr_other_claim_items as claim')
            ->join('hr_other_claim_applications as application', 'application.id', '=', 'claim.application_id')
            ->where('application.staff_id', $staffId)
            ->where('application.claim_month', 'like', $year.'-%')
            ->where('claim.type', 'Medical')
            ->whereNotIn('application.status', ['Draft', 'Rejected'])
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
        if (is_array($raw)) return $raw;
        if (! is_string($raw) || trim($raw) === '') return $default;

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $errors[$field] = 'The '.$field.' field must contain valid JSON.';
            return $default;
        }
    }

    private function decodeJson(?string $raw): array
    {
        if (! $raw) return [];

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
            'Paid' => 'Approved',
            default => $status,
        };
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
