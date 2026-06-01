<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_classification_examples')) {
            return;
        }

        Schema::create('task_classification_examples', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_title_hash', 64)->unique();
            $table->string('normalized_title', 255);
            $table->string('sample_title', 255);
            $table->string('task_category');
            $table->decimal('effort_score', 4, 1);
            $table->string('classification_confidence');
            $table->string('classification_source')->default('ai');
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->default('unclear');
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('task_category');
            $table->index('work_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_classification_examples');
    }
};
