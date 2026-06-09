<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'hr_leaves_allocation_staff_type_year_unique';

    public function up(): void
    {
        if (! Schema::hasTable('hr_leaves_allocation')) {
            return;
        }

        $duplicate = DB::table('hr_leaves_allocation')
            ->select('staff_id', 'leave_type', 'year', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('staff_id', 'leave_type', 'year')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                "Cannot add unique leave entitlement index because duplicate rows exist for staff #{$duplicate->staff_id}, {$duplicate->leave_type}, {$duplicate->year}."
            );
        }

        if ($this->indexExists()) {
            return;
        }

        Schema::table('hr_leaves_allocation', function (Blueprint $table): void {
            $table->unique(['staff_id', 'leave_type', 'year'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_leaves_allocation') || ! $this->indexExists()) {
            return;
        }

        Schema::table('hr_leaves_allocation', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }

    private function indexExists(): bool
    {
        return Schema::hasIndex('hr_leaves_allocation', self::INDEX_NAME);
    }
};
