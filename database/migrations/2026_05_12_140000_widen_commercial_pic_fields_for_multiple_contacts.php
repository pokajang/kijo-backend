<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            foreach (['invoice_pic_name', 'invoice_pic_phone', 'invoice_pic_email', 'invoice_pic_position'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    DB::statement("ALTER TABLE `invoices` MODIFY `{$column}` TEXT NULL");
                }
            }
        }

        if (Schema::hasTable('do_details')) {
            foreach ([
                'client_contact_name',
                'client_contact_position',
                'client_contact_email',
                'client_contact_phone',
            ] as $column) {
                if (Schema::hasColumn('do_details', $column)) {
                    DB::statement("ALTER TABLE `do_details` MODIFY `{$column}` TEXT NULL");
                }
            }

            if (Schema::hasColumn('do_details', 'project_service_period')) {
                DB::statement('ALTER TABLE `do_details` MODIFY `project_service_period` VARCHAR(255) NULL');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            $columns = [
                'invoice_pic_name' => 'VARCHAR(255) NULL',
                'invoice_pic_phone' => 'VARCHAR(50) NULL',
                'invoice_pic_email' => 'VARCHAR(255) NULL',
                'invoice_pic_position' => 'VARCHAR(100) NULL',
            ];

            foreach ($columns as $column => $definition) {
                if (Schema::hasColumn('invoices', $column)) {
                    DB::statement("ALTER TABLE `invoices` MODIFY `{$column}` {$definition}");
                }
            }
        }

        if (Schema::hasTable('do_details')) {
            $columns = [
                'client_contact_name' => 'VARCHAR(100) NOT NULL',
                'client_contact_position' => 'VARCHAR(100) NULL',
                'client_contact_email' => 'VARCHAR(100) NULL',
                'client_contact_phone' => 'VARCHAR(20) NULL',
            ];

            foreach ($columns as $column => $definition) {
                if (Schema::hasColumn('do_details', $column)) {
                    DB::statement("ALTER TABLE `do_details` MODIFY `{$column}` {$definition}");
                }
            }

            if (Schema::hasColumn('do_details', 'project_service_period')) {
                DB::statement('ALTER TABLE `do_details` MODIFY `project_service_period` VARCHAR(100) NULL');
            }
        }
    }
};
