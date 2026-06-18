<?php

namespace App\Services\Assistant\UserTrace;

use App\Services\Assistant\AssistantText;
use Carbon\Carbon;

class AssistantTraceAnswerFormatter
{
    public function __construct(private readonly AssistantText $text) {}

    /**
     * @param AssistantUserTraceResult[] $results
     */
    public function format(array $results, AssistantUserTraceIntent $intent, array $sourceSlugs, string $question): array
    {
        $isBm = $this->text->languageHint($question) === 'bahasa_malaysia';
        $lines = [];
        $blocks = [];

        if ($intent->aggregate) {
            $lines[] = $isBm
                ? 'Ini ringkasan rekod anda sendiri berdasarkan data Kijo yang selamat untuk dipaparkan.'
                : 'Here is a summary of your own records from the safe Kijo data available.';
        }

        foreach ($results as $index => $result) {
            if ($index > 0) {
                $lines[] = '---';
            }
            $lines[] = $this->summaryLine($result, $isBm);
            $lines[] = $isBm ? 'Skop: rekod anda sendiri.' : 'Scope: your own records.';
            if (($result->dateRange['is_all_time'] ?? false) !== true && $result->metricKey !== 'user_trace.employment_tenure') {
                $lines[] = ($isBm ? 'Julat tarikh: ' : 'Date range: ').$this->rangeLabel($result->dateRange).'.';
            }
            $lines[] = ($isBm ? 'Definisi: ' : 'Definition: ').$result->definition;

            if ($result->totals !== []) {
                $lines[] = ($isBm ? 'Jumlah:' : 'Totals:').$this->summaryBullets($result->totals, $result->metricKey, $isBm);
            }
            if ($result->breakdowns !== []) {
                $lines[] = ($isBm ? 'Pecahan ringkas:' : 'Quick breakdown:').$this->breakdownBullets($result->breakdowns, $isBm);
            }
            if ($result->missingFields !== []) {
                $lines[] = ($isBm ? 'Medan selamat yang tidak tersedia: ' : 'Safe fields unavailable: ').implode(', ', $result->missingFields).'.';
            }
            if ($result->nextDrilldowns !== []) {
                $lines[] = ($isBm ? 'Anda boleh tanya seterusnya: ' : 'You can ask next: ').implode('; ', array_slice($result->nextDrilldowns, 0, 4)).'.';
            }

            array_push($blocks, ...$this->displayBlocks($result, $isBm));
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
            'provider_key' => 'user_trace',
            'supported_intent' => $intent->aggregate ? 'user_trace_summary' : 'user_trace',
            'resolved_entity_ids' => [],
            'missing_fields' => $this->mergedMissingFields($results),
            'display_blocks' => $blocks,
        ];
    }

    private function summaryLine(AssistantUserTraceResult $result, bool $isBm): string
    {
        $range = $this->rangeLabel($result->dateRange);

        return match ($result->metricKey) {
            'user_trace.quote_issued' => $isBm
                ? $this->quoteSummaryLine($result, $range, true)
                : $this->quoteSummaryLine($result, $range, false),
            'user_trace.leave_taken' => $isBm
                ? 'Anda telah mengambil '.$this->formatDays($result->totals['taken_days'] ?? 0).' cuti approved dari '.$range.'.'
                : 'You have taken '.$this->formatDays($result->totals['taken_days'] ?? 0).' of approved leave from '.$range.'.',
            'user_trace.kpi_status' => ($result->totals['record_count'] ?? 0) > 0
                ? ($isBm
                    ? 'Rekod KPI/appraisal terkini anda tersedia'.($result->totals['latest_status'] ? ' dengan status '.$this->formatValue($result->totals['latest_status'], 'latest_status') : '').'.'
                    : 'Your latest KPI/appraisal record is available'.($result->totals['latest_status'] ? ' with status '.$this->formatValue($result->totals['latest_status'], 'latest_status') : '').'.')
                : ($isBm ? 'Saya tidak jumpa rekod KPI/appraisal untuk profil anda.' : 'I found no KPI/appraisal records for your profile.'),
            'user_trace.employment_tenure' => ! empty($result->totals['tenure_label'])
                ? ($isBm
                    ? 'Anda telah bekerja di sini selama '.$this->formatValue($result->totals['tenure_label'], 'tenure_label').', berdasarkan '.$this->joinDatePhrase($result, $isBm).'.'
                    : 'You have worked here for '.$this->formatValue($result->totals['tenure_label'], 'tenure_label').', based on '.$this->joinDatePhrase($result, $isBm).'.')
                : ($isBm ? 'Saya tidak dapat kira tempoh bekerja kerana tarikh mula kerja tidak tersedia.' : 'I could not calculate your tenure because a reliable join date is not available.'),
            'user_trace.task_status' => $isBm
                ? 'Saya jumpa '.$this->formatCount($result->totals['count'] ?? 0).' task untuk anda dalam julat yang dipilih.'
                : 'I found '.$this->formatCount($result->totals['count'] ?? 0).' tasks assigned to you for the selected range.',
            default => $this->formatDatesInText($result->summary),
        };
    }

