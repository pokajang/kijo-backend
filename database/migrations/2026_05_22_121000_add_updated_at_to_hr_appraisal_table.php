<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_appraisal') || Schema::hasColumn('hr_appraisal', 'updated_at')) {
            return;
        }

        Schema::table('hr_appraisal', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_appraisal', 'created_at')) {
                $table->dateTime('updated_at')->nullable()->after('created_at');
                return;
            }

            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_appraisal') && Schema::hasColumn('hr_appraisal', 'updated_at')) {
            Schema::table('hr_appraisal', function (Blueprint $table): void {
                $table->dropColumn('updated_at');
            });
        }
    }
};
