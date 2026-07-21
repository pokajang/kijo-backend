<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_other_claim_items')) {
            return;
        }

        Schema::table('hr_other_claim_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_other_claim_items', 'travel_group_id')) {
                $table->string('travel_group_id', 64)->nullable()->after('end_location');
            }
            if (! Schema::hasColumn('hr_other_claim_items', 'trip_mode')) {
                $table->string('trip_mode', 16)->nullable()->after('travel_group_id');
            }
            if (! Schema::hasColumn('hr_other_claim_items', 'expense_category')) {
                $table->string('expense_category', 32)->nullable()->after('trip_mode');
            }
        });

        Schema::table('hr_other_claim_items', function (Blueprint $table): void {
            $table->index(['application_id', 'travel_group_id'], 'hr_other_claim_travel_group_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_other_claim_items')) {
            return;
        }

        Schema::table('hr_other_claim_items', function (Blueprint $table): void {
            $table->dropIndex('hr_other_claim_travel_group_index');
            $table->dropColumn(['travel_group_id', 'trip_mode', 'expense_category']);
        });
    }
};
