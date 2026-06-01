<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_response_feedback')) {
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
        }

        if (! Schema::hasTable('assistant_provider_feedback_memory')) {
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
        }

        if (Schema::hasTable('assistant_answer_cache') && ! Schema::hasColumn('assistant_answer_cache', 'answer_signature')) {
            Schema::table('assistant_answer_cache', function (Blueprint $table): void {
                $table->string('answer_signature', 64)->nullable()->index()->after('answer_json');
            });
        }

        if (Schema::hasTable('assistant_live_result_cache') && ! Schema::hasColumn('assistant_live_result_cache', 'answer_signature')) {
            Schema::table('assistant_live_result_cache', function (Blueprint $table): void {
                $table->string('answer_signature', 64)->nullable()->index()->after('answer_json');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assistant_live_result_cache') && Schema::hasColumn('assistant_live_result_cache', 'answer_signature')) {
            Schema::table('assistant_live_result_cache', function (Blueprint $table): void {
                $table->dropColumn('answer_signature');
            });
        }

        if (Schema::hasTable('assistant_answer_cache') && Schema::hasColumn('assistant_answer_cache', 'answer_signature')) {
            Schema::table('assistant_answer_cache', function (Blueprint $table): void {
                $table->dropColumn('answer_signature');
            });
        }

        Schema::dropIfExists('assistant_provider_feedback_memory');
        Schema::dropIfExists('assistant_response_feedback');
    }
};
