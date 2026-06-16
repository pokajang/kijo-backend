<?php

namespace App\Services\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectValueCommercialImpactService
{
    private const TERMINAL_INVOICE_STATUSES = ['cancelled', 'canceled', 'void'];

    private const VARIATION_LINE = 'Project Value Variation';

    private const REDUCTION_LINE = 'Project Value Reduction';

    private const LEGACY_ADJUSTMENT_DESCRIPTION = 'Project current value adjustment';

    private const SYSTEM_ADJUSTMENT_KEY = 'project_value_adjustment';

    private const SYSTEM_ADJUSTMENT_SOURCE = 'project_value_sync';

    public function preview(int $projectId, float $oldValue, float $newValue): array
    {
        $delta = round($newValue - $oldValue, 2);

        $invoices = $this->invoiceRows($projectId, $newValue);
        $deliveryOrders = $this->deliveryOrderRows($projectId);
        $jd14 = $this->jd14Rows($projectId);
        $paymentAdjustments = array_values(array_filter(
            $invoices,
            static fn (array $row): bool => ($row['action'] ?? '') === 'record_adjustment_required'
        ));
        $blockedItems = array_values(array_filter(
            $invoices,
            static fn (array $row): bool => $row['classification'] === 'blocked'
        ));

        return [
            'project_id' => $projectId,
            'old_project_value' => round($oldValue, 2),
            'new_project_value' => round($newValue, 2),
            'delta' => $delta,
            'summary' => [
                'invoice_count' => count($invoices),
                'editable_invoice_count' => count(array_filter($invoices, static fn (array $row): bool => $row['classification'] === 'editable')),
                'payment_record_count' => count($paymentAdjustments),
                'blocked_count' => count($blockedItems),
                'delivery_order_count' => count($deliveryOrders),
                'jd14_count' => count($jd14),
                'affected_count' => count($invoices) + count($deliveryOrders) + count($jd14),
            ],
            'documents' => [
                'invoices' => $invoices,
                'payment_adjustments' => array_map(fn (array $row): array => [
                    'id' => $row['id'],
                    'document_type' => 'payment',
                    'reference' => $row['reference'],
                    'status' => $row['status'],
                    'old_amount' => $row['old_amount'],
                    'new_amount' => $row['new_amount'],
                    'delta' => $row['delta'],
                    'classification' => 'adjustment_required',
                    'action' => 'record_adjustment_required',
                    'message' => 'Paid receipt/payment will not be overwritten; an adjustment-required audit entry will be recorded.',
                ], $paymentAdjustments),
                'blocked_items' => array_map(fn (array $row): array => [
                    'id' => $row['id'],
                    'document_type' => 'invoice',
                    'reference' => $row['reference'],
                    'status' => $row['status'],
                    'old_amount' => $row['old_amount'],
                    'new_amount' => $row['new_amount'],
                    'delta' => $row['delta'],
                    'classification' => 'blocked',
                    'action' => $row['action'] ?? 'none',
                    'message' => $row['message'] ?? 'This invoice is not changed by project value sync.',
                ], $blockedItems),
                'delivery_orders' => $deliveryOrders,
                'jd14' => $jd14,
            ],
        ];
    }

    public function hasAffectedDocuments(array $preview): bool
    {
        return (int) ($preview['summary']['affected_count'] ?? 0) > 0;
    }

