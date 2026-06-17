<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\Sources\ModuleContextProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

class AssistantContextSanitizerTest extends TestCase
{
    public function test_detail_redacts_sensitive_nested_keys_and_keeps_attachment_metadata(): void
    {
        $payload = app(AssistantContextSanitizer::class)->detail([
            'title' => 'Quote QTR26-0001',
            'status' => 'Open',
            'password' => 'secret-password',
            'api_key' => 'secret-api-key',
            'file_path' => 'storage/app/private/quote.pdf',
            'attachment' => [
                'name' => 'scope.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1200,
                'stored_path' => 'storage/app/private/scope.pdf',
                'download_url' => '/files/private/token',
            ],
            'history' => [
                [
                    'action' => 'created',
                    'session_id' => 'session-secret',
                    'accessToken' => 'nested-token',
                    'remarks' => 'Created by sales',
                ],
            ],
        ]);

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringContainsString('Quote QTR26-0001', $encoded);
        $this->assertStringContainsString('scope.pdf', $encoded);
        $this->assertStringContainsString('application/pdf', $encoded);
        $this->assertStringContainsString('Created by sales', $encoded);
        $this->assertStringNotContainsString('secret-password', $encoded);
        $this->assertStringNotContainsString('secret-api-key', $encoded);
        $this->assertStringNotContainsString('storage/app/private', $encoded);
        $this->assertStringNotContainsString('/files/private/token', $encoded);
        $this->assertStringNotContainsString('session-secret', $encoded);
        $this->assertStringNotContainsString('nested-token', $encoded);
    }

    public function test_default_sanitizer_redacts_contact_and_financial_private_fields(): void
    {
        $payload = app(AssistantContextSanitizer::class)->sanitizeArray([
            'client_name' => 'Acme Sdn Bhd',
            'client_address' => 'Private address',
            'pic_email' => 'person@example.test',
            'bank_account' => '123456789',
            'workflow_progress_json' => '{"internal":true}',
            'summary' => 'Approved vendor record',
        ]);

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringContainsString('Acme Sdn Bhd', $encoded);
        $this->assertStringContainsString('Approved vendor record', $encoded);
        $this->assertStringNotContainsString('Private address', $encoded);
        $this->assertStringNotContainsString('person@example.test', $encoded);
        $this->assertStringNotContainsString('123456789', $encoded);
        $this->assertStringNotContainsString('internal', $encoded);
    }

    public function test_sanitizer_redacts_unsafe_values_even_under_harmless_keys(): void
    {
        $payload = app(AssistantContextSanitizer::class)->detail([
            'title' => 'Safe record',
            'notes' => 'Attachment stored at C:\\laragon\\www\\kijoV2\\storage\\app\\private\\secret.pdf',
            'remarks' => 'Authorization: Bearer sk_test_should_not_leave_backend',
            'payload' => 'data:application/pdf;base64,'.str_repeat('A', 120),
            'signed_link_note' => 'https://example.test/private/file.pdf?expires=999999&signature=abcdef',
            'private_key' => '-----BEGIN PRIVATE KEY----- secret -----END PRIVATE KEY-----',
            'access_key' => 'AKIA_TEST_ACCESS_KEY',
            'plain_note' => 'password=abc123',
            'jwt_note' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signaturetoken',
            'attachment' => [
                'name' => 'scope.pdf',
                'type' => 'application/pdf',
            ],
        ]);

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringContainsString('Safe record', $encoded);
        $this->assertStringContainsString('scope.pdf', $encoded);
        $this->assertStringNotContainsString('laragon', $encoded);
        $this->assertStringNotContainsString('storage\\app\\private', $encoded);
        $this->assertStringNotContainsString('Bearer', $encoded);
        $this->assertStringNotContainsString('data:application/pdf;base64', $encoded);
        $this->assertStringNotContainsString('signature=abcdef', $encoded);
        $this->assertStringNotContainsString('PRIVATE KEY', $encoded);
        $this->assertStringNotContainsString('AKIA_TEST_ACCESS_KEY', $encoded);
        $this->assertStringNotContainsString('password=abc123', $encoded);
        $this->assertStringNotContainsString('eyJhbGci', $encoded);
    }

    public function test_module_source_derives_lifecycle_metadata_from_payload(): void
    {
        $provider = new class(app(\App\Services\Assistant\AssistantText::class)) extends ModuleContextProvider {
            public function key(): string
            {
                return 'test';
            }

            public function supports(string $question, string $currentRoute, Request $request): bool
            {
                return true;
            }

            public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
            {
                return new AssistantContextResult([]);
            }

            public function exposeSource(array $payload): ?array
            {
                return $this->source('test:1', 'project', 'Archived project', '/project/manage/1', $payload, 100, 'Projects');
            }
        };

        $source = $provider->exposeSource([
            'project' => [
                'project_name' => 'Archived project',
                'status' => 'archived',
                'deleted_at' => null,
            ],
        ]);

        $this->assertSame('archived', $source['source_status'] ?? null);
        $this->assertFalse($source['source_is_deleted'] ?? true);
        $this->assertSame('Archived', $source['source_freshness_label'] ?? null);
    }
}
