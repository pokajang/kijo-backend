<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->supportsLifecycle()) {
            return;
        }

        $now = now();

        DB::table('tasks')
            ->whereNull('ai_classification_status')
            ->where('classification_source', 'ai')
            ->update([
                'ai_classification_status' => 'applied',
                'ai_classification_completed_at' => $now,
            ]);

        DB::table('tasks')
            ->whereNull('ai_classification_status')
            ->where('classification_source', 'ai_cache')
            ->update([
                'ai_classification_status' => 'cached',
                'ai_classification_completed_at' => $now,
            ]);

        DB::table('tasks')
            ->whereNull('ai_classification_status')
            ->where('task_category', 'non_work')
            ->update([
                'ai_classification_status' => 'not_applicable',
            ]);

        DB::table('tasks')
            ->whereNull('ai_classification_status')
            ->whereNotIn('task_category', ['unclear_unrated', 'uncategorised'])
            ->where('work_type', '<>', 'unclear')
            ->update([
                'ai_classification_status' => 'not_applicable',
            ]);

        DB::table('tasks')
            ->whereNull('ai_classification_status')
            ->where('task_category', 'uncategorised')
            ->where(function ($query): void {
                $query
                    ->where('classification_confidence', '<>', 'low')
                    ->orWhere('work_type', '<>', 'unclear');
            })
            ->update([
                'ai_classification_status' => 'not_applicable',
            ]);
    }

    public function down(): void
    {
        if (! $this->supportsLifecycle()) {
            return;
        }

        DB::table('tasks')
            ->whereIn('ai_classification_status', ['applied', 'cached', 'not_applicable'])
            ->update([
                'ai_classification_status' => null,
                'ai_classification_completed_at' => null,
            ]);
    }

    private function supportsLifecycle(): bool
    {
        return Schema::hasTable('tasks')
            && Schema::hasColumn('tasks', 'ai_classification_status')
            && Schema::hasColumn('tasks', 'ai_classification_completed_at')
            && Schema::hasColumn('tasks', 'classification_source')
            && Schema::hasColumn('tasks', 'task_category')
            && Schema::hasColumn('tasks', 'classification_confidence')
            && Schema::hasColumn('tasks', 'work_type');
    }
};
