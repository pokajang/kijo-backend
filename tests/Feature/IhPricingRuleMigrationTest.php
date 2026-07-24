<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IhPricingRuleMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('quotes_ih_items');
        Schema::dropIfExists('quotes_ih');

        parent::tearDown();
    }

    public function test_migration_versions_existing_rows_without_changing_financial_values(): void
    {
        Schema::create('quotes_ih', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('complexity_rating')->default(1);
            $table->decimal('complexity_markup', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('estimated_total_cost', 15, 2)->nullable();
            $table->string('traffic_light_rule_version')->nullable();
        });
        Schema::create('quotes_ih_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
        });

        DB::table('quotes_ih')->insert([
            ['id' => 1, 'complexity_rating' => 4, 'grand_total' => 13932, 'estimated_total_cost' => 9000],
            ['id' => 2, 'complexity_rating' => 1, 'grand_total' => 1000, 'estimated_total_cost' => 700],
            ['id' => 3, 'complexity_rating' => 1, 'grand_total' => 1500, 'estimated_total_cost' => null],
            ['id' => 4, 'complexity_rating' => 1, 'grand_total' => 500, 'estimated_total_cost' => null],
        ]);
        DB::table('quotes_ih_items')->insert(['quote_id' => 3]);

        $migration = require database_path(
            'migrations/2026_07_24_010000_version_ih_quote_pricing_rules.php',
        );
        $migration->up();

        $this->assertSame('ih_complexity_v1', DB::table('quotes_ih')->where('id', 1)->value('pricing_rule_version'));
        $this->assertSame('ih_standard_v2', DB::table('quotes_ih')->where('id', 2)->value('pricing_rule_version'));
        $this->assertSame('ih_standard_v2', DB::table('quotes_ih')->where('id', 3)->value('pricing_rule_version'));
        $this->assertSame('ih_complexity_v1', DB::table('quotes_ih')->where('id', 4)->value('pricing_rule_version'));
        $this->assertSame(13932.0, (float) DB::table('quotes_ih')->where('id', 1)->value('grand_total'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('quotes_ih', 'pricing_rule_version'));
        $this->assertSame(13932.0, (float) DB::table('quotes_ih')->where('id', 1)->value('grand_total'));
    }
}
