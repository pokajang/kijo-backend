<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_general') || ! Schema::hasColumn('staff_general', 'crm_position')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE staff_general MODIFY crm_position VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('staff_general') || ! Schema::hasColumn('staff_general', 'crm_position')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::table('staff_general')->whereNull('crm_position')->update(['crm_position' => '']);
        DB::statement('ALTER TABLE staff_general MODIFY crm_position VARCHAR(255) NOT NULL');
    }
};
