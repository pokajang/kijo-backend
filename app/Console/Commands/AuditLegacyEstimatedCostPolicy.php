<?php

namespace App\Console\Commands;

use App\Services\QuoteApprovals\LegacyEstimatedCostCandidateService;
use App\Services\QuoteApprovals\LegacyEstimatedCostPolicy;
use Illuminate\Console\Command;

class AuditLegacyEstimatedCostPolicy extends Command
{
    protected $signature = 'quotes:audit-legacy-cost-policy
        {--service= : Limit the audit to training, equipment, or manpower}
        {--quote-id= : Limit the audit to one quotation ID}
        {--format=table : Output table or json}';

    protected $description = 'Read-only audit of active quotations eligible for legacy estimated-cost treatment';

    public function handle(LegacyEstimatedCostCandidateService $candidates): int
    {
        $services = $this->services();
        if ($services === null) {
            return self::FAILURE;
        }

        $quoteId = $this->quoteId();
        if ($quoteId === false) {
            return self::FAILURE;
        }

        $results = collect($services)
            ->flatMap(fn (string $service) => $candidates->collect($service, $quoteId ?: null))
            ->values();
        $fingerprint = $candidates->fingerprint($results);
        $format = strtolower((string) $this->option('format'));

        if ($format === 'json') {
            $this->line(json_encode([
                'count' => $results->count(),
                'confirmation_fingerprint' => $fingerprint,
                'candidates' => $results->map(fn (array $candidate): array => [
                    'service' => $candidate['service'],
                    'quote_id' => (int) $candidate['quote']->id,
                    'quote_ref_no' => $candidate['quote']->quote_ref_no,
                    'context' => $candidate['context'],
                    'expected_approval' => $candidate['preview'],
                    'current_approval_id' => $candidate['current']?->id,
                ])->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($format === 'table') {
            $this->table(
                ['Service', 'ID', 'Reference', 'Current', 'Expected', 'Step', 'Reasons'],
                $candidates->rows($results),
            );
            $this->info("Confirmation fingerprint: {$fingerprint}");
        } else {
            $this->error('--format must be table or json.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function services(): ?array
    {
        $service = strtolower(trim((string) $this->option('service')));
        if ($service === '') {
            return LegacyEstimatedCostPolicy::SERVICES;
        }
        if (! in_array($service, LegacyEstimatedCostPolicy::SERVICES, true)) {
            $this->error('--service must be training, equipment, or manpower.');

            return null;
        }

        return [$service];
    }

    private function quoteId(): int|false
    {
        $raw = $this->option('quote-id');
        if ($raw === null || $raw === '') {
            return 0;
        }

        $quoteId = (int) $raw;
        if ($quoteId <= 0) {
            $this->error('--quote-id must be a positive integer.');

            return false;
        }

        return $quoteId;
    }
}
