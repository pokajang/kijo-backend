<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->string('custom_service_category', 191)
                ->nullable()
                ->after('service_category');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->dropColumn('custom_service_category');
        });
    }
};
