<?php

namespace App\Services\QuoteRecords;

class ProjectOutcomeSummary
{
    public static function attach(array &$quotes, array $projects): void
    {
        $byQuote = [];
        foreach ($projects as $project) {
            $quoteId = (int) self::field($project, 'quote_id');
            if ($quoteId <= 0) {
                continue;
            }

            if (! isset($byQuote[$quoteId])) {
                $byQuote[$quoteId] = self::emptySummary();
            }

            $status = strtolower(trim((string) self::field($project, 'status')));
            $value = (float) self::field($project, 'quote_value');
            $byQuote[$quoteId]['project_status_counts']['total']++;

            if (array_key_exists($status, $byQuote[$quoteId]['project_status_counts'])) {
                $byQuote[$quoteId]['project_status_counts'][$status]++;
            }
            if ($status === 'terminated') {
                $byQuote[$quoteId]['terminated_project_value'] += $value;
            }
            if ($status === 'active' || $status === 'completed') {
                $byQuote[$quoteId]['realized_project_value'] += $value;
            }
        }

        foreach ($quotes as &$quote) {
            $quoteId = (int) self::field($quote, 'id');
            $summary = $byQuote[$quoteId] ?? self::emptySummary();
            self::setField($quote, 'project_status_counts', $summary['project_status_counts']);
            self::setField($quote, 'terminated_project_value', $summary['terminated_project_value']);
            self::setField($quote, 'realized_project_value', $summary['realized_project_value']);
        }
        unset($quote);
    }

    private static function emptySummary(): array
    {
        return [
            'project_status_counts' => [
                'active' => 0,
                'completed' => 0,
                'terminated' => 0,
                'total' => 0,
            ],
            'terminated_project_value' => 0.0,
            'realized_project_value' => 0.0,
        ];
    }

    private static function field($row, string $key)
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    private static function setField(&$row, string $key, $value): void
    {
        if (is_array($row)) {
            $row[$key] = $value;

            return;
        }

        $row->{$key} = $value;
    }
}