    public function applySync(
        int $projectId,
        int $revisionId,
        float $newValue,
        array $sync,
        int $staffId,
        bool $isCommercialResync = false
    ): array {
        $applied = [
            'invoices' => [],
            'payment_adjustments' => [],
            'blocked_items' => [],
            'delivery_orders' => [],
            'jd14' => [],
        ];
        $skipped = [
            'invoices' => [],
            'payment_adjustments' => [],
            'blocked_items' => [],
            'delivery_orders' => [],
            'jd14' => [],
        ];

        foreach ($this->idsFromSync($sync, 'invoices') as $invoiceId) {
            $result = $this->syncInvoice($projectId, $revisionId, $invoiceId, $newValue, $staffId, $isCommercialResync);
            if ($result['applied']) {
                $applied['invoices'][] = $result;
            } else {
                throw new \RuntimeException($result['message'] ?? 'Selected invoice could not be synced.');
            }
        }

        foreach ($this->idsFromSync($sync, 'payment_adjustments') as $invoiceId) {
            $result = $this->recordPaymentAdjustment($projectId, $revisionId, $invoiceId, $newValue, $staffId);
            if ($result['applied']) {
                $applied['payment_adjustments'][] = $result;
            } else {
                throw new \RuntimeException($result['message'] ?? 'Selected payment adjustment could not be recorded.');
            }
        }

        foreach ($this->idsFromSync($sync, 'delivery_orders') as $doId) {
            if (
                ! Schema::hasTable('do_details')
                || ! DB::table('do_details')->where('project_id', $projectId)->where('id', $doId)->exists()
            ) {
                throw new \RuntimeException('Selected Delivery Order was not found for this project.');
            }

            $skipped['delivery_orders'][] = [
                'id' => $doId,
                'applied' => false,
                'message' => 'Delivery Orders are informational for project value changes in v1.',
            ];
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    private function invoiceRows(int $projectId, float $newValue): array
    {
        if (! Schema::hasTable('invoices')) {
            return [];
        }

        $columns = $this->selectColumns('invoices', [
            'id',
            'invoice_ref_no',
            'status',
            'amount',
            'sst_amount',
            'grand_total',
            'paid_amount',
            'paid_date',
        ]);

        return DB::table('invoices')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->select($columns)
            ->get()
            ->map(function ($row) use ($newValue): array {
                $status = (string) ($row->status ?? '');
                $normalizedStatus = strtolower(trim($status));
                $oldGrandTotal = round((float) ($row->grand_total ?? 0), 2);
                $sstAmount = round((float) ($row->sst_amount ?? 0), 2);
                $currentAmount = $row->amount !== null
                    ? round((float) $row->amount, 2)
                    : round($oldGrandTotal - $sstAmount, 2);
                $context = $this->invoiceAdjustmentContext((int) $row->id, $currentAmount, $sstAmount, $newValue);
                $isPaid = $this->isPaidOrPartiallyPaid($normalizedStatus, $row->paid_amount ?? null, $row->paid_date ?? null);
                $isTerminal = in_array($normalizedStatus, self::TERMINAL_INVOICE_STATUSES, true);

                if ($isTerminal) {
                    $classification = 'blocked';
                    $action = 'none';
                    $message = 'Cancelled, canceled, or void invoices are not changed by project value sync.';
                } elseif ($isPaid) {
                    $classification = 'adjustment_required';
                    $action = 'record_adjustment_required';
                    $message = 'Paid receipt/payment will not be overwritten.';
                } elseif ($context['target_amount'] < 0) {
                    $classification = 'blocked';
                    $action = 'manual_review_required';
                    $message = 'Invoice cannot be synced because the target project value is below the existing SST amount.';
                } else {
                    $classification = 'editable';
                    $action = 'update_invoice_total';
                    $message = $context['adjustment_label'] === self::REDUCTION_LINE
                        ? 'Invoice can be reduced with a Project Value Reduction line.'
                        : 'Invoice can be updated with a Project Value Variation line.';
                }

                return [
                    'id' => (int) $row->id,
                    'document_type' => 'invoice',
                    'reference' => (string) ($row->invoice_ref_no ?? "Invoice #{$row->id}"),
                    'status' => $status,
                    'old_amount' => $oldGrandTotal,
                    'new_amount' => round($newValue, 2),
                    'delta' => round($newValue - $oldGrandTotal, 2),
                    'base_amount' => $context['base_amount'],
                    'target_amount' => $context['target_amount'],
                    'sst_amount' => $sstAmount,
                    'existing_adjustment_amount' => $context['existing_adjustment_amount'],
                    'target_adjustment_amount' => $context['target_adjustment_amount'],
                    'adjustment_label' => $context['adjustment_label'],
                    'already_matches_project_value' => abs($oldGrandTotal - round($newValue, 2)) < 0.01,
                    'classification' => $classification,
                    'action' => $action,
                    'message' => $message,
                ];
            })
            ->all();
    }

    private function deliveryOrderRows(int $projectId): array
    {
        if (! Schema::hasTable('do_details')) {
            return [];
        }

        return DB::table('do_details')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->select($this->selectColumns('do_details', ['id', 'do_number']))
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'document_type' => 'delivery_order',
                'reference' => (string) ($row->do_number ?? "DO #{$row->id}"),
                'classification' => 'informational',
                'action' => 'none',
                'message' => 'Delivery Orders do not carry monetary totals in v1.',
            ])
            ->all();
    }

