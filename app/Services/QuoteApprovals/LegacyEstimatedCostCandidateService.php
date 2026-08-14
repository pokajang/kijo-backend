<?php

namespace App\Services\QuoteApprovals;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyEstimatedCostCandidateService
{
    private const TABLES = [
        'training' => 'quotes_training',
        'equipment' => 'quotes_equipment',
        'manpower' => 'quotes_manpower',
    ];

    public function __construct(
        private LegacyEstimatedCostPolicy $policy,
        private QuoteApprovalService $approvals,
    ) {}

    public function collect(string $service, ?int $quoteId = null): Collection
    {
        $service = strtolower($service);
        $table = self::TABLES[$service] ?? null;
        if (
            ! $table
            || ! Schema::hasTable($table)
            || ! Schema::hasTable('quote_approval_requests')
            || ! Schema::hasColumn($table, 'traffic_light_rule_version')
            || ! Schema::hasColumn($table, 'estimated_total_cost')
        ) {
            return collect();
        }

        $query = DB::table($table)
            ->whereRaw('LOWER(status) IN (?, ?)', ['open', 'pending'])
            ->orderBy('id');
        if ($quoteId !== null) {
            $query->where('id', $quoteId);
        }

        return $query->get()
            ->filter(fn (object $quote): bool => $this->policy->isGrandfathered($service, $quote))
            ->map(function (object $quote) use ($service): array {
                $current = DB::table('quote_approval_requests')
                    ->where('service', $service)
                    ->where('quote_id', $quote->id)
                    ->where('is_current', true)
                    ->latest('id')
                    ->first();

                return [
                    'service' => $service,
                    'quote' => $quote,
                    'context' => $this->approvals->contextForQuote($service, $quote),
                    'preview' => $this->approvals->previewCurrent($service, $quote, $current),
                    'current' => $current,
                ];
            })
            ->values();
    }

    public function fingerprint(Collection $candidates): string
    {
        $manifest = $candidates->map(function (array $candidate): array {
            $quote = $candidate['quote'];
            $context = $candidate['context'];
            $preview = $candidate['preview'];
            $current = $candidate['current'];

            return [
                'service' => $candidate['service'],
                'id' => (int) $quote->id,
                'reference' => (string) ($quote->quote_ref_no ?? ''),
                'status' => strtolower((string) ($quote->status ?? '')),
                'created_at' => (string) ($quote->created_at ?? ''),
                'estimated_total_cost' => $quote->estimated_total_cost ?? null,
                'stored_rule_version' => $quote->traffic_light_rule_version ?? null,
                'policy_zone' => $context['policy_zone'],
                'required_step' => $context['required_step'],
                'reasons' => $context['reasons'],
                'expected_status' => $preview['status'],
                'carries_existing_decision' => $preview['carries_existing_decision'],
                'current_approval_id' => $current?->id,
                'current_approval_version' => $current?->rule_version,
                'current_approval_fingerprint' => $current?->commercial_fingerprint,
            ];
        })->all();

        return hash('sha256', json_encode($manifest, JSON_PRESERVE_ZERO_FRACTION));
    }

    public function rows(Collection $candidates): array
    {
        return $candidates->map(function (array $candidate): array {
            $quote = $candidate['quote'];
            $context = $candidate['context'];
            $preview = $candidate['preview'];
            $current = $candidate['current'];

            return [
                strtoupper($candidate['service']),
                $quote->id,
                $quote->quote_ref_no ?: '-',
                $current ? "{$current->zone}/{$current->status}" : 'none',
                "{$context['policy_zone']}/{$preview['status']}",
                strtoupper((string) ($context['required_step'] ?? '-')),
                implode('; ', $context['reasons']),
            ];
        })->all();
    }
}
