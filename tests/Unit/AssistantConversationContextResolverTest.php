<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantConversationContextResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantConversationContextResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('knowledge_assistant_thread_contexts');
        Schema::dropIfExists('knowledge_assistant_messages');

        Schema::create('knowledge_assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('role', 20);
            $table->text('content');
            $table->json('sources_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_assistant_thread_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id')->unique();
            $table->json('context_json');
            $table->unsignedBigInteger('last_processed_message_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_ambiguous_follow_up_returns_clarification_options(): void
    {
        DB::table('knowledge_assistant_thread_contexts')->insert([
            'thread_id' => 7,
            'context_json' => json_encode([
                'active_entities' => [
                    [
                        'slug' => 'proposal-template:ih:11',
                        'source_type' => 'proposal_template',
                        'entity_type' => 'service',
                        'title' => 'CEM Chemical Exposure Monitoring',
                        'related_route' => '/templates/proposals/ih/11',
                        'service_key' => 'ih',
                        'codes' => ['CEM'],
                        'keywords' => ['cem', 'chemical', 'exposure', 'monitoring'],
                        'priority' => 380,
                        'last_seen_message_id' => 10,
                    ],
                    [
                        'slug' => 'proposal-template:ih:12',
                        'source_type' => 'proposal_template',
                        'entity_type' => 'service',
                        'title' => 'CHRA Chemical Health Risk Assessment',
                        'related_route' => '/templates/proposals/ih/12',
                        'service_key' => 'ih',
                        'codes' => ['CHRA'],
                        'keywords' => ['chra', 'chemical', 'health', 'risk', 'assessment'],
                        'priority' => 380,
                        'last_seen_message_id' => 11,
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(AssistantConversationContextResolver::class)->resolve(
            7,
            'how to quote this service',
            '/crm/quotes',
        );

        $this->assertTrue($resolved['clarification_needed']);
        $this->assertStringContainsString('Which previous item', $resolved['clarification_question']);
        $this->assertCount(2, $resolved['clarification_options']);
        $this->assertSame('CEM Chemical Exposure Monitoring', $resolved['clarification_options'][0]['label']);
        $this->assertSame('/templates/proposals/ih/11', $resolved['clarification_options'][0]['related_route']);
    }

    public function test_generic_follow_up_across_mixed_entities_asks_clarification(): void
    {
        DB::table('knowledge_assistant_thread_contexts')->insert([
            'thread_id' => 8,
            'context_json' => json_encode([
                'active_entities' => [
                    [
                        'slug' => 'quote-record:ih:44',
                        'source_type' => 'quote_record',
                        'entity_type' => 'quote',
                        'title' => 'QTR26-0044 CEM Quote',
                        'related_route' => '/crm/quotes?service=ih&edit=true&quoteId=44',
                        'service_key' => 'ih',
                        'codes' => ['QTR26-0044'],
                        'keywords' => ['qtr26', '0044', 'cem', 'quote'],
                        'priority' => 360,
                        'last_seen_message_id' => 21,
                    ],
                    [
                        'slug' => 'proposal-template:ih:11',
                        'source_type' => 'proposal_template',
                        'entity_type' => 'service',
                        'title' => 'CEM Chemical Exposure Monitoring',
                        'related_route' => '/templates/proposals/ih/11',
                        'service_key' => 'ih',
                        'codes' => ['CEM'],
                        'keywords' => ['cem', 'chemical', 'exposure', 'monitoring'],
                        'priority' => 380,
                        'last_seen_message_id' => 20,
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(AssistantConversationContextResolver::class)->resolve(
            8,
            'can we use it?',
            '',
        );

        $this->assertTrue($resolved['clarification_needed']);
        $this->assertStringContainsString('Which previous item', $resolved['clarification_question']);
        $this->assertCount(2, $resolved['clarification_options']);
        $this->assertSame('QTR26-0044 CEM Quote', $resolved['clarification_options'][0]['label']);
        $this->assertSame('CEM Chemical Exposure Monitoring', $resolved['clarification_options'][1]['label']);
    }

    public function test_typed_follow_up_prefers_requested_entity_type_without_clarification(): void
    {
        DB::table('knowledge_assistant_thread_contexts')->insert([
            'thread_id' => 9,
            'context_json' => json_encode([
                'active_entities' => [
                    [
                        'slug' => 'proposal-template:ih:11',
                        'source_type' => 'proposal_template',
                        'entity_type' => 'service',
                        'title' => 'CEM Chemical Exposure Monitoring',
                        'related_route' => '/templates/proposals/ih/11',
                        'service_key' => 'ih',
                        'codes' => ['CEM'],
                        'keywords' => ['cem', 'chemical', 'exposure', 'monitoring'],
                        'priority' => 380,
                        'last_seen_message_id' => 20,
                    ],
                    [
                        'slug' => 'quote-record:ih:44',
                        'source_type' => 'quote_record',
                        'entity_type' => 'quote',
                        'title' => 'QTR26-0044 CEM Quote',
                        'related_route' => '/crm/quotes?service=ih&edit=true&quoteId=44',
                        'service_key' => 'ih',
                        'codes' => ['QTR26-0044'],
                        'keywords' => ['qtr26', '0044', 'cem', 'quote'],
                        'priority' => 360,
                        'last_seen_message_id' => 21,
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(AssistantConversationContextResolver::class)->resolve(
            9,
            'what is the status of this quote?',
            '',
        );

        $this->assertFalse($resolved['clarification_needed']);
        $this->assertSame('quote-record:ih:44', $resolved['conversation_focus']['slug'] ?? null);
        $this->assertStringContainsString('QTR26-0044 CEM Quote', $resolved['retrieval_question']);
    }

    public function test_policy_how_about_follow_up_keeps_handbook_focus(): void
    {
        DB::table('knowledge_assistant_thread_contexts')->insert([
            'thread_id' => 10,
            'context_json' => json_encode([
                'active_entities' => [
                    [
                        'slug' => 'handbook:1:9-common-rules',
                        'source_type' => 'handbook',
                        'entity_type' => 'handbook',
                        'title' => 'Handbook: 9.0 Common Rules',
                        'related_route' => '/handbook',
                        'service_key' => null,
                        'codes' => [],
                        'keywords' => ['handbook', '9', 'common', 'rules'],
                        'priority' => 220,
                        'last_seen_message_id' => 30,
                    ],
                    [
                        'slug' => 'proposal-template:ih:11',
                        'source_type' => 'proposal_template',
                        'entity_type' => 'service',
                        'title' => 'CEM Chemical Exposure Monitoring',
                        'related_route' => '/templates/proposals/ih/11',
                        'service_key' => 'ih',
                        'codes' => ['CEM'],
                        'keywords' => ['cem', 'chemical', 'exposure', 'monitoring'],
                        'priority' => 380,
                        'last_seen_message_id' => 20,
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(AssistantConversationContextResolver::class)->resolve(
            10,
            'how about lunch break?',
            '',
        );

        $this->assertFalse($resolved['clarification_needed']);
        $this->assertSame('handbook:1:9-common-rules', $resolved['conversation_focus']['slug'] ?? null);
        $this->assertStringContainsString('Handbook: 9.0 Common Rules', $resolved['retrieval_question']);
    }

    public function test_direct_self_trace_questions_bypass_ambiguous_follow_up_clarification(): void
    {
        DB::table('knowledge_assistant_thread_contexts')->insert([
            'thread_id' => 11,
            'context_json' => json_encode([
                'active_entities' => [
                    $this->traceEntity('user-trace:user_trace.kpi_status:aaa111', 'My KPI trace'),
                    $this->traceEntity('user-trace:user_trace.leave_taken:bbb222', 'My leave trace'),
                    $this->traceEntity('user-trace:user_trace.quote_issued:ccc333', 'My quotation trace'),
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'how can i improve further',
            'how many years have i spent here in this company',
            'berapa quotation saya tahun ini',
        ] as $question) {
            $resolved = app(AssistantConversationContextResolver::class)->resolve(11, $question, '');

            $this->assertFalse($resolved['clarification_needed'], $question);
            $this->assertNull($resolved['conversation_focus'], $question);
            $this->assertSame($resolved['normalized_question'], $resolved['retrieval_question'], $question);
        }
    }

    private function traceEntity(string $slug, string $title): array
    {
        return [
            'slug' => $slug,
            'source_type' => 'user_trace',
            'entity_type' => 'user_trace',
            'title' => $title,
            'related_route' => '/my/profile',
            'service_key' => null,
            'codes' => [],
            'keywords' => ['user', 'trace'],
            'priority' => 320,
            'last_seen_message_id' => 40,
        ];
    }
}
