<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('invoices', 'sst_percent')) {
                    $table->decimal('sst_percent', 8, 4)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('invoices', 'calculation_version')) {
                    $table->string('calculation_version', 80)->nullable()->after('grand_total');
                }
                if (! Schema::hasColumn('invoices', 'source_snapshot')) {
                    $table->json('source_snapshot')->nullable()->after('calculation_version');
                }
                if (! Schema::hasColumn('invoices', 'deviation_reason')) {
                    $table->text('deviation_reason')->nullable()->after('source_snapshot');
                }
                if (! Schema::hasColumn('invoices', 'deviation_acknowledged_by')) {
                    $table->unsignedInteger('deviation_acknowledged_by')->nullable()->after('deviation_reason');
                }
                if (! Schema::hasColumn('invoices', 'deviation_acknowledged_at')) {
                    $table->timestamp('deviation_acknowledged_at')->nullable()->after('deviation_acknowledged_by');
                }
            });
        }

        if (! Schema::hasTable('invoice_breakdown')) {
            return;
        }

        Schema::table('invoice_breakdown', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_breakdown', 'line_type')) {
                $table->string('line_type', 40)->nullable()->after('description');
            }
            if (! Schema::hasColumn('invoice_breakdown', 'source_line_key')) {
                $table->string('source_line_key', 120)->nullable()->after('line_type');
            }
        });

        DB::table('invoice_breakdown')
            ->whereNull('line_type')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('invoice_breakdown')
                        ->where('id', $row->id)
                        ->update(['line_type' => $this->inferLineType($row)]);
                }
            });

        if (Schema::hasColumn('invoices', 'calculation_version')) {
            DB::table('invoices')
                ->whereNull('calculation_version')
                ->update(['calculation_version' => 'legacy_snapshot']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_breakdown')) {
            Schema::table('invoice_breakdown', function (Blueprint $table): void {
                foreach (['source_line_key', 'line_type'] as $column) {
                    if (Schema::hasColumn('invoice_breakdown', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                foreach (['deviation_acknowledged_at', 'deviation_acknowledged_by', 'deviation_reason', 'source_snapshot', 'calculation_version', 'sst_percent'] as $column) {
                    if (Schema::hasColumn('invoices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function inferLineType(object $row): string
    {
        $label = strtolower(trim((string) ($row->item_description ?? '')));
        $subtotal = (float) ($row->subtotal ?? 0);

        if ($subtotal < 0 || str_contains($label, 'discount') || str_contains($label, 'less')) {
            return 'discount';
        }
        if (str_contains($label, 'sst') || preg_match('/^\s*\d+(?:\.\d+)?\s*%\s*hrd\s*charge\b/i', $label)) {
            return 'tax';
        }
        if (str_contains($label, 'travel') || str_contains($label, 'mobilization')) {
            return 'travel';
        }

        return 'custom';
    }
};
