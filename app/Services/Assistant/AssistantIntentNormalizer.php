<?php

namespace App\Services\Assistant;

class AssistantIntentNormalizer
{
    private const STOPWORDS = [
        'a' => true, 'an' => true, 'and' => true, 'are' => true, 'can' => true, 'do' => true,
        'for' => true, 'how' => true, 'i' => true, 'in' => true, 'is' => true, 'me' => true,
        'of' => true, 'on' => true, 'our' => true, 'please' => true, 'show' => true,
        'tell' => true, 'that' => true, 'the' => true, 'this' => true, 'to' => true,
        'us' => true, 'what' => true, 'where' => true, 'who' => true,
        'apa' => true, 'bagaimana' => true, 'boleh' => true, 'dan' => true, 'dalam' => true,
        'dengan' => true, 'di' => true, 'ini' => true, 'itu' => true, 'ke' => true,
        'macam' => true, 'mana' => true, 'mohon' => true, 'nak' => true, 'saya' => true,
        'tolong' => true, 'untuk' => true, 'yang' => true,
    ];

    private const SYNONYMS = [
        'customer' => 'client',
        'customers' => 'client',
        'clients' => 'client',
        'bayar' => 'payment',
        'belum' => 'unpaid',
        'bil' => 'invoice',
        'cuti' => 'leave',
        'debtors' => 'debtor',
        'feedbacks' => 'feedback',
        'gaji' => 'salary',
        'harga' => 'quotation',
        'invois' => 'invoice',
        'kelulusan' => 'approval',
        'kuotasi' => 'quotation',
        'lulus' => 'approval',
        'pelanggan' => 'client',
        'pembekal' => 'vendor',
        'perkembangan' => 'progress',
        'polisi' => 'policy',
        'po' => 'purchase_order',
        'purchase' => 'purchase_order',
        'quotations' => 'quotation',
        'quote' => 'quotation',
        'resit' => 'receipt',
        'sebutharga' => 'quotation',
        'slip' => 'payslip',
        'staf' => 'staff',
        'tasks' => 'task',
        'tunjuk' => 'show',
        'vendors' => 'vendor',
        'waktu' => 'working',
    ];

    private const MODULE_HINTS = [
        'appraisal' => ['appraisal', 'performance', 'review'],
        'catalog' => ['catalog', 'item', 'purchase_order', 'supplier'],
        'client' => ['client', 'customer', 'pelanggan'],
        'dashboard' => ['dashboard', 'metric', 'sales', 'financial', 'monitoring'],
        'debtor' => ['debtor', 'overdue', 'receivable'],
        'invoice' => ['invoice', 'receipt', 'billing', 'bill'],
        'jd14' => ['jd14', 'jd', 'delivery'],
        'knowledge' => ['guide', 'knowledge', 'learn'],
        'leave' => ['leave', 'cuti'],
        'legal_compliance' => ['legal', 'compliance', 'assessment'],
        'meeting' => ['meeting', 'minutes', 'agenda'],
        'procedure' => ['procedure', 'sop'],
        'project' => ['project', 'projek'],
        'proposal_template' => ['proposal', 'template', 'service', 'quotation'],
        'salary' => ['salary', 'payslip'],
        'staff' => ['staff', 'staf', 'employee', 'hr'],
        'system_feedback' => ['feedback', 'issue', 'bug', 'support'],
        'task' => ['task', 'workload'],
        'vendor' => ['vendor', 'supplier'],
        'whats_new' => ['whats', 'new', 'release', 'update'],
    ];

    public function normalize(string $question): array
    {
        $normalizedQuestion = $this->normalizeCommonAssistantTypos($question);
        $language = $this->language($normalizedQuestion);
        $dateScope = $this->dateScope($normalizedQuestion);
        $tokens = $this->tokens($normalizedQuestion);

        return [
            'normalized_intent' => implode(' ', $tokens),
            'language' => $language,
            'entity_terms' => $this->entityTerms($tokens),
            'date_scope' => $dateScope,
            'module_hints' => $this->moduleHints($tokens),
        ];
    }

