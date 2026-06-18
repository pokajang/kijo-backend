<?php

namespace App\Services\Assistant\UserTrace;

use App\Services\Assistant\AssistantText;

class AssistantSelfDataIntentRouter
{
    public function __construct(
        private readonly AssistantText $text,
        private readonly AssistantTraceDateRangeResolver $dateRanges,
        private readonly AssistantUserTraceMetricCatalog $catalog,
    ) {}

    public function resolve(string $question, string $currentRoute = ''): AssistantUserTraceIntent
    {
        $normalized = $this->normalize($question);
        $subject = $this->subject($normalized);
        $dateRange = $this->dateRanges->resolve($question);

        if ($this->isWriteActionRequest($normalized)) {
            return new AssistantUserTraceIntent($subject, $this->domain($normalized, $currentRoute), $this->metric($normalized), $dateRange, supported: false);
        }

        if (in_array($subject, ['other_staff', 'team'], true)) {
            $domain = $this->domain($normalized, $currentRoute);

            if ($subject === 'team' && $this->isScopedRecordListQuestion($normalized)) {
                return new AssistantUserTraceIntent($subject, $domain, $this->metric($normalized), $dateRange, supported: false, catalogEntry: $this->catalog->entry($domain));
            }

            return new AssistantUserTraceIntent(
                $subject,
                $domain,
                $this->metric($normalized),
                $dateRange,
                supported: true,
                denied: true,
                denialReason: 'team_scope_not_enabled',
                catalogEntry: $this->catalog->entry($domain),
            );
        }

        if ($this->isBroadSummary($normalized)) {
            return new AssistantUserTraceIntent(
                'self',
                'summary',
                'summary',
                $dateRange,
                supported: true,
                aggregate: true,
                aggregateDomains: $this->summaryDomains($normalized),
            );
        }

        $domain = $this->domain($normalized, $currentRoute);
        $entry = $this->catalog->entry($domain);
        if ($entry === null || ! $this->hasSelfDataIntent($normalized, $entry)) {
            return new AssistantUserTraceIntent($subject, $domain, $this->metric($normalized), $dateRange, supported: false, catalogEntry: $entry);
        }

        if (($entry['default_range'] ?? null) === 'all_time' && ! $this->hasExplicitDateRange($normalized)) {
            $dateRange = [
                'label' => 'all time',
                'start' => null,
                'end' => null,
                'is_all_time' => true,
            ];
        }

        return new AssistantUserTraceIntent(
            $subject === 'unknown' ? 'self' : $subject,
            $domain,
            $this->metric($normalized),
            $dateRange,
            supported: true,
            catalogEntry: $entry,
        );
    }

    private function normalize(string $question): string
    {
        return $this->text->normalizeAssistantQueryTerms($question);
    }

    private function subject(string $question): string
    {
        if (preg_match('/\b(ali|other staff|another staff|staff lain|orang lain|someone else|employee lain)\b/i', $question)
            || preg_match('/\b[a-z][a-z]+(?:\'s|’s)\s+(kpi|appraisal|leave|cuti|salary|gaji|quote|quotation|tenure|profile)\b/i', $question)
            || preg_match('/\b(kpi|appraisal|leave|cuti|salary|gaji|quote|quotation|tenure|profile)\s+(for|of|untuk|milik)\s+[a-z][a-z]+\b/i', $question)
        ) {
            return 'other_staff';
        }
        if (preg_match('/\b(team|my team|team members|all staff|everyone|semua staff|pasukan)\b/i', $question)) {
            return 'team';
        }
        if (preg_match('/\b(i|my|mine|me|saya|aku|sendiri|own|personal)\b/i', $question)) {
            return 'self';
        }

        return 'unknown';
    }

    private function domain(string $question, string $currentRoute): ?string
    {
        foreach ($this->catalog->entries() as $domain => $entry) {
            foreach ((array) ($entry['synonyms'] ?? []) as $term) {
                if ($this->containsTerm($question, (string) $term)) {
                    return $domain;
                }
            }
        }

        $route = strtolower($currentRoute);
        return match (true) {
            str_contains($route, '/crm/quotes') => 'quote',
            str_contains($route, '/my/leaves') || str_contains($route, '/hr/leaves') => 'leave',
            str_contains($route, '/staff/appraise') || str_contains($route, '/kpi') => 'kpi',
            str_contains($route, '/my/profile') || str_contains($route, '/staff/profile') => 'employment',
            str_contains($route, '/profile') => 'employment',
            str_contains($route, '/task') => 'task',
            str_contains($route, '/project') => 'project',
            str_contains($route, '/invoice') => 'invoice',
            default => null,
        };
    }

