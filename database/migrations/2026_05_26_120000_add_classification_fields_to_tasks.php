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
            if (! Schema::hasColumn('tasks', 'task_category')) {
                $table->string('task_category', 60)->default('uncategorised')->after('title');
            }

            if (! Schema::hasColumn('tasks', 'effort_score')) {
                $table->decimal('effort_score', 4, 1)->default(1)->after('task_category');
            }

            if (! Schema::hasColumn('tasks', 'classification_confidence')) {
                $table->string('classification_confidence', 20)->nullable()->after('effort_score');
            }

            if (! Schema::hasColumn('tasks', 'classification_source')) {
                $table->string('classification_source', 20)->default('system')->after('classification_confidence');
            }

            if (! Schema::hasColumn('tasks', 'user_override')) {
                $table->boolean('user_override')->default(false)->after('classification_source');
            }

            if (! Schema::hasColumn('tasks', 'matched_pattern')) {
                $table->string('matched_pattern', 255)->nullable()->after('user_override');
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
                'matched_pattern',
                'user_override',
                'classification_source',
                'classification_confidence',
                'effort_score',
                'task_category',
            ] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
