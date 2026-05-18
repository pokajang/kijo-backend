<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')
            ->where('invoice_ref_no', 'like', 'INV-CRUD-SEED-%')
            ->update(['invoice_running_no' => 0]);
    }

    public function down(): void
    {
        // Intentionally irreversible: the high seed running number polluted real invoice sequences.
    }
};
