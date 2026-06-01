<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_other_claim_applications')) {
            return;
        }

        try {
            Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
                $table->dropUnique('hr_other_claim_staff_month_unique');
            });
        } catch (\Throwable) {
            // Fresh databases no longer create this index; ignore when absent.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_other_claim_applications')) {
            return;
        }

        try {
            Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
                $table->unique(['staff_id', 'claim_month'], 'hr_other_claim_staff_month_unique');
            });
        } catch (\Throwable) {
            // Existing duplicate free-flow claims cannot be made unique again.
        }
    }
};
