<?php

namespace App\Services\Assistant\CompanyAnalytics;

use App\Services\Assistant\AssistantText;

class AssistantCompanyAnalyticsIntentRouter
{
    public function __construct(
        private readonly AssistantText $text,
        private readonly AssistantCompanyAnalyticsDateRangeResolver $dateRanges,
        private readonly AssistantCompanyAnalyticsMetricCatalog $catalog,
    ) {}

    public function resolve(string $question, string $currentRoute = ''): AssistantCompanyAnalyticsIntent
    {
        $normalized = $this->text->normalizeAssistantQueryTerms($question);
        $dateRange = $this->dateRanges->resolve($question);

        if ($this->isScopedRecordListQuestion($normalized)) {
            return new AssistantCompanyAnalyticsIntent(null, $this->metric($normalized), $dateRange);
        }

        if ($this->isRestrictedPeopleAnalytics($normalized)) {
            return new AssistantCompanyAnalyticsIntent(
                null,
                $this->metric($normalized),
                $dateRange,
                supported: true,
                denied: true,
                denialReason: 'restricted_people_analytics',
            );
        }

        if ($this->isHowToOrAction($normalized) || $this->isDetailRecordQuestion($normalized, $currentRoute)) {
            return new AssistantCompanyAnalyticsIntent(null, $this->metric($normalized), $dateRange);
        }

        if ($this->isSelfScoped($normalized) && ! $this->hasCompanyScopeOverride($normalized)) {
            return new AssistantCompanyAnalyticsIntent(null, $this->metric($normalized), $dateRange);
        }

        if ($this->isBroadCommercialSummary($normalized)) {
            return new AssistantCompanyAnalyticsIntent(
                'commercial',
                $this->metric($normalized),
                $dateRange,
                supported: true,
                aggregate: true,
                aggregateDomains: $this->summaryDomains($normalized),
            );
        }

        $domain = $this->domain($normalized, $currentRoute);
        $entry = $this->catalog->entry($domain);
        if ($entry === null || ! $this->hasCommercialQuestionShape($normalized, $entry)) {
            return new AssistantCompanyAnalyticsIntent($domain, $this->metric($normalized), $dateRange, catalogEntry: $entry);
        }

        return new AssistantCompanyAnalyticsIntent(
            $domain,
            $this->metric($normalized),
            $dateRange,
            supported: true,
            catalogEntry: $entry,
        );
    }

    private function domain(string $question, string $currentRoute): ?string
    {
        if (preg_match('/\b(client|clients|customer|customers|pelanggan)\b/i', $question)
            && preg_match('/\b(contributes|contribution|received|collected|outstanding|sales|profit|margin|roi|payment)\b/i', $question)
            && ! $this->isSpecificRecordListQuestion($question)
        ) {
            return 'clients';
        }
        if (preg_match('/\b(unpaid|outstanding|overdue|aging|ageing|receivable|receivables|debtor|debtors|belum bayar|tertunggak|bayaran diterima|kutipan)\b/i', $question)
            && preg_match('/\b(by client|by status|by month|aging|ageing|summary|total|how many|how much|top|most|breakdown|trend|company|overall)\b/i', $question)
        ) {
            return 'receivables';
        }

        foreach ($this->catalog->entries() as $domain => $entry) {
            foreach ((array) ($entry['synonyms'] ?? []) as $term) {
                if ($this->containsTerm($question, (string) $term)) {
                    return $domain;
                }
            }
        }

        $route = strtolower($currentRoute);

        return match (true) {
            str_contains($route, '/dashboard/sales') => 'sales',
            str_contains($route, '/crm/quotes') => 'quotes',
            str_contains($route, '/crm/inquir') => 'crm_inquiries',
            str_contains($route, '/client') => 'clients',
            str_contains($route, '/project') => 'projects',
            str_contains($route, '/invoice') => 'invoices',
            str_contains($route, '/debtor') => 'receivables',
            str_contains($route, '/vendor') => 'vendor_costs',
            default => null,
        };
    }

    private function metric(string $question): string
    {
        return match (true) {
            preg_match('/\b(top|most|highest|best|ranking|rank)\b/i', $question) === 1 => 'top',
            preg_match('/\b(outstanding|unpaid|overdue|aging|ageing|receivable|debtor|belum bayar|tertunggak)\b/i', $question) === 1 => 'aging',
            preg_match('/\b(conversion|win rate|converted)\b/i', $question) === 1 => 'conversion',
            preg_match('/\b(profit|margin|roi)\b/i', $question) === 1 => 'profitability',
            preg_match('/\b(pipeline|open|stuck)\b/i', $question) === 1 => 'pipeline',
            preg_match('/\b(compare|comparison|versus|vs)\b/i', $question) === 1 => 'comparison',
            preg_match('/\b(trend|monthly|by month)\b/i', $question) === 1 => 'trend',
            preg_match('/\b(status|breakdown|by client|by service|by staff|by source|by status)\b/i', $question) === 1 => 'breakdown',
            preg_match('/\b(how many|count|berapa)\b/i', $question) === 1 => 'count',
            preg_match('/\b(sum|total|value|amount|sales|revenue|billed|received|collected|kutipan|bayaran diterima)\b/i', $question) === 1 => 'sum',
            default => 'summary',
        };
    }

