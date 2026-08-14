<?php

namespace App\Services\QuoteApprovals;

use Carbon\CarbonImmutable;

class TrainingQuoteLegacyPolicy
{
    public const HISTORICAL_REASON = 'Historical Training quotation retains its original approval basis.';

    public function currentRuleVersion(): string
    {
        return (string) config(
            'quote_approval.rule_versions.training',
            config('quote_approval.rule_version'),
        );
    }

    public function hasMissingCost(object $quote): bool
    {
        $cost = $quote->estimated_total_cost ?? null;

        return $cost === null || (float) $cost <= 0;
    }

    public function isGrandfathered(object $quote): bool
    {
        if (! $this->hasMissingCost($quote)) {
            return false;
        }

        if (trim((string) ($quote->traffic_light_rule_version ?? '')) !== '') {
            return false;
        }

        $createdAt = $quote->created_at ?? null;
        $cutoff = config('quote_approval.legacy_cutoffs.training');
        if (! $createdAt || ! $cutoff) {
            return false;
        }

        $timezone = (string) config('app.timezone', 'UTC');

        return CarbonImmutable::parse($createdAt, $timezone)
            ->lt(CarbonImmutable::parse((string) $cutoff, $timezone));
    }
}
