<?php

namespace Tests\Unit;

use App\Services\Knowledge\KnowledgeAssistantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KnowledgeAssistantServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('knowledge_articles');
        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('body_html');
            $table->string('category', 80);
            $table->json('tags')->nullable();
            $table->string('related_route', 255)->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_rank_articles_uses_title_tags_body_and_route_boost(): void
    {
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'quote-guide',
            'summary' => 'CRM quotation flow.',
            'body_html' => '<p>Select client and service type.</p>',
            'tags' => ['quotation', 'crm'],
            'related_route' => '/crm/quotes',
        ]);
        $this->insertArticle([
            'title' => 'How to Apply Leave',
            'slug' => 'leave-guide',
            'summary' => 'Leave flow.',
            'body_html' => '<p>Annual leave request.</p>',
            'tags' => ['leave'],
            'related_route' => '/my/leaves/apply',
        ]);

        $results = app(KnowledgeAssistantService::class)->rankArticles(
            'how do i create quotation',
            '/crm/quotes',
        );

        $this->assertSame('quote-guide', $results[0]['slug']);
        $this->assertSame('/crm/quotes', $results[0]['related_route']);
    }

    public function test_rank_articles_accepts_natural_wording_but_ignores_action_only_matches(): void
    {
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'quote-guide',
            'summary' => 'CRM quotation flow.',
            'body_html' => '<p>Select client and service type.</p>',
            'tags' => ['quotation', 'crm'],
            'related_route' => '/crm/quotes',
        ]);

        $service = app(KnowledgeAssistantService::class);

        $this->assertSame(
            'quote-guide',
            $service->rankArticles('how do i make quotation')[0]['slug'],
        );
        $this->assertSame([], $service->rankArticles('how do i create lunch'));
    }

    public function test_rank_articles_can_use_current_route_for_vague_questions(): void
    {
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'quote-guide',
            'summary' => 'CRM quotation flow.',
            'body_html' => '<p>Select client and service type.</p>',
            'tags' => ['quotation', 'crm'],
            'related_route' => '/crm/quotes',
        ]);

        $results = app(KnowledgeAssistantService::class)->rankArticles('how do i', '/crm/quotes');

        $this->assertSame('quote-guide', $results[0]['slug']);
    }

    public function test_rank_articles_understands_bahasa_malaysia_quotation_terms(): void
    {
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'quote-guide',
            'summary' => 'CRM quotation flow.',
            'body_html' => '<p>Select client and service type.</p>',
            'tags' => ['quotation', 'crm'],
            'related_route' => '/crm/quotes',
        ]);

        $results = app(KnowledgeAssistantService::class)->rankArticles('macam mana nak buat sebut harga');

        $this->assertSame('quote-guide', $results[0]['slug']);
    }

    public function test_plain_text_strips_unsafe_html_and_excerpt_limits_length(): void
    {
        $service = app(KnowledgeAssistantService::class);
        $plain = $service->plainTextFromHtml('<p>Hello&nbsp;world</p><script>alert("bad")</script>');

        $this->assertSame('Hello world', $plain);
        $this->assertStringNotContainsString('alert', $plain);
        $this->assertSame(20, strlen($service->excerpt(str_repeat('a', 50), 20)));
    }

    private function insertArticle(array $overrides): void
    {
        DB::table('knowledge_articles')->insert([
            'title' => $overrides['title'],
            'slug' => $overrides['slug'],
            'summary' => $overrides['summary'] ?? '',
            'body_html' => $overrides['body_html'] ?? '<p>Guide.</p>',
            'category' => $overrides['category'] ?? 'System',
            'tags' => json_encode($overrides['tags'] ?? []),
            'related_route' => $overrides['related_route'] ?? null,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
