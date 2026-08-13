<?php

namespace App\Services\Vendors;

use App\Http\Requests\Vendor\CancelVendorPaymentRequest;
use App\Http\Requests\Vendor\RecordVendorPaymentTransactionRequest;
use App\Http\Requests\Vendor\ResubmitVendorPaymentRequest;
use App\Http\Requests\Vendor\ReverseVendorPaymentTransactionRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\Vendor\UpdateVendorPaymentRequest;
use App\Services\AppNotificationService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VendorPaymentLifecycleService extends VendorBaseService
{
    public function store(StoreVendorPaymentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $existing = $this->paymentForIdempotencyKey((string) $data['idempotency_key']);
        if ($existing) {
            return response()->json(['status' => 'success', 'id' => (int) $existing->id, 'idempotent' => true]);
        }

        return $this->createPayment($request, $data);
    }

    public function update(UpdateVendorPaymentRequest $request, int $paymentId): JsonResponse
    {
        $data = $request->validated();
        $payment = $this->payment($paymentId);
        if (! $payment) {
            return $this->notFound();
        }
        if (! app(VendorPaymentAuthorizationService::class)->can($request, $payment, 'can_edit')) {
            return $this->forbidden('Only the creator can edit an untouched payment request.');
        }

        $newAttachment = null;
        try {
            if ($request->hasFile('receipt')) {
                $newAttachment = $this->storeAttachment($request->file('receipt'), 'payments', 'receipt');
            }

            $oldPath = (string) ($payment->receipt_path ?? '');
            DB::transaction(function () use ($request, $data, $paymentId, $newAttachment): void {
                $current = $this->payment($paymentId, true);
                if (! $current || ! app(VendorPaymentAuthorizationService::class)->can($request, $current, 'can_edit')) {
                    throw new VendorPaymentConflict('Payment changed before it could be edited.');
                }
                $this->assertVersion($current, (int) $data['version']);
                $snapshots = $this->submissionSnapshots($data);
                $this->assertAwardCapacity($data, $paymentId, $snapshots);
                $updates = array_merge([
                    'vendor_id' => $data['vendor_id'],
                    'project_id' => $data['project_id'] ?? null,
                    'payment_context' => $data['payment_context'],
                    'payment_type' => $data['payment_type'],
                    'amount' => $data['amount'],
                    'method' => $data['method'],
                    'remarks' => $data['remarks'] ?? '',
                ], $snapshots, $newAttachment ?: [], $this->mutationColumns($request, $current));

                DB::table('vendor_payments')->where('id', $paymentId)->update($this->existingPaymentColumns($updates));
                $this->event($request, $paymentId, 'edited', $current->status ?? null, $current->status ?? null, 'Creator updated request details.');
            });

            if ($newAttachment && $oldPath !== '' && $oldPath !== ($newAttachment['receipt_path'] ?? '')) {
                AppFilePaths::deleteStoredPath($oldPath);
            }

            $this->auditLog->log($request, "Updated vendor payment request #{$paymentId}");

            return response()->json(['status' => 'success', 'message' => 'Payment request updated.', 'id' => $paymentId]);
        } catch (VendorPaymentConflict $e) {
            $this->cleanupAttachment($newAttachment, 'receipt_path');

            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupAttachment($newAttachment, 'receipt_path');
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Unable to update payment request.'], 500);
        }
    }

    public function cancel(CancelVendorPaymentRequest $request, int $paymentId): JsonResponse
    {
        $data = $request->validated();
        try {
            DB::transaction(function () use ($request, $data, $paymentId): void {
                $payment = $this->payment($paymentId, true);
                if (! $payment) {
                    throw new VendorPaymentNotFound;
                }
                if (! app(VendorPaymentAuthorizationService::class)->can($request, $payment, 'can_cancel')) {
                    throw new VendorPaymentForbidden('You cannot cancel this payment request in its current state.');
                }
                $this->assertVersion($payment, (int) $data['version']);
                $updates = array_merge(['status' => 'Cancelled'], $this->existingPaymentColumns([
                    'cancelled_at' => now(),
                    'cancelled_by' => (int) $request->session()->get('staff_id'),
                    'cancellation_reason' => trim((string) $data['reason']),
                ]), $this->mutationColumns($request, $payment));
                DB::table('vendor_payments')->where('id', $paymentId)->update($updates);
                $this->event($request, $paymentId, 'cancelled', $payment->status ?? null, 'Cancelled', (string) $data['reason']);
            });
        } catch (VendorPaymentNotFound) {
            return $this->notFound();
        } catch (VendorPaymentForbidden $e) {
            return $this->forbidden($e->getMessage());
        } catch (VendorPaymentConflict $e) {
            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Unable to cancel payment request.'], 500);
        }

        $this->resolveNotifications($paymentId);
        $this->auditLog->log($request, "Cancelled vendor payment request #{$paymentId}");

        return response()->json(['status' => 'success', 'message' => 'Payment request cancelled.']);
    }

    public function resubmit(ResubmitVendorPaymentRequest $request, int $paymentId): JsonResponse
    {
        $payment = $this->payment($paymentId);
        if (! $payment) {
            return $this->notFound();
        }
        if (! app(VendorPaymentAuthorizationService::class)->can($request, $payment, 'can_resubmit')) {
            return $this->forbidden('Only the creator can amend and resubmit this returned request.');
        }

        $data = $request->validated();
        try {
            $this->assertVersion($payment, (int) $data['version']);
        } catch (VendorPaymentConflict $e) {
            return $this->conflict($e->getMessage());
        }

        return $this->createPayment($request, $data, $payment);
    }

    public function recordTransaction(RecordVendorPaymentTransactionRequest $request, int $paymentId): JsonResponse
    {
        if (! Schema::hasTable('vendor_payment_transactions')) {
            return response()->json(['status' => 'error', 'message' => 'Payment transactions are not available until the database migration is complete.'], 503);
        }
        $data = $request->validated();
        $existing = DB::table('vendor_payment_transactions')->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json(['status' => 'success', 'id' => (int) $existing->id, 'idempotent' => true]);
        }

        $proof = null;
        try {
            if ($request->hasFile('proof')) {
                $proof = $this->storeAttachment($request->file('proof'), 'payment-proofs', 'proof');
            }
            $transactionId = DB::transaction(function () use ($request, $data, $paymentId, $proof): int {
                $payment = $this->payment($paymentId, true);
                if (! $payment) {
                    throw new VendorPaymentNotFound;
                }
                if (! app(VendorPaymentAuthorizationService::class)->can($request, $payment, 'can_record_payment')) {
                    throw new VendorPaymentForbidden('You are not authorized to record payment for this request.');
                }
                $this->assertVersion($payment, (int) $data['version']);
                $paidBefore = $this->netPaidAmount($paymentId);
                $paidAfter = round($paidBefore + (float) $data['amount'], 2);
                $requested = round((float) $payment->amount, 2);
                if ($paidAfter > $requested) {
                    throw new VendorPaymentConflict('Payment amount exceeds the remaining approved balance.');
                }

                $transactionId = DB::table('vendor_payment_transactions')->insertGetId(array_merge([
                    'vendor_payment_id' => $paymentId,
                    'amount' => $data['amount'],
                    'paid_date' => $data['paid_date'],
                    'method' => $data['method'],
                    'reference_number' => trim((string) $data['reference_number']),
                    'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                    'bank_name_snapshot' => $payment->bank_name_snapshot ?? null,
                    'bank_account_snapshot' => $payment->bank_account_snapshot ?? null,
                    'created_by' => (int) $request->session()->get('staff_id'),
                    'created_by_name_code' => (string) $request->session()->get('name_code', ''),
                    'idempotency_key' => $data['idempotency_key'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $proof ?: []));

                $status = $paidAfter >= $requested ? 'Paid' : 'Partially Paid';
                DB::table('vendor_payments')->where('id', $paymentId)->update(array_merge([
                    'status' => $status,
                ], $this->existingPaymentColumns([
                    'paid_date' => $data['paid_date'],
                    'paid_amount' => $paidAfter,
                    'paid_by' => (int) $request->session()->get('staff_id'),
                    'paid_at' => now(),
                    'paid_remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                ]), $this->mutationColumns($request, $payment)));
                $this->event($request, $paymentId, 'payment_recorded', $payment->status ?? null, $status, (string) ($data['remarks'] ?? ''), [
                    'transaction_id' => $transactionId,
                    'amount' => (float) $data['amount'],
                    'reference_number' => $data['reference_number'],
                ]);

                return $transactionId;
            });
        } catch (VendorPaymentNotFound) {
            $this->cleanupAttachment($proof, 'proof_path');

            return $this->notFound();
        } catch (VendorPaymentForbidden $e) {
            $this->cleanupAttachment($proof, 'proof_path');

            return $this->forbidden($e->getMessage());
        } catch (VendorPaymentConflict $e) {
            $this->cleanupAttachment($proof, 'proof_path');

            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupAttachment($proof, 'proof_path');
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Unable to record payment.'], 500);
        }

        $updatedPayment = $this->payment($paymentId);
        $this->resolveNotifications($paymentId);
        if (strtolower((string) ($updatedPayment->status ?? '')) === 'partially paid') {
            app(VendorPaymentWorkflowService::class)->notifyPaymentStage(
                $request,
                $updatedPayment,
                $paymentId,
                VendorPaymentWorkflowService::STAGE_FINANCE,
                1,
                [
                    'type' => 'vendor_payment_balance_remaining',
                    'title' => 'Vendor payment balance remaining',
                    'message' => "Payment request #{$paymentId} still has a balance awaiting finance action.",
                    'severity' => 'warning',
                ],
            );
        }
        $this->auditLog->log($request, "Recorded vendor payment transaction #{$transactionId} for request #{$paymentId}");

        return response()->json(['status' => 'success', 'message' => 'Payment transaction recorded.', 'id' => $transactionId]);
    }

    public function reverseTransaction(
        ReverseVendorPaymentTransactionRequest $request,
        int $paymentId,
        int $transactionId,
    ): JsonResponse {
        if (! Schema::hasTable('vendor_payment_transactions')) {
            return response()->json(['status' => 'error', 'message' => 'Payment transactions are not available.'], 503);
        }
        $data = $request->validated();
        try {
            DB::transaction(function () use ($request, $data, $paymentId, $transactionId): void {
                $payment = $this->payment($paymentId, true);
                if (! $payment) {
                    throw new VendorPaymentNotFound;
                }
                if (! app(VendorPaymentAuthorizationService::class)->can($request, $payment, 'can_reverse_payment')) {
                    throw new VendorPaymentForbidden('You are not authorized to reverse this payment.');
                }
                $this->assertVersion($payment, (int) $data['version']);
                $transaction = DB::table('vendor_payment_transactions')
                    ->where('id', $transactionId)
                    ->where('vendor_payment_id', $paymentId)
                    ->lockForUpdate()
                    ->first();
                if (! $transaction) {
                    throw new VendorPaymentNotFound;
                }
                if ($transaction->reversed_at !== null) {
                    throw new VendorPaymentConflict('This payment transaction has already been reversed.');
                }

                DB::table('vendor_payment_transactions')->where('id', $transactionId)->update([
                    'reversed_at' => now(),
                    'reversed_by' => (int) $request->session()->get('staff_id'),
                    'reversal_reason' => trim((string) $data['reason']),
                    'updated_at' => now(),
                ]);
                $paidAfter = $this->netPaidAmount($paymentId);
                $status = $paidAfter <= 0 ? 'Approved' : ($paidAfter < (float) $payment->amount ? 'Partially Paid' : 'Paid');
                DB::table('vendor_payments')->where('id', $paymentId)->update(array_merge([
                    'status' => $status,
                ], $this->existingPaymentColumns([
                    'paid_amount' => $paidAfter > 0 ? $paidAfter : null,
                    'paid_date' => $paidAfter > 0 ? $this->lastPaidDate($paymentId) : null,
                    'paid_remarks' => $paidAfter > 0 ? ($payment->paid_remarks ?? null) : null,
                ]), $this->mutationColumns($request, $payment)));
                $this->event($request, $paymentId, 'payment_reversed', $payment->status ?? null, $status, (string) $data['reason'], [
                    'transaction_id' => $transactionId,
                    'amount' => (float) $transaction->amount,
                ]);
            });
        } catch (VendorPaymentNotFound) {
            return $this->notFound('Payment transaction not found.');
        } catch (VendorPaymentForbidden $e) {
            return $this->forbidden($e->getMessage());
        } catch (VendorPaymentConflict $e) {
            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Unable to reverse payment transaction.'], 500);
        }

        $updatedPayment = $this->payment($paymentId);
        $this->resolveNotifications($paymentId);
        if (in_array(strtolower((string) ($updatedPayment->status ?? '')), ['approved', 'partially paid'], true)) {
            app(VendorPaymentWorkflowService::class)->notifyPaymentStage(
                $request,
                $updatedPayment,
                $paymentId,
                VendorPaymentWorkflowService::STAGE_FINANCE,
                1,
                [
                    'type' => 'vendor_payment_finance_requested',
                    'title' => 'Vendor payment requires finance action',
                    'message' => "A reversed transaction reopened payment request #{$paymentId}.",
                    'severity' => 'warning',
                ],
            );
        }
        $this->auditLog->log($request, "Reversed vendor payment transaction #{$transactionId} for request #{$paymentId}");

        return response()->json(['status' => 'success', 'message' => 'Payment transaction reversed.']);
    }

    public function transactions(int $paymentId): array
    {
        if (! Schema::hasTable('vendor_payment_transactions')) {
            return [];
        }

        return DB::table('vendor_payment_transactions')
            ->where('vendor_payment_id', $paymentId)
            ->orderBy('id')
            ->get()
            ->map(function ($row): array {
                $data = (array) $row;
                $data['proof_url'] = AppFilePaths::publicUrlForStoredPath($data['proof_path'] ?? '');

                return $data;
            })
            ->all();
    }

    private function createPayment(Request $request, array $data, ?object $parent = null): JsonResponse
    {
        $attachment = null;
        try {
            if ($request->hasFile('receipt')) {
                $attachment = $this->storeAttachment($request->file('receipt'), 'payments', 'receipt');
            } elseif ($parent && ! empty($parent->receipt_path)) {
                $attachment = $this->copyParentAttachment($parent);
            }
            if (! $attachment) {
                throw new VendorPaymentConflict('A readable invoice attachment is required. Upload the invoice again.');
            }

            $result = DB::transaction(function () use ($request, $data, $parent, $attachment): array {
                $existing = $this->paymentForIdempotencyKey((string) $data['idempotency_key'], true);
                if ($existing) {
                    return ['id' => (int) $existing->id, 'created' => false];
                }
                if ($parent) {
                    $currentParent = $this->payment((int) $parent->id, true);
                    if (! $currentParent || ! app(VendorPaymentAuthorizationService::class)->can($request, $currentParent, 'can_resubmit')) {
                        throw new VendorPaymentConflict('Returned request changed before it could be resubmitted.');
                    }
                    $this->assertVersion($currentParent, (int) $data['version']);
                }

                $snapshots = $this->submissionSnapshots($data);
                $this->assertAwardCapacity($data, null, $snapshots);
                $workflow = app(VendorPaymentWorkflowService::class);
                $initialStatus = $workflow->initialStatus();
                $staffId = (int) $request->session()->get('staff_id', 0);
                $values = array_merge([
                    'vendor_id' => $data['vendor_id'],
                    'project_id' => $data['project_id'] ?? null,
                    'payment_context' => $data['payment_context'],
                    'payment_type' => $data['payment_type'],
                    'amount' => $data['amount'],
                    'method' => $data['method'],
                    'status' => $initialStatus,
                    'remarks' => $data['remarks'] ?? '',
                    'receipt_path' => $attachment['receipt_path'] ?? null,
                    'created_by' => $staffId,
                    'created_by_full_name' => (string) $request->session()->get('full_name', ''),
                    'created_by_name_code' => (string) $request->session()->get('name_code', ''),
                ], $snapshots, $attachment ?: [], [
                    'version' => 1,
                    'idempotency_key' => $data['idempotency_key'],
                    'parent_payment_id' => $parent?->id,
                    'revision_number' => $parent ? ((int) ($parent->revision_number ?? 1) + 1) : 1,
                    'current_review_level' => $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW) ? 1 : null,
                    'current_approval_level' => $workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL) ? 1 : null,
                    'workflow_progress_json' => json_encode([]),
                    'workflow_settings_snapshot_json' => $workflow->snapshot(),
                    'created_at' => now(),
                    'updated_at' => now(),
                    'updated_by' => $staffId,
                ]);
                $paymentId = DB::table('vendor_payments')->insertGetId($this->existingPaymentColumns($values));
                $this->event($request, $paymentId, $parent ? 'resubmitted' : 'created', null, $initialStatus, $parent ? 'Returned request amended and resubmitted.' : 'Payment request created.', $parent ? ['parent_payment_id' => (int) $parent->id] : []);

                if ($parent) {
                    DB::table('vendor_payments')->where('id', $parent->id)->update($this->existingPaymentColumns([
                        'status' => 'Superseded',
                        'superseded_by_payment_id' => $paymentId,
                        'superseded_at' => now(),
                        'updated_at' => now(),
                        'updated_by' => $staffId,
                        'version' => (int) ($parent->version ?? 1) + 1,
                    ]));
                    $this->event($request, (int) $parent->id, 'superseded', $parent->status ?? null, 'Superseded', 'A revised request was submitted.', ['revision_payment_id' => $paymentId]);
                }

                return ['id' => $paymentId, 'created' => true];
            });
            $paymentId = (int) $result['id'];
            if (! $result['created']) {
                $this->cleanupAttachment($attachment, 'receipt_path');

                return response()->json(['status' => 'success', 'id' => $paymentId, 'idempotent' => true]);
            }
        } catch (VendorPaymentConflict $e) {
            $this->cleanupAttachment($attachment, 'receipt_path');

            return $this->conflict($e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupAttachment($attachment, 'receipt_path');
            $existing = $this->paymentForIdempotencyKey((string) ($data['idempotency_key'] ?? ''));
            if ($existing) {
                return response()->json(['status' => 'success', 'id' => (int) $existing->id, 'idempotent' => true]);
            }
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Unable to create payment request.'], 500);
        }

        if ($parent) {
            $this->resolveNotifications((int) $parent->id);
        }
        $this->notifyInitialStage($request, $paymentId);
        $this->auditLog->log($request, ($parent ? 'Resubmitted' : 'Created')." vendor payment request #{$paymentId}");

        return response()->json(['status' => 'success', 'id' => $paymentId, 'idempotent' => false]);
    }

    private function submissionSnapshots(array $data): array
    {
        $vendor = DB::table('vendor_main_details')->where('vendor_id', $data['vendor_id'])->first();
        $assignment = null;
        $project = null;
        $clientName = null;
        if (! empty($data['project_id'])) {
            $assignmentQuery = DB::table('project_vendors')
                ->where('project_id', $data['project_id'])
                ->where('vendor_id', $data['vendor_id']);
            if (! empty($data['project_vendor_assignment_id'])) {
                $assignmentQuery->where('id', $data['project_vendor_assignment_id']);
            }
            $assignment = $assignmentQuery->orderByDesc('id')->first();
            $project = DB::table('projects_main')->where('id', $data['project_id'])->first();
            if ($project && Schema::hasTable('client_company') && isset($project->client_id)) {
                $clientName = DB::table('client_company')->where('company_id', $project->client_id)->value('company_name');
            }
        }

        return $this->existingPaymentColumns([
            'project_vendor_assignment_id' => $assignment->id ?? null,
            'vendor_name_snapshot' => $vendor->vendor_name ?? null,
            'project_name_snapshot' => $project->project_name ?? null,
            'client_name_snapshot' => $clientName,
            'payment_terms_snapshot' => $assignment->payment_terms ?? null,
            'award_value_snapshot' => $assignment->award_value ?? null,
            'bank_name_snapshot' => $vendor->bank_name ?? null,
            'bank_holder_name_snapshot' => $vendor->bank_holder_name ?? null,
            'bank_account_snapshot' => $vendor->bank_account ?? null,
        ]);
    }

    private function assertAwardCapacity(array $data, ?int $excludePaymentId, array $snapshots): void
    {
        if (strtolower(trim((string) $data['payment_context'])) !== 'project') {
            return;
        }
        $award = (float) ($snapshots['award_value_snapshot'] ?? 0);
        if ($award <= 0) {
            return;
        }
        $query = DB::table('vendor_payments')
            ->whereNull('deleted_at')
            ->where('project_id', $data['project_id'])
            ->where('vendor_id', $data['vendor_id'])
            ->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), ['cancelled', 'rejected', 'returned', 'superseded']);
        if ($excludePaymentId) {
            $query->where('id', '<>', $excludePaymentId);
        }
        if (Schema::hasColumn('vendor_payments', 'project_vendor_assignment_id') && ! empty($snapshots['project_vendor_assignment_id'])) {
            $query->where(function ($query) use ($snapshots): void {
                $query->where('project_vendor_assignment_id', $snapshots['project_vendor_assignment_id'])
                    ->orWhereNull('project_vendor_assignment_id');
            });
        }
        $committed = (float) $query->sum('amount');
        if (round($committed + (float) $data['amount'], 2) > round($award, 2)) {
            throw new VendorPaymentConflict('Requested amount exceeds the remaining vendor award balance.');
        }
    }

    private function storeAttachment(UploadedFile $file, string $root, string $prefix): array
    {
        $folder = $root.'/'.now()->format('Y/m');
        $filename = $prefix.'_'.Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = AppFilePaths::storeFileAs($folder, $file, $filename);
        if (! AppFilePaths::storedPathExists($path)) {
            throw new \RuntimeException('Attachment could not be stored.');
        }
        $localPath = AppFilePaths::storedPathLocalPath($path);
        if (! $localPath || ! is_readable($localPath) || filesize($localPath) < 1) {
            AppFilePaths::deleteStoredPath($path);
            throw new \RuntimeException('Stored attachment failed integrity verification.');
        }
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        if ($mime === 'application/pdf' && file_get_contents($localPath, false, null, 0, 5) !== '%PDF-') {
            AppFilePaths::deleteStoredPath($path);
            throw new \RuntimeException('Uploaded PDF is not readable.');
        }

        return [
            "{$prefix}_path" => $path,
            "{$prefix}_original_name" => $file->getClientOriginalName(),
            "{$prefix}_mime_type" => $mime,
            "{$prefix}_size" => filesize($localPath),
            "{$prefix}_sha256" => hash_file('sha256', $localPath),
            ...($prefix === 'receipt' ? ['receipt_state' => 'available'] : []),
        ];
    }

    private function copyParentAttachment(object $parent): ?array
    {
        $source = AppFilePaths::storedPathLocalPath((string) $parent->receipt_path);
        if (! $source || ! is_readable($source)) {
            return null;
        }
        $extension = pathinfo((string) $parent->receipt_path, PATHINFO_EXTENSION) ?: 'bin';
        $target = 'payments/'.now()->format('Y/m').'/receipt_'.Str::uuid().'.'.$extension;
        if (! AppFilePaths::copyStoredPath((string) $parent->receipt_path, $target)) {
            throw new \RuntimeException('Existing attachment could not be copied.');
        }
        $localPath = AppFilePaths::storedPathLocalPath($target);

        return [
            'receipt_path' => $target,
            'receipt_original_name' => $parent->receipt_original_name ?? basename($target),
            'receipt_mime_type' => $parent->receipt_mime_type ?? null,
            'receipt_size' => $localPath ? filesize($localPath) : null,
            'receipt_sha256' => $localPath ? hash_file('sha256', $localPath) : null,
            'receipt_state' => 'available',
        ];
    }

    private function notifyInitialStage(Request $request, int $paymentId): void
    {
        try {
            $workflow = app(VendorPaymentWorkflowService::class);
            if ($workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_REVIEW)) {
                $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_REVIEW, 1, [
                    'type' => 'vendor_payment_submitted', 'title' => 'Vendor payment requires review',
                    'message' => "Payment request #{$paymentId} is pending reviewer action.", 'severity' => 'warning',
                ]);
            } elseif ($workflow->stageEnabled(VendorPaymentWorkflowService::STAGE_APPROVAL)) {
                $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_APPROVAL, 1, [
                    'type' => 'vendor_payment_checked', 'title' => 'Vendor payment ready for approval',
                    'message' => "Payment request #{$paymentId} is ready for approver action.", 'severity' => 'primary',
                ]);
            } else {
                $workflow->notifyStage($request, $paymentId, VendorPaymentWorkflowService::STAGE_FINANCE, 1, [
                    'type' => 'vendor_payment_finance_requested', 'title' => 'Vendor payment ready for finance',
                    'message' => "Payment request #{$paymentId} is ready for finance payment.", 'severity' => 'primary',
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function event(
        Request $request,
        int $paymentId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $remarks = null,
        array $metadata = [],
    ): void {
        if (! Schema::hasTable('vendor_payment_events')) {
            return;
        }
        DB::table('vendor_payment_events')->insert([
            'vendor_payment_id' => $paymentId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
            'actor_name_code' => (string) $request->session()->get('name_code', '') ?: null,
            'remarks' => trim((string) $remarks) ?: null,
            'metadata_json' => $metadata ? json_encode($metadata) : null,
            'created_at' => now(),
        ]);
    }

    private function payment(int $paymentId, bool $lock = false): ?object
    {
        $query = DB::table('vendor_payments')->where('id', $paymentId)->whereNull('deleted_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function paymentForIdempotencyKey(string $key, bool $lock = false): ?object
    {
        if ($key === '' || ! Schema::hasColumn('vendor_payments', 'idempotency_key')) {
            return null;
        }
        $query = DB::table('vendor_payments')->where('idempotency_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function mutationColumns(Request $request, object $payment): array
    {
        return $this->existingPaymentColumns([
            'version' => (int) ($payment->version ?? 1) + 1,
            'updated_at' => now(),
            'updated_by' => (int) $request->session()->get('staff_id', 0),
        ]);
    }

    private function assertVersion(object $payment, int $expected): void
    {
        if (Schema::hasColumn('vendor_payments', 'version') && (int) ($payment->version ?? 1) !== $expected) {
            throw new VendorPaymentConflict('Payment changed before this action was completed. Please refresh and try again.');
        }
    }

    private function existingPaymentColumns(array $values): array
    {
        return array_filter(
            $values,
            static fn ($value, $column): bool => Schema::hasColumn('vendor_payments', (string) $column),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function netPaidAmount(int $paymentId): float
    {
        return (float) DB::table('vendor_payment_transactions')
            ->where('vendor_payment_id', $paymentId)
            ->whereNull('reversed_at')
            ->sum('amount');
    }

    private function lastPaidDate(int $paymentId): ?string
    {
        return DB::table('vendor_payment_transactions')
            ->where('vendor_payment_id', $paymentId)
            ->whereNull('reversed_at')
            ->orderByDesc('paid_date')
            ->value('paid_date');
    }

    private function resolveNotifications(int $paymentId): void
    {
        app(AppNotificationService::class)->resolveActive('vendor.payments', 'vendor_payment', $paymentId);
    }

    private function cleanupAttachment(?array $attachment, string $pathKey): void
    {
        if (! empty($attachment[$pathKey])) {
            AppFilePaths::deleteStoredPath($attachment[$pathKey]);
        }
    }

    private function notFound(string $message = 'Payment record not found.'): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 404);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 409);
    }
}

class VendorPaymentConflict extends \RuntimeException {}
class VendorPaymentForbidden extends \RuntimeException {}
class VendorPaymentNotFound extends \RuntimeException {}