    public function normalizedIntent(string $question): string
    {
        $intent = $this->normalize($question);

        return trim(($intent['date_scope'] ?? 'current').'|'.($intent['normalized_intent'] ?? ''));
    }

    public function tokens(string $question): array
    {
        $question = $this->normalizeCommonAssistantTypos($question);
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $question));
        $raw = array_filter(explode(' ', $normalized), static fn (string $token): bool => strlen($token) >= 2);
        $tokens = [];

        foreach ($raw as $token) {
            if (isset(self::STOPWORDS[$token])) {
                continue;
            }
            $token = $this->singularize($token);
            $tokens[] = self::SYNONYMS[$token] ?? $token;
        }

        return array_values(array_unique($tokens));
    }

    private function language(string $question): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $question));
        $tokens = array_flip(array_filter(explode(' ', $normalized)));

        foreach (['apa', 'bagaimana', 'berapa', 'buat', 'cara', 'cuti', 'gaji', 'kelulusan', 'lulus', 'macam', 'mana', 'nak', 'pelanggan', 'pembekal', 'polisi', 'projek', 'rehat', 'resit', 'sebut', 'sebutharga', 'tunjuk', 'waktu'] as $marker) {
            if (isset($tokens[$marker])) {
                return 'bahasa_malaysia';
            }
        }

        return 'auto';
    }

    public function normalizeCommonAssistantTypos(string $question): string
    {
        $normalized = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $question));
        $rules = [
            '/\bhot\s+to\b/i' => 'how to',
            '/\bqou+te\b/i' => 'quote',
            '/\bquou+te\b/i' => 'quote',
            '/\bquo+te\b/i' => 'quote',
            '/\bqu+ote\b/i' => 'quote',
            '/\bquotee\b/i' => 'quote',
            '/\bquotion\b/i' => 'quotation',
            '/\bquatation\b/i' => 'quotation',
            '/\bqotation\b/i' => 'quotation',
            '/\bqutation\b/i' => 'quotation',
            '/\bserviece\b/i' => 'service',
            '/\bservise\b/i' => 'service',
            '/\bservce\b/i' => 'service',
            '/\bsevice\b/i' => 'service',
            '/\bservis\b/i' => 'service',
            '/\bnegotation\b/i' => 'negotiation',
            '/\bnegotiaton\b/i' => 'negotiation',
            '/\bnegotiateion\b/i' => 'negotiation',
            '/\bnego\b/i' => 'negotiation',
            '/\bpolicie\b/i' => 'policy',
            '/\bpolcy\b/i' => 'policy',
            '/\bnie\b/i' => 'ini',
            '/\bni\b/i' => 'ini',
        ];

        foreach ($rules as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
        }

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $normalized));
    }

    private function dateScope(string $question): string
    {
        $value = strtolower($question);
        if (preg_match('/\b(today|hari ini)\b/', $value)) {
            return 'today';
        }
        if (preg_match('/\b(this month|bulan ini)\b/', $value)) {
            return 'current_month';
        }
        if (preg_match('/\b(this year|current year|tahun ini)\b/', $value)) {
            return 'current_year';
        }

        return 'current';
    }

    private function entityTerms(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => strlen($token) > 2 && ! in_array($token, array_keys(self::MODULE_HINTS), true),
        ));
    }

    private function moduleHints(array $tokens): array
    {
        $hints = [];
        foreach (self::MODULE_HINTS as $module => $markers) {
            if (array_intersect($tokens, $markers)) {
                $hints[] = $module;
            }
        }

        return $hints;
    }

    private function singularize(string $token): string
    {
        if (in_array($token, ['invois'], true)) {
            return $token;
        }

        if (strlen($token) > 4 && str_ends_with($token, 'ies')) {
            return substr($token, 0, -3).'y';
        }
        if (
            strlen($token) > 3
            && str_ends_with($token, 's')
            && ! str_ends_with($token, 'ss')
            && ! str_ends_with($token, 'us')
        ) {
            return substr($token, 0, -1);
        }

        return $token;
    }
}
