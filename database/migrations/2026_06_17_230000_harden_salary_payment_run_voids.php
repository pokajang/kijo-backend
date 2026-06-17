<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_salary_payment_runs')) {
            Schema::table('hr_salary_payment_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('hr_salary_payment_runs', 'voided_at')) {
                    $table->timestamp('voided_at')->nullable()->after('paid_at')->index();
                }
                if (! Schema::hasColumn('hr_salary_payment_runs', 'voided_by')) {
                    $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at')->index();
                }
                if (! Schema::hasColumn('hr_salary_payment_runs', 'void_reason')) {
                    $table->text('void_reason')->nullable()->after('voided_by');
                }
            });
        }

        if (Schema::hasTable('hr_salary_payment_run_items')) {
            Schema::table('hr_salary_payment_run_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('hr_salary_payment_run_items', 'voided_at')) {
                    $table->timestamp('voided_at')->nullable()->after('status_to')->index();
                }
            });

            $this->dropUniqueIfExists(
                'hr_salary_payment_run_items',
                'salary_payment_run_subject_unique',
            );

            $this->addIndexIfMissing(
                'hr_salary_payment_run_items',
                ['subject_type', 'subject_id'],
                'salary_payment_run_subject_idx',
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_salary_payment_run_items')) {
            $this->dropIndexIfExists(
                'hr_salary_payment_run_items',
                'salary_payment_run_subject_idx',
            );

            Schema::table('hr_salary_payment_run_items', function (Blueprint $table): void {
                $table->unique(['subject_type', 'subject_id'], 'salary_payment_run_subject_unique');
                if (Schema::hasColumn('hr_salary_payment_run_items', 'voided_at')) {
                    $table->dropColumn('voided_at');
                }
            });
        }

        if (Schema::hasTable('hr_salary_payment_runs')) {
            Schema::table('hr_salary_payment_runs', function (Blueprint $table): void {
                foreach (['void_reason', 'voided_by', 'voided_at'] as $column) {
                    if (Schema::hasColumn('hr_salary_payment_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addIndexIfMissing(string $table, array $columns, string $index): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
                $blueprint->index($columns, $index);
            });
        } catch (Throwable) {
            // The replacement index may already exist on repaired databases.
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$index}");
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
            });
        } catch (Throwable) {
            // The index may already be absent on repaired databases.
        }
    }

    private function dropUniqueIfExists(string $table, string $index): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$index}");
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropUnique($index);
            });
        } catch (Throwable) {
            // The index may already be absent on repaired databases.
        }
    }
};
