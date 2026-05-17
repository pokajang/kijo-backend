<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->string('segment_type', 32)->nullable()->after('source');
            $table->index(['segment_type', 'entry_date'], 'monitoring_manual_entries_segment_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->dropIndex('monitoring_manual_entries_segment_date_idx');
            $table->dropColumn('segment_type');
        });
    }
};