    private function isRestrictedPeopleAnalytics(string $question): bool
    {
        return (bool) preg_match('/\b(kpi|appraisal|salary|gaji|payslip|pay slip|leave|cuti|attendance|claim|personal file|personnel|staff profile|tenure)\b/i', $question)
            && (bool) preg_match('/\b(compare|ranking|rank|most|highest|lowest|who|which staff|person|staff|employee|team|all staff|everyone|ali|person a)\b/i', $question);
    }

    private function isHowToOrAction(string $question): bool
    {
        return (bool) preg_match('/\b(how do i|how to|guide|steps|create|make|issue|submit|approve|reject|update|delete|edit|change|generate)\b/i', $question)
            && ! (bool) preg_match('/\b(how many|how much|summary|trend|top|most|highest|outstanding|unpaid|sales)\b/i', $question);
    }

    private function isDetailRecordQuestion(string $question, string $currentRoute): bool
    {
        $hasRef = (bool) preg_match('/\b(inv|invoice|q|qt|quote|do|jd14|po|prj|project)[-_\/]?[a-z0-9]*\d{1,}\b/i', $question);
        $detailRoute = (bool) preg_match('/\/(show|edit|detail|view)\/?\d*|[?&]id=\d+/i', $currentRoute);

        return ($hasRef || $detailRoute)
            && ! (bool) preg_match('/\b(top|summary|trend|total|all|company|overall|ranking|breakdown|by month|by client|by status)\b/i', $question);
    }

    private function isSelfScoped(string $question): bool
    {
        return (bool) preg_match('/\b(i|my|mine|me|saya|aku|sendiri|own|personal)\b/i', $question);
    }

    private function hasCompanyScopeOverride(string $question): bool
    {
        return (bool) preg_match('/\b(company|overall|all company|whole company|business|commercial|everyone|all staff|team overall)\b/i', $question);
    }

    private function isBroadCommercialSummary(string $question): bool
    {
        return (bool) preg_match('/\b(commercial summary|business summary|company summary|how is sales|sales summary|business overview|commercial overview|what is stuck|stuck in commercial cycle)\b/i', $question);
    }

    private function summaryDomains(string $question): array
    {
        if (preg_match('/\b(stuck|blocked|delay|overdue)\b/i', $question)) {
            return ['quotes', 'projects', 'receivables'];
        }

        return ['sales', 'quotes', 'invoices', 'receivables', 'projects'];
    }

    private function hasCommercialQuestionShape(string $question, array $entry): bool
    {
        $domain = (string) ($entry['domain'] ?? '');
        if ($this->isSpecificRecordListQuestion($question)) {
            return false;
        }
        if ($domain === 'clients'
            && ! preg_match('/\b(contributes|contribution|sales|received|collected|outstanding|profit|margin|roi|payment|invoice|billed)\b/i', $question)
        ) {
            return false;
        }
        if (in_array($domain, ['invoices', 'receivables', 'debtors'], true)
            && preg_match('/\b(unpaid|outstanding|overdue|belum bayar|tertunggak)\b/i', $question)
            && ! preg_match('/\b(by client|by status|by month|aging|ageing|summary|total|how many|how much|top|most|breakdown|trend|company|overall)\b/i', $question)
        ) {
            return false;
        }

        if ((bool) preg_match('/\b(top|most|highest|which|who|how many|how much|total|summary|trend|breakdown|by client|by staff|by service|by status|unpaid|outstanding|overdue|aging|conversion|win rate|pipeline|profit|margin|roi|received|collected|billed|belum bayar|tertunggak|kutipan|bayaran diterima)\b/i', $question)) {
            return true;
        }

        foreach ((array) ($entry['synonyms'] ?? []) as $term) {
            if ($this->containsTerm($question, (string) $term) && $this->hasCompanyScopeOverride($question)) {
                return true;
            }
        }

        return false;
    }

    private function isScopedRecordListQuestion(string $question): bool
    {
        return (bool) preg_match('/\b(all staff|everyone|team|normal staff|manager)\b/i', $question)
            && (bool) preg_match('/\b(show|list|records|record|tasks|leave records|task records)\b/i', $question)
            && ! (bool) preg_match('/\b(who|most|highest|lowest|compare|comparison|how many|berapa|total|ranking|rank)\b/i', $question);
    }

    private function isSpecificRecordListQuestion(string $question): bool
    {
        return (bool) preg_match('/\b(show|list|find|tunjuk)\b/i', $question)
            && (bool) preg_match('/\b(for|of|untuk)\s+[a-z0-9 .&\'-]*(client|sdn|bhd|debtor|invoice)\b/i', $question)
            && ! (bool) preg_match('/\b(by client|by status|summary|total|how many|how much|top|most|ranking|trend|overall|company)\b/i', $question);
    }

    private function containsTerm(string $question, string $term): bool
    {
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        return (bool) preg_match('/\b'.preg_quote($term, '/').'\b/i', $question);
    }
}
