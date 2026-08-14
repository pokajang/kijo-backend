<?php

namespace App\Console\Commands;

use App\Services\QuoteApprovals\LegacyEstimatedCostCandidateService;
use App\Services\QuoteApprovals\LegacyEstimatedCostPolicy;
use App\Services\QuoteApprovals\QuoteApprovalService;
use Illuminate\Console\Command;

class ReconcileLegacyEstimatedCostApprovals extends Command
{
    protected $signature = 'quotes:reconcile-legacy-cost-approvals
        {--service= : Required: training, equipment, or manpower}
        {--quote-id= : Limit reconciliation to one quotation ID}
        {--commit : Apply changes; without this flag the command is read-only}
        {--confirm= : Fresh audit fingerprint required with --commit}';

    protected $description = 'Safely reconcile legacy estimated-cost approvals without deleting audit history';

    public function handle(
        LegacyEstimatedCostCandidateService $candidates,
        QuoteApprovalService $approvals,
    ): int {
        $service = strtolower(trim((string) $this->option('service')));
        if (! in_array($service, LegacyEstimatedCostPolicy::SERVICES, true)) {
            $this->error('--service is required and must be training, equipment, or manpower.');

            return self::FAILURE;
        }

        $rawQuoteId = $this->option('quote-id');
        $quoteId = (int) ($rawQuoteId ?: 0);
        if ($rawQuoteId !== null && $rawQuoteId !== '' && $quoteId <= 0) {
            $this->error('--quote-id must be a positive integer.');

            return self::FAILURE;
        }

        $results = $candidates->collect($service, $quoteId ?: null);
        $fingerprint = $candidates->fingerprint($results);
        $this->table(
            ['Service', 'ID', 'Reference', 'Current', 'Expected', 'Step', 'Reasons'],
            $candidates->rows($results),
        );
        $this->info("Confirmation fingerprint: {$fingerprint}");

        if (! $this->option('commit')) {
            $this->info("Previewed {$results->count()} grandfathered quotation(s); no changes were made.");

            return self::SUCCESS;
        }

        if (! hash_equals($fingerprint, trim((string) $this->option('confirm')))) {
            $this->error('The confirmation fingerprint is missing or stale. Run a fresh preview first.');

            return self::FAILURE;
        }

        foreach ($results as $candidate) {
            $approvals->current($service, (int) $candidate['quote']->id, true);
        }

        $this->info("Reconciled {$results->count()} grandfathered quotation(s).");

        return self::SUCCESS;
    }
}
