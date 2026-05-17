<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'quotes_training',
        'quotes_ih',
        'quotes_manpower',
        'quotes_special',
        'quotes_equipment',
    ];

    private array $columns = [
        'pic_name',
        'pic_email',
        'pic_phone',
        'pic_position',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($this->columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($this->columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(255) NULL");
                }
            }
        }
    }
};
