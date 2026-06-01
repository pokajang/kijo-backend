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
            if (! Schema::hasColumn('tasks', 'work_type')) {
                $table->string('work_type', 60)->default('unclear')->after('matched_pattern');
            }

            if (! Schema::hasColumn('tasks', 'work_type_confidence')) {
                $table->string('work_type_confidence', 20)->nullable()->after('work_type');
            }

            if (! Schema::hasColumn('tasks', 'work_type_matched_pattern')) {
                $table->string('work_type_matched_pattern', 255)->nullable()->after('work_type_confidence');
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
                'work_type_matched_pattern',
                'work_type_confidence',
                'work_type',
            ] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
