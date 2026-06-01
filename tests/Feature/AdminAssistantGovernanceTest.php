<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAssistantGovernanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'assistant_source_gap_actions',
            'assistant_source_gaps',
            'assistant_provider_feedback_memory',
            'assistant_response_feedback',
            'assistant_live_result_cache',
            'assistant_answer_cache',
            'knowledge_assistant_messages',
            'knowledge_articles',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('account_locked_until')->nullable();
            $table->boolean('total_lock')->default(false);
        });

        Schema::create('knowledge_assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('role', 20);
            $table->text('content');
            $table->json('sources_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
        });

        foreach (['assistant_answer_cache', 'assistant_live_result_cache'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->string('cache_key', 64)->unique();
                $table->string('question_hash', 64);
                $table->string('normalized_question', 500);
                $table->string('source_fingerprint', 64)->nullable();
                $table->json('answer_json');
                $table->string('answer_signature', 64)->nullable()->index();
                $table->unsignedInteger('hit_count')->default(0);
                if ($tableName === 'assistant_live_result_cache') {
                    $table->string('provider_key')->nullable();
                    $table->string('scope_hash', 64)->nullable();
                    $table->string('route_hash', 64)->nullable();
                    $table->json('sources_json')->nullable();
                    $table->timestamp('refreshed_at')->nullable();
                }
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('assistant_response_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id')->index();
            $table->unsignedBigInteger('thread_id')->index();
            $table->unsignedBigInteger('staff_id')->index();
            $table->string('rating', 20)->index();
            $table->json('reasons_json')->nullable();
            $table->text('note')->nullable();
            $table->text('question')->nullable();
            $table->text('answer_excerpt')->nullable();
            $table->json('sources_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->string('answer_mode', 20)->nullable();
            $table->string('current_route', 255)->nullable();
            $table->string('answer_signature', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('assistant_provider_feedback_memory', function (Blueprint $table): void {
            $table->id();
            $table->string('memory_key', 64)->unique();
            $table->string('question_hash', 64)->index();
            $table->string('normalized_question', 500);
            $table->string('provider_key', 191)->index();
            $table->string('source_type', 80)->index();
            $table->string('source_slug', 255)->nullable()->index();
            $table->string('route_hash', 64)->nullable()->index();
            $table->string('scope_hash', 64)->index();
            $table->unsignedInteger('positive_count')->default(0);
            $table->unsignedInteger('negative_count')->default(0);
            $table->timestamp('last_feedback_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('assistant_source_gaps', function (Blueprint $table): void {
            $table->id();
            $table->string('gap_key', 64)->unique();
            $table->string('normalized_intent', 500);
            $table->text('sample_question')->nullable();
            $table->string('current_route', 255)->nullable();
            $table->json('source_types_json')->nullable();
            $table->json('provider_keys_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->string('answer_mode', 20)->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('priority', 20)->default('low');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_source_gap_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_gap_id')->index();
            $table->string('action_type', 40);
            $table->string('status', 30)->default('open');
            $table->string('target_provider_key', 120)->nullable();
            $table->unsignedBigInteger('knowledge_article_id')->nullable();
            $table->string('title', 191)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('body_html');
            $table->string('category', 80);
            $table->json('tags')->nullable();
            $table->string('related_route', 255)->nullable();
            $table->text('contributor_note')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->string('created_by_name_code', 50)->nullable();
            $table->unsignedBigInteger('updated_by_staff_id')->nullable();
            $table->string('updated_by_name_code', 50)->nullable();
            $table->timestamps();
        });

        DB::table('system_users')->insert([
            ['id' => 1, 'staff_id' => 10, 'email' => 'admin@example.test', 'role' => json_encode(['System Admin']), 'is_active' => 1],
            ['id' => 2, 'staff_id' => 20, 'email' => 'manager@example.test', 'role' => json_encode(['Manager']), 'is_active' => 1],
        ]);
    }

    public function test_system_admin_can_read_assistant_governance_overview(): void
    {
        DB::table('knowledge_assistant_messages')->insert([
            [
                'thread_id' => 1,
                'role' => 'assistant',
                'content' => 'Answer',
                'sources_json' => json_encode(['sources' => [], 'ai_status' => 'usage_limit', 'degraded_reason' => 'usage_limit']),
                'confidence' => 'low',
                'input_tokens' => 20,
                'output_tokens' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'thread_id' => 1,
                'role' => 'assistant',
                'content' => 'Fallback answer',
                'sources_json' => json_encode(['sources' => [['provider_key' => 'knowledge', 'source_type' => 'knowledge']], 'ai_status' => 'source_fallback']),
                'confidence' => 'low',
                'input_tokens' => 10,
                'output_tokens' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('assistant_response_feedback')->insert([
            'message_id' => 1,
            'thread_id' => 1,
            'staff_id' => 10,
            'rating' => 'bad',
            'reasons_json' => json_encode(['Missing data']),
            'answer_signature' => 'abc123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withAdminSession()
            ->getJson('/admin/assistant/overview')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('summary.bad_count', 1)
            ->assertJsonPath('summary.blocked_signature_count', 1)
            ->assertJsonPath('summary.no_source_count', 1)
            ->assertJsonPath('summary.usage_limit_count', 1)
            ->assertJsonPath('summary.source_fallback_count', 1)
            ->assertJsonPath('summary.ai_unavailable_count', 1);
    }

    public function test_assistant_governance_requires_system_admin(): void
    {
        $this->withSession(['user_id' => 2, 'staff_id' => 20, 'roles' => ['Manager']])
            ->getJson('/admin/assistant/overview')
            ->assertStatus(403);
    }

    public function test_system_admin_can_update_source_gap_status_and_unblock_signature(): void
    {
        DB::table('assistant_source_gaps')->insert([
            'gap_key' => 'gap-a',
            'normalized_intent' => 'unknown module',
            'sample_question' => 'unknown module',
            'occurrence_count' => 2,
            'status' => 'open',
            'priority' => 'low',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assistant_response_feedback')->insert([
            'message_id' => 1,
            'thread_id' => 1,
            'staff_id' => 10,
            'rating' => 'bad',
            'answer_signature' => 'sig-blocked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gapId = (int) DB::table('assistant_source_gaps')->value('id');

        $this->withAdminSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/admin/assistant/source-gaps/{$gapId}/status", ['status' => 'planned', 'notes' => 'Add provider'])
            ->assertOk();

        $this->assertDatabaseHas('assistant_source_gaps', ['id' => $gapId, 'status' => 'planned']);

        $this->withAdminSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/admin/assistant/blocked-signatures/sig-blocked/unblock')
            ->assertOk();

        $this->assertDatabaseMissing('assistant_response_feedback', ['answer_signature' => 'sig-blocked']);
    }

    public function test_system_admin_can_read_analytics_and_promote_source_gap_actions(): void
    {
        DB::table('assistant_response_feedback')->insert([
            'message_id' => 1,
            'thread_id' => 1,
            'staff_id' => 10,
            'rating' => 'helpful',
            'sources_json' => json_encode([['provider_key' => 'invoice', 'source_type' => 'invoice']]),
            'confidence' => 'medium',
            'answer_mode' => 'live',
            'current_route' => '/commercial/invoice',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assistant_provider_feedback_memory')->insert([
            'memory_key' => 'memory-invoice',
            'question_hash' => 'hash',
            'normalized_question' => 'invoice status',
            'provider_key' => 'invoice',
            'source_type' => 'invoice',
            'scope_hash' => 'scope',
            'positive_count' => 2,
            'negative_count' => 0,
            'last_feedback_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assistant_source_gaps')->insert([
            'gap_key' => 'gap-invoice',
            'normalized_intent' => 'invoice missing',
            'sample_question' => 'invoice missing',
            'current_route' => '/commercial/invoice',
            'provider_keys_json' => json_encode(['invoice']),
            'source_types_json' => json_encode(['invoice']),
            'occurrence_count' => 4,
            'status' => 'open',
            'priority' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gapId = (int) DB::table('assistant_source_gaps')->where('gap_key', 'gap-invoice')->value('id');
        DB::table('knowledge_assistant_messages')->insert([
            'thread_id' => 1,
            'role' => 'assistant',
            'content' => 'AI usage fallback. Provider raw error: insufficient_quota should not be exposed.',
            'sources_json' => json_encode([
                'sources' => [['provider_key' => 'invoice', 'source_type' => 'invoice']],
                'ai_status' => 'usage_limit',
                'degraded_reason' => 'usage_limit',
            ]),
            'confidence' => 'low',
            'input_tokens' => 200,
            'output_tokens' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withAdminSession()
            ->getJson('/admin/assistant/analytics/overview?provider=invoice')
            ->assertOk()
            ->assertJsonPath('summary.helpful_count', 1)
            ->assertJsonPath('summary.usage_limit_count', 1)
            ->assertJsonPath('summary.ai_unavailable_count', 1)
            ->assertJsonMissing(['insufficient_quota']);

        $this->withAdminSession()
            ->getJson('/admin/assistant/analytics/providers')
            ->assertOk()
            ->assertJsonFragment(['provider_key' => 'invoice']);

        $this->withAdminSession()
            ->getJson('/admin/assistant/analytics/source-gaps')
            ->assertOk()
            ->assertJsonFragment(['priority' => 'medium']);

        $this->withAdminSession()
            ->getJson('/admin/assistant/analytics/overview?date_from=not-a-date')
            ->assertOk()
            ->assertJsonPath('summary.helpful_count', 1);

        $this->withAdminSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/admin/assistant/source-gaps/{$gapId}/promote-provider-backlog", [
                'notes' => 'Need invoice provider detail',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('assistant_source_gap_actions', [
            'source_gap_id' => $gapId,
            'action_type' => 'provider_backlog',
            'target_provider_key' => 'invoice',
        ]);
    }

    public function test_system_admin_can_create_unpublished_knowledge_draft_from_source_gap(): void
    {
        DB::table('assistant_source_gaps')->insert([
            'gap_key' => 'gap-knowledge',
            'normalized_intent' => 'how handle invoice dispute',
            'sample_question' => 'How do I handle invoice dispute?',
            'current_route' => '/commercial/invoice',
            'provider_keys_json' => json_encode(['invoice']),
            'source_types_json' => json_encode(['invoice']),
            'occurrence_count' => 10,
            'status' => 'open',
            'priority' => 'high',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gapId = (int) DB::table('assistant_source_gaps')->where('gap_key', 'gap-knowledge')->value('id');

        $this->withAdminSession()
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson("/admin/assistant/source-gaps/{$gapId}/create-knowledge-draft", [
                'title' => 'How to Handle Invoice Disputes',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('knowledge_articles', [
            'title' => 'How to Handle Invoice Disputes',
            'status' => 'draft',
            'published_at' => null,
        ]);
        $this->assertDatabaseHas('assistant_source_gap_actions', [
            'source_gap_id' => $gapId,
            'action_type' => 'knowledge_draft',
        ]);
    }

    private function withAdminSession(): self
    {
        return $this->withSession([
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
        ]);
    }
}
