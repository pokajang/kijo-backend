<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
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

            $table->index(['status', 'published_at'], 'knowledge_articles_status_published_idx');
            $table->index('category', 'knowledge_articles_category_idx');
            $table->index('created_by_staff_id', 'knowledge_articles_created_by_idx');
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

            $table->foreign('knowledge_article_id', 'knowledge_article_images_article_fk')
                ->references('id')
                ->on('knowledge_articles')
                ->cascadeOnDelete();
            $table->index('knowledge_article_id', 'knowledge_article_images_article_idx');
        });

        $now = now();
        $articles = [
            [
                'title' => 'How to Apply Leave',
                'category' => 'Leave & HR',
                'summary' => 'Submit personal leave requests and check the approval status.',
                'related_route' => '/my/leaves/apply',
                'body_html' => '<ol><li>Open Account and choose My Leaves.</li><li>Select Apply Leave.</li><li>Choose the leave type and date range.</li><li>Add a reason or attachment when required.</li><li>Submit the request and monitor the status in Leave Records.</li></ol>',
                'tags' => ['leave', 'hr', 'staff'],
            ],
            [
                'title' => 'How to Create a Proposal',
                'category' => 'Proposals',
                'summary' => 'Create a reusable proposal template for sales documents.',
                'related_route' => '/templates/create',
                'body_html' => '<ol><li>Open Proposals and select Create.</li><li>Choose the service type.</li><li>Fill in the proposal details, scope, and pricing content.</li><li>Review the content before saving.</li><li>Use Proposal Records to find the saved proposal.</li></ol>',
                'tags' => ['proposal', 'template'],
            ],
            [
                'title' => 'How to Create a Quotation',
                'category' => 'CRM',
                'summary' => 'Start a quotation from CRM and select the correct service form.',
                'related_route' => '/crm/quotes',
                'body_html' => '<ol><li>Open Quotations.</li><li>Select the client and PIC.</li><li>Choose the service type and inquiry source.</li><li>Complete the service-specific quotation form.</li><li>Save the quotation and check it in Records.</li></ol>',
                'tags' => ['crm', 'quotation'],
            ],
            [
                'title' => 'How to Submit a Support Request',
                'category' => 'Support',
                'summary' => 'Request internal tools, assets, or support from the support module.',
                'related_route' => '/support/requests',
                'body_html' => '<ol><li>Open Support and choose Request Tool.</li><li>Click the request button.</li><li>Fill in the equipment or support details.</li><li>Submit the request.</li><li>Update achievement when the request is completed.</li></ol>',
                'tags' => ['support', 'request'],
            ],
            [
                'title' => 'How to Check Your Tasks',
                'category' => 'Getting Started',
                'summary' => 'Review assigned tasks and open task details.',
                'related_route' => '/task-manager',
                'body_html' => '<ol><li>Open Five Minutes Meeting or Tasks from the bottom navigation.</li><li>Review the task list.</li><li>Open a task to see details and comments.</li><li>Update progress when the work is complete.</li></ol>',
                'tags' => ['tasks', 'getting-started'],
            ],
        ];

        foreach ($articles as $article) {
            DB::table('knowledge_articles')->insert([
                'title' => $article['title'],
                'slug' => Str::slug($article['title']),
                'summary' => $article['summary'],
                'body_html' => $article['body_html'],
                'category' => $article['category'],
                'tags' => json_encode($article['tags']),
                'related_route' => $article['related_route'],
                'contributor_note' => 'Starter guide seeded by system.',
                'status' => 'published',
                'published_at' => $now,
                'created_by_staff_id' => null,
                'created_by_name_code' => 'SYSTEM',
                'updated_by_staff_id' => null,
                'updated_by_name_code' => 'SYSTEM',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_article_images');
        Schema::dropIfExists('knowledge_articles');
    }
};
