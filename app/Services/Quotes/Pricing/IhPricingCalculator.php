<?php

namespace App\Services\Quotes\Pricing;

final class IhPricingCalculator
{
    private const HISTORICAL_TOTAL_TOLERANCE = 0.05;

    public const LEGACY_RULE = 'ih_complexity_v1';

    public const INTERMEDIATE_RULE = 'ih_standard_v1';

    public const STANDARD_RULE = 'ih_standard_v2';

    public function calculate(
        array $data,
        array $lineItems = [],
        string $pricingRuleVersion = self::STANDARD_RULE,
        int $complexityRating = 1,
    ): array {
        $rule = $this->normalizeRule($pricingRuleVersion);
        $sampleCounts = max(0, (float) ($data['sample_counts'] ?? 0));
        $workUnits = max(1, (float) ($data['num_work_units'] ?? 0));
        $unitPrice = max(0, (float) ($data['unit_price'] ?? 0));
        $travelCharge = max(0, (float) ($data['travel_charge'] ?? 0));
        $discount = max(0, (float) ($data['discount'] ?? 0));
        $sstPercent = max(0, (float) ($data['sst_percent'] ?? 0));
        $normalizedComplexity = $rule === self::LEGACY_RULE
            ? max(1, min(5, $complexityRating))
            : 1;
        $complexityMultiplier = 1 + (($normalizedComplexity - 1) * 0.1);
        $itemsTotal = $rule === self::STANDARD_RULE
            ? array_sum(array_map(
                fn (array $item): float => (float) ($item['line_total'] ?? 0),
                $lineItems,
            ))
            : 0.0;

        $serviceTotal = $sampleCounts * $workUnits * $unitPrice * $complexityMultiplier;
        $grossSubtotal = round($serviceTotal + $travelCharge + $itemsTotal, 2);
        $taxableTotal = round(max(0, $grossSubtotal - $discount), 2);
        $sstAmount = round($taxableTotal * $sstPercent / 100, 2);

        return [
            'pricing_rule_version' => $rule,
            'complexity_rating' => $normalizedComplexity,
            'complexity_multiplier' => $complexityMultiplier,
            'service_total' => round($serviceTotal, 2),
            'additional_fees_total' => round($itemsTotal, 2),
            'gross_subtotal' => $grossSubtotal,
            'taxable_total' => $taxableTotal,
            'discount' => round($discount, 2),
            'sst_percent' => $sstPercent,
            'sst_amount' => $sstAmount,
            // V1 historically stored the post-discount amount in sub_total.
            'sub_total' => $this->isHistoricalRule($rule) ? $taxableTotal : $grossSubtotal,
            'grand_total' => round($taxableTotal + $sstAmount, 2),
        ];
    }

    public function normalizeRule(?string $value): string
    {
        if (in_array($value, self::rules(), true)) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unsupported IH pricing rule [%s].',
            $value ?? 'null',
        ));
    }

    public function isHistoricalRule(?string $value): bool
    {
        return in_array($value, [self::LEGACY_RULE, self::INTERMEDIATE_RULE], true);
    }

    public static function rules(): array
    {
        return [
            self::LEGACY_RULE,
            self::INTERMEDIATE_RULE,
            self::STANDARD_RULE,
        ];
    }

    public function multiplierFor(int $complexityRating): float
    {
        $rating = max(1, min(5, $complexityRating));

        return 1 + (($rating - 1) * 0.1);
    }

    public function resolveStoredHistoricalTotals(
        array|object $quote,
        string $pricingRuleVersion,
        int $complexityRating = 1,
    ): array {
        $rule = $this->normalizeRule($pricingRuleVersion);
        if (! $this->isHistoricalRule($rule)) {
            throw new \InvalidArgumentException('Stored IH totals are only valid for historical pricing rules.');
        }

        $data = (array) $quote;
        $discount = round(max(0, (float) ($data['discount'] ?? 0)), 2);
        $storedSubtotal = round(max(0, (float) ($data['sub_total'] ?? 0)), 2);
        $sstAmount = round(max(0, (float) ($data['sst_amount'] ?? 0)), 2);
        $grandTotal = round(max(0, (float) ($data['grand_total'] ?? 0)), 2);
        $taxableFromGrandTotal = round(max(0, $grandTotal - $sstAmount), 2);
        $netConventionDifference = abs($storedSubtotal - $taxableFromGrandTotal);
        $grossConventionTaxable = round(max(0, $storedSubtotal - $discount), 2);
        $grossConventionDifference = abs($grossConventionTaxable - $taxableFromGrandTotal);
        $usesLegacyGrossSubtotal = $rule === self::LEGACY_RULE
            && $discount > 0
            && $grossConventionDifference <= self::HISTORICAL_TOTAL_TOLERANCE
            && $grossConventionDifference < $netConventionDifference;
        $grossSubtotal = $usesLegacyGrossSubtotal
            ? $storedSubtotal
            : round($storedSubtotal + $discount, 2);
        $taxableTotal = round(max(0, $grossSubtotal - $discount), 2);
        $normalizedComplexity = $rule === self::LEGACY_RULE
            ? max(1, min(5, $complexityRating))
            : 1;

        return [
            'pricing_rule_version' => $rule,
            'complexity_rating' => $normalizedComplexity,
            'complexity_multiplier' => $rule === self::LEGACY_RULE
                ? $this->multiplierFor($normalizedComplexity)
                : 1.0,
            'service_total' => round(max(0, $grossSubtotal - (float) ($data['travel_charge'] ?? 0)), 2),
            'additional_fees_total' => 0.0,
            'gross_subtotal' => $grossSubtotal,
            'taxable_total' => $taxableTotal,
            'discount' => $discount,
            'sst_percent' => max(0, (float) ($data['sst_percent'] ?? 0)),
            'sst_amount' => $sstAmount,
            'sub_total' => $storedSubtotal,
            'grand_total' => $grandTotal,
            'subtotal_convention' => $usesLegacyGrossSubtotal
                ? 'gross-before-discount'
                : 'net-after-discount',
            'is_reconciled' => ($usesLegacyGrossSubtotal
                ? $grossConventionDifference
                : $netConventionDifference) <= self::HISTORICAL_TOTAL_TOLERANCE,
        ];
    }
}
