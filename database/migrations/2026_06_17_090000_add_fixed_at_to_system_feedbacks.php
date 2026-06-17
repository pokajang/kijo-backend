<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_feedbacks')) {
            return;
        }

        if (! Schema::hasColumn('system_feedbacks', 'fixed_at')) {
            Schema::table('system_feedbacks', function (Blueprint $table): void {
                $table->timestamp('fixed_at')->nullable()->index();
            });
        }

        DB::table('system_feedbacks')
            ->where('status', 'Fixed Completed')
            ->whereNotNull('action_date')
            ->whereNull('fixed_at')
            ->update(['fixed_at' => DB::raw('action_date')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_feedbacks') || ! Schema::hasColumn('system_feedbacks', 'fixed_at')) {
            return;
        }

        Schema::table('system_feedbacks', function (Blueprint $table): void {
            $table->dropColumn('fixed_at');
        });
    }
};
