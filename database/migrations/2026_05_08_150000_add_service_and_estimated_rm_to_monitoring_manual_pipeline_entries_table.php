<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->string('service_category', 64)->nullable()->after('segment_type');
            $table->decimal('estimated_rm', 15, 2)->nullable()->after('service_category');
            $table->index(['service_category', 'entry_date'], 'monitoring_manual_entries_service_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->dropIndex('monitoring_manual_entries_service_date_idx');
            $table->dropColumn(['service_category', 'estimated_rm']);
        });
    }
};
