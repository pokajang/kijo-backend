<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_salary_year_snapshots')) {
            Schema::create('hr_salary_year_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('staff_id')->index();
                $table->unsignedSmallInteger('year');
                $table->decimal('basic_salary', 12, 2)->default(0);
                $table->decimal('allowance_total', 12, 2)->default(0);
                $table->decimal('increment_amount', 12, 2)->default(0);
                $table->timestamps();

                $table->unique(['staff_id', 'year'], 'hr_salary_snapshot_staff_year_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_year_snapshots');
    }
};
