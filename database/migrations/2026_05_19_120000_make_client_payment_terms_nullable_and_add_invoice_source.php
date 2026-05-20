<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_company') && Schema::hasColumn('client_company', 'payment_terms_days')) {
            DB::statement('ALTER TABLE client_company MODIFY payment_terms_days SMALLINT UNSIGNED NULL DEFAULT NULL');
            DB::statement('UPDATE client_company SET payment_terms_days = NULL WHERE payment_terms_days = 30');
        }

        if (Schema::hasTable('invoices')) {
            if (! Schema::hasColumn('invoices', 'payment_terms_source')) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->string('payment_terms_source', 32)->default('system_default')->after('payment_terms_days');
                });
            }

            if (Schema::hasColumn('invoices', 'payment_terms_source')) {
                DB::statement("UPDATE invoices SET payment_terms_source = CASE WHEN COALESCE(payment_terms_days, 30) = 30 THEN 'system_default' ELSE 'legacy' END WHERE payment_terms_source IS NULL OR payment_terms_source = '' OR payment_terms_source = 'system_default'");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_company') && Schema::hasColumn('client_company', 'payment_terms_days')) {
            DB::statement('UPDATE client_company SET payment_terms_days = 30 WHERE payment_terms_days IS NULL');
            DB::statement('ALTER TABLE client_company MODIFY payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30');
        }
    }
};
