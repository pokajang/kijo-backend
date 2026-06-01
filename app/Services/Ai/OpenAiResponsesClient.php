<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OpenAiResponsesClient
{
    private const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/responses';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function jsonSchemaResponse(
        array $messages,
        array $schema,
        string $schemaName,
        ?string $model = null,
        ?int $timeoutMs = null,
    ): OpenAiJsonResult {
        if (! $this->isConfigured()) {
            return OpenAiJsonResult::failure('OpenAI API key is not configured.');
        }

        $model = trim((string) ($model ?? config('services.openai.model', '')));
        if ($model === '') {
            return OpenAiJsonResult::failure('OpenAI model is not configured.');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds($timeoutMs))
                ->acceptJson()
                ->withToken($this->apiKey())
                ->post($this->endpoint(), [
                    'model' => $model,
                    'input' => $messages,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => $schemaName,
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            $message = $exception->getMessage() ?: 'OpenAI request failed.';
            $this->logFailure($message, null);

            return OpenAiJsonResult::failure($message);
        }

        $status = $response->status();
        $body = $response->json();
        if (! $response->successful()) {
            $message = $this->responseError($body);
            $this->logFailure($message, $status);

            return OpenAiJsonResult::failure($message, $status);
        }

        $text = $this->extractOutputText($body);
        if ($text === null) {
            return OpenAiJsonResult::failure('OpenAI response did not include output text.', $status);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            return OpenAiJsonResult::failure('OpenAI response was not valid JSON.', $status, $text);
        }

        return OpenAiJsonResult::success(
            $text,
            $decoded,
            $status,
            $this->tokenCount($body, ['input_tokens', 'prompt_tokens']),
            $this->tokenCount($body, ['output_tokens', 'completion_tokens']),
        );
    }

    private function apiKey(): string
    {
        return trim((string) config('services.openai.key', ''));
    }

    private function endpoint(): string
    {
        return trim((string) config('services.openai.responses_endpoint', self::DEFAULT_ENDPOINT))
            ?: self::DEFAULT_ENDPOINT;
    }

    private function timeoutSeconds(?int $timeoutMs): int
    {
        $configuredMs = $timeoutMs ?? (int) config('services.openai.timeout_ms', 30000);

        return max(1, (int) ceil(max(1, $configuredMs) / 1000));
    }

    private function extractOutputText(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }

        foreach (($body['output'] ?? []) as $outputItem) {
            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (isset($contentItem['text']) && is_string($contentItem['text'])) {
                    return $contentItem['text'];
                }
            }
        }

        return null;
    }

    private function responseError(mixed $body): string
    {
        if (is_array($body)) {
            $message = $body['error']['message'] ?? $body['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }

        return 'OpenAI request failed.';
    }

    private function tokenCount(mixed $body, array $keys): ?int
    {
        if (! is_array($body)) {
            return null;
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        foreach ($keys as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                return max(0, (int) $usage[$key]);
            }
        }

        return null;
    }

    private function logFailure(string $message, ?int $status): void
    {
        Log::warning('OpenAI Responses API request failed.', [
            'http_status' => $status,
            'error' => Str::limit($message, 500, ''),
        ]);
    }
}
