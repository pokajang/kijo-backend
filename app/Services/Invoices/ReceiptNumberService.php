<?php

namespace App\Services\Invoices;

use Illuminate\Support\Facades\DB;

final class ReceiptNumberService
{
    public function resolvePaidInvoice(int $invoiceId): object
    {
        $lockName = 'invoice_receipt_number_'.date('Y');
        $usesAdvisoryLock = DB::connection()->getDriverName() === 'mysql';
        if ($usesAdvisoryLock) {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS lock_status', [$lockName]);
            if (! isset($lock->lock_status) || (int) $lock->lock_status !== 1) {
                throw new \RuntimeException('Unable to acquire receipt number lock.');
            }
        }

        try {
            return DB::transaction(function () use ($invoiceId): object {
                $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
                if (! $invoice) {
                    throw new \OutOfBoundsException('Invoice not found');
                }
                $isPaid = strtolower(trim((string) ($invoice->status ?? ''))) === 'paid';
                $paidDate = trim((string) ($invoice->paid_date ?? ''));
                $paidAmount = $invoice->paid_amount ?? null;
                if (! $isPaid || $paidDate === '' || ! is_numeric($paidAmount) || (float) $paidAmount <= 0) {
                    throw new \DomainException('Only paid invoices with payment date and amount can generate a receipt.');
                }
                if (empty($invoice->receipt_no)) {
                    $year = date('Y');
                    $maximum = DB::table('invoices')->where('receipt_no', 'LIKE', "RCPT{$year}-%")->max('receipt_no');
                    $next = $maximum ? ((int) substr((string) $maximum, -4)) + 1 : 1;
                    DB::table('invoices')->where('id', $invoiceId)->update(['receipt_no' => sprintf('RCPT%s-%04d', $year, $next)]);
                    $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
                }

                return $invoice;
            });
        } finally {
            if ($usesAdvisoryLock) {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }
}
