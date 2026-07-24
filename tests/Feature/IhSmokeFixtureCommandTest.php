<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IhSmokeFixtureCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('quotes_ih_items');
        Schema::dropIfExists('quotes_ih');
        Schema::create('quotes_ih', function (Blueprint $table): void {
            $table->id();
            $table->string('service_group')->nullable();
            $table->integer('quote_running_no')->nullable();
            $table->string('quote_ref_no')->nullable();
            $table->integer('revision_no')->default(0);
            $table->string('status')->nullable();
            $table->integer('sample_counts')->default(0);
            $table->integer('num_work_units')->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('travel_charge', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('sst_percent', 15, 2)->default(0);
            $table->decimal('sst_amount', 15, 2)->default(0);
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('estimated_total_cost', 15, 2)->nullable();
            $table->string('pricing_rule_version')->default('ih_standard_v2');
            $table->unsignedTinyInteger('complexity_rating')->default(1);
            $table->decimal('complexity_markup', 15, 2)->default(0);
            $table->text('inquiry_remarks')->nullable();
            $table->boolean('attach_proposal')->default(false);
            $table->timestamps();
        });
        Schema::create('quotes_ih_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
        });

        DB::table('quotes_ih')->insert([
            'id' => 10,
            'service_group' => 'ih',
            'quote_running_no' => 1,
            'quote_ref_no' => 'QIH26-0001TST',
            'status' => 'Open',
            'pricing_rule_version' => 'ih_standard_v2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_command_prepares_and_cleans_up_a_disposable_legacy_quote(): void
    {
        $this->artisan('quotes:ih-smoke-fixture', [
            'action' => 'prepare',
            '--source-id' => 10,
            '--complexity' => 4,
        ])->assertSuccessful();

        $legacy = DB::table('quotes_ih')
            ->where('quote_ref_no', 'like', 'SMOKE-IH-V1-%')
            ->first();

        $this->assertNotNull($legacy);
        $this->assertSame('ih_complexity_v1', $legacy->pricing_rule_version);
        $this->assertSame(4, (int) $legacy->complexity_rating);
        $this->assertSame(1300.0, (float) $legacy->grand_total);

        $this->artisan('quotes:ih-smoke-fixture', [
            'action' => 'cleanup',
            '--quote-id' => $legacy->id,
        ])->assertSuccessful();

        $this->assertFalse(DB::table('quotes_ih')->where('id', $legacy->id)->exists());
    }

    public function test_cleanup_refuses_to_delete_a_non_smoke_quote(): void
    {
        $this->artisan('quotes:ih-smoke-fixture', [
            'action' => 'cleanup',
            '--quote-id' => 10,
        ])->assertFailed();

        $this->assertTrue(DB::table('quotes_ih')->where('id', 10)->exists());
    }
}
