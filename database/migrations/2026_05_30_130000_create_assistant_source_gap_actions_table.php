<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_source_gaps') && ! Schema::hasColumn('assistant_source_gaps', 'priority')) {
            Schema::table('assistant_source_gaps', function (Blueprint $table): void {
                $table->string('priority', 20)->default('low')->after('status')->index();
            });
        }

        if (! Schema::hasTable('assistant_source_gap_actions')) {
            Schema::create('assistant_source_gap_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('source_gap_id')->index();
                $table->string('action_type', 40)->index();
                $table->string('status', 30)->default('open')->index();
                $table->string('target_provider_key', 120)->nullable()->index();
                $table->unsignedBigInteger('knowledge_article_id')->nullable()->index();
                $table->string('title', 191)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_staff_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('source_gap_id', 'assistant_source_gap_actions_gap_fk')
                    ->references('id')
                    ->on('assistant_source_gaps')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_source_gap_actions');

        if (Schema::hasTable('assistant_source_gaps') && Schema::hasColumn('assistant_source_gaps', 'priority')) {
            Schema::table('assistant_source_gaps', function (Blueprint $table): void {
                $table->dropColumn('priority');
            });
        }
    }
};
