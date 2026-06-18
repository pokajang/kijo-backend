<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantDiagnosticsRecorder;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\UserTrace\AssistantTraceDateRangeResolver;
use App\Services\Assistant\UserTrace\AssistantUserIdentityResolver;
use App\Services\Assistant\UserTrace\AssistantUserTraceIdentity;
use App\Services\Assistant\UserTrace\AssistantUserTraceResult;
use App\Services\Assistant\UserTrace\UserEmploymentTraceAnalyzer;
use App\Services\Assistant\UserTrace\UserKpiTraceAnalyzer;
use App\Services\Assistant\UserTrace\UserLeaveTraceAnalyzer;
use App\Services\Assistant\UserTrace\UserQuoteTraceAnalyzer;
use App\Services\Assistant\UserTrace\UserTaskTraceAnalyzer;
use Illuminate\Http\Request;

class UserTraceContextProvider extends ModuleContextProvider
{
    public function __construct(
        AssistantText $text,
        private readonly AssistantUserIdentityResolver $identityResolver,
        private readonly AssistantTraceDateRangeResolver $dateRanges,
        private readonly UserQuoteTraceAnalyzer $quotes,
        private readonly UserLeaveTraceAnalyzer $leaves,
        private readonly UserEmploymentTraceAnalyzer $employment,
        private readonly UserKpiTraceAnalyzer $kpi,
        private readonly UserTaskTraceAnalyzer $tasks,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'user_trace';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        $domain = $this->traceDomain($question);

        return $domain !== null
            && (
                $this->hasSelfTraceIntent($question, $domain)
                || $this->hasImplicitSelfTraceIntent($question, $domain)
                || $this->isTraceFollowUp($question)
                || $this->asksOtherPersonTrace($question)
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $identity = $this->identityResolver->resolve($request);
        if ($identity->staffId <= 0) {
            return $this->directNoSource('Please sign in before asking about your personal Kijo trace.', 'not_authenticated');
        }

        if ($this->asksOtherPersonTrace($question)) {
            AssistantDiagnosticsRecorder::recordDenied($this->key(), 'user_trace', 'team_scope_not_enabled', [
                'metric_key' => $this->traceDomain($question) ? 'user_trace.'.$this->traceDomain($question) : 'user_trace',
                'scope_attempted' => 'team_or_other_staff',
            ]);

            return $this->directNoSource(
                'I can only answer self-scoped trace questions in this phase. Ask about your own records, or use the module reports you are authorized to access.',
                'team_scope_not_enabled',
            );
        }

        $dateRange = $this->dateRanges->resolve($question);
        $result = match ($this->traceDomain($question)) {
            'leave' => $this->leaves->analyze($question, $identity, $dateRange),
            'employment' => $this->employment->analyze($question, $identity, $dateRange),
            'kpi' => $this->kpi->analyze($question, $identity, $dateRange),
            'task' => $this->tasks->analyze($question, $identity, $dateRange),
            default => $this->quotes->analyze($question, $identity, $dateRange),
        };

        AssistantDiagnosticsRecorder::recordTrace(
            (string) ($result->diagnostics['analyzer'] ?? $result->metricKey),
            'self',
            $result->dateRange,
            $result->missingFields,
        );

        $source = $this->traceSource($result, $identity);
        $answer = $this->directAnswer($result, $source['slug'], $question);

        return new AssistantContextResult(
            [$source],
            'live',
            $source['freshness_label'] ?? null,
            [$this->key()],
            $result->missingFields === [] ? 'complete' : 'partial',
            $result->missingFields,
            [
                'direct_answer' => $answer,
                'provider_key' => $this->key(),
                'supported_intent' => 'user_trace',
                'trace_metric_key' => $result->metricKey,
            ],
        );
    }

    public function auditMetadata(): array
    {
        return [
            'provider_key' => $this->key(),
            'supported_routes' => ['/my/profile', '/crm/quotes', '/my/leaves', '/staff/appraise', '/task-manager'],
            'exact_ref_support' => false,
            'detail_route_support' => false,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'source_status_metadata' => 'not-applicable',
            'permission_scope' => 'self-only',
            'smoke_sample' => 'how many quotations have I issued',
            'tests_present' => 'covered',
            'classification' => 'summary-only',
        ];
    }

    private function traceSource(AssistantUserTraceResult $result, AssistantUserTraceIdentity $identity): array
    {
        $payload = $result->toPayload() + [
            'identity' => array_filter([
                'staff_id' => $identity->staffId,
                'name_code' => $identity->nameCode,
                'full_name' => $identity->fullName,
                'department' => $identity->department,
                'position' => $identity->position,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];
        $slug = 'user-trace:'.$result->metricKey.':'.substr(sha1(json_encode([$payload, $result->dateRange])), 0, 12);

        return $this->source(
            $slug,
            'user_trace',
            $result->title,
            $result->route,
            ['trace' => $payload],
            900,
            'User Trace',
            6000,
            [
                'supported_intent' => 'user_trace',
                'intent_tags' => ['user_trace', $result->metricKey],
                'context_quality' => $result->missingFields === [] ? 'complete' : 'partial',
                'permission_scope' => 'self',
            ],
        );
    }

    private function directAnswer(AssistantUserTraceResult $result, string $slug, string $question): array
    {
        $isBm = $this->text->languageHint($question) === 'bahasa_malaysia';
        $lines = [
            $this->summaryLine($result, $isBm),
            $isBm ? 'Skop: rekod anda sendiri.' : 'Scope: your own records.',
            ($isBm ? 'Julat tarikh: ' : 'Date range: ').$this->rangeLabel($result->dateRange).'.',
            ($isBm ? 'Definisi: ' : 'Definition: ').$result->definition,
        ];
        if ($result->totals !== []) {
            $lines[] = ($isBm ? 'Jumlah:' : 'Totals:').$this->summaryBullets($result->totals, $isBm);
        }
        if ($result->breakdowns !== []) {
            $lines[] = ($isBm ? 'Pecahan:' : 'Breakdowns:').$this->breakdownBullets($result->breakdowns, $isBm);
        }
        if ($result->missingFields !== []) {
            $lines[] = ($isBm ? 'Field yang tiada: ' : 'Missing fields: ').implode(', ', $result->missingFields).'.';
        }
        if ($result->nextDrilldowns !== []) {
            $lines[] = ($isBm ? 'Anda boleh tanya seterusnya: ' : 'You can ask next: ').implode('; ', array_slice($result->nextDrilldowns, 0, 4)).'.';
        }

        return [
            'answer_markdown' => implode("\n\n", $lines),
            'confidence' => $result->confidence,
            'source_slugs' => [$slug],
            'suggested_queries' => array_slice($result->nextDrilldowns, 0, 3),
            'freshness_label' => $this->freshnessLabel(),
            'answer_mode' => 'live',
            'route_refs' => [],
            'context_quality' => $result->missingFields === [] ? 'complete' : 'partial',
            'provider_key' => $this->key(),
            'supported_intent' => 'user_trace',
            'resolved_entity_ids' => [],
            'missing_fields' => $result->missingFields,
        ];
    }

    private function directNoSource(string $message, string $reason): AssistantContextResult
    {
        return new AssistantContextResult([], 'static', null, [$this->key()], 'insufficient', ['source'], [
            'direct_answer' => [
                'answer_markdown' => $message,
                'confidence' => 'low',
                'source_slugs' => [],
                'suggested_queries' => [],
                'freshness_label' => null,
                'answer_mode' => 'static',
                'route_refs' => [],
                'context_quality' => 'insufficient',
                'provider_key' => $this->key(),
                'supported_intent' => 'user_trace_denied',
                'resolved_entity_ids' => [],
                'missing_fields' => ['source'],
                'denied_reason' => $reason,
            ],
        ]);
    }

    private function summaryLine(AssistantUserTraceResult $result, bool $isBm): string
    {
        if (! $isBm) {
            return $result->summary;
        }

        $range = $this->rangeLabel($result->dateRange);

        return match ($result->metricKey) {
            'user_trace.quote_issued' => 'Untuk rekod anda sendiri, '.$range.', saya jumpa '.(int) ($result->totals['count'] ?? 0).' quotation dikeluarkan.',
            'user_trace.leave_taken' => 'Untuk rekod anda sendiri, '.$range.', anda telah mengambil '.(float) ($result->totals['taken_days'] ?? 0).' hari cuti approved dalam '.(int) ($result->totals['taken_count'] ?? 0).' permohonan.',
            'user_trace.kpi_status' => ($result->totals['record_count'] ?? 0) > 0
                ? 'Rekod KPI/appraisal terkini anda berstatus '.(string) ($result->totals['latest_status'] ?? 'available').'.'
                : 'Saya tidak jumpa rekod KPI/appraisal untuk profil anda.',
            'user_trace.employment_tenure' => ! empty($result->totals['tenure_label'])
                ? 'Berdasarkan profil staff anda, tempoh anda di syarikat ini ialah '.(string) $result->totals['tenure_label'].'.'
                : 'Saya tidak dapat kira tempoh bekerja kerana tarikh masuk kerja yang boleh dipercayai tidak tersedia.',
            'user_trace.task_status' => 'Saya jumpa '.(int) ($result->totals['count'] ?? 0).' task untuk anda dalam julat yang dipilih.',
            default => $result->summary,
        };
    }

    private function hasSelfIntent(string $question): bool
    {
        return (bool) preg_match('/\b(i|my|mine|me|saya|aku|sendiri|own|personal)\b/i', $question);
    }

    private function hasSelfTraceIntent(string $question, string $domain): bool
    {
        return $this->hasSelfIntent($question) && $this->hasTraceMetricIntent($question, $domain);
    }

    private function hasImplicitSelfTraceIntent(string $question, string $domain): bool
    {
        if ($domain !== 'leave') {
            return false;
        }

        return (bool) preg_match('/\b(what\s+leave\s+is\s+still\s+pending|show\s+pending\s+leave|pending\s+leave|cuti.*pending|cuti.*belum\s+lulus)\b/i', $question);
    }

    private function hasTraceMetricIntent(string $question, string $domain): bool
    {
        if (preg_match('/\b(how\s+many|berapa|count|total|sum|average|avg|trend|break\s*(it|that)?\s*down|breakdown|by\s+month|by\s+status|by\s+client|history|trace)\b/i', $question)) {
            return true;
        }
        if ($domain === 'quote' && preg_match('/\b(issued|created|won|awarded|failed|lost|menang|gagal)\b/i', $question)) {
            return true;
        }
        if ($domain === 'leave' && preg_match('/\b(status|taken|ambil|sudah\s+ambil|pending|remaining|entitlement|balance|baki)\b/i', $question)) {
            return true;
        }
        if ($domain === 'kpi' && preg_match('/\b(kpi\s+status|appraisal\s+status|performance\s+status|how\s+can\s+i\s+improve|improve\s+further|perbaiki|tingkat)\b/i', $question)) {
            return true;
        }
        if ($domain === 'employment' && preg_match('/\b(years?\s+have\s+i\s+spent|tenure|how\s+long|berapa\s+lama|lama.*kerja|kerja\s+sini|join\s+date|joined)\b/i', $question)) {
            return true;
        }

        return false;
    }

    private function hasTraceDomain(string $question): bool
    {
        return $this->traceDomain($question) !== null || $this->isTraceFollowUp($question);
    }

    private function isTraceFollowUp(string $question): bool
    {
        return (bool) preg_match('/\b(user[_ -]?trace|my quotation trace|my leave trace|my kpi trace|my employment trace|my task trace|break (it|that) down|by month|by status|by client|which failed|why|how can i improve)\b/i', $question);
    }

    private function asksOtherPersonTrace(string $question): bool
    {
        return (bool) preg_match('/\b(ali|other staff|another staff|team|staff lain|orang lain)\b/i', $question);
    }

    private function traceDomain(string $question): ?string
    {
        if (preg_match('/\b(my quotation trace|user_trace\.quote_issued)\b/i', $question)) {
            return 'quote';
        }
        if (preg_match('/\b(my leave trace|user_trace\.leave_taken)\b/i', $question)) {
            return 'leave';
        }
        if (preg_match('/\b(my kpi trace|user_trace\.kpi_status)\b/i', $question)) {
            return 'kpi';
        }
        if (preg_match('/\b(my employment trace|user_trace\.employment_tenure)\b/i', $question)) {
            return 'employment';
        }
        if (preg_match('/\b(my task trace|user_trace\.task_status)\b/i', $question)) {
            return 'task';
        }
        if (preg_match('/\b(leave|cuti|entitlement)\b/i', $question)) {
            return 'leave';
        }
        if (preg_match('/\b(kpi|appraisal|performance|feedback|improve|improvement|perbaiki|tingkat)\b/i', $question)) {
            return 'kpi';
        }
        if (preg_match('/\b(years?|tenure|spent here|joined|join date|lama.*kerja|kerja sini|profile|position|department)\b/i', $question)) {
            return 'employment';
        }
        if (preg_match('/\b(task|tasks|workload|todo|assigned)\b/i', $question)) {
            return 'task';
        }
        if (preg_match('/\b(quote|quotes|quotation|quotations|sebut\s+harga|sebutharga|sales|crm)\b/i', $question)) {
            return 'quote';
        }

        return null;
    }

    private function rangeLabel(array $dateRange): string
    {
        return ($dateRange['is_all_time'] ?? false)
            ? 'all time'
            : (string) ($dateRange['start'] ?? '').' to '.(string) ($dateRange['end'] ?? '');
    }

    private function compactBreakdowns(array $breakdowns): array
    {
        return array_map(static function ($value) {
            return is_array($value) ? array_slice($value, 0, 8, true) : $value;
        }, $breakdowns);
    }

    private function summaryBullets(array $payload, bool $isBm): string
    {
        $lines = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $lines[] = '- '.$this->humanLabel((string) $key, $isBm).': '.$this->humanValue($value);
        }

        return $lines === [] ? ' none.' : "\n".implode("\n", $lines);
    }

    private function breakdownBullets(array $breakdowns, bool $isBm): string
    {
        $lines = [];
        foreach ($this->compactBreakdowns($breakdowns) as $key => $value) {
            if ($value === [] || $value === null || $value === '') {
                continue;
            }

            if (is_array($value) && array_is_list($value)) {
                $lines[] = '- '.$this->humanLabel((string) $key, $isBm).': '.$this->listSummary($value, $isBm);
                continue;
            }

            if (is_array($value)) {
                $parts = [];
                foreach ($value as $label => $amount) {
                    if (is_array($amount)) {
                        continue;
                    }
                    $parts[] = trim((string) $label).': '.$this->humanValue($amount);
                    if (count($parts) >= 6) {
                        break;
                    }
                }
                if ($parts !== []) {
                    $lines[] = '- '.$this->humanLabel((string) $key, $isBm).': '.implode(', ', $parts).'.';
                }
                continue;
            }

            $lines[] = '- '.$this->humanLabel((string) $key, $isBm).': '.$this->humanValue($value);
        }

        return $lines === [] ? ' none.' : "\n".implode("\n", $lines);
    }

    private function listSummary(array $items, bool $isBm): string
    {
        $parts = [];
        foreach (array_slice($items, 0, 4) as $item) {
            if (! is_array($item)) {
                $parts[] = $this->humanValue($item);
                continue;
            }

            $label = (string) ($item['leave_type'] ?? $item['type'] ?? $item['status'] ?? $item['name'] ?? 'item');
            $details = [];
            foreach (['year', 'total_days', 'used_days', 'remaining'] as $key) {
                if (array_key_exists($key, $item)) {
                    $details[] = $this->humanLabel($key, $isBm).': '.$this->humanValue($item[$key]);
                }
            }
            $parts[] = $label.($details === [] ? '' : ' ('.implode(', ', $details).')');
        }

        $suffix = count($items) > 4 ? ' and '.(count($items) - 4).' more' : '';

        return implode('; ', $parts).$suffix.'.';
    }

    private function humanLabel(string $key, bool $isBm): string
    {
        $labels = $isBm ? [
            'taken_days' => 'Hari cuti diambil',
            'taken_count' => 'Permohonan approved',
            'pending_count' => 'Permohonan pending',
            'count' => 'Jumlah',
            'total_value' => 'Jumlah nilai',
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
            'tenure_label' => 'Tempoh',
            'all_matching_count_before_status_filter' => 'Jumlah sebelum filter status',
            'by_month' => 'Mengikut bulan',
            'by_type' => 'Mengikut jenis',
            'by_status' => 'Mengikut status',
            'by_service_type' => 'Mengikut service',
            'by_client' => 'Mengikut client',
            'by_category' => 'Mengikut kategori',
            'by_period' => 'Mengikut tempoh',
            'entitlements' => 'Entitlement',
            'total_days' => 'Jumlah hari',
            'used_days' => 'Hari digunakan',
            'remaining' => 'Baki',
            'year' => 'Tahun',
        ] : [
            'taken_days' => 'Approved leave days taken',
            'taken_count' => 'Approved applications',
            'pending_count' => 'Pending applications',
            'count' => 'Total',
            'total_value' => 'Total value',
            'open_count' => 'Open',
            'record_count' => 'Records',
            'latest_status' => 'Latest status',
            'latest_score' => 'Latest score',
            'latest_period' => 'Latest period',
            'has_feedback' => 'Feedback available',
            'latest_feedback_excerpt' => 'Latest feedback',
            'join_date' => 'Join date',
            'join_date_source' => 'Join date source',
            'tenure_years' => 'Tenure in years',
            'tenure_label' => 'Tenure',
            'all_matching_count_before_status_filter' => 'Records before status filter',
            'by_month' => 'By month',
            'by_type' => 'By leave type',
            'by_status' => 'By status',
            'by_service_type' => 'By service type',
            'by_client' => 'By client',
            'by_category' => 'By category',
            'by_period' => 'By period',
            'entitlements' => 'Entitlements',
            'total_days' => 'Total days',
            'used_days' => 'Used days',
            'remaining' => 'Remaining',
            'year' => 'Year',
        ];

        return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    private function humanValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'not recorded';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        }
        if (is_int($value) || is_numeric($value)) {
            $numeric = (float) $value;

            return fmod($numeric, 1.0) === 0.0
                ? (string) (int) $numeric
                : rtrim(rtrim(number_format($numeric, 2, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }
}
