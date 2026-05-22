<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\KnowledgeController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('knowledge_article_edit_logs');
        Schema::dropIfExists('knowledge_article_images');
        Schema::dropIfExists('knowledge_articles');
        Schema::dropIfExists('system_users');

        Schema::create('knowledge_articles', function (Blueprint $table) {
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

        Schema::create('knowledge_article_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('knowledge_article_id');
            $table->string('disk_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('description', 500);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('knowledge_article_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('knowledge_article_id');
            $table->string('action', 40);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('name_code', 50)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 7,
            'email' => 'staff7@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_staff_can_create_draft_and_self_publish(): void
    {
        $controller = app(KnowledgeController::class);

        $draft = $controller->store($this->request('POST', ['staff_id' => 7, 'name_code' => 'ST7'], [
            'title' => 'How to Use CRM',
            'summary' => 'CRM guide',
            'category' => 'CRM',
            'body_html' => '<p>Open CRM and start from the dashboard.</p>',
            'status' => 'draft',
        ]))->getData(true);

        $this->assertSame('success', $draft['status']);
        $this->assertSame('draft', $draft['data']['status']);
        $this->assertSame(0, DB::table('knowledge_articles')->where('status', 'published')->count());

        $published = $controller->publish(
            $this->request('POST', ['staff_id' => 7, 'name_code' => 'ST7']),
            (int) $draft['data']['id'],
        )->getData(true);

        $this->assertSame('published', $published['data']['status']);
        $this->assertSame(1, DB::table('knowledge_articles')->where('status', 'published')->count());
    }

    public function test_public_index_hides_drafts(): void
    {
        $controller = app(KnowledgeController::class);
        $this->insertArticle(['title' => 'Draft Guide', 'slug' => 'draft-guide', 'status' => 'draft']);
        $this->insertArticle([
            'title' => 'Published Guide',
            'slug' => 'published-guide',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $body = $controller->index($this->request('GET', ['staff_id' => 9]))->getData(true);

        $this->assertCount(1, $body['data']);
        $this->assertSame('Published Guide', $body['data'][0]['title']);
    }

    public function test_my_articles_returns_shared_article_workspace(): void
    {
        $controller = app(KnowledgeController::class);
        $this->insertArticle([
            'title' => 'My Draft Guide',
            'slug' => 'my-draft-guide',
            'status' => 'draft',
            'created_by_staff_id' => 7,
        ]);
        $this->insertArticle([
            'title' => 'Other Draft Guide',
            'slug' => 'other-draft-guide',
            'status' => 'draft',
            'created_by_staff_id' => 8,
        ]);

        $body = $controller->mine($this->request('GET', ['staff_id' => 7]))->getData(true);

        $this->assertSame(['Other Draft Guide', 'My Draft Guide'], array_column($body['data'], 'title'));
    }

    public function test_public_index_does_not_include_current_staff_own_drafts(): void
    {
        $controller = app(KnowledgeController::class);
        $this->insertArticle([
            'title' => 'My Draft Guide',
            'slug' => 'my-draft-guide',
            'status' => 'draft',
            'created_by_staff_id' => 7,
        ]);

        $body = $controller->index($this->request('GET', ['staff_id' => 7]))->getData(true);

        $this->assertSame([], array_column($body['data'], 'title'));
    }

    public function test_list_response_includes_safe_search_text_with_body_keywords(): void
    {
        $controller = app(KnowledgeController::class);
        $this->insertArticle([
            'title' => 'Proposal Guide',
            'slug' => 'proposal-guide',
            'summary' => 'Create proposal templates.',
            'body_html' => '<h2>BM proposal</h2><script>alert("bad")</script><p>Machine translated Bahasa Melayu copy.</p>',
            'category' => 'Proposals',
            'tags' => json_encode(['proposal', 'bm']),
            'related_route' => '/templates/create',
            'status' => 'published',
            'published_at' => now(),
            'created_by_name_code' => 'ST7',
            'updated_by_name_code' => 'ST8',
        ]);

        $body = $controller->index($this->request('GET', ['staff_id' => 7]))->getData(true);
        $searchText = $body['data'][0]['search_text'];

        $this->assertStringContainsString('Proposal Guide', $searchText);
        $this->assertStringContainsString('Machine translated Bahasa Melayu copy.', $searchText);
        $this->assertStringContainsString('/templates/create', $searchText);
        $this->assertStringContainsString('ST8', $searchText);
        $this->assertStringNotContainsString('<h2>', $searchText);
        $this->assertStringNotContainsString('<script', $searchText);
        $this->assertStringNotContainsString('alert("bad")', $searchText);
        $this->assertNull($body['data'][0]['body_html']);
    }

    public function test_list_response_caps_search_text_length(): void
    {
        $controller = app(KnowledgeController::class);
        $this->insertArticle([
            'title' => 'Long Guide',
            'slug' => 'long-guide',
            'body_html' => '<p>' . str_repeat('long searchable keyword ', 1200) . '</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $body = $controller->index($this->request('GET', ['staff_id' => 7]))->getData(true);

        $this->assertLessThanOrEqual(12000, strlen($body['data'][0]['search_text']));
    }

    public function test_authenticated_staff_can_update_shared_article_with_remarks(): void
    {
        $controller = app(KnowledgeController::class);
        $articleId = $this->insertArticle([
            'title' => 'Owner Guide',
            'slug' => 'owner-guide',
            'created_by_staff_id' => 7,
        ]);

        $allowed = $controller->update($this->request('POST', ['staff_id' => 8, 'name_code' => 'ST8'], [
            'title' => 'Shared Update',
            'summary' => 'Updated',
            'category' => 'CRM',
            'body_html' => '<p>Updated</p>',
            'status' => 'draft',
            'edit_remarks' => 'Clarified the CRM steps.',
        ]), $articleId);
        $body = $allowed->getData(true);

        $this->assertSame('Shared Update', $body['data']['title']);
        $this->assertSame('ST8', $body['data']['latest_edit_log']['name_code']);
        $this->assertSame('Clarified the CRM steps.', $body['data']['latest_edit_log']['remarks']);
        $this->assertSame(1, DB::table('knowledge_article_edit_logs')->where('knowledge_article_id', $articleId)->count());
    }

    public function test_update_requires_edit_remarks(): void
    {
        $controller = app(KnowledgeController::class);
        $articleId = $this->insertArticle([
            'title' => 'Owner Guide',
            'slug' => 'owner-guide',
            'created_by_staff_id' => 7,
        ]);

        $response = $controller->update($this->request('POST', ['staff_id' => 8], [
            'title' => 'Shared Update',
            'summary' => 'Updated',
            'category' => 'CRM',
            'body_html' => '<p>Updated</p>',
            'status' => 'draft',
        ]), $articleId);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_status_actions_store_supplied_edit_remarks(): void
    {
        $controller = app(KnowledgeController::class);
        $articleId = $this->insertArticle([
            'title' => 'Draft Guide',
            'slug' => 'draft-guide',
            'status' => 'draft',
        ]);

        $response = $controller->publish($this->request('POST', ['staff_id' => 8, 'name_code' => 'ST8'], [
            'edit_remarks' => 'Reviewed and ready for the team.',
        ]), $articleId);
        $body = $response->getData(true);

        $this->assertSame('published', $body['data']['status']);
        $this->assertSame('ST8', $body['data']['latest_edit_log']['name_code']);
        $this->assertSame('Reviewed and ready for the team.', $body['data']['latest_edit_log']['remarks']);
    }

    public function test_slug_collision_uses_numeric_suffix(): void
    {
        $controller = app(KnowledgeController::class);
        $payload = [
            'title' => 'Same Title',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p>Guide content</p>',
            'status' => 'published',
        ];

        $first = $controller->store($this->request('POST', ['staff_id' => 7], $payload))->getData(true);
        $second = $controller->store($this->request('POST', ['staff_id' => 7], $payload))->getData(true);

        $this->assertSame('same-title', $first['data']['slug']);
        $this->assertSame('same-title-2', $second['data']['slug']);
    }

    public function test_reserved_and_numeric_slugs_are_prefixed(): void
    {
        $controller = app(KnowledgeController::class);

        $reserved = $controller->store($this->request('POST', ['staff_id' => 7], [
            'title' => 'My',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p>Guide content</p>',
            'status' => 'draft',
        ]))->getData(true);
        $numeric = $controller->store($this->request('POST', ['staff_id' => 7], [
            'title' => '123',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p>Guide content</p>',
            'status' => 'draft',
        ]))->getData(true);

        $this->assertSame('knowledge-my', $reserved['data']['slug']);
        $this->assertSame('knowledge-123', $numeric['data']['slug']);
    }

    public function test_update_endpoint_cannot_change_lifecycle_status(): void
    {
        $controller = app(KnowledgeController::class);
        $articleId = $this->insertArticle([
            'title' => 'Published Guide',
            'slug' => 'published-guide',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $controller->update($this->request('POST', ['staff_id' => 8], [
            'title' => 'Published Guide Updated',
            'summary' => 'Updated',
            'category' => 'CRM',
            'body_html' => '<p>Updated</p>',
            'status' => 'draft',
            'edit_remarks' => 'Tried to unpublish through update.',
        ]), $articleId);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'published',
            DB::table('knowledge_articles')->where('id', $articleId)->value('status'),
        );
    }

    public function test_article_html_is_sanitized_before_storage(): void
    {
        $controller = app(KnowledgeController::class);

        $created = $controller->store($this->request('POST', ['staff_id' => 7], [
            'title' => 'Safe HTML',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p onclick="alert(1)">Open <a href="javascript:alert(1)" style="color:red">CRM</a>.</p><script>alert(1)</script>',
            'status' => 'published',
        ]))->getData(true);

        $html = $created['data']['body_html'];
        $this->assertStringContainsString('<p>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_category_is_derived_when_not_submitted(): void
    {
        $controller = app(KnowledgeController::class);

        $created = $controller->store($this->request('POST', ['staff_id' => 7], [
            'title' => 'How to Apply Leave',
            'summary' => 'Apply leave from the staff workspace.',
            'body_html' => '<p>Open the leave form.</p>',
            'related_route' => '/my/leaves/apply',
            'status' => 'draft',
        ]))->getData(true);

        $this->assertSame('Leave & HR', $created['data']['category']);
    }

    public function test_archived_article_cannot_be_republished_or_updated(): void
    {
        $controller = app(KnowledgeController::class);
        $articleId = $this->insertArticle([
            'title' => 'Archived Guide',
            'slug' => 'archived-guide',
            'status' => 'archived',
            'created_by_staff_id' => 7,
        ]);

        $publish = $controller->publish($this->request('POST', ['staff_id' => 7]), $articleId);
        $this->assertSame(422, $publish->getStatusCode());

        $update = $controller->update($this->request('POST', ['staff_id' => 7], [
            'title' => 'Updated Archived',
            'summary' => 'Updated',
            'body_html' => '<p>Updated</p>',
            'status' => 'published',
            'edit_remarks' => 'Attempt restore.',
        ]), $articleId);
        $this->assertSame(422, $update->getStatusCode());
    }

    public function test_image_description_is_required(): void
    {
        Storage::fake('public');
        $controller = app(KnowledgeController::class);
        $request = $this->request('POST', ['staff_id' => 7], [
            'title' => 'Image Guide',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p>Guide content</p>',
            'status' => 'draft',
        ]);
        $request->files->set('images', [UploadedFile::fake()->image('screen.png')]);

        $response = $controller->store($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, DB::table('knowledge_article_images')->count());
    }

    public function test_removed_images_are_deleted_from_public_disk_after_update(): void
    {
        Storage::fake('public');
        $controller = app(KnowledgeController::class);
        $request = $this->request('POST', ['staff_id' => 7], [
            'title' => 'Image Guide',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p>Guide content</p>',
            'status' => 'draft',
            'image_descriptions' => ['Original screenshot'],
        ]);
        $request->files->set('images', [UploadedFile::fake()->image('screen.png')]);

        $created = $controller->store($request)->getData(true);
        $path = DB::table('knowledge_article_images')->value('disk_path');
        Storage::disk('public')->assertExists($path);

        $controller->update($this->request('POST', ['staff_id' => 7], [
            'title' => 'Image Guide',
            'summary' => 'Guide',
            'category' => 'CRM',
            'body_html' => '<p>Guide content updated</p>',
            'edit_remarks' => 'Removed outdated screenshot.',
        ]), (int) $created['data']['id']);

        $this->assertSame(0, DB::table('knowledge_article_images')->count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_authenticated_routes_create_publish_and_list_article(): void
    {
        $create = $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/knowledge/articles', [
                'title' => 'Route Guide',
                'summary' => 'Created through route.',
                'category' => 'CRM',
                'body_html' => '<p>Route content</p>',
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->json('data');

        $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/knowledge/articles/' . $create['id'] . '/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->withSession($this->authenticatedSession())
            ->getJson('/knowledge/articles')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Route Guide');
    }

    public function test_route_validation_errors_use_standard_error_envelope(): void
    {
        $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/knowledge/articles', [
                'summary' => 'Missing title.',
                'category' => 'CRM',
                'body_html' => '<p>Route content</p>',
                'status' => 'draft',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['status', 'message', 'errors' => ['title']]);
    }

    private function insertArticle(array $overrides = []): int
    {
        $now = now();
        return DB::table('knowledge_articles')->insertGetId(array_merge([
            'title' => 'Guide',
            'slug' => 'guide',
            'summary' => 'Summary',
            'body_html' => '<p>Body</p>',
            'category' => 'CRM',
            'tags' => json_encode([]),
            'related_route' => null,
            'contributor_note' => null,
            'status' => 'draft',
            'published_at' => null,
            'created_by_staff_id' => 7,
            'created_by_name_code' => 'ST7',
            'updated_by_staff_id' => 7,
            'updated_by_name_code' => 'ST7',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function request(string $method, array $session = [], array $payload = []): Request
    {
        $request = Request::create('/knowledge/test', $method, $payload);
        $request->setLaravelSession(app('session')->driver());
        foreach ($session as $key => $value) {
            $request->session()->put($key, $value);
        }
        if (!$request->session()->has('roles')) {
            $request->session()->put('roles', []);
        }

        return $request;
    }

    private function authenticatedSession(): array
    {
        return [
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 7,
            'roles' => ['Staff'],
            'name_code' => 'ST7',
        ];
    }
}
