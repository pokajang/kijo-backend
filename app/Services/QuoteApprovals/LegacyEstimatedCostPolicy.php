<?php

namespace App\Services\QuoteApprovals;

use Carbon\CarbonImmutable;

class LegacyEstimatedCostPolicy
{
    public const SERVICES = ['training', 'equipment', 'manpower'];

    public function supports(string $service): bool
    {
        return in_array(strtolower($service), self::SERVICES, true);
    }

    public function currentRuleVersion(string $service): string
    {
        $service = strtolower($service);

        return (string) config(
            "quote_approval.rule_versions.{$service}",
            config('quote_approval.rule_version'),
        );
    }

    public function hasMissingCost(object $quote): bool
    {
        $cost = $quote->estimated_total_cost ?? null;

        return $cost === null || (float) $cost <= 0;
    }

    public function isGrandfathered(string $service, object $quote): bool
    {
        $service = strtolower($service);
        if (! $this->supports($service) || ! $this->hasMissingCost($quote)) {
            return false;
        }

        if (trim((string) ($quote->traffic_light_rule_version ?? '')) !== '') {
            return false;
        }

        $createdAt = $quote->created_at ?? null;
        $cutoff = config("quote_approval.legacy_cost_policy.{$service}.cutoff");
        if (! $createdAt || ! $cutoff) {
            return false;
        }

        $timezone = (string) config('app.timezone', 'UTC');

        return CarbonImmutable::parse($createdAt, $timezone)
            ->lt(CarbonImmutable::parse((string) $cutoff, $timezone));
    }

    public function historicalReason(string $service): string
    {
        return 'Historical '.match (strtolower($service)) {
            'equipment' => 'Equipment',
            'manpower' => 'Manpower',
            default => 'Training',
        }.' quotation retains its original approval basis.';
    }
}
