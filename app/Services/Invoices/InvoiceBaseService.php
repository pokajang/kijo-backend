<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class InvoiceBaseService
{
    protected const SYSTEM_DEFAULT_PAYMENT_TERMS_DAYS = 30;
    protected const PAYMENT_TERMS_SOURCE_SYSTEM_DEFAULT = 'system_default';
    protected const PAYMENT_TERMS_SOURCE_CLIENT = 'client';
    protected const PAYMENT_TERMS_SOURCE_INVOICE_OVERRIDE = 'invoice_override';
    protected const PAYMENT_TERMS_SOURCE_LEGACY = 'legacy';

    public function __construct(protected AuditLogService $auditLog) {}

    protected function insertProjectProgress(int $projectId, string $text, Request $request): void
    {
        if ($projectId <= 0 || $text === '') {
            return;
        }
        try {
            DB::table('project_progress')->insert([
                'project_id'    => $projectId,
                'progress_date' => now()->format('Y-m-d'),
                'progress_text' => $text,
                'updated_by'    => (int) $request->session()->get('staff_id', 0) ?: null,
                'updated_on'    => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function normalizeDocumentLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    protected function invoiceTotalValidationMessage(
        string $serviceType,
        array $breakdown,
        float $amount,
        float $sstAmount,
        float $grandTotal
    ): ?string {
        $tolerance = 0.05;
        $subtotalBeforeDiscount = 0.0;
        $discountTotal = 0.0;
        $hrdAmount = 0.0;

        foreach ($breakdown as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $label = strtolower(trim((string) ($line['item_description'] ?? '')));
            $qty = (float) ($line['quantity'] ?? 0);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $calculated = round($qty * $unitPrice, 2);

            if (array_key_exists('subtotal', $line) && $line['subtotal'] !== null && $line['subtotal'] !== '') {
                $submittedSubtotal = (float) $line['subtotal'];
                if (abs($submittedSubtotal - $calculated) > $tolerance) {
                    $row = $index + 1;
                    return "Invoice breakdown row {$row} subtotal must equal quantity x unit price.";
                }
            }

            if ($this->isInvoiceTaxLine($label)) {
                if ($this->isInvoiceHrdLine($label)) {
                    $hrdAmount += $calculated;
                }
                continue;
            }

            if ($this->isInvoiceDiscountLine($label)) {
                $discountTotal += abs($calculated);
                continue;
            }

            $subtotalBeforeDiscount += $calculated;
        }

        $expectedGrandTotal = round($subtotalBeforeDiscount - $discountTotal + $sstAmount + $hrdAmount, 2);
        if (abs($expectedGrandTotal - $grandTotal) > $tolerance) {
            return 'Invoice breakdown does not reconcile with the submitted grand total.';
        }

        if ($this->isIndustrialHygieneService($serviceType) && abs($subtotalBeforeDiscount - $amount) > $tolerance) {
            return 'Industrial Hygiene invoice amount must equal service, mobilization/travel, and custom item total before discount and SST.';
        }

        return null;
    }

    protected function isInvoiceDiscountLine(string $label): bool
    {
        return str_contains($label, 'discount') || str_contains($label, 'less');
    }

    protected function isInvoiceTaxLine(string $label): bool
    {
        return str_contains($label, 'sst') || $this->isInvoiceHrdLine($label);
    }

    protected function isInvoiceHrdLine(string $label): bool
    {
        return (bool) preg_match(
            '/^\s*(\d+(?:\.\d+)?\s*%\s*)?hrd\s*charge\b/i',
            trim($label)
        );
    }

    protected function isIndustrialHygieneService(string $serviceType): bool
    {
        $value = strtolower(trim($serviceType));
        return $value === 'ih' || $value === 'industrial hygiene';
    }
}
