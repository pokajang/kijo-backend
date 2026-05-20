<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManualDebtorFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-18 12:00:00'));
        Storage::fake('private');
        $this->registerSqliteDateFormat();

        $this->withoutMiddleware([
            \App\Http\Middleware\RequireAuth::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        $this->createTables();
        $this->seedSystemInvoice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registerSqliteDateFormat(): void
    {
        $pdo = DB::connection()->getPdo();
        if (!method_exists($pdo, 'sqliteCreateFunction')) {
            return;
        }

        $pdo->sqliteCreateFunction('DATE_FORMAT', static function ($date, $format) {
            if (!$date) {
                return null;
            }

            $timestamp = strtotime((string) $date);
            if ($timestamp === false) {
                return null;
            }

            return match ($format) {
                '%Y-%m' => date('Y-m', $timestamp),
                default => date('Y-m-d', $timestamp),
            };
        }, 2);
    }

    public function test_manual_debtor_crud_payment_lifecycle_and_attachment(): void
    {
        $createResponse = $this->actingSession()
            ->post('/debtors/manual', [
                'invoice_ref_no' => 'OLD-INV-001',
                'client_name' => 'Legacy Client Sdn Bhd',
                'pic_name' => 'Legacy PIC',
                'pic_phone' => '60120000000',
                'pic_email' => 'legacy@example.test',
                'service_type' => 'Training',
                'service_start_date' => '2024-01-01',
                'service_end_date' => '2024-01-31',
                'purpose' => 'Old external invoice',
                'invoice_date' => '2024-01-15',
                'grand_total' => '1500.50',
                'status' => 'Open',
                'payment_method' => 'Bank Transfer',
                'attachment' => UploadedFile::fake()->create('old-invoice.pdf', 100, 'application/pdf'),
            ]);

        $createResponse->assertCreated()->assertJsonPath('status', 'success');
        $id = (int) $createResponse->json('id');

        $record = DB::table('manual_debtors')->where('id', $id)->first();
        $this->assertNotNull($record);
        $this->assertSame('OLD-INV-001', $record->invoice_ref_no);
        $this->assertSame('2024-01-01 - 2024-01-31', $record->service_period);
        $this->assertSame('2024-01-01', (string) $record->service_start_date);
        $this->assertSame('2024-01-31', (string) $record->service_end_date);
        $this->assertNotEmpty($record->attachment_path);
        Storage::disk('private')->assertExists($record->attachment_path);

        $this->actingSession()
            ->putJson("/debtors/manual/{$id}", [
                'invoice_ref_no' => 'OLD-INV-001A',
                'client_name' => 'Legacy Client Sdn Bhd',
                'invoice_date' => '2024-01-15',
                'grand_total' => '1750.00',
                'status' => 'Open',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $id,
            'invoice_ref_no' => 'OLD-INV-001A',
            'grand_total' => 1750.00,
        ]);

        $this->actingSession()
            ->patchJson("/debtors/manual/{$id}/mark-paid", [
                'paid_date' => '2026-05-18',
                'paid_amount' => 1750,
                'paid_remarks' => 'Settled',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $id,
            'status' => 'Paid',
            'paid_date' => '2026-05-18',
            'paid_amount' => 1750,
        ]);

        $this->actingSession()
            ->patchJson("/debtors/manual/{$id}/mark-open")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $id,
            'status' => 'Open',
            'paid_date' => null,
            'paid_amount' => null,
        ]);

        $this->actingSession()
            ->deleteJson("/debtors/manual/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('manual_debtors', ['id' => $id]);
        Storage::disk('private')->assertMissing($record->attachment_path);
    }

    public function test_manual_debtor_validation_rejects_required_fields_dates_and_attachment_type(): void
    {
        $this->actingSession()
            ->post('/debtors/manual', [
                'invoice_ref_no' => '',
                'client_name' => '',
                'invoice_date' => '18-05-2026',
                'grand_total' => '0',
                'attachment' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->actingSession()
            ->postJson('/debtors/manual', [
                'invoice_ref_no' => 'BAD-SERVICE-001',
                'client_name' => 'Legacy Client Sdn Bhd',
                'service_type' => 'Unsupported Service',
                'invoice_date' => '2026-05-18',
                'grand_total' => '100.00',
                'status' => 'Open',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->actingSession()
            ->postJson('/debtors/manual', [
                'invoice_ref_no' => 'BAD-PERIOD-001',
                'client_name' => 'Legacy Client Sdn Bhd',
                'service_type' => 'Training',
                'service_start_date' => '2026-05-20',
                'service_end_date' => '2026-05-18',
                'invoice_date' => '2026-05-18',
                'grand_total' => '100.00',
                'status' => 'Open',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_manual_debtor_can_link_client_pic_and_clear_link(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 2, 'company_name' => 'Linked Client Sdn Bhd'],
            ['company_id' => 3, 'company_name' => 'Other Client Sdn Bhd'],
        ]);
        DB::table('client_pic')->insert([
            [
                'pic_id' => 10,
                'company_id' => 2,
                'full_name' => 'Linked PIC',
                'email' => 'linked.pic@example.test',
                'mobile_number' => '60122222222',
                'position' => 'Manager',
            ],
            [
                'pic_id' => 12,
                'company_id' => 2,
                'full_name' => 'Second Linked PIC',
                'email' => 'second.linked.pic@example.test',
                'mobile_number' => '60124444444',
                'position' => 'Director',
            ],
            [
                'pic_id' => 11,
                'company_id' => 3,
                'full_name' => 'Other PIC',
                'email' => 'other.pic@example.test',
                'mobile_number' => '60123333333',
                'position' => 'Manager',
            ],
        ]);

        $invalidResponse = $this->actingSession()
            ->postJson('/debtors/manual', [
                'invoice_ref_no' => 'LINKED-INVALID-001',
                'client_id' => 2,
                'pic_id' => 11,
                'client_name' => 'Linked Client Sdn Bhd',
                'pic_name' => 'Other PIC',
                'invoice_date' => '2026-05-01',
                'grand_total' => 100,
                'status' => 'Open',
            ]);

        $invalidResponse->assertStatus(422)->assertJsonPath('status', 'error');

        $createResponse = $this->actingSession()
            ->postJson('/debtors/manual', [
                'invoice_ref_no' => 'LINKED-001',
                'client_id' => 2,
                'pic_id' => 10,
                'client_name' => 'Linked Client Sdn Bhd',
                'pic_name' => 'Linked PIC, Second Linked PIC',
                'pic_phone' => '60122222222, 60124444444',
                'pic_email' => 'linked.pic@example.test, second.linked.pic@example.test',
                'invoice_date' => '2026-05-01',
                'grand_total' => 1000,
                'status' => 'Open',
            ]);

        $createResponse->assertCreated()->assertJsonPath('status', 'success');
        $id = (int) $createResponse->json('id');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $id,
            'client_id' => 2,
            'pic_id' => 10,
            'client_name' => 'Linked Client Sdn Bhd',
            'pic_name' => 'Linked PIC, Second Linked PIC',
            'payment_terms_days' => 30,
            'payment_terms_source' => 'system_default',
            'due_date' => '2026-05-31',
        ]);

        $manualRows = $this->actingSession()
            ->getJson('/debtors?source=manual&status=all&as_of_date=2026-05-18')
            ->assertOk()
            ->json('debtors');

        $linkedRow = collect($manualRows)->firstWhere('invoiceRef', 'LINKED-001');
        $this->assertSame(2, $linkedRow['clientId']);
        $this->assertSame(10, $linkedRow['picId']);
        $this->assertSame(30, $linkedRow['paymentTermsDays']);
        $this->assertSame('system_default', $linkedRow['paymentTermsSource']);
        $this->assertSame('2026-05-31', $linkedRow['dueDate']);
        $this->assertSame(-13, $linkedRow['overdueDays']);

        $this->actingSession()
            ->putJson("/debtors/manual/{$id}", [
                'invoice_ref_no' => 'LINKED-001',
                'client_id' => '',
                'pic_id' => '',
                'client_name' => 'Snapshot Only Client',
                'invoice_date' => '2026-05-01',
                'grand_total' => 1000,
                'status' => 'Open',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $id,
            'client_id' => null,
            'pic_id' => null,
            'client_name' => 'Snapshot Only Client',
            'payment_terms_days' => 30,
            'payment_terms_source' => 'system_default',
            'due_date' => '2026-05-31',
        ]);
    }

    public function test_manual_debtor_uses_client_terms_and_allows_manual_override(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 4, 'company_name' => 'Custom Terms Client', 'payment_terms_days' => 60],
        ]);

        $clientTermsResponse = $this->actingSession()
            ->postJson('/debtors/manual', [
                'invoice_ref_no' => 'CLIENT-TERMS-001',
                'client_id' => 4,
                'client_name' => 'Custom Terms Client',
                'invoice_date' => '2026-05-01',
                'grand_total' => 1000,
                'status' => 'Open',
                'override_payment_terms' => false,
            ]);

        $clientTermsResponse->assertCreated()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('manual_debtors', [
            'id' => (int) $clientTermsResponse->json('id'),
            'payment_terms_days' => 60,
            'payment_terms_source' => 'client',
            'due_date' => '2026-06-30',
        ]);
        $clientTermsId = (int) $clientTermsResponse->json('id');

        DB::table('client_company')->where('company_id', 4)->update(['payment_terms_days' => 45]);

        $this->actingSession()
            ->putJson("/debtors/manual/{$clientTermsId}", [
                'invoice_ref_no' => 'CLIENT-TERMS-001',
                'client_id' => 4,
                'client_name' => 'Custom Terms Client',
                'invoice_date' => '2026-05-02',
                'grand_total' => 1200,
                'status' => 'Open',
                'override_payment_terms' => false,
                'payment_terms_changed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $clientTermsId,
            'payment_terms_days' => 60,
            'payment_terms_source' => 'client',
            'due_date' => '2026-07-01',
        ]);

        $this->actingSession()
            ->putJson("/debtors/manual/{$clientTermsId}", [
                'invoice_ref_no' => 'CLIENT-TERMS-001',
                'client_id' => 4,
                'client_name' => 'Custom Terms Client',
                'invoice_date' => '2026-05-02',
                'grand_total' => 1200,
                'status' => 'Open',
                'override_payment_terms' => false,
                'payment_terms_changed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => $clientTermsId,
            'payment_terms_days' => 45,
            'payment_terms_source' => 'client',
            'due_date' => '2026-06-16',
        ]);

        $overrideResponse = $this->actingSession()
            ->postJson('/debtors/manual', [
                'invoice_ref_no' => 'MANUAL-TERMS-001',
                'client_id' => 4,
                'client_name' => 'Custom Terms Client',
                'invoice_date' => '2026-05-01',
                'grand_total' => 1000,
                'status' => 'Open',
                'override_payment_terms' => true,
                'payment_terms_days' => 45,
            ]);

        $overrideResponse->assertCreated()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('manual_debtors', [
            'id' => (int) $overrideResponse->json('id'),
            'payment_terms_days' => 45,
            'payment_terms_source' => 'manual_override',
            'due_date' => '2026-06-15',
        ]);
    }

    public function test_consolidated_debtors_default_open_filter_and_stats_include_manual_rows(): void
    {
        DB::table('manual_debtors')->insert([
            [
                'invoice_ref_no' => 'OLD-OPEN-2024',
                'client_name' => 'Manual Old Open Client',
                'invoice_date' => '2024-01-18',
                'grand_total' => 900,
                'status' => 'Open',
                'paid_date' => null,
                'paid_amount' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_ref_no' => 'OLD-OPEN-001',
                'client_name' => 'Manual Open Client',
                'invoice_date' => '2026-05-01',
                'grand_total' => 2000,
                'status' => 'Open',
                'paid_date' => null,
                'paid_amount' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_ref_no' => 'OLD-PAID-001',
                'client_name' => 'Manual Paid Client',
                'invoice_date' => '2026-05-01',
                'grand_total' => 800,
                'status' => 'Paid',
                'paid_date' => '2026-05-10',
                'paid_amount' => 800,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $debtors = $this->actingSession()
            ->getJson('/debtors?as_of_date=2026-05-18')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('debtors');

        $this->assertSame(['invoice', 'manual'], collect($debtors)->pluck('sourceType')->sort()->unique()->values()->all());
        $this->assertSame(['OLD-OPEN-2024', 'OLD-OPEN-001', 'INV-OPEN-001'], collect($debtors)->pluck('invoiceRef')->values()->all());

        $allDebtors = $this->actingSession()
            ->getJson('/debtors?status=all&as_of_date=2026-05-18')
            ->assertOk()
            ->json('debtors');

        $this->assertContains('OLD-PAID-001', collect($allDebtors)->pluck('invoiceRef')->all());

        $totals = $this->actingSession()
            ->postJson('/stats/monthly-income-statement', [
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-18',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(4300.0, (float) $totals->json('totalInvoiced'));
        $this->assertSame(1300.0, (float) $totals->json('totalReceived'));
        $this->assertSame(3900.0, (float) $totals->json('outstandingAmount'));
        $this->assertSame(3, (int) $totals->json('outstandingCount'));

        $trend = $this->actingSession()
            ->postJson('/stats/monthly-invoiced-received-trend', [
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-18',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('monthlyInvoicedReceivedTrend');

        $mayTrend = collect($trend)->firstWhere('month', '2026-05');
        $this->assertSame(4300.0, (float) $mayTrend['invoiced']);
        $this->assertSame(1300.0, (float) $mayTrend['received']);
        $this->assertSame(4, (int) $mayTrend['invoiceCount']);
        $this->assertSame(2, (int) $mayTrend['receivedCount']);

        $statsDebtors = $this->actingSession()
            ->postJson('/stats/debtors', ['end_date' => '2026-05-18'])
            ->assertOk()
            ->json('debtors');

        $this->assertSame(['OLD-OPEN-2024', 'OLD-OPEN-001', 'INV-OPEN-001'], collect($statsDebtors)->pluck('invoice_ref_no')->values()->all());

        $previousPeriodTotals = $this->actingSession()
            ->postJson('/stats/monthly-income-statement', [
                'start_date' => '2024-01-01',
                'end_date' => '2024-01-31',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(900.0, (float) $previousPeriodTotals->json('totalInvoiced'));
        $this->assertSame(900.0, (float) $previousPeriodTotals->json('outstandingAmount'));
        $this->assertSame(1, (int) $previousPeriodTotals->json('outstandingCount'));
    }

    private function createTables(): void
    {
        foreach ([
            'user_activities',
            'manual_debtors',
            'invoices',
            'client_pic',
            'client_company',
            'projects_main',
            'staff_general',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->integer('staff_id')->primary();
            $table->string('name_code')->nullable();
            $table->string('full_name')->nullable();
        });

        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });

        Schema::create('client_pic', function (Blueprint $table): void {
            $table->integer('pic_id')->primary();
            $table->integer('company_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('position')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('project_name')->nullable();
            $table->integer('created_by')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('payment_terms_source', 32)->default('system_default');
            $table->date('due_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->string('status')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('invoice_client_name')->nullable();
            $table->string('invoice_purpose')->nullable();
            $table->string('invoice_pic_name')->nullable();
            $table->string('invoice_pic_phone')->nullable();
            $table->string('invoice_pic_email')->nullable();
            $table->string('service_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('paid_remarks')->nullable();
        });

        Schema::create('manual_debtors', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('invoice_ref_no')->unique();
            $table->integer('client_id')->nullable();
            $table->integer('pic_id')->nullable();
            $table->string('client_name');
            $table->string('pic_name')->nullable();
            $table->string('pic_phone')->nullable();
            $table->string('pic_email')->nullable();
            $table->string('service_type')->nullable();
            $table->string('service_period')->nullable();
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
            $table->text('purpose')->nullable();
            $table->date('invoice_date');
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('payment_terms_source', 32)->default('legacy');
            $table->date('due_date')->nullable();
            $table->decimal('grand_total', 15, 2);
            $table->string('status')->default('Open');
            $table->string('payment_method')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime_type')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('created_by_code')->nullable();
            $table->timestamps();
        });
    }

    private function seedSystemInvoice(): void
    {
        DB::table('staff_general')->insert([
            'staff_id' => 1,
            'name_code' => 'AZA',
            'full_name' => 'Azam Bin Husain',
        ]);

        DB::table('client_company')->insert([
            'company_id' => 1,
            'company_name' => 'Open Client',
        ]);

        DB::table('projects_main')->insert([
            'id' => 1,
            'project_name' => 'Open Project',
            'created_by' => 1,
        ]);

        DB::table('invoices')->insert([
            [
                'invoice_ref_no' => 'INV-OPEN-001',
                'invoice_date' => '2026-05-05',
                'paid_date' => null,
                'grand_total' => 1000,
                'paid_amount' => null,
                'status' => 'Unpaid',
                'client_id' => 1,
                'project_id' => 1,
                'invoice_client_name' => 'Open Client',
                'invoice_purpose' => 'Open Project',
                'invoice_pic_name' => 'Client PIC',
                'invoice_pic_phone' => '60120000000',
                'invoice_pic_email' => 'pic@example.test',
                'service_type' => 'Training',
                'payment_method' => 'Bank Transfer',
            ],
            [
                'invoice_ref_no' => 'INV-PAID-001',
                'invoice_date' => '2026-05-02',
                'paid_date' => '2026-05-08',
                'grand_total' => 500,
                'paid_amount' => 500,
                'status' => 'Paid',
                'client_id' => 1,
                'project_id' => 1,
                'invoice_client_name' => null,
                'invoice_purpose' => null,
                'invoice_pic_name' => null,
                'invoice_pic_phone' => null,
                'invoice_pic_email' => null,
                'service_type' => null,
                'payment_method' => null,
            ],
        ]);
    }

    private function actingSession()
    {
        $session = [
            'user_id' => 1,
            'staff_id' => 1,
            'name_code' => 'AZA',
            '_token' => 'test-token',
        ];

        return $this
            ->withSession($session)
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
