<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_answer_cache')) {
            Schema::create('assistant_answer_cache', function (Blueprint $table): void {
                $table->id();
                $table->string('cache_key', 64)->unique();
                $table->string('question_hash', 64)->index();
                $table->string('normalized_question', 500);
                $table->string('source_fingerprint', 64)->index();
                $table->json('answer_json');
                $table->unsignedInteger('hit_count')->default(0);
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assistant_live_result_cache')) {
            Schema::create('assistant_live_result_cache', function (Blueprint $table): void {
                $table->id();
                $table->string('cache_key', 64)->unique();
                $table->string('question_hash', 64)->index();
                $table->string('normalized_question', 500);
                $table->string('provider_key', 191)->index();
                $table->string('scope_hash', 64)->index();
                $table->string('route_hash', 64)->nullable()->index();
                $table->string('source_fingerprint', 64)->index();
                $table->json('sources_json')->nullable();
                $table->json('answer_json');
                $table->timestamp('refreshed_at')->nullable();
                $table->timestamp('expires_at')->index();
                $table->unsignedInteger('hit_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assistant_query_plan_cache')) {
            Schema::create('assistant_query_plan_cache', function (Blueprint $table): void {
                $table->id();
                $table->string('cache_key', 64)->unique();
                $table->string('question_hash', 64)->index();
                $table->string('normalized_question', 500);
                $table->json('provider_keys_json');
                $table->string('answer_mode', 20)->index();
                $table->string('scope_hash', 64)->nullable()->index();
                $table->string('source_fingerprint', 64)->nullable()->index();
                $table->unsignedInteger('hit_count')->default(0);
                $table->timestamp('last_used_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_query_plan_cache');
        Schema::dropIfExists('assistant_live_result_cache');
        Schema::dropIfExists('assistant_answer_cache');
    }
};
