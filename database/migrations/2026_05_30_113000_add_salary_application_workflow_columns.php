<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_salary_applications')) {
            return;
        }

        Schema::table('hr_salary_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_salary_applications', 'checked_by')) {
                $table->unsignedBigInteger('checked_by')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'checked_at')) {
                $table->timestamp('checked_at')->nullable()->after('checked_by');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'checked_status')) {
                $table->string('checked_status', 32)->nullable()->after('checked_at');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'checked_remarks')) {
                $table->text('checked_remarks')->nullable()->after('checked_status');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('checked_remarks');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'approved_status')) {
                $table->string('approved_status', 32)->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'approved_remarks')) {
                $table->text('approved_remarks')->nullable()->after('approved_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_salary_applications')) {
            return;
        }

        Schema::table('hr_salary_applications', function (Blueprint $table): void {
            foreach ([
                'approved_remarks',
                'approved_status',
                'approved_at',
                'approved_by',
                'checked_remarks',
                'checked_status',
                'checked_at',
                'checked_by',
            ] as $column) {
                if (Schema::hasColumn('hr_salary_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
