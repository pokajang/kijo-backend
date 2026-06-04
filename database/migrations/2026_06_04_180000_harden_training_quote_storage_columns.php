<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quotes_training')) {
            return;
        }

        foreach ([
            'training_title' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'training_type' => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'payment_method' => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'venue' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'client_ssm' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'client_city' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'client_state' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'client_zip' => 'VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            'duration_per_session' => 'DECIMAL(8,2) NULL',
            'unit_price' => 'DECIMAL(12,2) NULL',
            'travel_charge' => 'DECIMAL(12,2) NULL',
            'meal_price' => 'DECIMAL(12,2) NULL',
            'discount_value' => 'DECIMAL(12,2) NULL',
            'training_total' => 'DECIMAL(12,2) NULL',
            'meal_total' => 'DECIMAL(12,2) NULL',
            'mobilization_cost' => 'DECIMAL(12,2) NULL',
            'discount_amount' => 'DECIMAL(12,2) NULL',
            'subtotal' => 'DECIMAL(12,2) NULL',
            'sst_amount' => 'DECIMAL(12,2) NULL',
            'hrd_amount' => 'DECIMAL(12,2) NULL',
            'grand_total' => 'DECIMAL(12,2) NULL',
        ] as $column => $definition) {
            $this->modifyColumn('quotes_training', $column, $definition);
        }
    }

    public function down(): void
    {
        // Intentionally do not narrow columns on rollback; existing quotes may contain wider data.
    }

    private function modifyColumn(string $table, string $column, string $definition): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$definition}");
    }
};