    private function quoteSummaryLine(AssistantUserTraceResult $result, string $range, bool $isBm): string
    {
        $count = (int) ($result->totals['count'] ?? 0);
        $summary = strtolower($result->summary);
        $status = match (true) {
            str_contains($summary, 'failed quotation') => 'failed',
            str_contains($summary, 'awarded quotation') => 'awarded',
            default => '',
        };

        if ($isBm) {
            return 'Untuk rekod anda sendiri, dari '.$range.', saya jumpa '.$this->formatCount($count).' quotation'
                .($status !== '' ? ' '.$status : ' dikeluarkan').'.';
        }

        $noun = $count === 1 ? 'quotation' : 'quotations';

        return 'For your own records, from '.$range.', I found '.$this->formatCount($count)
            .($status !== '' ? ' '.$status : '')
            .' '.$noun
            .($status === '' ? ' issued' : '')
            .'.';
    }

    private function joinDatePhrase(AssistantUserTraceResult $result, bool $isBm): string
    {
        $source = trim((string) ($result->totals['join_date_source'] ?? 'staff profile start date'));
        $date = $this->formatDate($result->totals['join_date'] ?? null);

        if ($isBm) {
            return 'tarikh mula profil staff'.($date ? ' pada '.$date : '');
        }

        return str_replace('_', ' ', $source).($date ? ' of '.$date : '');
    }

