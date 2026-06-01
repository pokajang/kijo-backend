<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('tasks', 'project_id')) {
                    $table->unsignedInteger('project_id')->nullable()->after('staff_id');
                    $table->index('project_id', 'tasks_project_id_idx');
                }

                if (! Schema::hasColumn('tasks', 'project_progress_id')) {
                    $table->unsignedInteger('project_progress_id')->nullable()->after('project_id');
                    $table->index('project_progress_id', 'tasks_project_progress_id_idx');
                }
            });
        }

        if (Schema::hasTable('project_progress')) {
            Schema::table('project_progress', function (Blueprint $table): void {
                if (! Schema::hasColumn('project_progress', 'source_type')) {
                    $table->string('source_type', 30)->nullable()->after('updated_on');
                }

                if (! Schema::hasColumn('project_progress', 'source_task_id')) {
                    $table->unsignedBigInteger('source_task_id')->nullable()->after('source_type');
                    $table->index('source_task_id', 'project_progress_source_task_id_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_progress')) {
            Schema::table('project_progress', function (Blueprint $table): void {
                if (Schema::hasColumn('project_progress', 'source_task_id')) {
                    $table->dropIndex('project_progress_source_task_id_idx');
                }
            });

            Schema::table('project_progress', function (Blueprint $table): void {
                foreach (['source_task_id', 'source_type'] as $column) {
                    if (Schema::hasColumn('project_progress', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                if (Schema::hasColumn('tasks', 'project_progress_id')) {
                    $table->dropIndex('tasks_project_progress_id_idx');
                }

                if (Schema::hasColumn('tasks', 'project_id')) {
                    $table->dropIndex('tasks_project_id_idx');
                }
            });

            Schema::table('tasks', function (Blueprint $table): void {
                foreach (['project_progress_id', 'project_id'] as $column) {
                    if (Schema::hasColumn('tasks', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
