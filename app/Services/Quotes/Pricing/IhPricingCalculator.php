<?php

namespace App\Services\Quotes\Pricing;

final class IhPricingCalculator
{
    public const LEGACY_RULE = 'ih_complexity_v1';

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
            'sub_total' => $rule === self::LEGACY_RULE ? $taxableTotal : $grossSubtotal,
            'grand_total' => round($taxableTotal + $sstAmount, 2),
        ];
    }

    public function normalizeRule(?string $value): string
    {
        return $value === self::LEGACY_RULE ? self::LEGACY_RULE : self::STANDARD_RULE;
    }

    public function multiplierFor(int $complexityRating): float
    {
        $rating = max(1, min(5, $complexityRating));

        return 1 + (($rating - 1) * 0.1);
    }
}
