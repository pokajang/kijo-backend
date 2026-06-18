<?php

namespace App\Services\Assistant\UserTrace;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserKpiTraceAnalyzer
{
    public function analyze(string $question, AssistantUserTraceIdentity $identity, array $dateRange): AssistantUserTraceResult
    {
        if (! Schema::hasTable('hr_appraisal')) {
            return $this->missing($dateRange, ['hr_appraisal.table']);
        }

        $columns = Schema::getColumnListing('hr_appraisal');
        $selected = array_values(array_intersect($columns, [
            'id', 'staff_id', 'title', 'section', 'status', 'period', 'score', 'feedback', 'created_at', 'updated_at',
        ]));
        if ($selected === []) {
            return $this->missing($dateRange, ['hr_appraisal.columns']);
        }

        $records = DB::table('hr_appraisal')
            ->select($selected)
            ->where('staff_id', $identity->staffId)
            ->orderByDesc(in_array('updated_at', $columns, true) ? 'updated_at' : 'id')
            ->limit(20)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        $latest = $records[0] ?? null;
        $wantsImprovement = (bool) preg_match('/\b(improve|improvement|better|weak|kurang|baik|tingkat|perbaiki)\b/i', $question);
        $feedback = trim((string) ($latest['feedback'] ?? ''));
        $summary = $latest
            ? 'Your latest KPI/appraisal record is '.trim((string) ($latest['status'] ?? 'available')).'.'
            : 'I found no KPI/appraisal records for your profile.';
        if ($wantsImprovement) {
            $summary = $feedback !== ''
                ? 'Based on your latest KPI/appraisal feedback, focus your improvement on the feedback areas recorded by your reviewer.'
                : 'I found your KPI/appraisal record, but it does not contain feedback or criteria for improvement guidance yet.';
        }

        return new AssistantUserTraceResult(
            'user_trace.kpi_status',
            'My KPI trace',
            'Latest self-visible KPI/appraisal records for the current user. Improvement guidance is limited to stored feedback and approved sources.',
            $dateRange,
            [
                'record_count' => count($records),
                'latest_status' => $latest['status'] ?? null,
                'latest_score' => $latest['score'] ?? null,
                'latest_period' => $latest['period'] ?? null,
                'has_feedback' => $feedback !== '',
                'latest_feedback_excerpt' => $feedback !== '' ? $this->safeExcerpt($feedback) : null,
            ],
            [
                'by_status' => $this->countBy($records, 'status'),
                'by_period' => $this->countBy($records, 'period'),
            ],
            array_slice(array_map(static fn (array $row): array => array_filter([
                'id' => $row['id'] ?? null,
                'title' => $row['title'] ?? $row['section'] ?? null,
                'period' => $row['period'] ?? null,
                'status' => $row['status'] ?? null,
                'score' => $row['score'] ?? null,
                'feedback' => $row['feedback'] ?? null,
                'updated_at' => isset($row['updated_at']) ? substr((string) $row['updated_at'], 0, 10) : null,
            ], static fn ($value): bool => $value !== null && $value !== ''), $records), 0, 5),
            ['show latest KPI feedback', 'show KPI history', 'how can I improve further'],
            [],
            $latest ? ($feedback !== '' || ! $wantsImprovement ? 'high' : 'medium') : 'low',
            $summary,
            '/staff/appraise',
            ['analyzer' => 'kpi', 'record_count' => count($records)],
        );
    }

    private function missing(array $dateRange, array $missing): AssistantUserTraceResult
    {
        return new AssistantUserTraceResult(
            'user_trace.kpi_status',
            'My KPI trace',
            'Latest self-visible KPI/appraisal records for the current user.',
            $dateRange,
            ['record_count' => 0],
            [],
            [],
            [],
            $missing,
            'low',
            'I could not verify your KPI/appraisal trace because the appraisal table is not available.',
            '/staff/appraise',
        );
    }

    private function countBy(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$key] ?? 'unknown')) ?: 'unknown';
            $values[$label] = ($values[$label] ?? 0) + 1;
        }
        arsort($values);

        return $values;
    }

    private function safeExcerpt(string $value): string
    {
        $value = preg_replace('/https?:\/\/\S+/i', '[redacted-url]', $value) ?? $value;
        $value = preg_replace('/\b[A-Z]:\\\\[^\s]+/i', '[redacted-path]', $value) ?? $value;
        $value = preg_replace('/\b(?:token|password|secret|api[_-]?key)\s*[:=]\s*\S+/i', '[redacted-secret]', $value) ?? $value;
        $value = preg_replace('/[A-Za-z0-9+\/]{80,}={0,2}/', '[redacted-payload]', $value) ?? $value;

        return Str::limit(trim($value), 240, '');
    }
}
