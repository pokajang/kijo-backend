<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'ai_classification_status')) {
                $table->string('ai_classification_status', 30)->nullable()->after('work_type_matched_pattern');
            }

            if (! Schema::hasColumn('tasks', 'ai_classification_queued_at')) {
                $table->timestamp('ai_classification_queued_at')->nullable()->after('ai_classification_status');
            }

            if (! Schema::hasColumn('tasks', 'ai_classification_started_at')) {
                $table->timestamp('ai_classification_started_at')->nullable()->after('ai_classification_queued_at');
            }

            if (! Schema::hasColumn('tasks', 'ai_classification_completed_at')) {
                $table->timestamp('ai_classification_completed_at')->nullable()->after('ai_classification_started_at');
            }

            if (! Schema::hasColumn('tasks', 'ai_classification_error')) {
                $table->string('ai_classification_error', 255)->nullable()->after('ai_classification_completed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            foreach ([
                'ai_classification_error',
                'ai_classification_completed_at',
                'ai_classification_started_at',
                'ai_classification_queued_at',
                'ai_classification_status',
            ] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
