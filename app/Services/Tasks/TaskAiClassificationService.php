<?php

namespace App\Services\Tasks;

use App\Services\Ai\OpenAiResponsesClient;

class TaskAiClassificationService
{
    private const ACCEPTED_CONFIDENCE = ['medium' => true, 'high' => true];
    private const LIFECYCLE_STATUSES = [
        'not_applicable' => true,
        'queued' => true,
        'processing' => true,
        'applied' => true,
        'cached' => true,
        'no_result' => true,
        'failed' => true,
        'stale' => true,
        'pending' => true,
    ];

    public function __construct(private readonly OpenAiResponsesClient $openAi) {}

    public function enabled(): bool
    {
        return (bool) config('services.workload_ai_classification.enabled', false)
            && $this->openAi->isConfigured();
    }

    public function shouldAttemptAiFallback(array $classification): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if (in_array(($classification['classification_source'] ?? null), ['ai', 'ai_cache'], true)) {
            return false;
        }

        $category = (string) ($classification['task_category'] ?? '');
        $confidence = (string) ($classification['classification_confidence'] ?? 'low');
        $workType = TaskClassificationService::normalizeWorkType((string) ($classification['work_type'] ?? 'unclear'));

        if ($category === 'non_work') {
            return false;
        }

        return $category === 'unclear_unrated'
            || ($category === 'uncategorised' && $confidence === 'low')
            || $workType === 'unclear';
    }

    public function statusForClassification(array $classification): string
    {
        $storedStatus = $this->normalizeLifecycleStatus(
            $classification['ai_classification_status']
                ?? $classification['aiClassificationStatus']
                ?? null
        );
        if ($storedStatus !== null) {
            return $storedStatus;
        }

        $source = (string) ($classification['classification_source'] ?? '');

        if ($source === 'ai') {
            return 'applied';
        }

        if ($source === 'ai_cache') {
            return 'cached';
        }

        return $this->shouldAttemptAiFallback($classification) ? 'pending' : 'not_applicable';
    }

    public function initialLifecycleStatus(array $classification): string
    {
        $source = (string) ($classification['classification_source'] ?? '');

        if ($source === 'ai') {
            return 'applied';
        }

        if ($source === 'ai_cache') {
            return 'cached';
        }

        return $this->shouldAttemptAiFallback($classification) ? 'queued' : 'not_applicable';
    }

    public function normalizeLifecycleStatus(mixed $status): ?string
    {
        $status = trim((string) $status);
        if ($status === '') {
            return null;
        }

        return isset(self::LIFECYCLE_STATUSES[$status]) ? $status : null;
    }

    public function classifyTitle(string $title, array $localClassification): ?array
    {
        if (! $this->shouldAttemptAiFallback($localClassification)) {
            return null;
        }

        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $result = $this->openAi->jsonSchemaResponse(
            $this->messages($title, $localClassification),
            $this->responseSchema(),
            'workload_task_classification',
            (string) config('services.workload_ai_classification.model', 'gpt-5-nano'),
            (int) config('services.workload_ai_classification.timeout_ms', 30000),
        );

        if (! $result->ok || $result->json === null) {
            return null;
        }

        return $this->validatedClassification($result->json);
    }

    private function messages(string $title, array $localClassification): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $title,
                    'local_classification' => [
                        'task_category' => $localClassification['task_category'] ?? null,
                        'effort_score' => $localClassification['effort_score'] ?? null,
                        'classification_confidence' => $localClassification['classification_confidence'] ?? null,
                        'work_type' => $localClassification['work_type'] ?? null,
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    private function systemPrompt(): string
    {
        $categoryLines = array_map(
            fn (string $category, array $definition): string => sprintf(
                '- %s: score %s (%s)',
                $category,
                rtrim(rtrim((string) $definition['effort_score'], '0'), '.'),
                $definition['label'],
            ),
            array_keys(TaskClassificationService::taskCategoryDefinitions()),
            TaskClassificationService::taskCategoryDefinitions(),
        );

        $workTypeLines = array_map(
            fn (string $workType, string $label): string => sprintf('- %s: %s', $workType, $label),
            array_keys(TaskClassificationService::workTypeDefinitions()),
            TaskClassificationService::workTypeDefinitions(),
        );

        return implode("\n", [
            'Classify one company task title for workload reporting.',
            'Return only the JSON schema fields.',
            'Use the existing local classification unless the title has enough work signal to improve an unclear or low-confidence result.',
            'Do not reward personal, leisure, food, or gibberish input.',
            'Effort score must match the selected task category exactly.',
            'Allowed task categories and exact scores:',
            ...$categoryLines,
            'Allowed work types:',
            ...$workTypeLines,
            'Use critical_escalation only for real incidents, outages, accidents, compliance breaches, urgent client escalations, or similar immediate-risk work.',
            'Routine preparation, summaries, handovers, filing, and document organization are not critical escalations.',
        ]);
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_category', 'effort_score', 'work_type', 'confidence', 'reason'],
            'properties' => [
                'task_category' => [
                    'type' => 'string',
                    'enum' => array_keys(TaskClassificationService::taskCategoryDefinitions()),
                ],
                'effort_score' => [
                    'type' => 'number',
                    'enum' => array_values(array_unique(array_map(
                        fn (array $definition): float => (float) $definition['effort_score'],
                        TaskClassificationService::taskCategoryDefinitions(),
                    ))),
                ],
                'work_type' => [
                    'type' => 'string',
                    'enum' => array_keys(TaskClassificationService::workTypeDefinitions()),
                ],
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                ],
                'reason' => [
                    'type' => 'string',
                    'maxLength' => 120,
                ],
            ],
        ];
    }

    private function validatedClassification(array $payload): ?array
    {
        $category = TaskClassificationService::normalizeTaskCategory((string) ($payload['task_category'] ?? ''));
        if ($category !== (string) ($payload['task_category'] ?? '')) {
            return null;
        }

        $expectedScore = TaskClassificationService::effortScoreForCategory($category);
        if (! array_key_exists('effort_score', $payload) || abs((float) $payload['effort_score'] - $expectedScore) > 0.001) {
            return null;
        }

        $workType = TaskClassificationService::normalizeWorkType((string) ($payload['work_type'] ?? ''));
        if ($workType !== (string) ($payload['work_type'] ?? '')) {
            return null;
        }

        if (($category === 'non_work' && $workType !== 'non_work') || ($category === 'unclear_unrated' && $workType !== 'unclear')) {
            return null;
        }

        $confidence = (string) ($payload['confidence'] ?? '');
        if (! isset(self::ACCEPTED_CONFIDENCE[$confidence])) {
            return null;
        }

        $reason = preg_replace('/\s+/', ' ', trim((string) ($payload['reason'] ?? ''))) ?: 'structured_output';
        $matchedPattern = 'ai:'.substr($reason, 0, 117);

        return [
            'task_category' => $category,
            'effort_score' => $expectedScore,
            'classification_confidence' => $confidence,
            'classification_source' => 'ai',
            'user_override' => false,
            'matched_pattern' => $matchedPattern,
            'work_type' => $workType,
            'work_type_confidence' => $confidence,
            'work_type_matched_pattern' => $matchedPattern,
        ];
    }
}
