<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_breakdown')) {
            return;
        }

        Schema::table('invoice_breakdown', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_breakdown', 'system_adjustment_key')) {
                $table->string('system_adjustment_key', 80)->nullable()->after('description');
            }

            if (! Schema::hasColumn('invoice_breakdown', 'system_adjustment_source')) {
                $table->string('system_adjustment_source', 80)->nullable()->after('system_adjustment_key');
            }
        });

        Schema::table('invoice_breakdown', function (Blueprint $table): void {
            if (! $this->hasIndex('invoice_breakdown', 'invoice_breakdown_system_adjustment_key_index')) {
                $table->index('system_adjustment_key', 'invoice_breakdown_system_adjustment_key_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_breakdown')) {
            return;
        }

        Schema::table('invoice_breakdown', function (Blueprint $table): void {
            if ($this->hasIndex('invoice_breakdown', 'invoice_breakdown_system_adjustment_key_index')) {
                $table->dropIndex('invoice_breakdown_system_adjustment_key_index');
            }
        });

        Schema::table('invoice_breakdown', function (Blueprint $table): void {
            if (Schema::hasColumn('invoice_breakdown', 'system_adjustment_source')) {
                $table->dropColumn('system_adjustment_source');
            }

            if (Schema::hasColumn('invoice_breakdown', 'system_adjustment_key')) {
                $table->dropColumn('system_adjustment_key');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $row): bool => ($row['name'] ?? null) === $index
        );
    }
};