    private function hasSelfDataIntent(string $question, array $entry): bool
    {
        if ($this->subject($question) === 'self') {
            foreach ((array) ($entry['metric_terms'] ?? []) as $term) {
                if ($this->containsTerm($question, (string) $term)) {
                    return true;
                }
            }

            return ($entry['domain'] ?? null) === 'salary';
        }

        if (($entry['domain'] ?? null) === 'leave'
            && preg_match('/\b(what\s+leave\s+is\s+still\s+pending|show\s+pending\s+leave|pending\s+leave|cuti.*pending|cuti.*belum\s+lulus)\b/i', $question)
        ) {
            return true;
        }

        return $this->isTraceFollowUp($question);
    }

    private function isTraceFollowUp(string $question): bool
    {
        $trimmed = trim((string) preg_replace('/\s+/', ' ', $question), " \t\n\r\0\x0B?.!");

        return (bool) preg_match('/\b(user[_ -]?trace|my quotation trace|my leave trace|my kpi trace|my employment trace|my task trace|break (it|that) down|which failed|why|how can i improve)\b/i', $question)
            || (bool) preg_match('/^(by month|by status|by client|by service|by type)$/i', $trimmed);
    }

    private function isWriteActionRequest(string $question): bool
    {
        return (bool) preg_match('/\b(create|make|issue|submit|approve|reject|cancel|update|delete|edit|change|generate|apply)\b/i', $question)
            && ! (bool) preg_match('/\b(how many|berapa|count|total|status|pending|approved|rejected|open|completed|created by me|i created|i have created|issued by me|i issued)\b/i', $question);
    }

    private function isScopedRecordListQuestion(string $question): bool
    {
        return (bool) preg_match('/\b(all staff|everyone|team|normal staff|manager)\b/i', $question)
            && (bool) preg_match('/\b(show|list|records|record|tasks|leave records|task records)\b/i', $question)
            && ! (bool) preg_match('/\b(who|most|highest|lowest|compare|comparison|how many|berapa|total|ranking|rank)\b/i', $question);
    }

    private function metric(string $question): string
    {
        return match (true) {
            preg_match('/\b(how can i improve|improve|improvement|better|perbaiki|tingkat)\b/i', $question) === 1 => 'improvement',
            preg_match('/\b(status|pending|approved|rejected|cancelled|open|completed)\b/i', $question) === 1 => 'status',
            preg_match('/\b(balance|remaining|baki|entitlement)\b/i', $question) === 1 => 'balance',
            preg_match('/\b(tenure|how long|worked here|been here|join date|joined|berapa lama)\b/i', $question) === 1 => 'tenure',
            preg_match('/\b(break down|breakdown|by month|by status|by client|by type|trend)\b/i', $question) === 1 => 'breakdown',
            preg_match('/\b(how many|berapa|count|total|sum|average|avg)\b/i', $question) === 1 => 'count',
            default => 'summary',
        };
    }

    private function isBroadSummary(string $question): bool
    {
        if (preg_match('/\b(how am i doing|summari[sz]e my work|my work status|overall performance|how is my performance|prestasi saya|status kerja saya)\b/i', $question)) {
            return true;
        }

        $trimmed = trim((string) preg_replace('/\s+/', ' ', $question), " \t\n\r\0\x0B?.!");

        return (bool) preg_match('/^(how can i improve|how can i improve further|how do i improve|improve further|cara saya.*improve|macam mana saya.*improve)$/i', $trimmed);
    }

    private function summaryDomains(string $question): array
    {
        if (preg_match('/\b(improve|improvement|better|perbaiki|tingkat)\b/i', $question)) {
            return ['kpi', 'task', 'quote'];
        }

        return ['kpi', 'task', 'quote', 'leave'];
    }

    private function hasExplicitDateRange(string $question): bool
    {
        return (bool) preg_match('/\b(all time|ever|last 12 months|last month|this month|last year|this year|current year|20\d{2}|sepanjang masa|bulan lepas|bulan ini|tahun lepas|tahun ini)\b/i', $question)
            || (bool) preg_match('/20\d{2}-\d{2}-\d{2}/', $question);
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
