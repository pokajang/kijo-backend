<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IhQuoteAdditionalFeesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('GET_LOCK', fn () => 1, -1);
        DB::connection()->getPdo()->sqliteCreateFunction('RELEASE_LOCK', fn () => 1, -1);
        DB::connection()->getPdo()->sqliteCreateFunction('FIELD', function (string $value, string ...$values): int {
            $index = array_search($value, $values, true);

            return $index === false ? 0 : $index + 1;
        }, -1);
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function (string $value, string $delimiter, int $count): string {
            $parts = explode($delimiter, $value);
            if ($count < 0) {
                return implode($delimiter, array_slice($parts, $count));
            }

            return implode($delimiter, array_slice($parts, 0, $count));
        }, 3);

        $this->createSchema();
    }

    public function test_ih_quote_create_update_show_and_invoice_lookup_include_additional_fees(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'estimated_total_cost' => 1200.45,
            'traffic_light_rule_version' => 'v1',
            'hygiene_items' => [
                [
                    'item_description' => 'Sample analysis',
                    'description' => 'Lab analysis',
                    'quantity' => 2,
                    'unit' => 'sample',
                    'unit_price' => 120,
                ],
                [
                    'item_description' => 'Report writing',
                    'quantity' => 1,
                    'unit' => 'Lot',
                    'unit_price' => 300,
                ],
            ],
        ]));

        $create->assertOk()
            ->assertJsonPath('status', 'success');

        $quoteId = (int) $create->json('quote_id');
        $this->assertSame(1540.0, (float) DB::table('quotes_ih')->where('id', $quoteId)->value('sub_total'));
        $this->assertSame(1200.45, (float) DB::table('quotes_ih')->where('id', $quoteId)->value('estimated_total_cost'));
        $this->assertSame('v1', DB::table('quotes_ih')->where('id', $quoteId)->value('traffic_light_rule_version'));
        $this->assertSame('ih_standard_v2', DB::table('quotes_ih')->where('id', $quoteId)->value('pricing_rule_version'));
        $this->assertSame(2, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->getJson("/quotes/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.estimated_total_cost', 1200.45)
            ->assertJsonPath('data.traffic_light_rule_version', 'v1')
            ->assertJsonPath('data.hygiene_items.0.item_description', 'Sample analysis');

        $this->authenticated()->getJson("/invoices/quote/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.hygiene_items.1.item_description', 'Report writing');

        $this->authenticated()->getJson('/quote-records/ih')
            ->assertOk()
            ->assertJsonPath('data.0.hygiene_items.0.item_description', 'Sample analysis')
            ->assertJsonPath('data.0.line_items.1.item_description', 'Report writing')
            ->assertJsonPath('data.0.sub_total', 1540);

        $update = $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $this->payload([
            'discount' => 40,
            'sst_percent' => 8,
            'estimated_total_cost' => 1300.55,
            'hygiene_items' => [
                [
                    'item_description' => 'Professional fee',
                    'quantity' => 1,
                    'unit' => 'Lot',
                    'unit_price' => 500,
                ],
            ],
        ]));

        $update->assertOk()
            ->assertJsonPath('status', 'success');

        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
        $this->assertSame(1500.0, (float) $quote->sub_total);
        $this->assertSame(116.8, (float) $quote->sst_amount);
        $this->assertSame(1576.8, (float) $quote->grand_total);
        $this->assertSame(1300.55, (float) $quote->estimated_total_cost);
        $this->assertSame('v1', $quote->traffic_light_rule_version);
        $this->assertSame(1, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->getJson("/quotes/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.estimated_total_cost', 1300.55)
            ->assertJsonPath('data.traffic_light_rule_version', 'v1');

        $this->authenticated()->deleteJson("/quote-records/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->assertSame(0, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());
        $this->authenticated()->getJson("/quotes/ih/{$quoteId}")->assertNotFound();
        $this->authenticated()->getJson("/invoices/quote/ih/{$quoteId}")->assertNotFound();
        $this->authenticated()->getJson('/quote-records/ih')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_new_quote_flow_cannot_be_downgraded_to_legacy_pricing_by_the_client(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'pricing_rule_version' => 'ih_complexity_v1',
            'complexity_rating' => 5,
            'hygiene_items' => [
                [
                    'item_description' => 'Laboratory analysis',
                    'quantity' => 2,
                    'unit' => 'sample',
                    'unit_price' => 100,
                ],
            ],
        ]));

        $create->assertOk()
            ->assertJsonPath('status', 'success');

        $quoteId = (int) $create->json('quote_id');
        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();

        $this->assertSame('ih_standard_v2', $quote->pricing_rule_version);
        $this->assertSame(1, (int) $quote->complexity_rating);
        $this->assertSame(1200.0, (float) $quote->sub_total);
        $this->assertSame(1200.0, (float) $quote->grand_total);
        $this->assertSame(1, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->getJson("/quotes/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.pricing_rule_version', 'ih_standard_v2')
            ->assertJsonPath('data.complexity_rating', 1)
            ->assertJsonPath('data.complexity_multiplier', 1);
    }

    public function test_ih_quote_update_preserves_additional_fees_when_payload_omits_them(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'hygiene_items' => [
                [
                    'item_description' => 'Sample analysis',
                    'description' => 'Lab analysis',
                    'quantity' => 2,
                    'unit' => 'sample',
                    'unit_price' => 120,
                ],
            ],
        ]));

        $create->assertOk();
        $quoteId = (int) $create->json('quote_id');

        $payload = $this->payload([
            'discount' => 40,
            'sst_percent' => 0,
        ]);
        unset($payload['hygiene_items']);

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
        $this->assertSame(1240.0, (float) $quote->sub_total);
        $this->assertSame(1200.0, (float) $quote->grand_total);
        $this->assertSame(1, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());
    }

    public function test_standard_revision_increments_once_and_preserves_the_v2_contract(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'estimated_total_cost' => 800,
            'hygiene_items' => [[
                'item_description' => 'Laboratory fee',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 200,
            ]],
        ]));
        $create->assertOk();
        $quoteId = (int) $create->json('quote_id');

        $revisionPayload = $this->payload([
            'isRevision' => true,
            'estimated_total_cost' => 800,
        ]);
        unset($revisionPayload['hygiene_items']);

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $revisionPayload)
            ->assertOk()
            ->assertJsonPath('data.revision_no', 1);

        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
        $this->assertSame(1, (int) $quote->revision_no);
        $this->assertSame('ih_standard_v2', $quote->pricing_rule_version);
        $this->assertSame(1200.0, (float) $quote->grand_total);
        $this->assertSame(1, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", [
            ...$revisionPayload,
            'inquiry_remarks' => 'Second revision',
        ])->assertOk()
            ->assertJsonPath('data.revision_no', 2);

        $this->assertSame(2, (int) DB::table('quotes_ih')->where('id', $quoteId)->value('revision_no'));
    }

    public function test_create_rolls_back_quote_when_additional_fee_storage_fails(): void
    {
        Schema::drop('quotes_ih_items');

        $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'hygiene_items' => [[
                'item_description' => 'Cannot persist',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 100,
            ]],
        ]))->assertStatus(500)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, DB::table('quotes_ih')->count());
    }

    public function test_update_rolls_back_quote_when_additional_fee_storage_fails(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->payload());
        $create->assertOk();
        $quoteId = (int) $create->json('quote_id');
        Schema::drop('quotes_ih_items');

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $this->payload([
            'unit_price' => 900,
            'hygiene_items' => [[
                'item_description' => 'Cannot persist',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 100,
            ]],
        ]))->assertStatus(500)
            ->assertJsonPath('status', 'error');

        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
        $this->assertSame(500.0, (float) $quote->unit_price);
        $this->assertSame(1000.0, (float) $quote->grand_total);
    }

    public function test_ih_quote_rejects_malformed_additional_fee_rows(): void
    {
        $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'hygiene_items' => ['not-an-object'],
        ]))->assertStatus(422);
    }

    public function test_ih_quote_rejects_zero_value_additional_fee_rows(): void
    {
        $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'hygiene_items' => [
                [
                    'item_description' => 'Zero quantity row',
                    'quantity' => 0,
                    'unit' => 'Lot',
                    'unit_price' => 100,
                ],
            ],
        ]))->assertStatus(422);

        $this->authenticated()->postJson('/quotes/ih', $this->payload([
            'hygiene_items' => [
                [
                    'item_description' => 'Zero price row',
                    'quantity' => 1,
                    'unit' => 'Lot',
                    'unit_price' => 0,
                ],
            ],
        ]))->assertStatus(422);
    }

    public function test_legacy_revision_preserves_complexity_rule_and_existing_totals(): void
    {
        $create = $this->authenticated()->postJson('/quotes/ih', $this->payload());
        $create->assertOk();
        $quoteId = (int) $create->json('quote_id');

        DB::table('quotes_ih')->where('id', $quoteId)->update([
            'sample_counts' => 10,
            'num_work_units' => 2,
            'unit_price' => 500,
            'travel_charge' => 200,
            'discount' => 300,
            'sst_percent' => 8,
            'sub_total' => 12900,
            'sst_amount' => 1032,
            'grand_total' => 13932,
            'estimated_total_cost' => null,
            'pricing_rule_version' => 'ih_complexity_v1',
            'complexity_rating' => 4,
        ]);

        $legacyPayload = $this->payload([
            'sample_counts' => 10,
            'num_work_units' => 2,
            'unit_price' => 500,
            'travel_charge' => 200,
            'discount' => 300,
            'sst_percent' => 8,
            'estimated_total_cost' => null,
            'inquiry_remarks' => 'Non-pricing revision',
            'isRevision' => true,
            // These fields are intentionally hostile: the server must derive
            // the immutable pricing rule and rating from the existing quote.
            'pricing_rule_version' => 'ih_standard_v2',
            'complexity_rating' => 1,
        ]);

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", $legacyPayload)
            ->assertOk();

        $preserved = DB::table('quotes_ih')->where('id', $quoteId)->first();
        $this->assertSame(12900.0, (float) $preserved->sub_total);
        $this->assertSame(1032.0, (float) $preserved->sst_amount);
        $this->assertSame(13932.0, (float) $preserved->grand_total);
        $this->assertSame('ih_complexity_v1', $preserved->pricing_rule_version);
        $this->assertSame(4, (int) $preserved->complexity_rating);

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", [
            ...$legacyPayload,
            'hygiene_items' => [[
                'item_description' => 'V2 fee injection',
                'quantity' => 1,
                'unit' => 'Lot',
                'unit_price' => 999,
            ]],
        ])->assertOk();
        $this->assertSame(0, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->putJson("/quotes/ih/{$quoteId}", [
            ...$legacyPayload,
            'unit_price' => 600,
        ])->assertOk();

        $recalculated = DB::table('quotes_ih')->where('id', $quoteId)->first();
        $this->assertSame(15500.0, (float) $recalculated->sub_total);
        $this->assertSame(1240.0, (float) $recalculated->sst_amount);
        $this->assertSame(16740.0, (float) $recalculated->grand_total);

        $this->authenticated()->getJson("/quotes/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.pricing_rule_version', 'ih_complexity_v1')
            ->assertJsonPath('data.complexity_rating', 4)
            ->assertJsonPath('data.complexity_multiplier', 1.3);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_ssm' => '',
            'client_address' => '1 Test Road',
            'client_city' => 'City',
            'client_state' => 'State',
            'client_zip' => '12345',
            'pic_name' => 'PIC A',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'service_id' => 201,
            'service_title' => 'CEM Monitoring',
            'service_code' => 'CEM',
            'site_address' => 'Site A',
            'travel_charge' => 0,
            'sample_counts' => 2,
            'sample_unit' => 'sample(s)',
            'num_work_units' => 1,
            'unit_price' => 500,
            'discount' => 0,
            'sst_percent' => 0,
            'sst_amount' => 0,
            'sub_total' => 0,
            'grand_total' => 0,
            'inquiry_remarks' => '',
            'attach_proposal' => 1,
            'proposal_language' => 'en',
            'hygiene_items' => [],
        ], $overrides);
    }

    private function createSchema(): void
    {
        foreach (['quote_inquiry_sources', 'quote_followups', 'quote_price_exception_requests', 'projects_main', 'quotes_ih_items', 'quotes_ih', 'staff_general', 'system_users', 'user_activities'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
        });

        Schema::create('quotes_ih', function (Blueprint $table): void {
            $table->id();
            $table->string('service_group')->nullable();
            $table->integer('quote_running_no')->nullable();
            $table->string('quote_ref_no')->nullable();
            $table->integer('revision_no')->default(0);
            $table->unsignedBigInteger('price_exception_request_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_ssm')->nullable();
            $table->string('client_address')->nullable();
            $table->string('client_city')->nullable();
            $table->string('client_state')->nullable();
            $table->string('client_zip')->nullable();
            $table->text('pic_name')->nullable();
            $table->text('pic_email')->nullable();
            $table->text('pic_phone')->nullable();
            $table->text('pic_position')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->string('site_address')->nullable();
            $table->decimal('travel_charge', 15, 2)->default(0);
            $table->decimal('sample_counts', 15, 2)->default(0);
            $table->string('sample_unit')->nullable();
            $table->decimal('num_work_units', 15, 2)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('sst_percent', 15, 2)->default(0);
            $table->decimal('sst_amount', 15, 2)->default(0);
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('estimated_total_cost', 15, 2)->nullable();
            $table->string('traffic_light_rule_version', 50)->nullable();
            $table->string('pricing_rule_version', 40)->default('ih_standard_v2');
            $table->unsignedTinyInteger('complexity_rating')->default(1);
            $table->decimal('complexity_markup', 15, 2)->default(0);
            $table->text('inquiry_remarks')->nullable();
            $table->boolean('attach_proposal')->default(false);
            $table->string('proposal_language')->nullable();
            $table->string('status')->nullable();
            $table->date('award_date')->nullable();
            $table->string('client_award_ref_no')->nullable();
            $table->text('status_remarks')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_name')->nullable();
            $table->string('created_by_code')->nullable();
            $table->timestamps();
        });

        Schema::create('quotes_ih_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('quote_id');
            $table->string('item_description');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_price_exception_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_type')->nullable();
            $table->string('service_group')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->string('status')->nullable();
        });

        Schema::create('quote_inquiry_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('quote_ref_no')->nullable();
            $table->string('service_type')->nullable();
            $table->string('source')->nullable();
            $table->text('remarks')->nullable();
        });

        Schema::create('quote_followups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('quote_type')->nullable();
            $table->text('remarks')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('project_type')->nullable();
            $table->date('award_date')->nullable();
            $table->string('status')->nullable();
            $table->decimal('quote_value', 12, 2)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->integer('staff_id')->nullable();
            $table->string('name_code', 20)->nullable();
            $table->text('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'sysadmin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);
        DB::table('staff_general')->insert([
            'staff_id' => 10,
            'full_name' => 'System Admin',
            'name_code' => 'AZA',
        ]);
    }

    private function authenticated(): self
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 10,
                'name_code' => 'AZA',
                'roles' => ['System Admin'],
                'full_name' => 'System Admin',
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
