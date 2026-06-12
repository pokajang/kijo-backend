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
        $this->assertSame(2, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->getJson("/quotes/ih/{$quoteId}")
            ->assertOk()
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
        $this->assertSame(1, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());

        $this->authenticated()->deleteJson("/quote-records/ih/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->assertSame(0, DB::table('quotes_ih_items')->where('quote_id', $quoteId)->count());
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
