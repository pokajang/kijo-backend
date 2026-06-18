<?php

namespace App\Services\Assistant\CompanyAnalytics;

use Carbon\Carbon;

class CompanyAnalyticsAnswerFormatter
{
    /**
     * @param CompanyAnalyticsResult[] $results
     */
    public function format(array $results, AssistantCompanyAnalyticsIntent $intent, array $sourceSlugs, string $question): array
    {
        $lines = [];
        $blocks = [];

        if ($intent->aggregate) {
            $lines[] = 'Here is the company commercial summary from safe Kijo commercial records.';
        }

        foreach ($results as $index => $result) {
            if ($index > 0) {
                $lines[] = '---';
            }
            $lines[] = $this->summaryLine($result);
            $lines[] = 'Scope: company commercial records.';
            $lines[] = 'Date range: '.$this->rangeLabel($result->dateRange).'.';
            $lines[] = 'Definition: '.$result->definition;
            $lines[] = 'Data freshness: Live Kijo data.';

            if ($result->totals !== []) {
                $lines[] = 'Totals:'.$this->summaryBullets($result->totals, $result->metricKey);
            }
            if ($result->breakdowns !== []) {
                $lines[] = 'Quick breakdown:'.$this->breakdownBullets($result);
            }
            if ($result->missingFields !== []) {
                $lines[] = 'Partial data note: these tables or safe fields were unavailable: '.implode(', ', $result->missingFields).'.';
            }
            if ($result->nextDrilldowns !== []) {
                $lines[] = 'You can ask next: '.implode('; ', array_slice($result->nextDrilldowns, 0, 4)).'.';
            }

            array_push($blocks, ...$this->displayBlocks($result));
        }

        return [
            'answer_markdown' => implode("\n\n", array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''))),
            'confidence' => $this->confidence($results),
            'source_slugs' => $sourceSlugs,
            'suggested_queries' => array_slice($this->mergedNextDrilldowns($results), 0, 3),
            'freshness_label' => 'Live Kijo data',
            'answer_mode' => 'live',
            'route_refs' => [],
            'context_quality' => $this->contextQuality($results),
            'provider_key' => 'company_analytics',
            'supported_intent' => $intent->aggregate ? 'company_analytics_summary' : 'company_analytics',
            'resolved_entity_ids' => [],
            'missing_fields' => $this->mergedMissingFields($results),
            'display_blocks' => $blocks,
        ];
    }

    private function summaryLine(CompanyAnalyticsResult $result): string
    {
        $range = $this->rangeLabel($result->dateRange);

        return match ($result->metricKey) {
            'company_analytics.sales' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['sales_count'] ?? 0).' sales with total awarded value '.$this->formatMoney($result->totals['sales_value'] ?? 0).'.',
            'company_analytics.quotes' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['quote_count'] ?? 0).' quotations with quoted value '.$this->formatMoney($result->totals['quoted_value'] ?? 0).'.',
            'company_analytics.clients' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['client_count'] ?? 0).' contributing clients.',
            'company_analytics.projects' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['project_count'] ?? 0).' projects with total value '.$this->formatMoney($result->totals['project_value'] ?? 0).'.',
            'company_analytics.invoices' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['invoice_count'] ?? 0).' invoices with billed value '.$this->formatMoney($result->totals['billed_value'] ?? 0).'.',
            'company_analytics.receivables' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['unpaid_count'] ?? 0).' unpaid receivable records with outstanding value '.$this->formatMoney($result->totals['outstanding_value'] ?? 0).'.',
            'company_analytics.crm_inquiries' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['inquiry_count'] ?? 0).' CRM inquiries.',
            'company_analytics.vendor_costs' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['cost_record_count'] ?? 0).' vendor cost records with value '.$this->formatMoney($result->totals['cost_value'] ?? 0).'.',
            'company_analytics.commercial_documents' => 'For company commercial records, from '.$range.', I found '.$this->formatCount($result->totals['document_count'] ?? 0).' commercial document records.',
            default => $this->formatDatesInText($result->summary),
        };
    }

    private function summaryBullets(array $payload, string $metricKey): string
    {
        $lines = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || $this->hideKey((string) $key)) {
                continue;
            }
            $lines[] = '- '.$this->label((string) $key).': '.$this->formatValue($value, (string) $key);
        }

        return $lines === [] ? ' none.' : "\n".implode("\n", $lines);
    }

    private function breakdownBullets(CompanyAnalyticsResult $result): string
    {
        $lines = [];
        foreach ($result->breakdowns as $key => $value) {
            if (! is_array($value) || $value === []) {
                continue;
            }
            if (array_is_list($value)) {
                $parts = [];
                foreach (array_slice($value, 0, 5) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? $item['client'] ?? 'Item');
                    $amountKey = $this->firstMetricKey($item);
                    $parts[] = $name.($amountKey ? ': '.$this->formatValue($item[$amountKey], $amountKey) : '');
                }
                if ($parts !== []) {
                    $lines[] = '- '.$this->label((string) $key).': '.implode(', ', $parts).(count($value) > 5 ? ' and '.(count($value) - 5).' more' : '').'.';
                }
                continue;
            }
            $parts = [];
            foreach (array_slice($value, 0, 5, true) as $label => $amount) {
                $parts[] = $this->formatBreakdownLabel((string) $label).': '.$this->formatValue($amount, $this->breakdownValueKey($result, (string) $key));
            }
            if ($parts !== []) {
                $lines[] = '- '.$this->label((string) $key).': '.implode(', ', $parts).(count($value) > 5 ? ' and '.(count($value) - 5).' more' : '').'.';
            }
        }

        return $lines === [] ? ' none.' : "\n".implode("\n", $lines);
    }

    private function displayBlocks(CompanyAnalyticsResult $result): array
    {
        $blocks = [];
        $cards = $this->metricCards($result);
        if ($cards !== []) {
            $blocks[] = ['type' => 'metric_cards', 'title' => 'Totals', 'items' => $cards];
        }
        foreach ($this->tables($result) as $table) {
            $blocks[] = $table;
        }
        if (isset($result->breakdowns['by_month']) && is_array($result->breakdowns['by_month']) && $result->breakdowns['by_month'] !== []) {
            $isMoney = $this->monthBreakdownIsMoney($result);
            $blocks[] = [
                'type' => 'bar_chart',
                'title' => 'By month',
                'labels' => array_map(fn (string $label): string => $this->formatMonth($label), array_keys($result->breakdowns['by_month'])),
                'values' => array_values(array_map(static fn (mixed $value): float => (float) $value, $result->breakdowns['by_month'])),
                'display_values' => array_values(array_map(fn (mixed $value): string => $isMoney ? $this->formatMoney($value) : $this->formatCount($value), $result->breakdowns['by_month'])),
                'value_format' => $isMoney ? 'money' : 'count',
            ];
        }
        if ($result->missingFields !== []) {
            $blocks[] = [
                'type' => 'note',
                'tone' => 'warning',
                'content' => 'Partial data: '.implode(', ', $result->missingFields).' unavailable.',
            ];
        }

        return $blocks;
    }

    private function metricCards(CompanyAnalyticsResult $result): array
    {
        $keys = match ($result->metricKey) {
            'company_analytics.sales' => ['sales_count', 'sales_value'],
            'company_analytics.quotes' => ['quote_count', 'quoted_value', 'awarded_count', 'awarded_value', 'win_rate'],
            'company_analytics.clients' => array_keys($result->totals),
            'company_analytics.projects' => ['project_count', 'project_value', 'active_count', 'closed_count'],
            'company_analytics.invoices' => ['invoice_count', 'billed_value', 'received_value', 'outstanding_value'],
            'company_analytics.receivables' => ['unpaid_count', 'outstanding_value', 'received_value'],
            default => array_keys($result->totals),
        };

        $cards = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $result->totals) || $this->hideKey((string) $key)) {
                continue;
            }
            $cards[] = [
                'label' => $this->label((string) $key),
                'value' => $this->formatValue($result->totals[$key], (string) $key),
            ];
        }

        return $cards;
    }

    private function tables(CompanyAnalyticsResult $result): array
    {
        $tables = [];
        foreach ($result->breakdowns as $key => $value) {
            if ($key === 'by_month' || ! is_array($value) || $value === []) {
                continue;
            }
            if (array_is_list($value)) {
                $metricKeys = $this->tableMetricKeys($value);
                $rows = [];
                foreach (array_slice($value, 0, 10) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $row = [(string) ($item['name'] ?? 'Item')];
                    foreach ($metricKeys as $metricKey) {
                        $row[] = $this->formatValue($item[$metricKey] ?? null, $metricKey);
                    }
                    $rows[] = $row;
                }
                if ($rows !== []) {
                    $tables[] = [
                        'type' => 'table',
                        'title' => $this->label((string) $key),
                        'columns' => array_merge(['Name'], array_map(fn (string $metricKey): string => $this->label($metricKey), $metricKeys)),
                        'rows' => $rows,
                    ];
                }
                continue;
            }

            $rows = [];
            foreach (array_slice($value, 0, 10, true) as $label => $amount) {
                $rows[] = [$this->formatBreakdownLabel((string) $label), $this->formatValue($amount, $this->breakdownValueKey($result, (string) $key))];
            }
            if ($rows !== []) {
                $tables[] = [
                    'type' => 'table',
                    'title' => $this->label((string) $key),
                    'columns' => ['Category', 'Value'],
                    'rows' => $rows,
                ];
            }
        }

        return $tables;
    }

    private function tableMetricKeys(array $items): array
    {
        $keys = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (array_keys($item) as $key) {
                if ($key !== 'name') {
                    $keys[] = (string) $key;
                }
            }
        }

        return array_values(array_slice(array_unique($keys), 0, 3));
    }

    private function firstMetricKey(array $item): ?string
    {
        foreach (array_keys($item) as $key) {
            if ($key !== 'name') {
                return (string) $key;
            }
        }

        return null;
    }

    private function label(string $key): string
    {
        $labels = [
            'sales_count' => 'Sales count',
            'sales_value' => 'Sales value',
            'quote_count' => 'Quotation count',
            'quoted_value' => 'Quoted value',
            'awarded_count' => 'Awarded count',
            'awarded_value' => 'Awarded value',
            'failed_count' => 'Failed count',
            'open_count' => 'Open count',
            'win_rate' => 'Win rate',
            'client_count' => 'Client count',
            'received_value' => 'Received value',
            'outstanding_value' => 'Outstanding value',
            'project_count' => 'Project count',
            'project_value' => 'Project value',
            'active_count' => 'Active count',
            'closed_count' => 'Closed count',
            'invoice_count' => 'Invoice count',
            'billed_value' => 'Billed value',
            'record_count' => 'Record count',
            'unpaid_count' => 'Unpaid count',
            'inquiry_count' => 'Inquiry count',
            'linked_quote_count' => 'Linked quote count',
            'stale_open_count' => 'Stale open count',
            'cost_record_count' => 'Cost record count',
            'cost_value' => 'Cost value',
            'paid_value' => 'Paid value',
            'document_count' => 'Document count',
            'by_status' => 'By status',
            'by_service' => 'By service',
            'by_staff' => 'By staff',
            'top_staff' => 'Top staff',
            'top_clients' => 'Top clients',
            'by_client' => 'By client',
            'by_source' => 'By source',
            'by_owner' => 'By owner',
            'by_vendor' => 'By vendor',
            'by_project' => 'By project',
            'by_document_type' => 'By document type',
            'aging_buckets' => 'Aging buckets',
            'by_month' => 'By month',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    private function formatValue(mixed $value, string $key): string
    {
        if ($value === null || $value === '') {
            return 'not recorded';
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $this->formatDate($value) ?: $value;
        }
        if (str_contains($key, 'count')) {
            return $this->formatCount($value);
        }
        if ($this->isMoneyKey($key)) {
            return $this->formatMoney($value);
        }
        if (str_contains($key, 'rate') || str_contains($key, 'percent')) {
            return number_format((float) $value, 2, '.', ',').'%';
        }
        if (is_numeric($value)) {
            $numeric = (float) $value;

            return fmod($numeric, 1.0) === 0.0
                ? number_format($numeric, 0, '.', ',')
                : number_format($numeric, 2, '.', ',');
        }

        return trim((string) $value);
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }

    private function formatCount(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }

    private function rangeLabel(array $dateRange): string
    {
        if (($dateRange['is_all_time'] ?? false) === true) {
            return 'all time';
        }

        return ($this->formatDate($dateRange['start'] ?? null) ?: (string) ($dateRange['start'] ?? ''))
            .' to '.
            ($this->formatDate($dateRange['end'] ?? null) ?: (string) ($dateRange['end'] ?? ''));
    }

    private function formatDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(substr($value, 0, 10))->format('j M Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatMonth(string $value): string
    {
        if ($value === 'Not recorded') {
            return $value;
        }

        try {
            return Carbon::parse($value.'-01')->format('M Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatBreakdownLabel(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $this->formatMonth($value);
        }

        return $value === 'unknown' ? 'Not recorded' : $value;
    }

    private function isMoneyKey(string $key): bool
    {
        return str_contains($key, 'value')
            || str_contains($key, 'amount')
            || str_contains($key, 'cost')
            || str_contains($key, 'outstanding')
            || str_contains($key, 'billed')
            || str_contains($key, 'received')
            || str_contains($key, 'paid')
            || $key === 'money_value';
    }

    private function monthBreakdownIsMoney(CompanyAnalyticsResult $result): bool
    {
        return ! in_array($result->metricKey, ['company_analytics.quotes', 'company_analytics.projects', 'company_analytics.crm_inquiries', 'company_analytics.commercial_documents'], true);
    }

    private function breakdownValueKey(CompanyAnalyticsResult $result, string $breakdownKey): string
    {
        if ($breakdownKey === 'aging_buckets') {
            return 'money_value';
        }
        if ($breakdownKey === 'by_month') {
            return $this->monthBreakdownIsMoney($result) ? 'money_value' : 'count';
        }
        if (in_array($breakdownKey, ['by_client', 'by_service'], true)) {
            return in_array($result->metricKey, [
                'company_analytics.sales',
                'company_analytics.quotes',
                'company_analytics.clients',
                'company_analytics.projects',
                'company_analytics.invoices',
                'company_analytics.receivables',
            ], true) ? 'money_value' : 'count';
        }
        if (in_array($breakdownKey, ['by_vendor', 'by_project'], true)) {
            return $result->metricKey === 'company_analytics.vendor_costs' ? 'money_value' : 'count';
        }

        return 'count';
    }

    private function hideKey(string $key): bool
    {
        return in_array($key, ['table', 'id', 'ref'], true);
    }

    private function confidence(array $results): string
    {
        if ($results === []) {
            return 'low';
        }
        if (in_array('low', array_map(static fn (CompanyAnalyticsResult $result): string => $result->confidence, $results), true)) {
            return 'low';
        }
        if (in_array('medium', array_map(static fn (CompanyAnalyticsResult $result): string => $result->confidence, $results), true)) {
            return 'medium';
        }

        return 'high';
    }

    private function contextQuality(array $results): string
    {
        foreach ($results as $result) {
            if ($result->missingFields !== []) {
                return 'partial';
            }
        }

        return 'complete';
    }

    private function mergedNextDrilldowns(array $results): array
    {
        $queries = [];
        foreach ($results as $result) {
            foreach ($result->nextDrilldowns as $query) {
                $queries[] = $query;
            }
        }

        return array_values(array_unique($queries));
    }

    private function mergedMissingFields(array $results): array
    {
        $fields = [];
        foreach ($results as $result) {
            foreach ($result->missingFields as $field) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    private function formatDatesInText(string $text): string
    {
        return preg_replace_callback('/\b(20\d{2}-\d{2}-\d{2})\b/', fn (array $match): string => $this->formatDate($match[1]) ?: $match[1], $text) ?? $text;
    }
}
