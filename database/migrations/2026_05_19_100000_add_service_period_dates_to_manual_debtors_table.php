<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (! Schema::hasColumn('manual_debtors', 'service_start_date')) {
                $table->date('service_start_date')->nullable()->after('service_period');
            }

            if (! Schema::hasColumn('manual_debtors', 'service_end_date')) {
                $table->date('service_end_date')->nullable()->after('service_start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (Schema::hasColumn('manual_debtors', 'service_end_date')) {
                $table->dropColumn('service_end_date');
            }

            if (Schema::hasColumn('manual_debtors', 'service_start_date')) {
                $table->dropColumn('service_start_date');
            }
        });
    }
};
