<?php

namespace App\Services\Assistant;

class AssistantRetrievalPlan
{
    private const ALLOWED_DOMAINS = [
        'appraisal',
        'catalog',
        'client',
        'dashboard',
        'debtor',
        'feedback',
        'handbook',
        'invoice',
        'jd14',
        'knowledge',
        'leave',
        'legal_compliance',
        'meeting',
        'procedure',
        'project',
        'proposal_template',
        'purchase_order',
        'quote_record',
        'sales_inquiry',
        'staff',
        'task',
        'vendor',
        'vendor_registration',
        'whats_new',
    ];

    private const ALLOWED_INTENTS = [
        'policy_question',
        'record_detail',
        'how_to',
        'metric_question',
        'clarification_needed',
        'unknown',
    ];

    private const ALLOWED_CONFIDENCE = ['high', 'medium', 'low'];

    private const GENERIC_REFS = [
        'ABOUT' => true,
        'CREATE' => true,
        'DETAIL' => true,
        'EXPLAIN' => true,
        'HOW' => true,
        'KIJO' => true,
        'SHOW' => true,
        'SOURCE' => true,
        'STATUS' => true,
        'TELL' => true,
        'WHAT' => true,
        'WHEN' => true,
        'WHERE' => true,
        'WHICH' => true,
    ];

    public function __construct(
        public readonly array $domains = [],
        public readonly array $searchTerms = [],
        public readonly array $recordRefs = [],
        public readonly string $intent = 'unknown',
        public readonly string $confidence = 'low',
        public readonly ?string $clarificationQuestion = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public static function fromArray(array $payload): self
    {
        $domains = array_values(array_intersect(
            self::strings($payload['domains'] ?? []),
            self::ALLOWED_DOMAINS,
        ));
        $searchTerms = self::safeTerms($payload['search_terms'] ?? []);
        $recordRefs = array_values(array_filter(
            self::safeTerms($payload['record_refs'] ?? []),
            static fn (string $ref): bool => ! isset(self::GENERIC_REFS[strtoupper($ref)]),
        ));
        $intent = in_array((string) ($payload['intent'] ?? ''), self::ALLOWED_INTENTS, true)
            ? (string) $payload['intent']
            : 'unknown';
        $confidence = in_array((string) ($payload['confidence'] ?? ''), self::ALLOWED_CONFIDENCE, true)
            ? (string) $payload['confidence']
            : 'low';
        $clarification = trim((string) ($payload['clarification_question'] ?? '')) ?: null;

        return new self(
            array_slice($domains, 0, 8),
            array_slice($searchTerms, 0, 10),
            array_slice($recordRefs, 0, 8),
            $intent,
            $confidence,
            $clarification ? mb_substr($clarification, 0, 180) : null,
        );
    }

    public function hasDomain(string $domain): bool
    {
        return in_array($domain, $this->domains, true);
    }

    public function isEmpty(): bool
    {
        return $this->domains === []
            && $this->searchTerms === []
            && $this->recordRefs === []
            && $this->intent === 'unknown';
    }

    public function expandedQuestion(string $question): string
    {
        $parts = array_values(array_filter(array_merge(
            [$question],
            $this->searchTerms,
            $this->recordRefs,
        )));

        return trim(implode(' ', array_unique($parts)));
    }

    public function toArray(): array
    {
        return [
            'domains' => $this->domains,
            'search_terms' => $this->searchTerms,
            'record_refs' => $this->recordRefs,
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'clarification_question' => $this->clarificationQuestion,
        ];
    }

    public static function allowedDomains(): array
    {
        return self::ALLOWED_DOMAINS;
    }

    private static function safeTerms(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $term): string => mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', $term)), 0, 80),
            self::strings($value),
        ), static fn (string $term): bool => $term !== '' && ! self::looksUnsafe($term)));
    }

    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    private static function looksUnsafe(string $term): bool
    {
        return (bool) preg_match('/\b(select|insert|update|delete|drop|alter|truncate|password|token|secret|cookie|session|credential|api[_ -]?key|file path|storage path)\b/i', $term);
    }
}
