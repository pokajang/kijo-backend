<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuoteRecordProposalPayloadFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction(
            'FIELD',
            static function (mixed $value, mixed ...$values): int {
                $position = array_search($value, $values, true);

                return $position === false ? 0 : $position + 1;
            },
            -1
        );

        $this->createSchema();
        $this->seedTemplates();
        $this->seedQuotes();
    }

    public function test_quote_record_endpoints_return_normalized_proposal_payloads(): void
    {
        $expectations = [
            'training' => ['training', 101, 'Training Template', 'ms-MY', true],
            'ih' => ['ih', 201, 'IH Template', 'en', true],
            'manpower' => ['manpower', 301, 'Manpower Template', 'ms-MY', true],
            'special' => ['special', 401, 'Special Template', 'en', true],
            'equipment' => [null, null, null, null, false],
        ];

        foreach ($expectations as $service => [$type, $id, $title, $language, $canPreview]) {
            $response = $this->authenticated()
                ->getJson("/quote-records/{$service}");

            $response->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.0.proposal.attachedToPdf', $service !== 'ih')
                ->assertJsonPath('data.0.proposal.templateType', $type)
                ->assertJsonPath('data.0.proposal.templateId', $id)
                ->assertJsonPath('data.0.proposal.title', $title)
                ->assertJsonPath('data.0.proposal.language', $language)
                ->assertJsonPath('data.0.proposal.canPreviewInline', $canPreview);
        }
    }

    public function test_training_quote_records_return_complete_detail_fields(): void
    {
        $response = $this->authenticated()
            ->getJson('/quote-records/training');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.pricing_basis', 'per_session')
            ->assertJsonPath('data.0.training_rate_type', 'client_site_normal')
            ->assertJsonPath('data.0.travel_region', 'northern')
            ->assertJsonPath('data.0.training_total', '9000')
            ->assertJsonPath('data.0.meal_total', '750')
            ->assertJsonPath('data.0.mobilization_cost', '500')
            ->assertJsonPath('data.0.discount_amount', '250')
            ->assertJsonPath('data.0.subtotal', '10000')
            ->assertJsonPath('data.0.sst_amount', '800')
            ->assertJsonPath('data.0.hrd_amount', '87.5')
            ->assertJsonPath('data.0.grand_total', '10887.5')
            ->assertJsonPath('data.0.estimated_total_cost', '7000')
            ->assertJsonPath('data.0.traffic_light_rule_version', 'v1');
    }

    public function test_other_quote_records_return_complete_detail_fields(): void
    {
        $this->authenticated()->getJson('/quote-records/ih')
            ->assertOk()
            ->assertJsonPath('data.0.pricing_rule_version', 'ih_standard_v2')
            ->assertJsonPath('data.0.complexity_rating', '1')
            ->assertJsonPath('data.0.estimated_total_cost', '800')
            ->assertJsonPath('data.0.traffic_light_rule_version', 'v1');

        $this->authenticated()->getJson('/quote-records/manpower')
            ->assertOk()
            ->assertJsonPath('data.0.billing_unit', 'hour')
            ->assertJsonPath('data.0.duration_hours', '12.5')
            ->assertJsonPath('data.0.estimated_total_cost', '4000')
            ->assertJsonPath('data.0.traffic_light_rule_version', 'v1');

        $this->authenticated()->getJson('/quote-records/special')
            ->assertOk()
            ->assertJsonPath('data.0.discount', '50')
            ->assertJsonPath('data.0.sst_percent', '8')
            ->assertJsonPath('data.0.line_items.0.quantity', 1.5);

        $this->authenticated()->getJson('/quote-records/equipment')
            ->assertOk()
            ->assertJsonPath('data.0.estimated_total_cost', '1000')
            ->assertJsonPath('data.0.traffic_light_rule_version', 'v1')
            ->assertJsonPath('data.0.line_items.0.supplier_name', 'Supplier A')
            ->assertJsonPath('data.0.line_items.0.marked_up_price', 600);
    }

    private function createSchema(): void
    {
        foreach ([
            'quote_price_exception_requests',
            'quote_inquiry_sources',
            'quote_followups',
            'projects_main',
            'quotes_equipment_items',
            'quotes_special_items',
            'catalog_items',
            'quotes_equipment',
            'quotes_special',
            'quotes_manpower',
            'quotes_ih',
            'quotes_training',
            'proposal_template_special',
            'proposal_template_manpower',
            'proposal_template_ih',
            'proposal_template_training_main',
            'system_users',
            'user_activities',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->text('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->createTemplateTable('proposal_template_training_main', 'training_title');
        $this->createTemplateTable('proposal_template_ih', 'service_title');
        $this->createTemplateTable('proposal_template_manpower', 'service_title');
        $this->createTemplateTable('proposal_template_special', 'service_title');

        $this->createQuoteTable('quotes_training', [
            'quote_running_no', 'quote_ref_no', 'revision_no', 'price_exception_request_id',
            'created_at', 'updated_at', 'status', 'award_date', 'client_award_ref_no',
            'status_remarks', 'created_by_id', 'created_by_name', 'created_by_code',
            'attach_proposal', 'proposal_id', 'proposal_language', 'client_id', 'training_id',
            'client_name', 'client_ssm', 'client_address', 'client_city', 'client_state',
            'client_zip', 'pic_name', 'pic_email', 'pic_phone', 'pic_position',
            'training_title', 'training_type', 'payment_method', 'proposed_date',
            'proposed_end_date', 'to_be_confirmed', 'venue', 'remarks', 'target_groups',
            'pax', 'session_count', 'duration_per_session', 'duration_unit', 'unit_price',
            'pricing_basis', 'training_rate_type', 'travel_charge', 'travel_region',
            'meals_provided', 'meal_price', 'discount_type',
            'discount_value', 'sst_rate', 'hrd_charge', 'training_total', 'meal_total',
            'mobilization_cost', 'discount_amount', 'subtotal', 'sst_amount', 'hrd_amount',
            'grand_total', 'estimated_total_cost', 'traffic_light_rule_version',
        ]);

        $this->createQuoteTable('quotes_ih', [
            'quote_running_no', 'quote_ref_no', 'revision_no', 'price_exception_request_id',
            'created_at', 'updated_at', 'status', 'award_date', 'client_award_ref_no',
            'status_remarks', 'created_by_id', 'created_by_name', 'created_by_code',
            'attach_proposal', 'proposal_language', 'service_group', 'client_id',
            'client_name', 'client_ssm', 'client_address', 'client_city', 'client_state',
            'client_zip', 'pic_name', 'pic_email', 'pic_phone', 'pic_position',
            'service_id', 'service_title', 'service_code', 'site_address', 'travel_charge',
            'sample_counts', 'sample_unit', 'num_work_units', 'inquiry_remarks',
            'unit_price', 'discount', 'sst_percent', 'sst_amount', 'sub_total',
            'grand_total', 'estimated_total_cost', 'traffic_light_rule_version',
            'pricing_rule_version', 'complexity_rating',
        ]);

        $this->createQuoteTable('quotes_manpower', [
            'quote_running_no', 'quote_ref_no', 'revision_no', 'price_exception_request_id',
            'created_at', 'updated_at', 'status', 'award_date', 'client_award_ref_no',
            'status_remarks', 'created_by_id', 'created_by_name', 'created_by_code',
            'attach_proposal', 'proposal_language', 'service_group', 'client_id',
            'client_name', 'client_ssm', 'client_address', 'client_city', 'client_state',
            'client_zip', 'pic_name', 'pic_email', 'pic_phone', 'pic_position',
            'mp_id', 'service_title', 'service_code', 'manpower_rate_type', 'billing_unit',
            'duration_hours', 'requires_management_approval', 'nature_of_work',
            'site_location', 'duration_months', 'no_of_pax', 'unit_cost', 'discount',
            'sst_percent', 'sst_amount', 'sub_total', 'grand_total', 'inquiry_remarks',
            'estimated_total_cost', 'traffic_light_rule_version',
        ]);

        $this->createQuoteTable('quotes_special', [
            'quote_running_no', 'quote_ref_no', 'revision_no', 'price_exception_request_id', 'created_at',
            'updated_at', 'status', 'status_remarks', 'award_date', 'client_award_ref_no',
            'created_by_id', 'created_by_name', 'created_by_code', 'client_id',
            'client_name', 'client_ssm', 'client_address', 'client_city', 'client_state',
            'client_zip', 'pic_name', 'pic_email', 'pic_phone', 'pic_position', 'sp_id',
            'service_title', 'service_code', 'proposal_language', 'general_remarks',
            'discount', 'sst_percent', 'sst_amount', 'sub_total', 'grand_total', 'attach_proposal',
            'service_group',
        ]);

        $this->createQuoteTable('quotes_equipment', [
            'quote_running_no', 'quote_ref_no', 'revision_no', 'price_exception_request_id', 'created_at',
            'updated_at', 'status', 'status_remarks', 'award_date', 'client_award_ref_no',
            'created_by_id', 'created_by_name', 'created_by_code', 'client_id',
            'client_name', 'client_ssm', 'client_address', 'client_city', 'client_state',
            'client_zip', 'pic_name', 'pic_email', 'pic_phone', 'pic_position',
            'inquiry_remarks', 'discount', 'delivery_charge', 'misc_charge', 'sst_percent',
            'sst_amount', 'sub_total', 'grand_total', 'attach_proposal', 'service_group',
            'estimated_total_cost', 'traffic_light_rule_version',
        ]);

        Schema::create('quote_inquiry_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
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

        Schema::create('quote_price_exception_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_type')->nullable();
            $table->string('service_group')->nullable();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('status')->nullable();
        });

        Schema::create('quotes_special_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('line_item_title')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('item_name')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('supplier_price', 12, 2)->nullable();
            $table->date('price_date')->nullable();
        });

        Schema::create('quotes_equipment_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('marked_up_price', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function createTemplateTable(string $tableName, string $titleColumn): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($titleColumn): void {
            $table->id();
            $table->string($titleColumn)->nullable();
            $table->string('proposal_language', 10)->nullable();
            $table->integer('is_deleted')->default(0);
        });
    }

    private function createQuoteTable(string $tableName, array $columns): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($columns): void {
            $table->id();
            foreach ($columns as $column) {
                $table->text($column)->nullable();
            }
        });
    }

    private function seedTemplates(): void
    {
        DB::table('proposal_template_training_main')->insert([
            'id' => 101,
            'training_title' => 'Training Template',
            'proposal_language' => 'en',
            'is_deleted' => 0,
        ]);
        DB::table('proposal_template_ih')->insert([
            'id' => 201,
            'service_title' => 'IH Template',
            'proposal_language' => 'en',
            'is_deleted' => 0,
        ]);
        DB::table('proposal_template_manpower')->insert([
            'id' => 301,
            'service_title' => 'Manpower Template',
            'proposal_language' => 'ms-MY',
            'is_deleted' => 0,
        ]);
        DB::table('proposal_template_special')->insert([
            'id' => 401,
            'service_title' => 'Special Template',
            'proposal_language' => 'en',
            'is_deleted' => 0,
        ]);
    }

    private function seedQuotes(): void
    {
        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'sysadmin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);

        DB::table('quotes_training')->insert($this->quotePayload([
            'proposal_id' => 101,
            'proposal_language' => 'ms-MY',
            'training_id' => 999,
            'training_title' => 'Quote Training',
            'pricing_basis' => 'per_session',
            'training_rate_type' => 'client_site_normal',
            'travel_region' => 'northern',
            'training_total' => 9000,
            'meal_total' => 750,
            'mobilization_cost' => 500,
            'discount_amount' => 250,
            'subtotal' => 10000,
            'sst_amount' => 800,
            'hrd_amount' => 87.5,
            'grand_total' => 10887.5,
            'estimated_total_cost' => 7000,
            'traffic_light_rule_version' => 'v1',
        ]));

        DB::table('quotes_ih')->insert($this->quotePayload([
            'attach_proposal' => 0,
            'service_group' => 'ih',
            'service_id' => 201,
            'proposal_language' => 'en',
            'service_title' => 'Quote IH',
            'pricing_rule_version' => 'ih_standard_v2',
            'complexity_rating' => 1,
            'estimated_total_cost' => 800,
            'traffic_light_rule_version' => 'v1',
        ]));

        DB::table('quotes_manpower')->insert($this->quotePayload([
            'service_group' => 'manpower',
            'mp_id' => 301,
            'proposal_language' => 'ms-MY',
            'service_title' => 'Quote Manpower',
            'billing_unit' => 'hour',
            'duration_hours' => 12.5,
            'estimated_total_cost' => 4000,
            'traffic_light_rule_version' => 'v1',
        ]));

        DB::table('quotes_special')->insert($this->quotePayload([
            'service_group' => 'special',
            'sp_id' => 401,
            'proposal_language' => 'en',
            'service_title' => 'Quote Special',
            'discount' => 50,
            'sst_percent' => 8,
        ]));

        DB::table('quotes_equipment')->insert($this->quotePayload([
            'service_group' => 'Equipment',
            'estimated_total_cost' => 1000,
            'traffic_light_rule_version' => 'v1',
        ]));

        DB::table('quotes_special_items')->insert([
            'quote_id' => 1,
            'service_id' => 401,
            'line_item_title' => 'Audit day',
            'quantity' => 1.5,
            'unit' => 'Day',
            'unit_price' => 300,
            'line_total' => 450,
        ]);

        DB::table('catalog_items')->insert([
            'id' => 501,
            'item_name' => 'Gas Detector',
            'category_id' => 1,
            'description' => 'Portable detector',
            'unit' => 'unit',
            'supplier_name' => 'Supplier A',
            'supplier_price' => 400,
            'price_date' => '2026-07-01',
        ]);

        DB::table('quotes_equipment_items')->insert([
            'quote_id' => 1,
            'item_id' => 501,
            'quantity' => 2,
            'unit_price' => 400,
            'marked_up_price' => 600,
            'line_total' => 1200,
        ]);
    }

    private function quotePayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'quote_ref_no' => 'Q-1',
            'quote_running_no' => 'Q-1',
            'revision_no' => 0,
            'created_at' => '2026-05-21 00:00:00',
            'updated_at' => '2026-05-21 00:00:00',
            'status' => 'Open',
            'attach_proposal' => 1,
            'client_id' => 1,
            'client_name' => 'Client',
            'pic_name' => 'PIC',
            'pic_email' => 'pic@example.test',
            'grand_total' => 1000,
        ], $overrides);
    }

    private function authenticated(): self
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 10,
                'roles' => ['System Admin'],
                'full_name' => 'System Admin',
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