    private function jd14Rows(int $projectId): array
    {
        if (! Schema::hasTable('invoices_jd14form')) {
            return [];
        }

        return DB::table('invoices_jd14form')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->select($this->selectColumns('invoices_jd14form', ['id', 'approval_no']))
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'document_type' => 'jd14',
                'reference' => (string) ($row->approval_no ?? "JD14 #{$row->id}"),
                'classification' => 'informational',
                'action' => 'none',
                'message' => 'Existing JD14 records are not mutated by project value changes.',
            ])
            ->all();
    }

    private function syncInvoice(
        int $projectId,
        int $revisionId,
        int $invoiceId,
        float $newValue,
        int $staffId,
        bool $isCommercialResync
    ): array {
        if (! Schema::hasTable('invoices')) {
            return ['id' => $invoiceId, 'applied' => false, 'message' => 'Invoices table is not available.'];
        }

        $invoice = DB::table('invoices')
            ->where('project_id', $projectId)
            ->where('id', $invoiceId)
            ->lockForUpdate()
            ->first();

        if (! $invoice) {
            return ['id' => $invoiceId, 'applied' => false, 'message' => 'Invoice not found for this project.'];
        }

        $status = strtolower(trim((string) ($invoice->status ?? '')));
        $paid = $this->isPaidOrPartiallyPaid($status, $invoice->paid_amount ?? null, $invoice->paid_date ?? null);
        if ($paid || in_array($status, self::TERMINAL_INVOICE_STATUSES, true)) {
            return ['id' => $invoiceId, 'applied' => false, 'message' => 'Invoice is not editable for direct value sync.'];
        }

        $oldGrandTotal = round((float) ($invoice->grand_total ?? 0), 2);
        $newGrandTotal = round($newValue, 2);
        $sstAmount = round((float) ($invoice->sst_amount ?? 0), 2);
        $currentAmount = $invoice->amount !== null
            ? round((float) $invoice->amount, 2)
            : round($oldGrandTotal - $sstAmount, 2);
        $newAmount = round($newGrandTotal - $sstAmount, 2);
        if ($newAmount < 0) {
            return [
                'id' => $invoiceId,
                'reference' => (string) ($invoice->invoice_ref_no ?? "Invoice #{$invoiceId}"),
                'applied' => false,
                'message' => 'Invoice cannot be synced because the target project value is below the existing SST amount.',
            ];
        }

        $context = $this->invoiceAdjustmentContext($invoiceId, $currentAmount, $sstAmount, $newGrandTotal);
        $delta = round($newGrandTotal - $oldGrandTotal, 2);
        $this->replaceSystemAdjustmentLine($invoiceId, $context['target_adjustment_amount'], $context['adjustment_label']);

        DB::table('invoices')->where('id', $invoiceId)->update([
            'amount' => $newAmount,
            'sst_amount' => $sstAmount,
            'grand_total' => $newGrandTotal,
            'remarks' => trim((string) ($invoice->remarks ?? '')."\nProject value synced with adjustment RM ".number_format($context['target_adjustment_amount'], 2).'.'),
            'updated_at' => now(),
        ]);

        $this->insertDocumentAudit(
            $revisionId,
            $projectId,
            'invoice',
            $invoiceId,
            (string) ($invoice->invoice_ref_no ?? "Invoice #{$invoiceId}"),
            $isCommercialResync ? 'resynced_invoice_total' : 'updated_invoice_total',
            $oldGrandTotal,
            $newGrandTotal,
            (string) ($invoice->status ?? ''),
            (string) ($invoice->status ?? ''),
            $isCommercialResync
                ? 'Invoice resynced to existing project current value using system adjustment line.'
                : 'Invoice total updated after project value change using system adjustment line.',
            $staffId
        );

        return [
            'id' => $invoiceId,
            'reference' => (string) ($invoice->invoice_ref_no ?? "Invoice #{$invoiceId}"),
            'applied' => true,
            'old_amount' => $oldGrandTotal,
            'new_amount' => $newGrandTotal,
            'delta' => $delta,
            'target_adjustment_amount' => $context['target_adjustment_amount'],
            'adjustment_label' => $context['adjustment_label'],
            'message' => abs($delta) < 0.01 ? 'Invoice resynced to the project value.' : 'Invoice updated.',
        ];
    }

    private function recordPaymentAdjustment(int $projectId, int $revisionId, int $invoiceId, float $newValue, int $staffId): array
    {
        $invoice = Schema::hasTable('invoices')
            ? DB::table('invoices')->where('project_id', $projectId)->where('id', $invoiceId)->lockForUpdate()->first()
            : null;

        if (! $invoice) {
            return ['id' => $invoiceId, 'applied' => false, 'message' => 'Paid invoice/payment record not found for this project.'];
        }

        $status = strtolower(trim((string) ($invoice->status ?? '')));
        if (in_array($status, self::TERMINAL_INVOICE_STATUSES, true)) {
            return ['id' => $invoiceId, 'applied' => false, 'message' => 'Terminal invoice records cannot create payment adjustment entries.'];
        }

        $isPaid = $this->isPaidOrPartiallyPaid($status, $invoice->paid_amount ?? null, $invoice->paid_date ?? null);
        if (! $isPaid) {
            return ['id' => $invoiceId, 'applied' => false, 'message' => 'Only paid invoice/payment records can create payment adjustment audit entries.'];
        }

        $oldAmount = round((float) ($invoice->grand_total ?? 0), 2);
        $newAmount = round($newValue, 2);
        $delta = round($newAmount - $oldAmount, 2);
        if (abs($delta) < 0.01) {
            return [
                'id' => $invoiceId,
                'reference' => (string) ($invoice->invoice_ref_no ?? "Invoice #{$invoiceId}"),
                'applied' => true,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'delta' => $delta,
                'message' => 'No payment adjustment is required.',
            ];
        }

        $this->insertDocumentAudit(
            $revisionId,
            $projectId,
            'payment',
            $invoiceId,
            (string) ($invoice->invoice_ref_no ?? "Invoice #{$invoiceId}"),
            'adjustment_required',
            $oldAmount,
            $newAmount,
            (string) ($invoice->status ?? ''),
            (string) ($invoice->status ?? ''),
            'Paid receipt/payment was not overwritten. Adjustment required for delta RM '.number_format($delta, 2).'.',
            $staffId
        );

        return [
            'id' => $invoiceId,
            'reference' => (string) ($invoice->invoice_ref_no ?? "Invoice #{$invoiceId}"),
            'applied' => true,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'delta' => $delta,
            'message' => 'Adjustment-required audit entry recorded.',
        ];
    }

    private function invoiceAdjustmentContext(int $invoiceId, float $currentAmount, float $sstAmount, float $targetGrandTotal): array
    {
        $targetGrandTotal = round($targetGrandTotal, 2);
        $targetAmount = round($targetGrandTotal - $sstAmount, 2);
        $existingAdjustmentAmount = $this->systemAdjustmentRows($invoiceId)->sum(
            fn ($row): float => round((float) ($row->subtotal ?? 0), 2)
        );
        $baseAmount = round($currentAmount - $existingAdjustmentAmount, 2);
        $targetAdjustmentAmount = round($targetAmount - $baseAmount, 2);
        $adjustmentLabel = abs($targetAdjustmentAmount) < 0.01
            ? null
            : ($targetAdjustmentAmount < 0 ? self::REDUCTION_LINE : self::VARIATION_LINE);

        return [
            'base_amount' => $baseAmount,
            'target_amount' => $targetAmount,
            'existing_adjustment_amount' => round($existingAdjustmentAmount, 2),
            'target_adjustment_amount' => $targetAdjustmentAmount,
            'adjustment_label' => $adjustmentLabel,
        ];
    }

    private function isPaidOrPartiallyPaid(string $normalizedStatus, mixed $paidAmount, mixed $paidDate): bool
    {
        $normalizedStatus = preg_replace('/[\s_-]+/', ' ', strtolower(trim($normalizedStatus))) ?: '';

        return $normalizedStatus === 'paid'
            || preg_match('/\b(partial|partially) paid\b/', $normalizedStatus) === 1
            || (float) ($paidAmount ?? 0) > 0
            || ! empty($paidDate);
    }

    private function replaceSystemAdjustmentLine(int $invoiceId, float $amount, ?string $label): void
    {
        if (! Schema::hasTable('invoice_breakdown')) {
            return;
        }

        $this->systemAdjustmentRows($invoiceId)->each(
            fn ($row) => DB::table('invoice_breakdown')->where('id', $row->id)->delete()
        );

        if (abs($amount) < 0.01 || $label === null) {
            return;
        }

        $insert = [
            'invoice_id' => $invoiceId,
            'item_description' => $label,
            'description' => self::LEGACY_ADJUSTMENT_DESCRIPTION,
            'unit' => 'Lot',
            'quantity' => 1,
            'unit_price' => round($amount, 2),
            'subtotal' => round($amount, 2),
            'sort_order' => (int) DB::table('invoice_breakdown')->where('invoice_id', $invoiceId)->max('sort_order') + 1,
        ];

        if (Schema::hasColumn('invoice_breakdown', 'system_adjustment_key')) {
            $insert['system_adjustment_key'] = self::SYSTEM_ADJUSTMENT_KEY;
        }

        if (Schema::hasColumn('invoice_breakdown', 'system_adjustment_source')) {
            $insert['system_adjustment_source'] = self::SYSTEM_ADJUSTMENT_SOURCE;
        }

        DB::table('invoice_breakdown')->insert($insert);
    }

    private function systemAdjustmentRows(int $invoiceId)
    {
        if (! Schema::hasTable('invoice_breakdown')) {
            return collect();
        }

        $query = DB::table('invoice_breakdown')
            ->where('invoice_id', $invoiceId)
            ->lockForUpdate();

        if (Schema::hasColumn('invoice_breakdown', 'system_adjustment_key')) {
            $metadataRows = (clone $query)
                ->where('system_adjustment_key', self::SYSTEM_ADJUSTMENT_KEY)
                ->get();

            if ($metadataRows->isNotEmpty()) {
                return $metadataRows;
            }
        }

        return $query
            ->whereIn('item_description', [self::VARIATION_LINE, self::REDUCTION_LINE])
            ->where('description', self::LEGACY_ADJUSTMENT_DESCRIPTION)
            ->get();
    }

    private function insertDocumentAudit(
        int $revisionId,
        int $projectId,
        string $documentType,
        ?int $documentId,
        string $documentRef,
        string $action,
        ?float $oldAmount,
        ?float $newAmount,
        ?string $statusBefore,
        ?string $statusAfter,
        string $note,
        int $staffId
    ): void {
        if (! Schema::hasTable('project_value_revision_documents')) {
            return;
        }

        DB::table('project_value_revision_documents')->insert([
            'project_value_revision_id' => $revisionId,
            'project_id' => $projectId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_ref' => $documentRef,
            'action' => $action,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'status_before' => $statusBefore,
            'status_after' => $statusAfter,
            'note' => $note,
            'changed_by' => $staffId > 0 ? $staffId : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function idsFromSync(array $sync, string $key): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) ($sync[$key] ?? [])),
            static fn (int $id): bool => $id > 0
        )));
    }

    private function selectColumns(string $table, array $columns): array
    {
        return array_map(
            static fn (string $column) => Schema::hasColumn($table, $column)
                ? $column
                : DB::raw("NULL as {$column}"),
            $columns
        );
    }
}
