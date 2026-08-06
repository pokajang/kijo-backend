<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EquipmentQuoteRemarksMigrationTest extends TestCase
{
    public function test_it_adds_nullable_remark_fields_without_rewriting_existing_quotes(): void
    {
        Schema::dropIfExists('quotes_equipment_items');
        Schema::dropIfExists('quotes_equipment');

        Schema::create('quotes_equipment', function (Blueprint $table): void {
            $table->id();
            $table->text('inquiry_remarks')->nullable();
        });
        Schema::create('quotes_equipment_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('item_id');
        });

        $quoteId = DB::table('quotes_equipment')->insertGetId(['inquiry_remarks' => 'Website lead']);
        $itemId = DB::table('quotes_equipment_items')->insertGetId([
            'quote_id' => $quoteId,
            'item_id' => 701,
        ]);

        $migration = require database_path('migrations/2026_08_06_010000_add_remarks_to_equipment_quotes.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('quotes_equipment', 'quotation_remarks'));
        $this->assertTrue(Schema::hasColumn('quotes_equipment_items', 'item_remarks'));
        $this->assertNull(DB::table('quotes_equipment')->where('id', $quoteId)->value('quotation_remarks'));
        $this->assertNull(DB::table('quotes_equipment_items')->where('id', $itemId)->value('item_remarks'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('quotes_equipment', 'quotation_remarks'));
        $this->assertFalse(Schema::hasColumn('quotes_equipment_items', 'item_remarks'));
    }
}
