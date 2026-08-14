<?php

namespace App\Console\Commands;

use App\Services\QuoteApprovals\QuoteApprovalService;
use App\Services\QuoteApprovals\TrainingQuoteLegacyPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileLegacyTrainingApprovals extends Command
{
    protected $signature = 'quotes:reconcile-legacy-training-approvals
        {--dry-run : Preview candidates without changing approvals or notifications}
        {--quote-id= : Limit reconciliation to one Training quote ID}';

    protected $description = 'Reevaluate open grandfathered Training quotations without deleting approval history';

    public function handle(
        QuoteApprovalService $approvals,
        TrainingQuoteLegacyPolicy $legacyPolicy,
    ): int {
        if (
            ! Schema::hasTable('quotes_training')
            || ! Schema::hasTable('quote_approval_requests')
            || ! Schema::hasColumn('quotes_training', 'traffic_light_rule_version')
            || ! Schema::hasColumn('quotes_training', 'estimated_total_cost')
        ) {
            $this->error('The Training traffic-light approval migrations have not been applied.');

            return self::FAILURE;
        }

        $rawQuoteId = $this->option('quote-id');
        $quoteId = (int) ($rawQuoteId ?: 0);
        if ($rawQuoteId !== null && $quoteId <= 0) {
            $this->error('--quote-id must be a positive integer.');

            return self::FAILURE;
        }

        $query = DB::table('quotes_training')
            ->whereRaw('LOWER(status) IN (?, ?)', ['open', 'pending'])
            ->orderBy('id');
        if ($quoteId > 0) {
            $query->where('id', $quoteId);
        }

        $quotes = $query->get()->filter(
            fn (object $quote): bool => $legacyPolicy->isGrandfathered($quote),
        );
        $rows = [];

        foreach ($quotes as $quote) {
            $before = DB::table('quote_approval_requests')
                ->where('service', 'training')
                ->where('quote_id', $quote->id)
                ->where('is_current', true)
                ->latest('id')
                ->first();
            $context = $approvals->contextForQuote('training', $quote);
            $after = $this->option('dry-run')
                ? null
                : $approvals->current('training', (int) $quote->id, true);

            $rows[] = [
                $quote->id,
                $quote->quote_ref_no ?: '-',
                $before ? "{$before->zone}/{$before->status}" : 'none',
                $after ? "{$after->zone}/{$after->status}" : "{$context['policy_zone']}/preview",
                strtoupper((string) ($after->required_step ?? $context['required_step'] ?? '-')),
            ];
        }

        $this->table(['ID', 'Reference', 'Before', 'After', 'Step'], $rows);
        $this->info(sprintf(
            '%s %d grandfathered Training quotation(s).',
            $this->option('dry-run') ? 'Previewed' : 'Reconciled',
            $quotes->count(),
        ));

        return self::SUCCESS;
    }
}
