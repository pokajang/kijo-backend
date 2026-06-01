<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_source_gaps')) {
            Schema::create('assistant_source_gaps', function (Blueprint $table): void {
                $table->id();
                $table->string('gap_key', 64)->unique();
                $table->string('normalized_intent', 500)->index();
                $table->text('sample_question')->nullable();
                $table->string('current_route', 255)->nullable()->index();
                $table->json('source_types_json')->nullable();
                $table->json('provider_keys_json')->nullable();
                $table->string('confidence', 20)->nullable()->index();
                $table->string('answer_mode', 20)->nullable()->index();
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->string('status', 20)->default('open')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_source_gaps');
    }
};
