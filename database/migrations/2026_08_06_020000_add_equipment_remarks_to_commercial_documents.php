<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addTextColumn('invoices', 'quotation_remarks', 'remarks');
        $this->addTextColumn('invoice_breakdown', 'item_remarks', 'description');
        $this->addTextColumn('do_details', 'quotation_remarks', 'project_description');
        $this->addTextColumn('do_breakdown', 'item_remarks', 'description');
        $this->addTextColumn('supplier_po_main', 'quotation_remarks', 'status_remarks');
        $this->addTextColumn('supplier_po_items', 'item_remarks', 'description');

        if (Schema::hasTable('do_breakdown') && Schema::hasColumn('do_breakdown', 'item_name')) {
            Schema::table('do_breakdown', function (Blueprint $table): void {
                $table->string('item_name', 255)->change();
            });
        }

        if (Schema::hasTable('project_vendors') && Schema::hasColumn('project_vendors', 'services_description')) {
            Schema::table('project_vendors', function (Blueprint $table): void {
                $table->mediumText('services_description')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_vendors') && Schema::hasColumn('project_vendors', 'services_description')) {
            Schema::table('project_vendors', function (Blueprint $table): void {
                $table->text('services_description')->nullable()->change();
            });
        }

        if (Schema::hasTable('do_breakdown') && Schema::hasColumn('do_breakdown', 'item_name')) {
            Schema::table('do_breakdown', function (Blueprint $table): void {
                $table->string('item_name', 100)->change();
            });
        }

        $this->dropColumn('supplier_po_items', 'item_remarks');
        $this->dropColumn('supplier_po_main', 'quotation_remarks');
        $this->dropColumn('do_breakdown', 'item_remarks');
        $this->dropColumn('do_details', 'quotation_remarks');
        $this->dropColumn('invoice_breakdown', 'item_remarks');
        $this->dropColumn('invoices', 'quotation_remarks');
    }

    private function addTextColumn(string $tableName, string $column, string $after): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $after): void {
            $table->text($column)->nullable()->after($after);
        });
    }

    private function dropColumn(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
    }
};
