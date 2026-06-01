<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_salary_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_salary_profiles', 'yearly_medical_claim')) {
                $table->decimal('yearly_medical_claim', 12, 2)->default(0)->after('default_mileage_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_salary_profiles', 'yearly_medical_claim')) {
                $table->dropColumn('yearly_medical_claim');
            }
        });
    }
};
