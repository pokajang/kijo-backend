<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes_special_items') || ! Schema::hasColumn('quotes_special_items', 'quantity')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE quotes_special_items MODIFY quantity DECIMAL(12, 2) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotes_special_items') || ! Schema::hasColumn('quotes_special_items', 'quantity')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE quotes_special_items MODIFY quantity INT NULL');
        }
    }
};
