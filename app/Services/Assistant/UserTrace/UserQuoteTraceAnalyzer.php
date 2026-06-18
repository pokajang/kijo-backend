<?php

namespace App\Services\Assistant\UserTrace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserQuoteTraceAnalyzer
{
    private const TABLES = [
        'training' => 'quotes_training',
        'ih' => 'quotes_ih',
        'manpower' => 'quotes_manpower',
        'special' => 'quotes_special',
        'equipment' => 'quotes_equipment',
    ];

    public function __construct(private readonly AssistantTraceDateRangeResolver $dates) {}

    public function analyze(string $question, AssistantUserTraceIdentity $identity, array $dateRange): AssistantUserTraceResult
    {
        $rows = [];
        $missing = [];

        foreach (self::TABLES as $service => $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = "{$table}.table";
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (! in_array('created_by_id', $columns, true) && ! in_array('created_by_code', $columns, true)) {
                $missing[] = "{$table}.owner";
                continue;
            }

            $query = DB::table($table);
            if (in_array('created_by_id', $columns, true) && $identity->nameCode && in_array('created_by_code', $columns, true)) {
                $query->where(function ($scope) use ($identity): void {
                    $scope->where('created_by_id', $identity->staffId)
                        ->orWhere(function ($fallback) use ($identity): void {
                            $fallback->where(function ($missingId): void {
                                $missingId->whereNull('created_by_id')->orWhere('created_by_id', 0);
                            })->whereRaw('UPPER(created_by_code) = ?', [strtoupper((string) $identity->nameCode)]);
                        });
                });
            } elseif (in_array('created_by_id', $columns, true)) {
                $query->where('created_by_id', $identity->staffId);
            } elseif ($identity->nameCode && in_array('created_by_code', $columns, true)) {
                $query->whereRaw('UPPER(created_by_code) = ?', [strtoupper($identity->nameCode)]);
            }

            if (in_array('deleted_at', $columns, true)) {
                $query->whereNull('deleted_at');
            }
            if (in_array('is_deleted', $columns, true)) {
                $query->where('is_deleted', 0);
            }

            foreach ($query->limit(1000)->get() as $row) {
                $item = (array) $row;
                $date = $this->dateFor($item);
                if (! $this->dates->contains($date, $dateRange)) {
                    continue;
                }

                $rows[] = [
                    'id' => $item['id'] ?? null,
                    'service_type' => $this->label($service),
                    'quote_ref_no' => $item['quote_ref_no'] ?? $item['quotation_ref_no'] ?? null,
                    'client_name' => $item['client_name'] ?? null,
                    'status' => $this->normalizedStatus($item['status'] ?? null),
                    'grand_total' => $this->amount($item['grand_total'] ?? $item['quote_value'] ?? null),
                    'revision_no' => is_numeric($item['revision_no'] ?? null) ? (int) $item['revision_no'] : 0,
                    'created_at' => $date,
                ];
            }
        }

        $rows = $this->dedupeRevisions($rows);
        $filtered = $this->statusFiltered($rows, $question);
        $totals = [
            'count' => count($filtered),
            'total_value' => round(array_sum(array_map(static fn (array $row): float => (float) ($row['grand_total'] ?? 0), $filtered)), 2),
            'all_matching_count_before_status_filter' => count($rows),
        ];

        return new AssistantUserTraceResult(
            'user_trace.quote_issued',
            'My quotation trace',
            'Quote records created by the current user across all supported quotation service tables, counted once per saved quote record.',
            $dateRange,
            $totals,
            [
                'by_month' => $this->countBy($filtered, 'created_at', month: true),
                'by_status' => $this->countBy($filtered, 'status'),
                'by_service_type' => $this->countBy($filtered, 'service_type'),
                'by_client' => $this->countBy($filtered, 'client_name', limit: 8),
            ],
            array_slice($filtered, 0, 8),
            ['break down by month', 'break down by status', 'break down by client', 'show failed quotations'],
            array_values(array_unique($missing)),
            $missing === [] ? 'high' : 'medium',
            $this->summary($totals['count'], $dateRange, $question),
            '/crm/quotes',
            ['analyzer' => 'quote', 'row_count' => count($filtered)],
        );
    }

    private function statusFiltered(array $rows, string $question): array
    {
        if (preg_match('/\b(won|awarded|success|menang)\b/i', $question)) {
            return array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) $row['status']) === 'awarded'));
        }
        if (preg_match('/\b(failed|lost|fail|kalah|gagal)\b/i', $question)) {
            return array_values(array_filter($rows, static fn (array $row): bool => strtolower((string) $row['status']) === 'failed'));
        }
        if (preg_match('/\b(cancelled|canceled|void|batal)\b/i', $question)) {
            return array_values(array_filter($rows, static fn (array $row): bool => in_array(strtolower((string) $row['status']), ['cancelled', 'canceled', 'void'], true)));
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! in_array(strtolower((string) $row['status']), ['cancelled', 'canceled', 'void', 'deleted'], true),
        ));
    }

    private function dateFor(array $row): ?string
    {
        foreach (['created_at', 'quote_date', 'updated_at'] as $key) {
            if (! empty($row[$key])) {
                return substr((string) $row[$key], 0, 10);
            }
        }

        return null;
    }

    private function normalizedStatus(mixed $status): string
    {
        $value = trim((string) $status);
        return $value !== '' ? $value : 'unknown';
    }

    private function amount(mixed $amount): ?float
    {
        return is_numeric($amount) ? round((float) $amount, 2) : null;
    }

    private function countBy(array $rows, string $key, bool $month = false, int $limit = 24): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = $month
                ? substr((string) ($row[$key] ?? 'unknown'), 0, 7)
                : trim((string) ($row[$key] ?? 'unknown'));
            $value = $value !== '' ? $value : 'unknown';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }

    private function dedupeRevisions(array $rows): array
    {
        $deduped = [];
        foreach ($rows as $row) {
            $key = (string) ($row['service_type'] ?? 'quote').'|'.trim((string) ($row['quote_ref_no'] ?? ''));
            if (str_ends_with($key, '|')) {
                $key .= 'id:'.(string) ($row['id'] ?? count($deduped));
            }

            $existing = $deduped[$key] ?? null;
            if ($existing === null
                || (int) ($row['revision_no'] ?? 0) > (int) ($existing['revision_no'] ?? 0)
                || (
                    (int) ($row['revision_no'] ?? 0) === (int) ($existing['revision_no'] ?? 0)
                    && (string) ($row['created_at'] ?? '') > (string) ($existing['created_at'] ?? '')
                )
            ) {
                $deduped[$key] = $row;
            }
        }

        return array_values($deduped);
    }

    private function label(string $service): string
    {
        return match ($service) {
            'ih' => 'Industrial Hygiene',
            'manpower' => 'Manpower',
            'special' => 'Special Service',
            'equipment' => 'Equipment',
            default => ucfirst($service),
        };
    }

    private function summary(int $count, array $dateRange, string $question): string
    {
        $range = ($dateRange['is_all_time'] ?? false) ? 'all time' : "{$dateRange['start']} to {$dateRange['end']}";
        if (preg_match('/\b(failed|lost|fail|kalah|gagal)\b/i', $question)) {
            return "For your own records, {$range}, I found {$count} failed quotation(s).";
        }
        if (preg_match('/\b(won|awarded|success|menang)\b/i', $question)) {
            return "For your own records, {$range}, I found {$count} awarded quotation(s).";
        }

        return "For your own records, {$range}, I found {$count} quotation(s) issued.";
    }
}
