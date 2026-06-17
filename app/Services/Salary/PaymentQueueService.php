<?php

namespace App\Services\Salary;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PaymentQueueService
{
    private const SALARY_SUBJECT_TYPE = 'salary_application';
    private const OTHER_CLAIM_SUBJECT_TYPE = 'other_claim_application';
    private const APPROVED_STATUS = 'Approved';
    private const PAID_STATUS = 'Paid';
    private const PAYMENT_ROLES = ['HR', 'Manager', 'Finance', 'Account', 'Bank'];

    public function queue(Request $request): JsonResponse
    {
        $rows = $this->queueRows($request);

        return response()->json([
            'status' => 'success',
            'records' => array_values($rows),
        ]);
    }

    public function detail(Request $request, int $staffId, string $period): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payment period.'], 422);
        }

        $rows = $this->queueRows($request, $staffId, $period, includeItems: true);
        $key = $this->rowKey($staffId, $period);
        if (! isset($rows[$key])) {
            return response()->json(['status' => 'error', 'message' => 'Payment queue row not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'record' => $rows[$key],
            'row' => $rows[$key],
            'items' => $rows[$key]['items'] ?? [],
        ]);
    }

    public function markPaid(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'staff_id' => ['required', 'integer', 'min:1'],
            'payment_period' => ['required', 'date_format:Y-m'],
            'payment_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_reference' => ['nullable', 'string', 'max:191'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ])->validate();

        return response()->json($this->markPaidWithData($request, $data));
    }

    public function undoPaid(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'staff_id' => ['required', 'integer', 'min:1'],
            'payment_period' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return response()->json($this->undoPaidWithData($request, $data));
    }

    public function bulkMarkPaid(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.staff_id' => ['required', 'integer', 'min:1'],
            'rows.*.payment_period' => ['required', 'date_format:Y-m'],
            'payment_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_reference' => ['nullable', 'string', 'max:191'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return response()->json($this->bulkOperation(
            $request,
            $data['rows'],
            fn (array $row, int $index): array => $this->markPaidWithData($request, [
                'staff_id' => (int) $row['staff_id'],
                'payment_period' => (string) $row['payment_period'],
                'payment_date' => $data['payment_date'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'idempotency_key' => implode(':', [
                    'bulk-pay',
                    (int) $row['staff_id'],
                    (string) $row['payment_period'],
                    (string) ($data['payment_date'] ?? ''),
                    md5((string) ($data['payment_reference'] ?? '').'|'.(string) ($data['payment_method'] ?? '').'|'.$index),
                ]),
            ]),
            'Bulk mark paid completed.',
        ));
    }

    public function bulkUndoPaid(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.staff_id' => ['required', 'integer', 'min:1'],
            'rows.*.payment_period' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return response()->json($this->bulkOperation(
            $request,
            $data['rows'],
            fn (array $row): array => $this->undoPaidWithData($request, [
                'staff_id' => (int) $row['staff_id'],
                'payment_period' => (string) $row['payment_period'],
                'reason' => (string) $data['reason'],
            ]),
            'Bulk undo paid completed.',
        ));
    }

    private function markPaidWithData(Request $request, array $data): array
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0 || $actorId === (int) $data['staff_id'] || ! $this->hasAnyRole($request, self::PAYMENT_ROLES)) {
            $this->abortOperation('You are not authorized to mark this payment as paid.', 403);
        }

        if (! Schema::hasTable('hr_salary_payment_runs') || ! Schema::hasTable('hr_salary_payment_run_items')) {
            $this->abortOperation('Payment run tables are not available.', 422);
        }

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        $storedIdempotencyKey = null;
        if ($idempotencyKey !== '') {
            $existingRun = DB::table('hr_salary_payment_runs')
                ->where('idempotency_key', $idempotencyKey)
                ->when(
                    Schema::hasColumn('hr_salary_payment_runs', 'voided_at'),
                    fn ($query) => $query->whereNull('voided_at'),
                )
                ->first();
            if ($existingRun) {
                return [
                    'status' => 'success',
                    'message' => 'Payment was already marked paid.',
                    'paymentRunId' => (int) $existingRun->id,
                    'idempotent' => true,
                ];
            }
            $storedIdempotencyKey = DB::table('hr_salary_payment_runs')
                ->where('idempotency_key', $idempotencyKey)
                ->exists()
                    ? null
                    : $idempotencyKey;
        }

        $paymentRunId = null;
        DB::transaction(function () use ($request, $data, $actorId, $storedIdempotencyKey, &$paymentRunId): void {
            $salaryItems = DB::table('hr_salary_applications as application')
                ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
                ->where('application.staff_id', (int) $data['staff_id'])
                ->where('application.salary_month', (string) $data['payment_period'])
                ->where('application.status', self::APPROVED_STATUS)
                ->lockForUpdate()
                ->select([
                    'application.*',
                    'staff.full_name as staff_name',
                    'staff.name_code as staff_code',
                ])
                ->get();

            $otherClaimItems = DB::table('hr_other_claim_applications as application')
                ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
                ->where('application.staff_id', (int) $data['staff_id'])
                ->where('application.claim_month', (string) $data['payment_period'])
                ->where('application.status', self::APPROVED_STATUS)
                ->lockForUpdate()
                ->select([
                    'application.*',
                    'staff.full_name as staff_name',
                    'staff.name_code as staff_code',
                ])
                ->get();

            if ($salaryItems->count() > 1) {
                $this->abortOperation('Multiple approved salary records exist for this employee and period. Resolve duplicates before payment.', 409);
            }
            if ($salaryItems->isEmpty() && $otherClaimItems->isEmpty()) {
                $this->abortOperation('Payment queue row changed. Refresh before marking paid.', 409);
            }

            $allItems = [
                ...$salaryItems->map(fn (object $item): array => ['type' => self::SALARY_SUBJECT_TYPE, 'record' => $item])->all(),
                ...$otherClaimItems->map(fn (object $item): array => ['type' => self::OTHER_CLAIM_SUBJECT_TYPE, 'record' => $item])->all(),
            ];

            foreach ($allItems as $item) {
                if (! $this->hasValidWorkflowCompletion($item['type'], (int) $item['record']->id)) {
                    $this->abortOperation('Payment queue row changed. Refresh before marking paid.', 409);
                }
                if (! $this->canViewSubject($request, $item['type'], $item['record'])) {
                    $this->abortOperation('You are not authorized to mark this payment as paid.', 403);
                }
            }

            $salaryTotal = (float) $this->money($salaryItems->sum(fn (object $item): float => (float) $item->payable_salary));
            $otherClaimTotal = (float) $this->money($otherClaimItems->sum(fn (object $item): float => (float) $item->claims_total));
            $totalPaid = (float) $this->money($salaryTotal + $otherClaimTotal);
            if ($totalPaid <= 0) {
                $this->abortOperation('Payment total must be greater than zero.', 422);
            }

            $paymentDate = $data['payment_date'] ?? Carbon::now()->toDateString();
            $snapshot = [
                'staffId' => (int) $data['staff_id'],
                'paymentPeriod' => (string) $data['payment_period'],
                'salaryTotal' => $salaryTotal,
                'otherClaimTotal' => $otherClaimTotal,
                'totalPaid' => $totalPaid,
                'items' => array_map(fn (array $item): array => $this->itemSnapshot($item['type'], $item['record']), $allItems),
            ];

            $paymentRunId = (int) DB::table('hr_salary_payment_runs')->insertGetId([
                'staff_id' => (int) $data['staff_id'],
                'payment_period' => (string) $data['payment_period'],
                'salary_total' => $salaryTotal,
                'other_claim_total' => $otherClaimTotal,
                'total_paid' => $totalPaid,
                'payment_date' => $paymentDate,
                'payment_reference' => trim((string) ($data['payment_reference'] ?? '')) ?: null,
                'payment_method' => trim((string) ($data['payment_method'] ?? '')) ?: null,
                'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                'actor_staff_id' => $actorId,
                'paid_at' => now(),
                'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'idempotency_key' => $storedIdempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($allItems as $item) {
                $record = $item['record'];
                DB::table('hr_salary_payment_run_items')->insert([
                    'payment_run_id' => $paymentRunId,
                    'subject_type' => $item['type'],
                    'subject_id' => (int) $record->id,
                    'amount_paid' => $item['type'] === self::SALARY_SUBJECT_TYPE
                        ? (float) $record->payable_salary
                        : (float) $record->claims_total,
                    'status_from' => self::APPROVED_STATUS,
                    'status_to' => self::PAID_STATUS,
                    'snapshot_json' => json_encode($this->itemSnapshot($item['type'], $record), JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($salaryItems->isNotEmpty()) {
                DB::table('hr_salary_applications')
                    ->whereIn('id', $salaryItems->pluck('id')->map(fn ($id): int => (int) $id)->all())
                    ->update(['status' => self::PAID_STATUS, 'updated_at' => now()]);
            }
            if ($otherClaimItems->isNotEmpty()) {
                DB::table('hr_other_claim_applications')
                    ->whereIn('id', $otherClaimItems->pluck('id')->map(fn ($id): int => (int) $id)->all())
                    ->update(['status' => self::PAID_STATUS, 'updated_at' => now()]);
            }
        });

        return [
            'status' => 'success',
            'message' => 'Payment marked as paid.',
            'paymentRunId' => $paymentRunId,
        ];
    }

    private function undoPaidWithData(Request $request, array $data): array
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0 || $actorId === (int) $data['staff_id'] || ! $this->hasAnyRole($request, self::PAYMENT_ROLES)) {
            $this->abortOperation('You are not authorized to undo this payment.', 403);
        }

        if (! Schema::hasTable('hr_salary_payment_runs') || ! Schema::hasTable('hr_salary_payment_run_items')) {
            $this->abortOperation('Payment run tables are not available.', 422);
        }
        if (! Schema::hasColumn('hr_salary_payment_runs', 'voided_at') || ! Schema::hasColumn('hr_salary_payment_run_items', 'voided_at')) {
            $this->abortOperation('Payment undo fields are not available.', 422);
        }

        $paymentRunIds = [];
        DB::transaction(function () use ($request, $data, $actorId, &$paymentRunIds): void {
            $runs = DB::table('hr_salary_payment_runs')
                ->where('staff_id', (int) $data['staff_id'])
                ->where('payment_period', (string) $data['payment_period'])
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->get();

            if ($runs->isEmpty()) {
                $this->abortOperation('Active paid row not found.', 404);
            }

            foreach ($runs as $run) {
                if (! $this->canUndoPaymentRun($request, $run)) {
                    $this->abortOperation('You are not authorized to undo this payment.', 403);
                }
            }

            $paymentRunIds = $runs->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $items = DB::table('hr_salary_payment_run_items')
                ->whereIn('payment_run_id', $paymentRunIds)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                $this->abortOperation('Payment run has no active items to undo.', 409);
            }

            $salaryIds = $items
                ->where('subject_type', self::SALARY_SUBJECT_TYPE)
                ->pluck('subject_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $otherClaimIds = $items
                ->where('subject_type', self::OTHER_CLAIM_SUBJECT_TYPE)
                ->pluck('subject_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if (! $this->allSubjectsArePaid(self::SALARY_SUBJECT_TYPE, $salaryIds)) {
                $this->abortOperation('Salary records are no longer paid and cannot be undone.', 409);
            }
            if (! $this->allSubjectsArePaid(self::OTHER_CLAIM_SUBJECT_TYPE, $otherClaimIds)) {
                $this->abortOperation('Other claim records are no longer paid and cannot be undone.', 409);
            }

            $now = now();
            DB::table('hr_salary_payment_runs')
                ->whereIn('id', $paymentRunIds)
                ->whereNull('voided_at')
                ->update([
                    'voided_at' => $now,
                    'voided_by' => $actorId,
                    'void_reason' => trim((string) $data['reason']),
                    'updated_at' => $now,
                ]);

            DB::table('hr_salary_payment_run_items')
                ->whereIn('payment_run_id', $paymentRunIds)
                ->whereNull('voided_at')
                ->update([
                    'voided_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($salaryIds) {
                DB::table('hr_salary_applications')
                    ->whereIn('id', $salaryIds)
                    ->where('status', self::PAID_STATUS)
                    ->update(['status' => self::APPROVED_STATUS, 'updated_at' => $now]);
            }
            if ($otherClaimIds) {
                DB::table('hr_other_claim_applications')
                    ->whereIn('id', $otherClaimIds)
                    ->where('status', self::PAID_STATUS)
                    ->update(['status' => self::APPROVED_STATUS, 'updated_at' => $now]);
            }
        });

        return [
            'status' => 'success',
            'message' => 'Payment was undone.',
            'paymentRunId' => $paymentRunIds[0] ?? null,
            'paymentRunIds' => $paymentRunIds,
        ];
    }

    private function bulkOperation(Request $request, array $rows, callable $operation, string $message): array
    {
        $results = [];
        $success = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $index => $row) {
            $staffId = (int) ($row['staff_id'] ?? 0);
            $period = (string) ($row['payment_period'] ?? '');
            try {
                $payload = $operation($row, $index);
                $success++;
                $results[] = [
                    'staffId' => $staffId,
                    'paymentPeriod' => $period,
                    'status' => 'success',
                    'message' => $payload['message'] ?? 'Completed.',
                    'paymentRunId' => $payload['paymentRunId'] ?? null,
                ];
            } catch (HttpResponseException $exception) {
                $response = $exception->getResponse();
                $body = json_decode((string) $response->getContent(), true) ?: [];
                $skipped++;
                $results[] = [
                    'staffId' => $staffId,
                    'paymentPeriod' => $period,
                    'status' => 'skipped',
                    'message' => $body['message'] ?? 'Skipped.',
                    'code' => $response->getStatusCode(),
                ];
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $results[] = [
                    'staffId' => $staffId,
                    'paymentPeriod' => $period,
                    'status' => 'failed',
                    'message' => 'Unexpected payment queue error.',
                ];
            }
        }

        return [
            'status' => $failed > 0 ? 'partial' : 'success',
            'message' => $message,
            'summary' => [
                'success' => $success,
                'skipped' => $skipped,
                'failed' => $failed,
            ],
            'results' => $results,
        ];
    }

    private function queueRows(Request $request, ?int $onlyStaffId = null, ?string $onlyPeriod = null, bool $includeItems = false): array
    {
        $rows = $this->paidRows($request, $onlyStaffId, $onlyPeriod, $includeItems);

        $salaryRecords = DB::table('hr_salary_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->where('application.status', self::APPROVED_STATUS)
            ->when($onlyStaffId, fn ($query) => $query->where('application.staff_id', $onlyStaffId))
            ->when($onlyPeriod, fn ($query) => $query->where('application.salary_month', $onlyPeriod))
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
            ])
            ->get();

        $otherClaimRecords = DB::table('hr_other_claim_applications as application')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'application.staff_id')
            ->where('application.status', self::APPROVED_STATUS)
            ->when($onlyStaffId, fn ($query) => $query->where('application.staff_id', $onlyStaffId))
            ->when($onlyPeriod, fn ($query) => $query->where('application.claim_month', $onlyPeriod))
            ->select([
                'application.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
            ])
            ->get();

        foreach ($salaryRecords as $record) {
            if (! $this->hasValidWorkflowCompletion(self::SALARY_SUBJECT_TYPE, (int) $record->id)) {
                continue;
            }
            $rowKey = $this->rowKey((int) $record->staff_id, (string) $record->salary_month);
            if (($rows[$rowKey]['status'] ?? '') === self::PAID_STATUS) {
                unset($rows[$rowKey]);
            }
            $this->addItemToRows($rows, $request, self::SALARY_SUBJECT_TYPE, $record, (string) $record->salary_month, includeItems: $includeItems);
        }
        foreach ($otherClaimRecords as $record) {
            if (! $this->hasValidWorkflowCompletion(self::OTHER_CLAIM_SUBJECT_TYPE, (int) $record->id)) {
                continue;
            }
            $rowKey = $this->rowKey((int) $record->staff_id, (string) $record->claim_month);
            if (($rows[$rowKey]['status'] ?? '') === self::PAID_STATUS) {
                unset($rows[$rowKey]);
            }
            $this->addItemToRows($rows, $request, self::OTHER_CLAIM_SUBJECT_TYPE, $record, (string) $record->claim_month, includeItems: $includeItems);
        }

        foreach ($rows as &$row) {
            if (($row['status'] ?? '') === self::PAID_STATUS) {
                continue;
            }
            $row['salaryDue'] = $this->money($row['salaryDue']);
            $row['otherClaimsDue'] = $this->money($row['otherClaimsDue']);
            $row['otherClaimDue'] = $row['otherClaimsDue'];
            $row['totalDue'] = $this->money($row['salaryDue'] + $row['otherClaimsDue']);
            $row['itemCount'] = count($row['itemsRaw'] ?? []);
            $row['canMarkPaid'] = $row['canViewValues'] && $this->canMarkPaid($request, (int) $row['staffId']);
            $row['canUndoPaid'] = false;

            if ($row['totalDue'] === 0.0) {
                $row['excludeFromQueue'] = true;
            }
            if ($row['salaryCount'] > 1) {
                $row['status'] = 'Blocked';
                $row['blockReason'] = 'Multiple approved salary records exist for this employee and period. Resolve duplicates before payment.';
                $row['canMarkPaid'] = false;
            }
            if ($row['totalDue'] < 0) {
                $row['status'] = 'Blocked';
                $row['blockReason'] = 'Payment total is negative and requires finance review.';
                $row['canMarkPaid'] = false;
            }
            if (! $row['canViewValues']) {
                $row = $this->redactRow($row);
            } else {
                unset($row['itemsRaw']);
                if (! $includeItems) {
                    unset($row['items']);
                }
            }
        }
        unset($row);

        return collect($rows)
            ->filter(fn (array $row): bool => empty($row['excludeFromQueue']))
            ->map(function (array $row): array {
                unset($row['excludeFromQueue']);

                return $row;
            })
            ->sortByDesc(fn (array $row): string => (string) ($row['lastApprovedAt'] ?? $row['paidAt'] ?? ''))
            ->all();
    }

    private function paidRows(Request $request, ?int $onlyStaffId = null, ?string $onlyPeriod = null, bool $includeItems = false): array
    {
        if (
            ! Schema::hasTable('hr_salary_payment_runs')
            || ! Schema::hasTable('hr_salary_payment_run_items')
            || ! Schema::hasColumn('hr_salary_payment_runs', 'voided_at')
        ) {
            return [];
        }

        $runs = DB::table('hr_salary_payment_runs as run')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'run.staff_id')
            ->whereNull('run.voided_at')
            ->when($onlyStaffId, fn ($query) => $query->where('run.staff_id', $onlyStaffId))
            ->when($onlyPeriod, fn ($query) => $query->where('run.payment_period', $onlyPeriod))
            ->select([
                'run.*',
                'staff.full_name as staff_name',
                'staff.name_code as staff_code',
            ])
            ->orderByDesc('run.paid_at')
            ->get();

        $rows = [];
        foreach ($runs as $run) {
            $key = $this->rowKey((int) $run->staff_id, (string) $run->payment_period);

            if (! $this->canViewPaymentRun($request, $run)) {
                $rows[$key] ??= $this->redactPaidRow($run);
                continue;
            }

            $items = DB::table('hr_salary_payment_run_items')
                ->where('payment_run_id', (int) $run->id)
                ->when(
                    Schema::hasColumn('hr_salary_payment_run_items', 'voided_at'),
                    fn ($query) => $query->whereNull('voided_at'),
                )
                ->get();

            if (! isset($rows[$key]) || ! ($rows[$key]['canViewValues'] ?? false)) {
                $rows[$key] = [
                    'id' => $key,
                    'staffId' => (int) $run->staff_id,
                    'staffName' => (string) ($run->staff_name ?? ''),
                    'staffCode' => (string) ($run->staff_code ?? ''),
                    'paymentPeriod' => (string) $run->payment_period,
                    'period' => (string) $run->payment_period,
                    'periodLabel' => $this->periodLabel((string) $run->payment_period),
                    'salaryDue' => 0.0,
                    'otherClaimsDue' => 0.0,
                    'otherClaimDue' => 0.0,
                    'totalDue' => 0.0,
                    'itemCount' => 0,
                    'salaryCount' => 0,
                    'otherClaimCount' => 0,
                    'status' => self::PAID_STATUS,
                    'blockReason' => null,
                    'lastApprovedAt' => null,
                    'paidAt' => $run->paid_at,
                    'paidBy' => $run->actor_staff_id,
                    'paymentRunId' => (int) $run->id,
                    'paymentRunIds' => [],
                    'paymentDate' => $run->payment_date,
                    'paymentReference' => $run->payment_reference,
                    'paymentMethod' => $run->payment_method,
                    'remarks' => $run->remarks,
                    'voidedAt' => null,
                    'canViewValues' => true,
                    'canMarkPaid' => false,
                    'canUndoPaid' => false,
                ];
            }

            $rows[$key]['salaryDue'] = $this->money((float) $rows[$key]['salaryDue'] + (float) $run->salary_total);
            $rows[$key]['otherClaimsDue'] = $this->money((float) $rows[$key]['otherClaimsDue'] + (float) $run->other_claim_total);
            $rows[$key]['otherClaimDue'] = $rows[$key]['otherClaimsDue'];
            $rows[$key]['totalDue'] = $this->money((float) $rows[$key]['totalDue'] + (float) $run->total_paid);
            $rows[$key]['itemCount'] += $items->count();
            $rows[$key]['salaryCount'] += $items->where('subject_type', self::SALARY_SUBJECT_TYPE)->count();
            $rows[$key]['otherClaimCount'] += $items->where('subject_type', self::OTHER_CLAIM_SUBJECT_TYPE)->count();
            $rows[$key]['paymentRunIds'][] = (int) $run->id;
            $rows[$key]['canUndoPaid'] = $rows[$key]['canUndoPaid'] || $this->canUndoPaymentRun($request, $run);

            if ($includeItems) {
                $rows[$key]['items'] = [
                    ...($rows[$key]['items'] ?? []),
                    ...$items->map(fn (object $item): array => $this->paidItemPayload($item))->all(),
                ];
            }
        }

        return $rows;
    }

    private function addItemToRows(array &$rows, Request $request, string $subjectType, object $record, string $period, bool $includeItems): void
    {
        $key = $this->rowKey((int) $record->staff_id, $period);
        $canView = $this->canViewSubject($request, $subjectType, $record);
        if (! isset($rows[$key])) {
            $rows[$key] = [
                'id' => $key,
                'staffId' => (int) $record->staff_id,
                'staffName' => (string) ($record->staff_name ?? ''),
                'staffCode' => (string) ($record->staff_code ?? ''),
                'paymentPeriod' => $period,
                'period' => $period,
                'periodLabel' => $this->periodLabel($period),
                'salaryDue' => 0.0,
                'otherClaimsDue' => 0.0,
                'otherClaimDue' => 0.0,
                'totalDue' => 0.0,
                'itemCount' => 0,
                'salaryCount' => 0,
                'otherClaimCount' => 0,
                'status' => 'Pending Payment',
                'blockReason' => null,
                'lastApprovedAt' => null,
                'canViewValues' => true,
                'canMarkPaid' => false,
                'itemsRaw' => [],
                'items' => [],
            ];
        }

        $amount = $subjectType === self::SALARY_SUBJECT_TYPE
            ? (float) $record->payable_salary
            : (float) $record->claims_total;
        if ($subjectType === self::SALARY_SUBJECT_TYPE) {
            $rows[$key]['salaryDue'] += $amount;
            $rows[$key]['salaryCount']++;
        } else {
            $rows[$key]['otherClaimsDue'] += $amount;
            $rows[$key]['otherClaimCount']++;
        }
        $rows[$key]['lastApprovedAt'] = max(
            (string) ($rows[$key]['lastApprovedAt'] ?? ''),
            (string) ($record->approved_at ?? $record->updated_at ?? ''),
        ) ?: null;
        $rows[$key]['canViewValues'] = $rows[$key]['canViewValues'] && $canView;
        $rows[$key]['itemsRaw'][] = ['type' => $subjectType, 'record' => $record];
        if ($includeItems) {
            $rows[$key]['items'][] = $this->itemPayload($subjectType, $record, $canView);
        }
    }

    private function itemPayload(string $subjectType, object $record, bool $canView): array
    {
        $isSalary = $subjectType === self::SALARY_SUBJECT_TYPE;
        if (! $canView) {
            return [
                'subjectType' => $subjectType,
                'subjectId' => null,
                'label' => 'Restricted',
                'amount' => null,
                'status' => 'Restricted',
            ];
        }

        return [
            'subjectType' => $subjectType,
            'subjectId' => (int) $record->id,
            'label' => $isSalary
                ? (string) ($record->salary_month_label ?? $record->salary_month)
                : (string) ($record->claim_month_label ?? $record->claim_month),
            'period' => $isSalary ? (string) $record->salary_month : (string) $record->claim_month,
            'amount' => $isSalary ? (float) $record->payable_salary : (float) $record->claims_total,
            'approvedAt' => $record->approved_at ?? null,
            'status' => (string) $record->status,
        ];
    }

    private function paidItemPayload(object $item): array
    {
        $snapshot = json_decode((string) ($item->snapshot_json ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        return [
            'subjectType' => (string) $item->subject_type,
            'subjectId' => (int) $item->subject_id,
            'label' => (string) ($snapshot['label'] ?? ''),
            'period' => (string) ($snapshot['period'] ?? ''),
            'amount' => (float) $item->amount_paid,
            'approvedAt' => $snapshot['approvedAt'] ?? null,
            'status' => self::PAID_STATUS,
        ];
    }

    private function redactRow(array $row): array
    {
        return [
            ...$row,
            'staffId' => null,
            'staffName' => 'Restricted',
            'staffCode' => '',
            'salaryDue' => null,
            'otherClaimsDue' => null,
            'otherClaimDue' => null,
            'totalDue' => null,
            'itemCount' => null,
            'salaryCount' => null,
            'otherClaimCount' => null,
            'blockReason' => null,
            'canViewValues' => false,
            'canMarkPaid' => false,
            'items' => array_map(
                fn (): array => ['subjectType' => 'restricted', 'subjectId' => null, 'label' => 'Restricted', 'amount' => null, 'status' => 'Restricted'],
                $row['itemsRaw'] ?? [],
            ),
        ];
    }

    private function redactPaidRow(object $run): array
    {
        return [
            'id' => $this->rowKey((int) $run->staff_id, (string) $run->payment_period),
            'staffId' => null,
            'staffName' => 'Restricted',
            'staffCode' => '',
            'paymentPeriod' => (string) $run->payment_period,
            'period' => (string) $run->payment_period,
            'periodLabel' => $this->periodLabel((string) $run->payment_period),
            'salaryDue' => null,
            'otherClaimsDue' => null,
            'otherClaimDue' => null,
            'totalDue' => null,
            'itemCount' => null,
            'salaryCount' => null,
            'otherClaimCount' => null,
            'status' => self::PAID_STATUS,
            'blockReason' => null,
            'paidAt' => $run->paid_at,
            'paidBy' => null,
            'paymentRunId' => null,
            'paymentDate' => null,
            'paymentReference' => null,
            'paymentMethod' => null,
            'remarks' => null,
            'voidedAt' => null,
            'canViewValues' => false,
            'canMarkPaid' => false,
            'canUndoPaid' => false,
            'restricted' => true,
        ];
    }

    private function hasValidWorkflowCompletion(string $subjectType, int $subjectId): bool
    {
        $instance = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first();
        if (! $instance) {
            return false;
        }

        return (string) $instance->status === self::APPROVED_STATUS
            || DB::table('workflow_actions')
                ->where('instance_id', (int) $instance->id)
                ->where('action', 'approve')
                ->exists();
    }

    private function canViewSubject(Request $request, string $subjectType, object $record): bool
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0) {
            return false;
        }
        if ((int) ($record->checked_by ?? 0) === $actorId || (int) ($record->approved_by ?? 0) === $actorId) {
            return true;
        }

        $instance = DB::table('workflow_instances')
            ->where('subject_type', $subjectType)
            ->where('subject_id', (int) $record->id)
            ->first();
        if (! $instance) {
            return false;
        }

        return DB::table('workflow_actions')
            ->where('instance_id', (int) $instance->id)
            ->where('actor_staff_id', $actorId)
            ->whereIn('action', ['check', 'approve', 'reject'])
            ->exists();
    }

    private function canViewPaymentRun(Request $request, object $run): bool
    {
        $actorId = $this->staffId($request);
        if ($actorId <= 0) {
            return false;
        }
        if ($actorId === (int) ($run->actor_staff_id ?? 0)) {
            return true;
        }

        $items = DB::table('hr_salary_payment_run_items')
            ->where('payment_run_id', (int) $run->id)
            ->when(
                Schema::hasColumn('hr_salary_payment_run_items', 'voided_at'),
                fn ($query) => $query->whereNull('voided_at'),
            )
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        foreach ($items as $item) {
            $table = $item->subject_type === self::SALARY_SUBJECT_TYPE
                ? 'hr_salary_applications'
                : 'hr_other_claim_applications';
            $record = DB::table($table)->where('id', (int) $item->subject_id)->first();
            if (! $record || ! $this->canViewSubject($request, (string) $item->subject_type, $record)) {
                return false;
            }
        }

        return true;
    }

    private function canMarkPaid(Request $request, int $staffId): bool
    {
        return $this->staffId($request) > 0
            && $this->staffId($request) !== $staffId
            && $this->hasAnyRole($request, self::PAYMENT_ROLES);
    }

    private function canUndoPaymentRun(Request $request, object $run): bool
    {
        return $this->canMarkPaid($request, (int) $run->staff_id)
            && $this->canViewPaymentRun($request, $run);
    }

    private function allSubjectsArePaid(string $subjectType, array $ids): bool
    {
        if ($ids === []) {
            return true;
        }

        $table = $subjectType === self::SALARY_SUBJECT_TYPE
            ? 'hr_salary_applications'
            : 'hr_other_claim_applications';

        return DB::table($table)
            ->whereIn('id', $ids)
            ->where('status', self::PAID_STATUS)
            ->count() === count($ids);
    }

    private function abortOperation(string $message, int $statusCode): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => $message,
        ], $statusCode));
    }

    private function itemSnapshot(string $subjectType, object $record): array
    {
        $isSalary = $subjectType === self::SALARY_SUBJECT_TYPE;

        return [
            'subjectType' => $subjectType,
            'subjectId' => (int) $record->id,
            'staffId' => (int) $record->staff_id,
            'staffName' => (string) ($record->staff_name ?? ''),
            'staffCode' => (string) ($record->staff_code ?? ''),
            'period' => $isSalary ? (string) $record->salary_month : (string) $record->claim_month,
            'label' => $isSalary
                ? (string) ($record->salary_month_label ?? $record->salary_month)
                : (string) ($record->claim_month_label ?? $record->claim_month),
            'amount' => $isSalary ? (float) $record->payable_salary : (float) $record->claims_total,
            'status' => (string) $record->status,
            'approvedAt' => $record->approved_at ?? null,
        ];
    }

    private function rowKey(int $staffId, string $period): string
    {
        return $staffId.':'.$period;
    }

    private function periodLabel(string $period): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            return $period;
        }

        return Carbon::createFromDate((int) $matches[1], (int) $matches[2], 1)->format('F Y');
    }

    private function money(float $value): float
    {
        return round($value + PHP_FLOAT_EPSILON, 2);
    }

    private function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    private function hasAnyRole(Request $request, array $roles): bool
    {
        $allowed = array_map(static fn ($role): string => strtolower(trim((string) $role)), $roles);
        $sessionRoles = $request->session()->get('roles', []);
        if (is_string($sessionRoles)) {
            $decoded = json_decode($sessionRoles, true);
            $sessionRoles = is_array($decoded) ? $decoded : [$sessionRoles];
        }
        $current = array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            is_array($sessionRoles) ? $sessionRoles : [$sessionRoles],
        );

        return ! empty(array_intersect($allowed, $current));
    }
}
