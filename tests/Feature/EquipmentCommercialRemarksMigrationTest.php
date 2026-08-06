<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EquipmentCommercialRemarksMigrationTest extends TestCase
{
    public function test_it_adds_nullable_snapshot_fields_without_rewriting_existing_documents(): void
    {
        foreach (['supplier_po_items', 'supplier_po_main', 'do_breakdown', 'do_details', 'invoice_breakdown', 'invoices', 'project_vendors'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->text('remarks')->nullable();
        });
        Schema::create('invoice_breakdown', function (Blueprint $table): void {
            $table->id();
            $table->text('description')->nullable();
        });
        Schema::create('do_details', function (Blueprint $table): void {
            $table->id();
            $table->text('project_description')->nullable();
        });
        Schema::create('do_breakdown', function (Blueprint $table): void {
            $table->id();
            $table->string('item_name', 100);
            $table->text('description')->nullable();
        });
        Schema::create('supplier_po_main', function (Blueprint $table): void {
            $table->id('po_id');
            $table->text('status_remarks')->nullable();
        });
        Schema::create('supplier_po_items', function (Blueprint $table): void {
            $table->id();
            $table->text('description')->nullable();
        });
        Schema::create('project_vendors', function (Blueprint $table): void {
            $table->id();
            $table->text('services_description')->nullable();
        });

        $invoiceId = DB::table('invoices')->insertGetId(['remarks' => 'Existing invoice note']);
        $doId = DB::table('do_details')->insertGetId(['project_description' => 'Existing delivery scope']);
        $poId = DB::table('supplier_po_main')->insertGetId(['status_remarks' => 'Pending']);

        $migration = require database_path('migrations/2026_08_06_020000_add_equipment_remarks_to_commercial_documents.php');
        $migration->up();

        foreach ([
            ['invoices', 'quotation_remarks'],
            ['invoice_breakdown', 'item_remarks'],
            ['do_details', 'quotation_remarks'],
            ['do_breakdown', 'item_remarks'],
            ['supplier_po_main', 'quotation_remarks'],
            ['supplier_po_items', 'item_remarks'],
        ] as [$table, $column]) {
            $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} was not added");
        }

        $this->assertNull(DB::table('invoices')->where('id', $invoiceId)->value('quotation_remarks'));
        $this->assertNull(DB::table('do_details')->where('id', $doId)->value('quotation_remarks'));
        $this->assertNull(DB::table('supplier_po_main')->where('po_id', $poId)->value('quotation_remarks'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('invoices', 'quotation_remarks'));
        $this->assertFalse(Schema::hasColumn('do_breakdown', 'item_remarks'));
        $this->assertFalse(Schema::hasColumn('supplier_po_items', 'item_remarks'));
    }
}
