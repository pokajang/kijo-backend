<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_vendor_registrations') || !Schema::hasColumn('client_vendor_registrations', 'portal_password')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE client_vendor_registrations MODIFY portal_password TEXT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_vendor_registrations') || !Schema::hasColumn('client_vendor_registrations', 'portal_password')) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE client_vendor_registrations MODIFY portal_password VARCHAR(255) NULL');
        }
    }
};
