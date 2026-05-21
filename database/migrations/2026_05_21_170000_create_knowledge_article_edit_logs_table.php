<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_edit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('knowledge_article_id');
            $table->string('action', 40);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('name_code', 50)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('knowledge_article_id', 'knowledge_article_edit_logs_article_fk')
                ->references('id')
                ->on('knowledge_articles')
                ->cascadeOnDelete();
            $table->index('knowledge_article_id', 'knowledge_article_edit_logs_article_idx');
        });

        $now = now();
        DB::table('knowledge_articles')
            ->select('id', 'created_by_staff_id', 'created_by_name_code', 'created_at')
            ->orderBy('id')
            ->chunk(100, function ($articles) use ($now): void {
                foreach ($articles as $article) {
                    DB::table('knowledge_article_edit_logs')->insert([
                        'knowledge_article_id' => $article->id,
                        'action' => 'created',
                        'remarks' => 'Existing article imported into edit logs.',
                        'staff_id' => $article->created_by_staff_id,
                        'name_code' => $article->created_by_name_code,
                        'created_at' => $article->created_at ?: $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_article_edit_logs');
    }
};
