<?php

namespace App\Services\Assistant;

class AssistantQuestionIntentResolver
{
    public function __construct(private readonly AssistantText $text) {}

    public function resolve(string $question, string $currentRoute = '', ?AssistantRetrievalPlan $plan = null): AssistantQuestionIntent
    {
        $normalizedText = $this->text->normalizeAssistantQueryTerms($question);
        $normalized = strtolower($normalizedText);
        $route = strtolower(trim($currentRoute));
        $tokens = $this->text->tokens($normalized);
        $hasDetailRoute = $this->hasDetailRoute($route);
        $hasExactRef = $this->hasExactReference($normalized);
        $targetTypes = $this->targetTypes($normalized, $route, $tokens, $plan);

        if ($this->quoteNegotiationIntent($normalized, $tokens)) {
            return $this->intent(AssistantQuestionIntent::QUOTE_NEGOTIATION, ['quote_record', 'knowledge'], $tokens, $hasExactRef, $hasDetailRoute, 'high');
        }

        if ($this->actionRequestIntent($normalized)) {
            return $this->intent(AssistantQuestionIntent::ACTION_REQUEST, $targetTypes ?: ['knowledge'], $tokens, $hasExactRef, $hasDetailRoute, 'medium');
        }

        if ($this->quoteCreationIntent($normalized)) {
            return $this->intent(AssistantQuestionIntent::QUOTE_CREATION, ['proposal_template', 'knowledge'], $tokens, $hasExactRef, $hasDetailRoute, 'high', ['quote_negotiation']);
        }

        if ($hasDetailRoute || $hasExactRef) {
            $status = $this->recordStatusIntent($normalized, $tokens);

            return $this->intent(
                $status ? AssistantQuestionIntent::RECORD_STATUS : AssistantQuestionIntent::RECORD_DETAIL,
                $targetTypes,
                $tokens,
                $hasExactRef,
                $hasDetailRoute,
                'high',
            );
        }

        if ($this->policyQuestionIntent($normalized, $tokens, $route)) {
            return $this->intent(AssistantQuestionIntent::POLICY_QUESTION, ['handbook', 'knowledge'], $tokens, $hasExactRef, $hasDetailRoute, 'high');
        }

        if ($this->serviceExplanationIntent($normalized, $tokens, $normalizedText)) {
            return $this->intent(AssistantQuestionIntent::SERVICE_EXPLANATION, ['proposal_template'], $tokens, $hasExactRef, $hasDetailRoute, 'high');
        }

        if ($this->recordStatusIntent($normalized, $tokens)) {
            return $this->intent(AssistantQuestionIntent::RECORD_STATUS, $targetTypes, $tokens, $hasExactRef, $hasDetailRoute, 'medium');
        }

        if ($this->metricQuestionIntent($normalized, $tokens, $route)) {
            return $this->intent(AssistantQuestionIntent::METRIC_QUESTION, ['dashboard'], $tokens, $hasExactRef, $hasDetailRoute, 'medium');
        }

        if ($this->listSearchIntent($tokens)) {
            return $this->intent(AssistantQuestionIntent::LIST_SEARCH, $targetTypes, $tokens, $hasExactRef, $hasDetailRoute, 'medium');
        }

        if ($this->howToIntent($normalized)) {
            return $this->intent(AssistantQuestionIntent::HOW_TO, ['knowledge'], $tokens, $hasExactRef, $hasDetailRoute, 'medium');
        }

        return $this->intent(AssistantQuestionIntent::UNKNOWN, $targetTypes, $tokens, $hasExactRef, $hasDetailRoute, 'low');
    }

    private function intent(
        string $primaryIntent,
        array $targetTypes,
        array $positiveTerms,
        bool $hasExactRef,
        bool $hasDetailRoute,
        string $confidence,
        array $negativeTerms = [],
    ): AssistantQuestionIntent {
        return new AssistantQuestionIntent(
            $primaryIntent,
            array_values(array_unique($targetTypes)),
            array_values(array_unique($positiveTerms)),
            array_values(array_unique($negativeTerms)),
            $hasExactRef,
            $hasDetailRoute,
            $confidence,
        );
    }

    private function quoteCreationIntent(string $question): bool
    {
        return (bool) preg_match('/\b(how\s+to\s+quote|quote\s+(this|that|the|for|ini|service)|prepare\s+(a\s+)?quot|create\s+(a\s+)?quot|send\s+(a\s+)?quot|price\s+(this|that|the|ini|service)|macam\s+mana\s+nak\s+quote|cara\s+quote|buat\s+quote|buat\s+sebut\s+harga|buat\s+sebutharga|cara\s+buat\s+sebut\s+harga)\b/i', $question);
    }

    private function quoteNegotiationIntent(string $question, array $tokens): bool
    {
        if (preg_match('/\b(requested\s+final\s+total|apply\s+negotiation|quote\s+negotiation)\b/i', $question)) {
            return true;
        }

        return count(array_intersect($tokens, ['negotiate', 'negotiation', 'negotiations', 'discount', 'approval', 'approved'])) > 0;
    }

    private function recordStatusIntent(string $question, array $tokens): bool
    {
        if (! (bool) preg_match('/\b(status|state|stage|progress|open|pending|approved|approval|awarded|failed|apa\s+status|perkembangan|kelulusan|lulus)\b/i', $question)) {
            return false;
        }

        return $this->hasRecordTerm($tokens) || (bool) preg_match('/\b(this|that|ini|quote|quotation|project|projek|client|pelanggan|task|leave|cuti|invoice|invois|record|rekod)\b/i', $question);
    }

