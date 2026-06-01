<?php

namespace App\Services\Salary;

use App\Http\Requests\Salary\UpdateSalaryProfileRequest;
use App\Services\Pdf\PdfRenderer;
use App\Services\Quotes\Pdf\PdfMergeService;
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

class SalaryService extends PdfRenderer
{
    private const CLAIM_TYPES = ['Allowance', 'Expense', 'Mileage', 'Medical'];

    private const FINANCIAL_ACTIONS = ['check', 'approve', 'reject'];

    private const STAFF_MUTABLE_STATUSES = ['Draft', 'Submitted', 'Prepared', 'Rejected'];

    public function __construct(
        private WorkflowService $workflowService,
        private SalaryCalculator $salaryCalculator,
        private SalaryWorkflowNotificationService $workflowNotifications,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'profile' => $this->profilePayload($this->staffId($request)),
        ]);
    }

    public function updateProfile(UpdateSalaryProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $staffId = $this->staffId($request);

        DB::transaction(function () use ($data, $staffId): void {
            $profile = DB::table('hr_salary_profiles')->where('staff_id', $staffId)->first();
            $profilePayload = [
                'basic_salary' => $this->money($data['basic_salary']),
                'effective_month' => $data['effective_month'],
                'default_mileage_rate' => $this->money($data['default_mileage_rate']),
                'yearly_medical_claim' => $this->money($data['yearly_medical_claim'] ?? 0),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('hr_salary_profiles', 'vehicle')) {
                $profilePayload['vehicle'] = trim((string) ($data['vehicle'] ?? '')) ?: null;
            }
            if (array_key_exists('notes', $data)) {
                $profilePayload['notes'] = $data['notes'];
            }
            if ($profile) {
                DB::table('hr_salary_profiles')->where('id', $profile->id)->update($profilePayload);
            } else {
                DB::table('hr_salary_profiles')->insert([
                    ...$profilePayload,
                    'staff_id' => $staffId,
                    'created_at' => now(),
                ]);
            }
            $profile = DB::table('hr_salary_profiles')->where('staff_id', $staffId)->first();
            DB::table('hr_salary_recurring_allowances')->where('profile_id', $profile->id)->delete();

            foreach (($data['recurring_allowances'] ?? []) as $allowance) {
                DB::table('hr_salary_recurring_allowances')->insert([
                    'profile_id' => $profile->id,
                    'description' => trim((string) $allowance['description']),
                    'amount' => $this->money($allowance['amount']),
                    'start_month' => $allowance['start_month'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Salary settings saved.',
            'profile' => $this->profilePayload($staffId),
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $records = DB::table('hr_salary_applications')
            ->select('hr_salary_applications.*')
            ->selectSub(
                DB::table('hr_salary_claims')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('application_id', 'hr_salary_applications.id')
                    ->where('type', 'Medical'),
                'medical_claims_total',
            )
            ->where('staff_id', $this->staffId($request))
            ->orderByDesc('salary_month')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $record): array => $this->recordPayload($record, includeClaims: false, request: $request))
            ->all();

        return response()->json(['status' => 'success', 'records' => $records]);
    }

    public function financialRecords(Request $request): JsonResponse
    {
        $records = DB::table('hr_salary_applications as application')
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
            ->orderByDesc('application.salary_month')
            ->orderByDesc('application.submitted_at')
            ->orderByDesc('application.id')
            ->get();

        foreach ($records as $record) {
            $this->workflowService->ensureSalaryWorkflowForExistingRecord($record);
        }

        $workflowPayloads = $this->workflowService->salaryWorkflowPayloads(
            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $request,
        );
        $records = $records
            ->map(fn (object $record): array => $this->recordPayload(
                $record,
                includeClaims: false,
                request: $request,
                workflowPayload: $workflowPayloads[(int) $record->id] ?? null,
            ))
            ->all();

        return response()->json(['status' => 'success', 'records' => $records]);
    }

    public function financialRecordAction(Request $request, int $id): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'action' => ['required', 'string', 'in:'.implode(',', self::FINANCIAL_ACTIONS)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $record = DB::table('hr_salary_applications')->where('id', $id)->first();
        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Salary record not found.'], 404);
        }
        if ((string) $record->status === 'Draft') {
            return response()->json(['status' => 'error', 'message' => 'Salary draft is not ready for financial action.'], 422);
        }
        $this->workflowService->ensureSalaryWorkflowForExistingRecord($record);
        $instanceId = $this->workflowService->salaryInstanceId($id);
        if (! $instanceId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Salary workflow instance not found.',
            ], 404);
        }

        $request->merge($data);

        return $this->workflowService->action($request, $instanceId);
    }

    public function record(Request $request, int $id): JsonResponse
    {
        $record = DB::table('hr_salary_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('id', $id)
            ->first();

        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Salary record not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'record' => $this->recordPayload($record, includeClaims: true, request: $request),
        ]);
    }

    public function claimsPdf(Request $request, int $id)
    {
        $record = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'staff.email as staff_email',
            ])
            ->where('application.staff_id', $this->staffId($request))
            ->where('application.id', $id)
            ->where('application.status', '<>', 'Draft')
            ->first();

        if (! $record) {
            abort(404, 'Salary record not found.');
        }

        return $this->salaryClaimsPdfResponse($request, $record);
    }

    public function financialClaimsPdf(Request $request, int $id)
    {
        $record = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'staff.email as staff_email',
            ])
            ->where('application.id', $id)
            ->where('application.status', '<>', 'Draft')
            ->first();

        if (! $record) {
            abort(404, 'Salary record not found.');
        }

        return $this->salaryClaimsPdfResponse($request, $record);
    }

    public function payslipPdf(Request $request, int $id)
    {
        $record = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'staff.email as staff_email',
            ])
            ->where('application.staff_id', $this->staffId($request))
            ->where('application.id', $id)
            ->where('application.status', '<>', 'Draft')
            ->first();

        if (! $record) {
            abort(404, 'Salary record not found.');
        }

        return $this->salaryPayslipPdfResponse($request, $record);
    }

    public function financialPayslipPdf(Request $request, int $id)
    {
        $record = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
                'staff.email as staff_email',
            ])
            ->where('application.id', $id)
            ->where('application.status', '<>', 'Draft')
            ->first();

        if (! $record) {
            abort(404, 'Salary record not found.');
        }

        return $this->salaryPayslipPdfResponse($request, $record);
    }

    private function salaryClaimsPdfResponse(Request $request, object $record)
    {
        $claims = $this->claimsForApplication((int) $record->id);
        $generatedAt = now();
        $generatorCode = $this->generatorCode($request);
        $generatorId = (string) $request->session()->get('user_id', '-');
        $profile = DB::table('hr_salary_profiles')->where('staff_id', (int) $record->staff_id)->first();
        $approver = ! empty($record->approved_by)
            ? DB::table('staff_general')->where('staff_id', (int) $record->approved_by)->first()
            : null;
        $year = substr((string) $record->salary_month, 0, 4) ?: $generatedAt->format('Y');
        $yearlyMedicalClaim = (float) $this->money($profile->yearly_medical_claim ?? 0);
        $medicalClaimTotal = (float) $this->money(collect($claims)
            ->filter(fn (array $claim): bool => ($claim['type'] ?? '') === 'Medical')
            ->sum(fn (array $claim): float => (float) ($claim['amount'] ?? 0)));
        $medicalCurrentLeft = (float) $this->money(max(
            0,
            $yearlyMedicalClaim - $this->usedMedicalClaimsForYear(
                (int) $record->staff_id,
                $year,
                (string) $record->salary_month,
            ),
        ));
        $html = view('pdf.salary-claims-report', [
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
            'vehicle' => Schema::hasColumn('hr_salary_profiles', 'vehicle')
                ? (string) ($profile->vehicle ?? '')
                : '',
            'mileageRate' => (float) ($profile->default_mileage_rate ?? 0.6),
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
        $safeMonth = Str::slug((string) $record->salary_month_label) ?: (string) $record->salary_month;

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"salary-claims-{$safeMonth}.pdf\"",
        ]);
    }

    private function salaryPayslipPdfResponse(Request $request, object $record)
    {
        $this->assertPayslipAvailable($record);

        $claims = $this->claimsForApplication((int) $record->id);
        $generatedAt = now();
        $generatorCode = $this->generatorCode($request);
        $generatorId = (string) $request->session()->get('user_id', '-');
        [$managementSignatureDataUri, $companyStampDataUri] = $this->managementSignatureAndStampDataUris();

        $html = view('pdf.salary-payslip', [
            'record' => $this->recordPayload($record, includeClaims: false, request: $request),
            'claims' => $claims,
            'generatedAt' => $generatedAt,
            'managementSignatureDataUri' => $managementSignatureDataUri,
            'companyStampDataUri' => $companyStampDataUri,
            'logoDataUri' => $this->companyLogoDataUri(),
        ])->render();

        $pdfBytes = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId)->output();
        $safeMonth = Str::slug((string) $record->salary_month_label) ?: (string) $record->salary_month;

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"salary-payslip-{$safeMonth}.pdf\"",
        ]);
    }

    private function assertPayslipAvailable(object $record): void
    {
        if ((string) ($record->status ?? '') !== 'Approved') {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Payslip is available after salary approval.',
            ], 422));
        }

        $salaryMonth = (string) ($record->salary_month ?? '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $salaryMonth)) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Payslip is not available because the salary month is invalid.',
            ], 422));
        }

        try {
            $availableFrom = Carbon::createFromFormat('!Y-m', $salaryMonth)
                ->startOfMonth()
                ->addMonth();
        } catch (\Throwable) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Payslip is not available because the salary month is invalid.',
            ], 422));
        }

        if (now()->startOfDay()->lt($availableFrom->copy()->startOfDay())) {
            abort(response()->json([
                'status' => 'error',
                'message' => 'Payslip is available from '.$availableFrom->format('d-M-Y').' after salary month closes.',
            ], 422));
        }
    }

    private function managementSignatureAndStampDataUris(): array
    {
        return [
            $this->localImageDataUri(AppFilePaths::tcpdfTemplatePath('assets/sign.png'), 'png'),
            $this->localImageDataUri(AppFilePaths::tcpdfTemplatePath('assets/stamp.png'), 'png'),
        ];
    }

    private function localImageDataUri(string $path, string $extension): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $mimeType = match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return 'data:'.$mimeType.';base64,'.base64_encode($bytes);
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

    public function destroyRecord(Request $request, int $id): JsonResponse
    {
        $staffId = $this->staffId($request);
        $record = DB::table('hr_salary_applications')
            ->where('staff_id', $staffId)
            ->where('id', $id)
            ->first();

        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Salary record not found.'], 404);
        }

        if (! in_array((string) $record->status, self::STAFF_MUTABLE_STATUSES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only draft, submitted/prepared, or rejected salary applications can be deleted by staff.',
            ], 422);
        }

        DB::transaction(function () use ($id): void {
            $this->deleteApplicationClaims($id);
            DB::table('workflow_instances')
                ->where('subject_type', 'salary_application')
                ->where('subject_id', $id)
                ->delete();
            DB::table('hr_salary_applications')->where('id', $id)->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Salary application deleted.',
        ]);
    }

    public function draftApplication(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'salary_month' => ['required', 'date_format:Y-m'],
        ])->validate();

        $record = DB::table('hr_salary_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('salary_month', $data['salary_month'])
            ->where('status', 'Draft')
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
        $settings = $this->submissionSettings($staffId, $data);
        $savedRecord = null;

        DB::transaction(function () use ($request, $data, $staffId, $settings, &$savedRecord): void {
            $existing = DB::table('hr_salary_applications')
                ->where('staff_id', $staffId)
                ->where('salary_month', $data['salary_month'])
                ->lockForUpdate()
                ->first();
            if ($existing && (string) $existing->status !== 'Draft') {
                $savedRecord = $this->recordPayload($existing, includeClaims: true, request: $request);

                return;
            }

            $files = $request->file('attachments', []);
            $preservedAttachments = $existing
                ? $this->preservedClaimAttachments((int) $existing->id, $data['claims'], $staffId)
                : collect();
            $data['claims'] = $this->salaryCalculator->prepareClaims(
                $data['claims'],
                $settings['mileageRate'],
            );
            $summary = $this->salaryCalculator->summarize($settings['basicSalary'], $data['claims']);

            if ($existing) {
                $this->deleteApplicationClaims(
                    (int) $existing->id,
                    $preservedAttachments->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            }

            $applicationPayload = [
                'staff_id' => $staffId,
                'salary_month' => $data['salary_month'],
                'salary_month_label' => $this->formatSalaryMonth($data['salary_month']),
                'basic_salary' => $this->money($summary['basicSalary']),
                'claims_total' => $this->money($summary['claimsTotal']),
                'employee_deductions' => $this->money($summary['employeeDeductions']),
                'employer_contributions' => $this->money($summary['employerContributions']),
                'payable_salary' => $this->money($summary['payableSalary']),
                'status' => 'Draft',
                'deductions_json' => json_encode($summary['deductions'], JSON_THROW_ON_ERROR),
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
            if (Schema::hasColumn('hr_salary_applications', 'draft_payload_json')) {
                $applicationPayload['draft_payload_json'] = json_encode(
                    $data['draft_payload'],
                    JSON_THROW_ON_ERROR,
                );
            }
            if (Schema::hasColumn('hr_salary_applications', 'draft_saved_at')) {
                $applicationPayload['draft_saved_at'] = now();
            }

            if ($existing) {
                DB::table('hr_salary_applications')->where('id', $existing->id)->update($applicationPayload);
                $applicationId = (int) $existing->id;
            } else {
                $applicationPayload['created_at'] = now();
                $applicationId = (int) DB::table('hr_salary_applications')->insertGetId($applicationPayload);
            }

            foreach ($data['claims'] as $index => $claim) {
                $claimId = (int) DB::table('hr_salary_claims')->insertGetId([
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
                if (
                    $clientClaimId !== ''
                    && isset($files[$clientClaimId])
                    && $this->isSalaryAttachmentFile($files[$clientClaimId])
                ) {
                    $this->storeClaimAttachment($files[$clientClaimId], $claimId, $staffId, $data['salary_month']);
                } elseif (isset($claim['attachmentId']) && $preservedAttachments->has((int) $claim['attachmentId'])) {
                    $this->copyClaimAttachment($preservedAttachments->get((int) $claim['attachmentId']), $claimId);
                }
            }

            $record = DB::table('hr_salary_applications')->where('id', $applicationId)->first();
            $savedRecord = $this->recordPayload($record, includeClaims: true, request: $request);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Salary draft saved.',
            'record' => $savedRecord,
        ]);
    }

    public function destroyDraftApplication(Request $request): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'salary_month' => ['required', 'date_format:Y-m'],
        ])->validate();

        $record = DB::table('hr_salary_applications')
            ->where('staff_id', $this->staffId($request))
            ->where('salary_month', $data['salary_month'])
            ->where('status', 'Draft')
            ->first();

        if ($record) {
            DB::transaction(function () use ($record): void {
                $this->deleteApplicationClaims((int) $record->id);
                DB::table('hr_salary_applications')->where('id', $record->id)->delete();
            });
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Salary draft cleared.',
        ]);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        $data = $this->validatedApplicationPayload($request);
        $staffId = $this->staffId($request);
        $settings = $this->submissionSettings($staffId, $data);
        if ($settings['basicSalary'] <= 0) {
            throw ValidationException::withMessages([
                'basic_salary' => ['Enter a valid fixed monthly salary before submitting.'],
            ]);
        }
        $savedRecord = null;

        $finalRecordExists = DB::table('hr_salary_applications')
            ->where('staff_id', $staffId)
            ->where('salary_month', $data['salary_month'])
            ->whereIn('status', ['Paid', 'Approved', 'Checked'])
            ->exists();
        if ($finalRecordExists) {
            return response()->json([
                'status' => 'error',
                'message' => 'This salary month has already entered review or final approval and cannot be replaced.',
                'errors' => [
                    'salary_month' => ['This salary month has already entered review or final approval and cannot be replaced.'],
                ],
            ], 422);
        }

        DB::transaction(function () use ($request, $data, $staffId, $settings, &$savedRecord): void {
            $existing = DB::table('hr_salary_applications')
                ->where('staff_id', $staffId)
                ->where('salary_month', $data['salary_month'])
                ->lockForUpdate()
                ->first();
            $preservedAttachments = collect();
            $files = $request->file('attachments', []);

            if ($existing) {
                if (! in_array((string) $existing->status, self::STAFF_MUTABLE_STATUSES, true)) {
                    abort(response()->json([
                        'status' => 'error',
                        'message' => 'Only draft, submitted, or rejected salary applications can be edited by staff.',
                    ], 422));
                }

                $preservedAttachments = $this->preservedClaimAttachments(
                    (int) $existing->id,
                    $data['claims'],
                    $staffId,
                );
            }

            $this->assertClaimRules($data['claims'], $files, $preservedAttachments);
            $data['basic_salary'] = $settings['basicSalary'];
            $data['claims'] = $this->salaryCalculator->prepareClaims($data['claims'], $settings['mileageRate']);
            $this->assertMedicalClaimLimit(
                $staffId,
                $data['salary_month'],
                $data['claims'],
                $settings['yearlyMedicalClaim'],
            );
            $summary = $this->salaryCalculator->summarize($settings['basicSalary'], $data['claims']);

            if ($existing) {
                $this->deleteApplicationClaims(
                    (int) $existing->id,
                    $preservedAttachments->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                );
            }

            $applicationPayload = [
                'staff_id' => $staffId,
                'salary_month' => $data['salary_month'],
                'salary_month_label' => $this->formatSalaryMonth($data['salary_month']),
                'basic_salary' => $this->money($summary['basicSalary']),
                'claims_total' => $this->money($summary['claimsTotal']),
                'employee_deductions' => $this->money($summary['employeeDeductions']),
                'employer_contributions' => $this->money($summary['employerContributions']),
                'payable_salary' => $this->money($summary['payableSalary']),
                'status' => 'Submitted',
                'deductions_json' => json_encode($summary['deductions'], JSON_THROW_ON_ERROR),
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
            if (Schema::hasColumn('hr_salary_applications', 'draft_payload_json')) {
                $applicationPayload['draft_payload_json'] = null;
            }
            if (Schema::hasColumn('hr_salary_applications', 'draft_saved_at')) {
                $applicationPayload['draft_saved_at'] = null;
            }

            if ($existing) {
                DB::table('hr_salary_applications')->where('id', $existing->id)->update($applicationPayload);
                $applicationId = (int) $existing->id;
            } else {
                $applicationPayload['created_at'] = now();
                $applicationId = (int) DB::table('hr_salary_applications')->insertGetId($applicationPayload);
            }

            foreach ($data['claims'] as $index => $claim) {
                $claimId = (int) DB::table('hr_salary_claims')->insertGetId([
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
                if ($clientClaimId !== '' && isset($files[$clientClaimId])) {
                    $this->storeClaimAttachment($files[$clientClaimId], $claimId, $staffId, $data['salary_month']);
                } elseif (isset($claim['attachmentId']) && $preservedAttachments->has((int) $claim['attachmentId'])) {
                    $this->copyClaimAttachment($preservedAttachments->get((int) $claim['attachmentId']), $claimId);
                }
            }

            $record = DB::table('hr_salary_applications')->where('id', $applicationId)->first();
            $this->workflowService->createOrResetSalaryWorkflow($applicationId, $staffId);
            $savedRecord = $this->recordPayload($record, includeClaims: true, request: $request);
        });

        $mailSent = false;
        if ($savedRecord && isset($savedRecord['id'])) {
            $mailSent = $this->workflowNotifications->notifySubmittedSalary($request, (int) $savedRecord['id']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Salary application was submitted for review.',
            'record' => $savedRecord,
            'mail_sent' => null,
            'mail_status' => $mailSent ? 'digest' : 'notification_missing',
            'mail_message' => $mailSent
                ? 'Salary application submitted for review. Reviewers were notified in-app and will receive the daily pending-work digest when applicable.'
                : 'Salary application submitted for review. No workflow recipient notification was created; check workflow recipient settings.',
        ]);
    }

    public function attachment(Request $request, int $id)
    {
        $attachment = DB::table('hr_salary_claim_attachments as attachment')
            ->join('hr_salary_claims as claim', 'claim.id', '=', 'attachment.claim_id')
            ->join('hr_salary_applications as application', 'application.id', '=', 'claim.application_id')
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

    private function claimAttachmentPdfSources(
        object $record,
        array $claims,
        Carbon $generatedAt,
        string $generatorCode,
        string $generatorId,
    ): array {
        $claimIds = collect($claims)
            ->pluck('id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($claimIds === []) {
            return [];
        }

        $claimsById = collect($claims)->keyBy('id');
        $attachments = DB::table('hr_salary_claim_attachments as attachment')
            ->whereIn('claim_id', $claimIds)
            ->orderBy('id')
            ->get();

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
                $imagePdf = $this->renderClaimAttachmentImagePdf(
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
                $imagePdf = null;
            }
            if ($imagePdf !== null) {
                $sources[] = $imagePdf;
            }
        }

        return $sources;
    }

    private function renderClaimAttachmentImagePdf(
        object $record,
        array $claim,
        object $attachment,
        string $path,
        string $mimeType,
        Carbon $generatedAt,
        string $generatorCode,
        string $generatorId,
        int $attachmentIndex,
        int $attachmentCount,
    ): ?string {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $imageMime = str_contains($mimeType, 'png') ? 'image/png' : 'image/jpeg';
        $html = view('pdf.salary-claim-attachment-image', [
            'documentTitle' => 'Salary Claim Attachment',
            'periodLabel' => 'Salary Period',
            'periodValue' => (string) $record->salary_month_label,
            'record' => [
                'salaryMonth' => (string) $record->salary_month_label,
                'staffName' => (string) ($record->staff_name ?? ''),
                'staffCode' => (string) ($record->staff_code ?? ''),
            ],
            'claim' => $claim,
            'attachment' => [
                'name' => (string) $attachment->original_name,
            ],
            'attachmentIndex' => $attachmentIndex,
            'attachmentCount' => $attachmentCount,
            'imageDataUri' => 'data:'.$imageMime.';base64,'.base64_encode($bytes),
            'logoDataUri' => $this->companyLogoDataUri(),
        ])->render();

        return $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId)->output();
    }

    private function validatedApplicationPayload(Request $request): array
    {
        $jsonErrors = [];
        $claims = $this->decodeJsonField($request, 'claims', [], $jsonErrors);
        $deductions = $this->decodeJsonField($request, 'deductions', [], $jsonErrors);

        $payload = [
            ...$request->only([
                'salary_month',
                'basic_salary',
                'claims_total',
                'employee_deductions',
                'employer_contributions',
                'payable_salary',
            ]),
            'claims' => $claims,
            'deductions' => $deductions,
        ];

        $validator = Validator::make($payload, [
            'salary_month' => ['required', 'date_format:Y-m'],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'claims_total' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'employee_deductions' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'employer_contributions' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payable_salary' => ['nullable', 'numeric', 'min:-9999999.99', 'max:9999999.99'],
            'claims' => ['array'],
            'claims.*.id' => ['required', 'string', 'max:191'],
            'claims.*.type' => ['required', 'string', 'in:'.implode(',', self::CLAIM_TYPES)],
            'claims.*.date' => ['nullable', 'date_format:Y-m-d'],
            'claims.*.description' => ['required', 'string', 'max:255'],
            'claims.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'claims.*.meta' => ['nullable', 'string', 'max:255'],
            'claims.*.km' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'claims.*.startLocation' => ['nullable', 'string', 'max:255'],
            'claims.*.endLocation' => ['nullable', 'string', 'max:255'],
            'claims.*.source' => ['nullable', 'string', 'max:64'],
            'claims.*.sourceLabel' => ['nullable', 'string', 'max:255'],
            'claims.*.attachmentId' => ['nullable', 'integer'],
            'deductions' => ['nullable', 'array'],
        ]);

        $validator->after(function ($validator) use ($request, $claims, $jsonErrors): void {
            foreach ($jsonErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            foreach ($request->file('attachments', []) as $key => $file) {
                $claimExists = collect($claims)->contains(fn ($claim) => (string) ($claim['id'] ?? '') === (string) $key);
                if (! $claimExists) {
                    $validator->errors()->add("attachments.{$key}", 'Attachment does not match a claim row.');

                    continue;
                }
                $matchedClaim = collect($claims)->first(
                    fn ($claim) => (string) ($claim['id'] ?? '') === (string) $key,
                );
                $claimType = is_array($matchedClaim) ? ($matchedClaim['type'] ?? null) : null;
                if ($claimType === 'Mileage') {
                    $validator->errors()->add("attachments.{$key}", 'Mileage claims cannot include attachments.');

                    continue;
                }
                $fileValidator = Validator::make(
                    ['attachment' => $file],
                    ['attachment' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']],
                );
                if ($fileValidator->fails()) {
                    $validator->errors()->add("attachments.{$key}", 'Upload a PDF, JPG, JPEG, or PNG file up to 5 MB.');
                }
            }
        });

        return $validator->validate();
    }

    private function validatedDraftPayload(Request $request): array
    {
        $jsonErrors = [];
        $claims = $this->decodeJsonField($request, 'claims', [], $jsonErrors);
        $draftPayload = $this->decodeJsonField($request, 'draft_payload', [], $jsonErrors);
        $claims = $this->completeDraftClaims(is_array($claims) ? $claims : []);

        $payload = [
            ...$request->only(['salary_month', 'basic_salary']),
            'claims' => $claims,
            'draft_payload' => $draftPayload,
        ];

        $validator = Validator::make($payload, [
            'salary_month' => ['required', 'date_format:Y-m'],
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
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

        $validator->after(function ($validator) use ($request, $claims, $jsonErrors): void {
            foreach ($jsonErrors as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            foreach ($request->file('attachments', []) as $key => $file) {
                $claimExists = collect($claims)->contains(fn ($claim) => (string) ($claim['id'] ?? '') === (string) $key);
                if (! $claimExists) {
                    continue;
                }
                $matchedClaim = collect($claims)->first(
                    fn ($claim) => (string) ($claim['id'] ?? '') === (string) $key,
                );
                if (is_array($matchedClaim) && ($matchedClaim['type'] ?? null) === 'Mileage') {
                    continue;
                }
            }
        });

        $validated = $validator->validate();

        return $validated;
    }

    private function completeDraftClaims(array $claims): array
    {
        return collect($claims)
            ->filter(function (array $claim): bool {
                $type = (string) ($claim['type'] ?? '');
                $date = (string) ($claim['date'] ?? '');
                $amount = (float) ($claim['amount'] ?? 0);
                $km = (float) ($claim['km'] ?? 0);
                if (! in_array($type, self::CLAIM_TYPES, true)) {
                    return false;
                }
                if (trim((string) ($claim['id'] ?? '')) === '') {
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
                $errors["claims.{$index}.attachmentId"][] = 'Attachment does not belong to this editable salary record.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function submissionSettings(int $staffId, array $data): array
    {
        $profile = DB::table('hr_salary_profiles')->where('staff_id', $staffId)->first();

        return [
            'basicSalary' => (float) ($profile->basic_salary ?? $data['basic_salary'] ?? 0),
            'mileageRate' => (float) ($profile->default_mileage_rate ?? 0.6),
            'yearlyMedicalClaim' => (float) ($profile->yearly_medical_claim ?? 0),
            'vehicle' => Schema::hasColumn('hr_salary_profiles', 'vehicle')
                ? (string) ($profile->vehicle ?? '')
                : '',
        ];
    }

    private function assertMedicalClaimLimit(
        int $staffId,
        string $salaryMonth,
        array $claims,
        float $yearlyMedicalClaim,
    ): void {
        $medicalTotal = (float) $this->money(collect($claims)
            ->filter(fn (array $claim): bool => ($claim['type'] ?? '') === 'Medical')
            ->sum(fn (array $claim): float => (float) ($claim['amount'] ?? 0)));
        if ($medicalTotal <= 0) {
            return;
        }

        $available = (float) $this->money($yearlyMedicalClaim - $this->usedMedicalClaimsForYear(
            $staffId,
            substr($salaryMonth, 0, 4),
            $salaryMonth,
        ));

        if ($medicalTotal > $available) {
            throw ValidationException::withMessages([
                'claims' => [
                    'Medical claims exceed the annual medical claim balance of '.$this->money(max(0, $available)).'.',
                ],
            ]);
        }
    }

    private function usedMedicalClaimsForYear(int $staffId, string $year, ?string $excludeSalaryMonth = null): float
    {
        $salaryUsed = DB::table('hr_salary_claims as claim')
            ->join('hr_salary_applications as application', 'application.id', '=', 'claim.application_id')
            ->where('application.staff_id', $staffId)
            ->where('application.salary_month', 'like', $year.'-%')
            ->where('claim.type', 'Medical')
            ->whereNotIn('application.status', ['Draft', 'Rejected'])
            ->when(
                $excludeSalaryMonth,
                fn ($query) => $query->where('application.salary_month', '<>', $excludeSalaryMonth),
            )
            ->sum('claim.amount');

        $otherClaimUsed = DB::table('hr_other_claim_items as claim')
            ->join('hr_other_claim_applications as application', 'application.id', '=', 'claim.application_id')
            ->where('application.staff_id', $staffId)
            ->where('application.claim_month', 'like', $year.'-%')
            ->where('claim.type', 'Medical')
            ->whereNotIn('application.status', ['Draft', 'Rejected'])
            ->sum('claim.amount');

        return (float) $this->money((float) $salaryUsed + (float) $otherClaimUsed);
    }

    private function medicalClaimsTotalForApplication(int $applicationId): float
    {
        return (float) $this->money(DB::table('hr_salary_claims')
            ->where('application_id', $applicationId)
            ->where('type', 'Medical')
            ->sum('amount'));
    }

    private function profilePayload(int $staffId): array
    {
        $profile = DB::table('hr_salary_profiles')->where('staff_id', $staffId)->first();
        if (! $profile) {
            return [
                'basicSalary' => '3000',
                'effectiveMonth' => now()->format('Y-m'),
                'vehicle' => '',
                'defaultMileageRate' => '0.60',
                'yearlyMedicalClaim' => '0.00',
                'notes' => '',
                'recurringAllowances' => [],
            ];
        }

        $allowances = DB::table('hr_salary_recurring_allowances')
            ->where('profile_id', $profile->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $allowance): array => [
                'id' => (string) $allowance->id,
                'description' => (string) $allowance->description,
                'amount' => $this->decimalString($allowance->amount),
                'startMonth' => (string) ($allowance->start_month ?? ''),
                'endMonth' => '',
                'active' => true,
            ])
            ->all();

        return [
            'basicSalary' => $this->decimalString($profile->basic_salary),
            'effectiveMonth' => (string) $profile->effective_month,
            'vehicle' => Schema::hasColumn('hr_salary_profiles', 'vehicle')
                ? (string) ($profile->vehicle ?? '')
                : '',
            'defaultMileageRate' => $this->decimalString($profile->default_mileage_rate),
            'yearlyMedicalClaim' => $this->decimalString($profile->yearly_medical_claim ?? 0),
            'notes' => (string) ($profile->notes ?? ''),
            'recurringAllowances' => $allowances,
        ];
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
            'salaryMonth' => (string) $record->salary_month_label,
            'salaryMonthValue' => (string) $record->salary_month,
            'basicSalary' => (float) $record->basic_salary,
            'claimsTotal' => (float) $record->claims_total,
            'medicalClaimsTotal' => property_exists($record, 'medical_claims_total')
                ? (float) $record->medical_claims_total
                : $this->medicalClaimsTotalForApplication((int) $record->id),
            'employeeDeductions' => (float) $record->employee_deductions,
            'employerContributions' => (float) $record->employer_contributions,
            'payableSalary' => (float) $record->payable_salary,
            'status' => $this->displayStatus((string) $record->status),
            'deductions' => $this->decodeJson($record->deductions_json),
            'draftPayload' => property_exists($record, 'draft_payload_json')
                ? $this->decodeJson($record->draft_payload_json)
                : [],
            'draftSavedAt' => property_exists($record, 'draft_saved_at')
                ? $record->draft_saved_at
                : null,
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

        if (property_exists($record, 'staff_name') || property_exists($record, 'staff_code')) {
            $payload['staffName'] = (string) ($record->staff_name ?? '');
            $payload['staffCode'] = (string) ($record->staff_code ?? '');
        }

        if (property_exists($record, 'checker_name') || property_exists($record, 'checker_code')) {
            $payload['checkerName'] = (string) ($record->checker_name ?? '');
            $payload['checkerCode'] = (string) ($record->checker_code ?? '');
        }

        if (property_exists($record, 'approver_name') || property_exists($record, 'approver_code')) {
            $payload['approverName'] = (string) ($record->approver_name ?? '');
            $payload['approverCode'] = (string) ($record->approver_code ?? '');
        }

        if ($includeClaims) {
            $payload['claims'] = $this->claimsForApplication((int) $record->id);
        }

        $payload['workflow'] = (string) $record->status === 'Draft'
            ? null
            : ($workflowPayload ?? $this->workflowService->salaryWorkflowPayload((int) $record->id, $request));

        return $payload;
    }

    private function financialRecordById(int $id): array
    {
        $record = DB::table('hr_salary_applications as application')
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
            ->where('application.id', $id)
            ->first();

        return $this->recordPayload($record, includeClaims: false);
    }

    private function displayStatus(string $status): string
    {
        return match ($status) {
            'Prepared' => 'Submitted',
            'Paid' => 'Approved',
            default => $status,
        };
    }

    private function claimsForApplication(int $applicationId): array
    {
        $claims = DB::table('hr_salary_claims')
            ->where('application_id', $applicationId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $attachments = DB::table('hr_salary_claim_attachments')
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
                    'url' => url("hr/salary/attachments/{$attachment->id}"),
                    'downloadUrl' => url("hr/salary/attachments/{$attachment->id}"),
                ] : null,
            ];
        })->all();
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

        return DB::table('hr_salary_claim_attachments as attachment')
            ->join('hr_salary_claims as claim', 'claim.id', '=', 'attachment.claim_id')
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
        $attachments = DB::table('hr_salary_claim_attachments as attachment')
            ->join('hr_salary_claims as claim', 'claim.id', '=', 'attachment.claim_id')
            ->where('claim.application_id', $applicationId)
            ->select('attachment.id', 'attachment.stored_path')
            ->get();

        foreach ($attachments as $attachment) {
            if (in_array((int) $attachment->id, $preserveAttachmentIds, true)) {
                continue;
            }

            AppFilePaths::deleteStoredPath((string) $attachment->stored_path);
        }

        DB::table('hr_salary_claims')->where('application_id', $applicationId)->delete();
    }

    private function isSalaryAttachmentFile($file): bool
    {
        return ! Validator::make(
            ['attachment' => $file],
            ['attachment' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']],
        )->fails();
    }

    private function storeClaimAttachment($file, int $claimId, int $staffId, string $salaryMonth): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedName = Str::uuid()->toString().'.'.$extension;
        $storedPath = AppFilePaths::storeFileAs("salary/{$staffId}/{$salaryMonth}", $file, $storedName);

        DB::table('hr_salary_claim_attachments')->insert([
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
        DB::table('hr_salary_claim_attachments')->insert([
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

    private function decodeJsonField(
        Request $request,
        string $field,
        mixed $default,
        array &$errors = [],
    ): mixed {
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

    private function formatSalaryMonth(string $salaryMonth): string
    {
        return Carbon::createFromFormat('Y-m', $salaryMonth)->format('F Y');
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

    private function decimalString(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