    private function summaryBullets(array $payload, string $metricKey, bool $isBm): string
    {
        $lines = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || $this->hideTotalKey((string) $key)) {
                continue;
            }
            $lines[] = '- '.$this->label((string) $key, $isBm).': '.$this->formatValue($value, (string) $key);
        }

        return $lines === [] ? ' none.' : "\n".implode("\n", $lines);
    }

    private function breakdownBullets(array $breakdowns, bool $isBm): string
    {
        $lines = [];
        foreach ($breakdowns as $key => $value) {
            if ($value === [] || $value === null || $value === '') {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                $lines[] = '- '.$this->label((string) $key, $isBm).': '.$this->listSummary($value).'.';
                continue;
            }
            if (is_array($value)) {
                $parts = [];
                foreach (array_slice($value, 0, 5, true) as $label => $amount) {
                    if (! is_array($amount)) {
                        $parts[] = $this->formatBreakdownLabel((string) $label).': '.$this->formatValue($amount, (string) $key);
                    }
                }
                if ($parts !== []) {
                    $more = count($value) > 5 ? ' and '.(count($value) - 5).' more' : '';
                    $lines[] = '- '.$this->label((string) $key, $isBm).': '.implode(', ', $parts).$more.'.';
                }
            }
        }

        return $lines === [] ? ' none.' : "\n".implode("\n", $lines);
    }

    private function displayBlocks(AssistantUserTraceResult $result, bool $isBm): array
    {
        $blocks = [];
        $cards = $this->metricCards($result, $isBm);
        if ($cards !== []) {
            $blocks[] = ['type' => 'metric_cards', 'title' => $isBm ? 'Jumlah' : 'Totals', 'items' => $cards];
        }
        foreach ($this->tables($result, $isBm) as $table) {
            $blocks[] = $table;
        }
        if (isset($result->breakdowns['by_month']) && is_array($result->breakdowns['by_month']) && $result->breakdowns['by_month'] !== []) {
            $blocks[] = [
                'type' => 'bar_chart',
                'title' => $isBm ? 'Mengikut bulan' : 'By month',
                'labels' => array_map(fn (string $label): string => $this->formatMonth($label), array_keys($result->breakdowns['by_month'])),
                'values' => array_values(array_map(static fn (mixed $value): float => (float) $value, $result->breakdowns['by_month'])),
                'display_values' => array_values(array_map(
                    fn (mixed $value): string => str_contains($result->metricKey, 'leave')
                        ? $this->formatDays($value)
                        : $this->formatCount($value),
                    $result->breakdowns['by_month'],
                )),
                'value_format' => str_contains($result->metricKey, 'leave') ? 'days' : 'count',
            ];
        }
        if ($result->missingFields !== []) {
            $blocks[] = [
                'type' => 'note',
                'tone' => 'warning',
                'content' => ($isBm ? 'Medan selamat yang tidak tersedia: ' : 'Safe fields unavailable: ').implode(', ', $result->missingFields).'.',
            ];
        }

        return $blocks;
    }

    private function metricCards(AssistantUserTraceResult $result, bool $isBm): array
    {
        $keys = match ($result->metricKey) {
            'user_trace.quote_issued' => ['count', 'total_value'],
            'user_trace.leave_taken' => ['taken_days', 'taken_count', 'pending_count'],
            'user_trace.kpi_status' => ['record_count', 'latest_status', 'latest_score', 'latest_period'],
            'user_trace.employment_tenure' => ['tenure_label', 'join_date', 'join_date_source'],
            'user_trace.task_status' => ['count', 'open_count'],
            default => array_keys($result->totals),
        };

        $cards = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $result->totals) || $this->hideTotalKey($key)) {
                continue;
            }
            $cards[] = [
                'label' => $this->label($key, $isBm),
                'value' => $this->formatValue($result->totals[$key], $key),
            ];
        }

        return $cards;
    }

    private function tables(AssistantUserTraceResult $result, bool $isBm): array
    {
        $tables = [];
        foreach ($result->breakdowns as $key => $value) {
            if ($key === 'by_month' || ! is_array($value) || $value === []) {
                continue;
            }
            if (array_is_list($value)) {
                $rows = [];
                foreach (array_slice($value, 0, 8) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $rows[] = [
                        (string) ($item['leave_type'] ?? $item['type'] ?? $item['name'] ?? 'Item'),
                        $this->formatValue($item['year'] ?? null, 'year'),
                        $this->formatValue($item['total_days'] ?? null, 'total_days'),
                        $this->formatValue($item['used_days'] ?? null, 'used_days'),
                        $this->formatValue($item['remaining'] ?? null, 'remaining'),
                    ];
                }
                if ($rows !== []) {
                    $tables[] = [
                        'type' => 'table',
                        'title' => $this->label($key, $isBm),
                        'columns' => [$isBm ? 'Jenis' : 'Type', $isBm ? 'Tahun' : 'Year', $isBm ? 'Jumlah' : 'Total', $isBm ? 'Digunakan' : 'Used', $isBm ? 'Baki' : 'Remaining'],
                        'rows' => $rows,
                    ];
                }
                continue;
            }

            $rows = [];
            foreach (array_slice($value, 0, 8, true) as $label => $amount) {
                if (! is_array($amount)) {
                    $rows[] = [$this->formatBreakdownLabel((string) $label), $this->formatValue($amount, $key)];
                }
            }
            if ($rows !== []) {
                $tables[] = [
                    'type' => 'table',
                    'title' => $this->label($key, $isBm),
                    'columns' => [$isBm ? 'Kategori' : 'Category', $isBm ? 'Jumlah' : 'Value'],
                    'rows' => $rows,
                ];
            }
        }

        return $tables;
    }

    private function label(string $key, bool $isBm): string
    {
        $labels = $isBm ? [
            'taken_days' => 'Hari cuti approved',
            'taken_count' => 'Permohonan approved',
            'pending_count' => 'Permohonan pending',
            'count' => 'Jumlah',
            'total_value' => 'Jumlah nilai quotation',
            'open_count' => 'Masih open',
            'record_count' => 'Jumlah rekod',
            'latest_status' => 'Status terkini',
            'latest_score' => 'Skor terkini',
            'latest_period' => 'Tempoh terkini',
            'has_feedback' => 'Ada feedback',
            'latest_feedback_excerpt' => 'Feedback terkini',
            'join_date' => 'Tarikh mula',
            'join_date_source' => 'Sumber tarikh mula',
            'tenure_years' => 'Tempoh tahun',
            'tenure_label' => 'Tempoh bekerja',
            'by_month' => 'Mengikut bulan',
            'by_type' => 'Mengikut jenis',
            'by_status' => 'Mengikut status',
            'by_service_type' => 'Mengikut service',
            'by_client' => 'Mengikut client',
            'by_category' => 'Mengikut kategori',
            'by_period' => 'Mengikut tempoh',
            'entitlements' => 'Entitlement cuti',
            'total_days' => 'Jumlah hari',
            'used_days' => 'Hari digunakan',
            'remaining' => 'Baki',
            'year' => 'Tahun',
        ] : [
            'taken_days' => 'Approved leave days',
            'taken_count' => 'Approved applications',
            'pending_count' => 'Pending applications',
            'count' => 'Total',
            'total_value' => 'Total quoted value',
            'open_count' => 'Open',
            'record_count' => 'Records',
            'latest_status' => 'Latest status',
            'latest_score' => 'Latest score',
            'latest_period' => 'Latest period',
            'has_feedback' => 'Feedback available',
            'latest_feedback_excerpt' => 'Latest feedback',
            'join_date' => 'Start date',
            'join_date_source' => 'Start date source',
            'tenure_years' => 'Tenure in years',
            'tenure_label' => 'Tenure',
            'by_month' => 'By month',
            'by_type' => 'By leave type',
            'by_status' => 'By status',
            'by_service_type' => 'By service type',
            'by_client' => 'By client',
            'by_category' => 'By category',
            'by_period' => 'By period',
            'entitlements' => 'Leave entitlements',
            'total_days' => 'Total days',
            'used_days' => 'Used days',
            'remaining' => 'Remaining',
            'year' => 'Year',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    private function formatValue(mixed $value, string $key): string
    {
        if ($value === null || $value === '') {
            return 'not recorded';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $this->formatDate($value) ?: $value;
        }
        if (str_contains($key, 'value') || str_contains($key, 'amount') || str_contains($key, 'total_value')) {
            return $this->formatMoney($value);
        }
        if (str_contains($key, 'percent') || str_contains($key, 'rate')) {
            return $this->formatDecimal($value, 2).'%';
        }
        if (in_array($key, ['taken_days', 'total_days', 'used_days', 'remaining'], true)) {
            return $this->formatDays($value);
        }
        if (is_numeric($value)) {
            $numeric = (float) $value;

            return fmod($numeric, 1.0) === 0.0
                ? number_format($numeric, 0, '.', ',')
                : $this->formatDecimal($numeric, 2);
        }
        if ($key === 'tenure_label') {
            return str_replace(['year(s)', 'month(s)'], ['years', 'months'], (string) $value);
        }

        return trim((string) $value);
    }

    private function formatCount(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }

    private function formatDecimal(mixed $value, int $precision): string
    {
        return number_format((float) $value, $precision, '.', ',');
    }

    private function formatDays(mixed $value): string
    {
        $numeric = (float) $value;
        $formatted = fmod($numeric, 1.0) === 0.0
            ? number_format($numeric, 0, '.', ',')
            : number_format($numeric, 2, '.', ',');

        return $formatted.' '.($numeric === 1.0 ? 'day' : 'days');
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

    private function listSummary(array $items): string
    {
        $labels = [];
        foreach (array_slice($items, 0, 4) as $item) {
            if (is_array($item)) {
                $labels[] = (string) ($item['leave_type'] ?? $item['type'] ?? $item['status'] ?? $item['name'] ?? 'item');
            } else {
                $labels[] = (string) $item;
            }
        }

        return implode(', ', $labels).(count($items) > 4 ? ' and '.(count($items) - 4).' more' : '');
    }

    private function confidence(array $results): string
    {
        if ($results === []) {
            return 'low';
        }
        if (in_array('low', array_map(static fn (AssistantUserTraceResult $result): string => $result->confidence, $results), true)) {
            return 'low';
        }
        if (in_array('medium', array_map(static fn (AssistantUserTraceResult $result): string => $result->confidence, $results), true)) {
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

    /**
     * @param AssistantUserTraceResult[] $results
     */
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

    /**
     * @param AssistantUserTraceResult[] $results
     */
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

    private function hideTotalKey(string $key): bool
    {
        return in_array($key, ['all_matching_count_before_status_filter'], true);
    }

    private function formatDatesInText(string $text): string
    {
        return preg_replace_callback('/\b(20\d{2}-\d{2}-\d{2})\b/', fn (array $match): string => $this->formatDate($match[1]) ?: $match[1], $text) ?? $text;
    }
}
