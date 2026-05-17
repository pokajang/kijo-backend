<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('quotes_manpower', 'manpower_rate_type')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->string('manpower_rate_type', 50)->nullable()->after('service_code');
            });
        }

        if (!Schema::hasColumn('quotes_manpower', 'billing_unit')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->string('billing_unit', 20)->default('month')->after('manpower_rate_type');
            });
        }

        if (!Schema::hasColumn('quotes_manpower', 'duration_hours')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->decimal('duration_hours', 10, 2)->nullable()->after('duration_months');
            });
        }

        if (!Schema::hasColumn('quotes_manpower', 'requires_management_approval')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->boolean('requires_management_approval')->default(false)->after('duration_hours');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'requires_management_approval',
            'duration_hours',
            'billing_unit',
            'manpower_rate_type',
        ] as $column) {
            if (Schema::hasColumn('quotes_manpower', $column)) {
                Schema::table('quotes_manpower', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
