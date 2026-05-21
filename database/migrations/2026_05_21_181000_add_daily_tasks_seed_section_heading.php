<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $article = DB::table('knowledge_articles')
            ->where('slug', 'how-to-create-and-manage-daily-tasks')
            ->where('created_by_name_code', 'SYSTEM')
            ->where('updated_by_name_code', 'SYSTEM')
            ->first();

        if (! $article || str_contains((string) $article->body_html, '<h3>Creating daily tasks</h3>')) {
            return;
        }

        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'body_html' => '<h3>Creating daily tasks</h3>' . (string) $article->body_html,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $article = DB::table('knowledge_articles')
            ->where('slug', 'how-to-create-and-manage-daily-tasks')
            ->where('created_by_name_code', 'SYSTEM')
            ->where('updated_by_name_code', 'SYSTEM')
            ->first();

        if (! $article) {
            return;
        }

        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'body_html' => preg_replace(
                '/^<h3>Creating daily tasks<\/h3>/',
                '',
                (string) $article->body_html,
            ),
            'updated_at' => now(),
        ]);
    }
};
