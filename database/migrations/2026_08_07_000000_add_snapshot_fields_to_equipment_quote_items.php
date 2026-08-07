<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes_equipment_items')) {
            return;
        }

        Schema::table('quotes_equipment_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('quotes_equipment_items', 'item_name')) {
                $table->string('item_name')->nullable()->after('item_id');
            }
            if (! Schema::hasColumn('quotes_equipment_items', 'description')) {
                $table->text('description')->nullable()->after('item_name');
            }
            if (! Schema::hasColumn('quotes_equipment_items', 'unit')) {
                $table->string('unit', 50)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotes_equipment_items')) {
            return;
        }

        $columns = array_values(array_filter(
            ['item_name', 'description', 'unit'],
            static fn (string $column): bool => Schema::hasColumn('quotes_equipment_items', $column),
        ));

        if ($columns !== []) {
            Schema::table('quotes_equipment_items', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
