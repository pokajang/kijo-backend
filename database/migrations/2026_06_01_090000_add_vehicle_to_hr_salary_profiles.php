<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_salary_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_salary_profiles', 'vehicle')) {
                $table->string('vehicle', 120)->nullable()->after('effective_month');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_salary_profiles', 'vehicle')) {
                $table->dropColumn('vehicle');
            }
        });
    }
};
