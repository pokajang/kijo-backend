<?php

namespace Tests\Unit;

use App\Services\Ai\OpenAiResponsesClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OpenAiResponsesClientTest extends TestCase
{
    private array $messages = [
        ['role' => 'system', 'content' => 'Return JSON.'],
        ['role' => 'user', 'content' => '{"title":"Prepare report"}'],
    ];

    private array $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['label'],
        'properties' => [
            'label' => ['type' => 'string'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.key' => 'test-key',
            'services.openai.responses_endpoint' => 'https://api.openai.com/v1/responses',
            'services.openai.timeout_ms' => 30000,
        ]);
    }

    public function test_json_schema_response_reads_output_text(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => '{"label":"work"}',
            ]),
        ]);

        $result = app(OpenAiResponsesClient::class)->jsonSchemaResponse(
            $this->messages,
            $this->schema,
            'test_schema',
            'gpt-5-nano',
        );

        $this->assertTrue($result->ok);
        $this->assertSame('{"label":"work"}', $result->text);
        $this->assertSame(['label' => 'work'], $result->json);
        $this->assertSame(200, $result->status);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'gpt-5-nano'
            && $request['input'] === $this->messages
            && $request['text']['format']['name'] === 'test_schema'
            && $request['text']['format']['schema'] === $this->schema);
    }

    public function test_json_schema_response_reads_nested_output_text(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [
                    [
                        'content' => [
                            ['text' => '{"label":"nested"}'],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(OpenAiResponsesClient::class)->jsonSchemaResponse(
            $this->messages,
            $this->schema,
            'test_schema',
            'gpt-5-nano',
        );

        $this->assertTrue($result->ok);
        $this->assertSame(['label' => 'nested'], $result->json);
    }

    public function test_json_schema_response_reports_http_failure(): void
    {
        Log::spy();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'rate limited'],
            ], 429),
        ]);

        $result = app(OpenAiResponsesClient::class)->jsonSchemaResponse(
            $this->messages,
            $this->schema,
            'test_schema',
            'gpt-5-nano',
        );

        $this->assertFalse($result->ok);
        $this->assertSame(429, $result->status);
        $this->assertSame('rate limited', $result->error);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('OpenAI Responses API request failed.', \Mockery::on(
                fn (array $context): bool => ($context['http_status'] ?? null) === 429
                    && ($context['error'] ?? null) === 'rate limited',
            ));
    }

    public function test_json_schema_response_reports_request_exception(): void
    {
        Log::spy();
        Http::fake(fn () => throw new RuntimeException('connection timed out'));

        $result = app(OpenAiResponsesClient::class)->jsonSchemaResponse(
            $this->messages,
            $this->schema,
            'test_schema',
            'gpt-5-nano',
        );

        $this->assertFalse($result->ok);
        $this->assertSame('connection timed out', $result->error);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('OpenAI Responses API request failed.', \Mockery::on(
                fn (array $context): bool => ($context['http_status'] ?? null) === null
                    && ($context['error'] ?? null) === 'connection timed out',
            ));
    }

    public function test_json_schema_response_reports_invalid_json(): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => 'not json',
            ]),
        ]);

        $result = app(OpenAiResponsesClient::class)->jsonSchemaResponse(
            $this->messages,
            $this->schema,
            'test_schema',
            'gpt-5-nano',
        );

        $this->assertFalse($result->ok);
        $this->assertSame('not json', $result->text);
        $this->assertSame('OpenAI response was not valid JSON.', $result->error);
    }

    public function test_json_schema_response_does_not_send_without_api_key(): void
    {
        config([
            'services.openai.key' => null,
        ]);
        Http::fake();

        $client = app(OpenAiResponsesClient::class);
        $result = $client->jsonSchemaResponse(
            $this->messages,
            $this->schema,
            'test_schema',
            'gpt-5-nano',
        );

        $this->assertFalse($client->isConfigured());
        $this->assertFalse($result->ok);
        $this->assertSame('OpenAI API key is not configured.', $result->error);
        Http::assertNothingSent();
    }
}
