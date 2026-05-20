<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manual_debtors')) {
            return;
        }

        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (! Schema::hasColumn('manual_debtors', 'payment_terms_days')) {
                $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('invoice_date');
            }
            if (! Schema::hasColumn('manual_debtors', 'payment_terms_source')) {
                $table->string('payment_terms_source', 32)->default('legacy')->after('payment_terms_days');
            }
            if (! Schema::hasColumn('manual_debtors', 'due_date')) {
                $table->date('due_date')->nullable()->after('payment_terms_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('manual_debtors')) {
            return;
        }

        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (Schema::hasColumn('manual_debtors', 'due_date')) {
                $table->dropColumn('due_date');
            }
            if (Schema::hasColumn('manual_debtors', 'payment_terms_source')) {
                $table->dropColumn('payment_terms_source');
            }
            if (Schema::hasColumn('manual_debtors', 'payment_terms_days')) {
                $table->dropColumn('payment_terms_days');
            }
        });
    }
};
