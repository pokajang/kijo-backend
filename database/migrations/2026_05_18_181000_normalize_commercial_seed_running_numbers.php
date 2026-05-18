<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'quotes_equipment',
            'quotes_manpower',
            'quotes_ih',
            'quotes_special',
            'quotes_training',
        ] as $table) {
            DB::table($table)
                ->where('quote_ref_no', 'like', '%-CRUD-SEED-%')
                ->update(['quote_running_no' => 0]);
        }

        DB::table('project_vendors')
            ->where('loa_ref_no', 'like', 'LOA-CRUD-SEED-%')
            ->update(['loa_running_no' => 0]);

        DB::table('project_vendors')
            ->where('loa_ref_no', 'like', 'LOA-B2-SEED-%')
            ->update(['loa_running_no' => 0]);

        DB::table('supplier_po_main')
            ->where('po_ref_no', 'like', 'PO-B2-SEED-%')
            ->update(['po_running_no' => 0]);
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring smoke/CRUD seed running numbers would pollute real sequences.
    }
};
