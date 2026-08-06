<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes_equipment') && ! Schema::hasColumn('quotes_equipment', 'quotation_remarks')) {
            Schema::table('quotes_equipment', function (Blueprint $table): void {
                $table->text('quotation_remarks')->nullable()->after('inquiry_remarks');
            });
        }

        if (Schema::hasTable('quotes_equipment_items') && ! Schema::hasColumn('quotes_equipment_items', 'item_remarks')) {
            Schema::table('quotes_equipment_items', function (Blueprint $table): void {
                $table->text('item_remarks')->nullable()->after('item_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quotes_equipment_items') && Schema::hasColumn('quotes_equipment_items', 'item_remarks')) {
            Schema::table('quotes_equipment_items', function (Blueprint $table): void {
                $table->dropColumn('item_remarks');
            });
        }

        if (Schema::hasTable('quotes_equipment') && Schema::hasColumn('quotes_equipment', 'quotation_remarks')) {
            Schema::table('quotes_equipment', function (Blueprint $table): void {
                $table->dropColumn('quotation_remarks');
            });
        }
    }
};