    private function policyQuestionIntent(string $question, array $tokens, string $route): bool
    {
        return str_starts_with($route, '/handbook')
            || (bool) preg_match('/\b(handbook|policy|polisi|working\s+time|working\s+hours|office\s+hours|waktu\s+kerja|lunch|lunch\s+break|rehat\s+tengah\s+hari|dress\s+code|attendance|employee|staff|hr)\b/i', $question)
            || count(array_intersect($tokens, ['handbook', 'policy', 'policie', 'attendance', 'employee', 'working', 'office', 'lunch', 'dress', 'break', 'hours'])) > 0;
    }

    private function serviceExplanationIntent(string $question, array $tokens, string $originalQuestion): bool
    {
        $explain = (bool) preg_match('/\b(what\s+is|explain|tell\s+me\s+about|describe)\b/i', $question);
        $service = count(array_intersect($tokens, ['service', 'proposal', 'training', 'course', 'assessment', 'monitoring', 'inspection', 'manpower', 'supply', 'hygiene'])) > 0;

        return ($explain && $service) || ($explain && $this->hasServiceCode($originalQuestion));
    }

    private function metricQuestionIntent(string $question, array $tokens, string $route): bool
    {
        return str_contains($route, '/dashboard')
            || count(array_intersect($tokens, ['dashboard', 'metric', 'stats', 'statistic', 'sales', 'conversion', 'workload'])) > 0
            || (bool) preg_match('/\b(how\s+many|total|count|average|top)\b/i', $question);
    }

    private function listSearchIntent(array $tokens): bool
    {
        return count(array_intersect($tokens, ['all', 'available', 'list', 'show', 'find', 'search', 'which', 'active', 'inactive', 'recent'])) > 0;
    }

    private function howToIntent(string $question): bool
    {
        return (bool) preg_match('/\b(how\s+to|how\s+do\s+i|macam\s+mana|cara|steps?)\b/i', $question);
    }

    private function actionRequestIntent(string $question): bool
    {
        return (bool) preg_match('/\b(create|update|submit|approve|delete|send|apply|buat|hantar|lulus|padam)\s+(this|that|it|ini|for\s+me|now|sekarang)\b/i', $question)
            || (bool) preg_match('/\b(create|update|submit|approve|delete|send|apply|buat|hantar|lulus|padam)\b.{0,60}\b(for\s+me|now|on\s+my\s+behalf|untuk\s+saya|sekarang)\b/i', $question)
            || (bool) preg_match('/\b(do\s+this\s+for\s+me|can\s+you\s+(create|update|submit|approve|delete|send|apply))\b/i', $question);
    }

    private function targetTypes(string $question, string $route, array $tokens, ?AssistantRetrievalPlan $plan): array
    {
        $targets = $plan?->domains ?? [];
        if (str_contains($route, '/crm/quotes') || count(array_intersect($tokens, ['quote', 'quotation', 'sebutharga'])) > 0) {
            $targets[] = 'quote_record';
        }
        if (str_contains($route, '/templates/proposals') || count(array_intersect($tokens, ['service', 'proposal', 'training', 'assessment', 'monitoring', 'hygiene'])) > 0) {
            $targets[] = 'proposal_template';
        }
        if (str_starts_with($route, '/handbook') || count(array_intersect($tokens, ['handbook', 'policy'])) > 0) {
            $targets[] = 'handbook';
        }
        foreach ([
            'project' => 'project',
            'client' => 'client',
            'vendor' => 'vendor',
            'invoice' => 'invoice',
            'salary' => 'salary',
            'task' => 'task',
            'leave' => 'leave',
        ] as $token => $target) {
            if (in_array($token, $tokens, true) || str_contains($route, '/'.$token)) {
                $targets[] = $target;
            }
        }

        return array_values(array_unique(array_filter($targets, 'is_string')));
    }

    private function hasDetailRoute(string $route): bool
    {
        return (bool) preg_match('/(quoteid=|\/templates\/proposals\/[^\/]+\/\d+|\/\d+(?:\?|$))/i', $route);
    }

    private function hasExactReference(string $question): bool
    {
        return (bool) preg_match('/\bQ(?=[A-Z0-9-]*\d)[A-Z0-9-]{2,}\b/i', $question)
            || (bool) preg_match('/\b[A-Z]{2,}[A-Z0-9]*(?:-[A-Z0-9]+)+\b/i', $question);
    }

    private function hasRecordTerm(array $tokens): bool
    {
        return count(array_intersect($tokens, ['quote', 'quotation', 'record', 'project', 'client', 'task', 'leave', 'invoice', 'vendor', 'salary', 'rekod'])) > 0;
    }

    private function hasServiceCode(string $question): bool
    {
        if (! preg_match_all('/\b[A-Z]{3,}(?:-[A-Z0-9]+)*\b/', $question, $matches)) {
            return false;
        }

        $ignored = [
            'about' => true,
            'client' => true,
            'create' => true,
            'explain' => true,
            'quote' => true,
            'record' => true,
            'service' => true,
            'status' => true,
            'that' => true,
            'this' => true,
            'what' => true,
            'where' => true,
            'which' => true,
        ];

        foreach ($matches[0] as $match) {
            $candidate = strtolower($match);
            if (strlen($candidate) <= 12 && ! isset($ignored[$candidate])) {
                return true;
            }
        }

        return false;
    }
}
