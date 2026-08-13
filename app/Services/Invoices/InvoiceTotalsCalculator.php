<?php

namespace App\Services\Invoices;

final class InvoiceTotalsCalculator
{
    public const VERSION = 'typed_lines_v1';

    private const TOLERANCE = 0.05;

    public function calculateIndustrialHygiene(array $breakdown, float $sstPercent): array
    {
        $fieldErrors = [];
        $grossSubtotal = 0.0;
        $discountTotal = 0.0;

        foreach ($breakdown as $index => $line) {
            if (! is_array($line)) {
                $fieldErrors["breakdown.{$index}"] = ['Enter a valid invoice line.'];

                continue;
            }

            $quantity = $this->number($line['quantity'] ?? null);
            $unitPrice = $this->number($line['unit_price'] ?? null);
            $lineType = $this->lineType($line);

            if ($quantity === null || $quantity < 0) {
                $fieldErrors["breakdown.{$index}.quantity"] = ['Enter a quantity of zero or more.'];

                continue;
            }
            if ($unitPrice === null) {
                $fieldErrors["breakdown.{$index}.unit_price"] = ['Enter a valid unit price.'];

                continue;
            }

            $subtotal = round($quantity * $unitPrice, 2);
            if ($lineType === 'discount') {
                $discountTotal += abs($subtotal);

                continue;
            }
            if ($lineType === 'tax') {
                continue;
            }
            if ($subtotal < 0) {
                $fieldErrors["breakdown.{$index}.unit_price"] = ['Charge lines cannot have a negative unit price.'];

                continue;
            }

            $grossSubtotal += $subtotal;
        }

        $grossSubtotal = round($grossSubtotal, 2);
        $discountTotal = round($discountTotal, 2);
        if ($discountTotal > $grossSubtotal + self::TOLERANCE) {
            $discountIndex = $this->firstLineIndex($breakdown, 'discount');
            $fieldErrors["breakdown.{$discountIndex}.unit_price"] = [
                sprintf('Discount cannot exceed the gross subtotal of RM %s.', number_format($grossSubtotal, 2)),
            ];
        }
        if ($sstPercent < 0 || $sstPercent > 100) {
            $fieldErrors['sst_percent'] = ['SST rate must be between 0 and 100.'];
        }

        $taxableSubtotal = round(max(0, $grossSubtotal - $discountTotal), 2);
        $sstAmount = round($taxableSubtotal * max(0, min(100, $sstPercent)) / 100, 2);

        return [
            'amount' => $grossSubtotal,
            'discount_total' => $discountTotal,
            'taxable_subtotal' => $taxableSubtotal,
            'sst_percent' => round($sstPercent, 4),
            'sst_amount' => $sstAmount,
            'grand_total' => round($taxableSubtotal + $sstAmount, 2),
            'field_errors' => $fieldErrors,
        ];
    }

    /**
     * Resolve an IH payload across both invoice contracts.
     *
     * Current clients submit the SST rate explicitly. Older deployed clients
     * submit only the already-rounded SST amount and grand total, so derive the
     * rate from those values and reject inconsistent legacy totals instead of
     * silently treating a missing rate as zero.
     */
    public function calculateIndustrialHygienePayload(
        array $breakdown,
        ?float $sstPercent,
        float $submittedSstAmount,
        float $submittedGrandTotal,
    ): array {
        if ($sstPercent !== null) {
            return $this->calculateIndustrialHygiene($breakdown, $sstPercent);
        }

        $base = $this->calculateIndustrialHygiene($breakdown, 0);
        if ($base['field_errors'] !== []) {
            return $base;
        }

        $taxableSubtotal = (float) $base['taxable_subtotal'];
        if ($submittedSstAmount < 0) {
            $base['field_errors']['sst_amount'] = ['SST amount cannot be negative.'];

            return $base;
        }

        if ($taxableSubtotal <= self::TOLERANCE) {
            if (abs($submittedSstAmount) > self::TOLERANCE
                || abs($submittedGrandTotal - $taxableSubtotal) > self::TOLERANCE) {
                $base['field_errors']['sst_amount'] = [
                    'The submitted SST amount does not match the taxable subtotal.',
                ];
            }

            return $base;
        }

        $derivedPercent = round(($submittedSstAmount / $taxableSubtotal) * 100, 4);
        if ($derivedPercent < 0 || $derivedPercent > 100) {
            $base['field_errors']['sst_amount'] = [
                'The submitted SST amount produces a rate outside the allowed 0% to 100% range.',
            ];

            return $base;
        }

        $resolved = $this->calculateIndustrialHygiene($breakdown, $derivedPercent);
        if (abs((float) $resolved['sst_amount'] - $submittedSstAmount) > self::TOLERANCE
            || abs((float) $resolved['grand_total'] - $submittedGrandTotal) > self::TOLERANCE) {
            $resolved['field_errors']['sst_amount'] = [
                'The submitted SST amount and grand total are inconsistent. Refresh the invoice values and try again.',
            ];
        }

        return $resolved;
    }

    public function lineType(array $line): string
    {
        $type = strtolower(trim((string) ($line['line_type'] ?? '')));
        if (in_array($type, ['service', 'travel', 'custom', 'discount', 'tax', 'hrd', 'delivery', 'misc', 'system_adjustment'], true)) {
            return $type;
        }

        $label = strtolower(trim((string) ($line['item_description'] ?? '')));
        if (str_contains($label, 'discount') || str_contains($label, 'less')) {
            return 'discount';
        }
        if (str_contains($label, 'sst') || preg_match('/^\s*(\d+(?:\.\d+)?\s*%\s*)?hrd\s*charge\b/i', $label)) {
            return 'tax';
        }
        if (str_contains($label, 'travel') || str_contains($label, 'mobilization')) {
            return 'travel';
        }

        return 'custom';
    }

    private function firstLineIndex(array $breakdown, string $type): int
    {
        foreach ($breakdown as $index => $line) {
            if (is_array($line) && $this->lineType($line) === $type) {
                return $index;
            }
        }

        return 0;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
