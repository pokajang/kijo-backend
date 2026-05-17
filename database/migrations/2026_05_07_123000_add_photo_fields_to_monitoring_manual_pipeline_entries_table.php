<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('notes');
            $table->string('photo_original_name', 191)->nullable()->after('photo_path');
            $table->string('photo_mime_type', 100)->nullable()->after('photo_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->dropColumn(['photo_path', 'photo_original_name', 'photo_mime_type']);
        });
    }
};
