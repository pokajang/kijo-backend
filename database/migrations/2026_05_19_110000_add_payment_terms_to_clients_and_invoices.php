<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_company') && ! Schema::hasColumn('client_company', 'payment_terms_days')) {
            Schema::table('client_company', function (Blueprint $table): void {
                $table->unsignedSmallInteger('payment_terms_days')->nullable()->default(null)->after('client_status');
            });
        }

        if (Schema::hasTable('invoices')) {
            $needsInvoiceTerms = ! Schema::hasColumn('invoices', 'payment_terms_days');
            $needsInvoiceTermsSource = ! Schema::hasColumn('invoices', 'payment_terms_source');
            $needsInvoiceDueDate = ! Schema::hasColumn('invoices', 'due_date');

            if ($needsInvoiceTerms) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->unsignedSmallInteger('payment_terms_days')->default(30)->after('invoice_date');
                });
            }

            if ($needsInvoiceTermsSource) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->string('payment_terms_source', 32)->default('system_default')->after('payment_terms_days');
                });
            }

            if ($needsInvoiceDueDate) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->date('due_date')->nullable()->after('payment_terms_source');
                });
            }

            if (Schema::hasColumn('invoices', 'invoice_date') && Schema::hasColumn('invoices', 'due_date')) {
                DB::statement('UPDATE invoices SET due_date = DATE_ADD(invoice_date, INTERVAL payment_terms_days DAY) WHERE invoice_date IS NOT NULL AND due_date IS NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            $hasInvoiceDueDate = Schema::hasColumn('invoices', 'due_date');
            $hasInvoiceTermsSource = Schema::hasColumn('invoices', 'payment_terms_source');
            $hasInvoiceTerms = Schema::hasColumn('invoices', 'payment_terms_days');

            if ($hasInvoiceDueDate) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->dropColumn('due_date');
                });
            }

            if ($hasInvoiceTermsSource) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->dropColumn('payment_terms_source');
                });
            }

            if ($hasInvoiceTerms) {
                Schema::table('invoices', function (Blueprint $table): void {
                    $table->dropColumn('payment_terms_days');
                });
            }
        }

        if (Schema::hasTable('client_company') && Schema::hasColumn('client_company', 'payment_terms_days')) {
            Schema::table('client_company', function (Blueprint $table): void {
                $table->dropColumn('payment_terms_days');
            });
        }
    }
};
