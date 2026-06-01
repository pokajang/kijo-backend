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
            if (! Schema::hasColumn('hr_salary_applications', 'draft_payload_json')) {
                $table->json('draft_payload_json')->nullable()->after('deductions_json');
            }
            if (! Schema::hasColumn('hr_salary_applications', 'draft_saved_at')) {
                $table->timestamp('draft_saved_at')->nullable()->after('draft_payload_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_salary_applications')) {
            return;
        }

        Schema::table('hr_salary_applications', function (Blueprint $table): void {
            foreach (['draft_saved_at', 'draft_payload_json'] as $column) {
                if (Schema::hasColumn('hr_salary_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
