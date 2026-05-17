<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_handbook_change_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('hr_handbook_change_logs', 'section_id')) {
                $table->string('section_id', 80)->nullable()->after('action');
            }

            if (!Schema::hasColumn('hr_handbook_change_logs', 'section_title')) {
                $table->string('section_title', 255)->nullable()->after('section_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_handbook_change_logs', function (Blueprint $table) {
            if (Schema::hasColumn('hr_handbook_change_logs', 'section_title')) {
                $table->dropColumn('section_title');
            }

            if (Schema::hasColumn('hr_handbook_change_logs', 'section_id')) {
                $table->dropColumn('section_id');
            }
        });
    }
};
