<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (! Schema::hasColumn('manual_debtors', 'client_id')) {
                $table->unsignedBigInteger('client_id')->nullable()->after('invoice_ref_no');
            }
            if (! Schema::hasColumn('manual_debtors', 'pic_id')) {
                $table->unsignedBigInteger('pic_id')->nullable()->after('client_id');
            }
        });

        Schema::table('manual_debtors', function (Blueprint $table): void {
            $table->index(['client_id', 'pic_id'], 'manual_debtors_client_pic_idx');
        });
    }

    public function down(): void
    {
        Schema::table('manual_debtors', function (Blueprint $table): void {
            $table->dropIndex('manual_debtors_client_pic_idx');
            if (Schema::hasColumn('manual_debtors', 'pic_id')) {
                $table->dropColumn('pic_id');
            }
            if (Schema::hasColumn('manual_debtors', 'client_id')) {
                $table->dropColumn('client_id');
            }
        });
    }
};
