<?php

namespace App\Services\Invoices;

use App\Services\Quotes\Pricing\IhPricingCalculator;

final class IhInvoiceSeedService
{
    public function __construct(private IhPricingCalculator $pricingCalculator) {}

    public function build(object $quote, iterable $items): array
    {
        $itemRows = collect($items)->map(fn ($item): array => (array) $item)->values()->all();
        $rawRule = (string) ($quote->pricing_rule_version ?? '');
        $rule = in_array($rawRule, IhPricingCalculator::rules(), true)
            ? $rawRule
            : ($itemRows !== [] ? IhPricingCalculator::STANDARD_RULE : IhPricingCalculator::LEGACY_RULE);
        $complexity = (int) ($quote->complexity_rating ?? 1);
        $totals = $this->pricingCalculator->isHistoricalRule($rule)
            ? $this->pricingCalculator->resolveStoredHistoricalTotals($quote, $rule, $complexity)
            : $this->pricingCalculator->calculate((array) $quote, $itemRows, $rule, $complexity);
        $serviceTitle = trim((string) ($quote->service_title ?? 'Industrial Hygiene'));
        $serviceCode = trim((string) ($quote->service_code ?? ''));
        $siteAddress = trim((string) ($quote->site_address ?? ''));
        $displayTitle = $serviceTitle
            .($serviceCode !== '' ? " ({$serviceCode})" : '')
            .($siteAddress !== '' ? " at {$siteAddress}" : '');

        return [
            'pricing_rule_version' => $rule,
            'complexity_rating' => $totals['complexity_rating'],
            'service_title' => $displayTitle,
            'sample_counts' => (float) ($quote->sample_counts ?? 0),
            'sample_unit' => (string) ($quote->sample_unit ?? 'sample(s)'),
            'num_work_units' => (float) ($quote->num_work_units ?? 0),
            'unit_price' => (float) ($quote->unit_price ?? 0),
            'travel_qty' => 1,
            'travel_unit' => 'Lot',
            'travel_unit_price' => (float) ($quote->travel_charge ?? 0),
            'travel_charge' => (float) ($quote->travel_charge ?? 0),
            'discount_qty' => 1,
            'discount_unit' => 'Lot',
            'discount_unit_price' => (float) ($quote->discount ?? 0),
            'discount' => (float) ($quote->discount ?? 0),
            'hygiene_items' => array_map(fn (array $item): array => [
                'id' => $item['id'] ?? null,
                'item_description' => (string) ($item['item_description'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit' => (string) ($item['unit'] ?? 'Lot'),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'line_type' => 'custom',
                'source_line_key' => isset($item['id']) ? 'quote_ih_item:'.$item['id'] : null,
            ], $rule === IhPricingCalculator::STANDARD_RULE ? $itemRows : []),
            'hygiene_items_initialized' => true,
            'sst_percent' => (float) ($totals['sst_percent'] ?? 0),
            'sst_amount' => (float) ($totals['sst_amount'] ?? 0),
            'sub_total' => (float) $totals['gross_subtotal'],
            'grand_total' => (float) $totals['grand_total'],
            'remarks' => (string) ($quote->inquiry_remarks ?? ''),
            'source_snapshot' => [
                'quote_id' => (int) ($quote->id ?? 0),
                'pricing_rule' => $rule,
                'quote_grand_total' => (float) ($quote->grand_total ?? 0),
                'captured_at' => now()->toIso8601String(),
            ],
        ];
    }
}
