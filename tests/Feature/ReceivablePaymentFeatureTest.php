<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReceivablePaymentFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-10 12:00:00');
        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
        $this->createTables();
        $this->seedReceivables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_receivable_accumulates_partial_payments_and_settles_the_balance(): void
    {
        $firstToken = '7e911a89-31f8-47a2-9e5d-ef81a33cc2ae';
        $partial = $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '300.00',
            'payment_date' => '2026-08-04',
            'payment_method' => 'Bank Transfer',
            'transaction_reference' => 'TX-001',
            'request_token' => $firstToken,
        ]);

        $partial->assertOk()
            ->assertJsonPath('summary.paidTotal', 300)
            ->assertJsonPath('summary.outstandingAmount', 700)
            ->assertJsonPath('summary.paymentStatus', 'Partially Paid');
        $this->assertDatabaseHas('manual_debtors', [
            'id' => 1,
            'status' => 'Partially Paid',
            'paid_amount' => 300,
            'paid_date' => '2026-08-04',
        ]);

        $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '300.00',
            'payment_date' => '2026-08-04',
            'request_token' => $firstToken,
        ])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, DB::table('receivable_payments')->count());

        $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'full',
            'payment_date' => '2026-08-05',
            'request_token' => '618512f1-c01d-4efd-860b-fb3fc77dc38b',
        ])->assertOk()
            ->assertJsonPath('payment.amount', 700)
            ->assertJsonPath('summary.paidTotal', 1000)
            ->assertJsonPath('summary.outstandingAmount', 0)
            ->assertJsonPath('summary.paymentStatus', 'Paid');

        $this->actingSession()
            ->getJson('/receivables/manual/1/payments?as_of_date=2026-08-04')
            ->assertOk()
            ->assertJsonPath('summary.paidTotal', 300)
            ->assertJsonPath('summary.outstandingAmount', 700)
            ->assertJsonCount(2, 'payments');

        $historicalRows = $this->actingSession()
            ->getJson('/debtors?source=manual&status=open&as_of_date=2026-08-03')
            ->assertOk()
            ->json('debtors');
        $this->assertSame(1000.0, (float) $historicalRows[0]['outstandingAmount']);
    }

    public function test_overpayment_is_rejected_and_reversal_restores_the_balance(): void
    {
        $payment = $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '300.00',
            'payment_date' => '2026-08-04',
            'request_token' => 'f74dbd20-f5df-4d4e-80b3-81a7a66da591',
        ])->assertOk()->json('payment');

        $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '701.00',
            'payment_date' => '2026-08-05',
            'request_token' => '474a5a4d-d759-481d-b830-571473c8c24f',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->actingSession()->postJson("/receivable-payments/{$payment['id']}/reverse", [
            'reason' => 'Matched the wrong bank transaction',
        ])->assertOk()
            ->assertJsonPath('summary.paidTotal', 0)
            ->assertJsonPath('summary.outstandingAmount', 1000)
            ->assertJsonPath('summary.paymentStatus', 'Open');

        $this->assertDatabaseHas('manual_debtors', [
            'id' => 1,
            'status' => 'Open',
            'paid_amount' => null,
            'paid_date' => null,
        ]);
    }

    public function test_historical_summary_includes_payment_until_its_later_reversal(): void
    {
        $payment = $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '300.00',
            'payment_date' => '2026-08-04',
            'request_token' => '1650164c-1b5a-43e4-8620-2023fcbbcc7f',
        ])->assertOk()->json('payment');

        $this->actingSession()->postJson("/receivable-payments/{$payment['id']}/reverse", [
            'reason' => 'Correction recorded later',
        ])->assertOk();

        $this->actingSession()
            ->getJson('/receivables/manual/1/payments?as_of_date=2026-08-05')
            ->assertOk()
            ->assertJsonPath('summary.paidTotal', 300)
            ->assertJsonPath('summary.outstandingAmount', 700);

        $this->actingSession()
            ->getJson('/receivables/manual/1/payments?as_of_date=2026-08-10')
            ->assertOk()
            ->assertJsonPath('summary.paidTotal', 0)
            ->assertJsonPath('summary.outstandingAmount', 1000);

        $this->actingSession()
            ->getJson('/receivables/manual/1/payments?as_of_date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of_date');
    }

    public function test_reversed_only_ledger_remains_discoverable_after_receivable_is_cancelled(): void
    {
        $payment = $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '300.00',
            'payment_date' => '2026-08-04',
            'request_token' => 'f791c9ac-2b57-4ef7-9232-1b0293820c3f',
        ])->assertOk()->json('payment');

        $this->actingSession()->postJson("/receivable-payments/{$payment['id']}/reverse", [
            'reason' => 'Wrong receivable selected',
        ])->assertOk();
        DB::table('manual_debtors')->where('id', 1)->update(['status' => 'Cancelled']);

        $cancelledDebtor = $this->actingSession()
            ->getJson('/debtors?source=manual&status=cancelled&as_of_date=2026-08-10')
            ->assertOk()
            ->assertJsonCount(1, 'debtors')
            ->json('debtors.0');

        $this->assertSame('Cancelled', $cancelledDebtor['status']);
        $this->assertTrue($cancelledDebtor['hasPaymentHistory']);
        $this->assertSame(0, $cancelledDebtor['paymentCount']);
        $this->assertSame(1000.0, (float) $cancelledDebtor['outstandingAmount']);
    }

    public function test_legacy_reopen_reverses_all_payments_in_one_audited_operation(): void
    {
        foreach ([['100.00', 'aa90bc66-1d6d-41dd-b2b1-38fd14f31f80'], ['200.00', '44f7af0e-14f5-443a-be30-c39d88b0df89']] as [$amount, $token]) {
            $this->actingSession()->postJson('/receivables/manual/1/payments', [
                'payment_type' => 'partial',
                'amount' => $amount,
                'payment_date' => '2026-08-04',
                'request_token' => $token,
            ])->assertOk();
        }

        $this->actingSession()->patchJson('/debtors/manual/1/mark-open', [
            'reason' => 'Reset incorrect imported payments',
        ])->assertOk()
            ->assertJsonCount(2, 'payments')
            ->assertJsonPath('summary.paymentStatus', 'Open');

        $this->assertSame(
            2,
            DB::table('receivable_payments')->whereNotNull('reversed_at')->count(),
        );
        $this->assertDatabaseHas('receivable_audit_events', [
            'source_type' => 'manual',
            'source_id' => 1,
            'event_type' => 'payments_reversed',
            'reason' => 'Reset incorrect imported payments',
        ]);
    }

    public function test_legacy_reopen_materializes_and_reverses_unbackfilled_paid_fields(): void
    {
        DB::table('manual_debtors')->where('id', 1)->update([
            'status' => 'Paid',
            'paid_amount' => 1000,
            'paid_date' => '2026-08-04',
        ]);

        $this->actingSession()->patchJson('/debtors/manual/1/mark-open', [
            'reason' => 'Legacy payment correction',
        ])->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('summary.paymentStatus', 'Open');

        $this->assertDatabaseHas('receivable_payments', [
            'source_type' => 'manual',
            'source_id' => 1,
            'amount' => 1000,
            'recorded_by_code' => 'BACKFILL',
            'reversal_reason' => 'Legacy payment correction',
        ]);
    }

    public function test_system_invoice_uses_the_same_partial_payment_contract(): void
    {
        DB::table('invoices')->where('id', 1)->update(['payment_method' => 'HRD Grant']);

        $this->actingSession()->postJson('/receivables/invoice/1/payments', [
            'payment_type' => 'partial',
            'amount' => '125.50',
            'payment_date' => '2026-08-06',
            'request_token' => '31b8db12-ff51-490d-b092-3b35b17061fd',
        ])->assertOk()
            ->assertJsonPath('summary.paidTotal', 125.5)
            ->assertJsonPath('summary.outstandingAmount', 674.5)
            ->assertJsonPath('summary.paymentStatus', 'Partially Paid');

        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'status' => 'Partially Paid',
            'paid_amount' => 125.5,
            'payment_method' => 'HRD Grant',
        ]);
    }

    public function test_legacy_payment_fields_are_respected_by_historical_as_of_date(): void
    {
        DB::table('manual_debtors')->where('id', 1)->update([
            'status' => 'Paid',
            'paid_amount' => 1000,
            'paid_date' => '2026-08-08',
        ]);

        $this->actingSession()
            ->getJson('/receivables/manual/1/payments?as_of_date=2026-08-04')
            ->assertOk()
            ->assertJsonPath('summary.paidTotal', 0)
            ->assertJsonPath('summary.outstandingAmount', 1000)
            ->assertJsonPath('summary.paymentStatus', 'Open');

        $this->actingSession()
            ->getJson('/receivables/manual/1/payments')
            ->assertOk()
            ->assertJsonPath('summary.paidTotal', 1000)
            ->assertJsonPath('summary.outstandingAmount', 0);
    }

    public function test_first_new_payment_materializes_safe_legacy_payment_without_losing_it(): void
    {
        DB::table('manual_debtors')->where('id', 1)->update([
            'status' => 'Partially Paid',
            'paid_amount' => 200,
            'paid_date' => '2026-08-03',
            'paid_remarks' => 'Legacy receipt',
        ]);

        $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => 100,
            'payment_date' => '2026-08-04',
            'request_token' => '272bbdda-a17e-47f2-9e6a-c144bd8b2a3e',
        ])->assertOk()
            ->assertJsonPath('summary.paidTotal', 300)
            ->assertJsonPath('summary.outstandingAmount', 700);

        $this->assertSame(2, DB::table('receivable_payments')->count());
        $this->assertDatabaseHas('receivable_payments', [
            'source_type' => 'manual',
            'source_id' => 1,
            'amount' => 200,
            'recorded_by_code' => 'BACKFILL',
            'remarks' => 'Legacy receipt',
        ]);
    }

    public function test_manual_deletion_removes_payments_and_retains_audit_tombstone(): void
    {
        $this->actingSession()->postJson('/receivables/manual/1/payments', [
            'payment_type' => 'partial',
            'amount' => '100.00',
            'payment_date' => '2026-08-04',
            'request_token' => '2ad25f99-3742-4649-be9c-585555e00e18',
        ])->assertOk();

        $this->actingSession()->deleteJson('/debtors/manual/1', [
            'reason' => 'Duplicate entry created during testing',
        ])->assertOk();

        $this->assertDatabaseMissing('manual_debtors', ['id' => 1]);
        $this->assertDatabaseMissing('receivable_payments', ['source_type' => 'manual', 'source_id' => 1]);
        $this->assertDatabaseHas('receivable_audit_events', [
            'source_type' => 'manual',
            'source_id' => 1,
            'event_type' => 'receivable_deleted',
            'reason' => 'Duplicate entry created during testing',
        ]);
    }

    public function test_pending_invoice_deletion_removes_reversed_payment_history_and_audits_tombstone(): void
    {
        $payment = $this->actingSession()->postJson('/receivables/invoice/1/payments', [
            'payment_type' => 'partial',
            'amount' => '100.00',
            'payment_date' => '2026-08-04',
            'request_token' => '21830c56-0dd0-45bc-957b-fbe50bc7c644',
        ])->assertOk()->json('payment');
        $this->actingSession()->postJson("/receivable-payments/{$payment['id']}/reverse", [
            'reason' => 'Payment belonged to another invoice',
        ])->assertOk();

        $this->actingSession()->deleteJson('/invoices', [
            'invoice_ref_no' => 'INV-PARTIAL-001',
            'reason' => 'Duplicate system invoice',
        ])->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => 1]);
        $this->assertDatabaseMissing('receivable_payments', ['source_type' => 'invoice', 'source_id' => 1]);
        $this->assertDatabaseHas('receivable_audit_events', [
            'source_type' => 'invoice',
            'source_id' => 1,
            'event_type' => 'receivable_deleted',
            'reason' => 'Duplicate system invoice',
        ]);
    }

    public function test_backfill_projects_partial_payments_and_skips_unsafe_overpayments(): void
    {
        DB::table('manual_debtors')->where('id', 1)->update([
            'status' => 'Paid',
            'paid_amount' => 300,
            'paid_date' => '2026-08-04',
        ]);
        DB::table('manual_debtors')->insert([
            'id' => 2,
            'invoice_ref_no' => 'MAN-OVERPAID-002',
            'client_name' => 'Unsafe Legacy Client',
            'invoice_date' => '2026-08-01',
            'grand_total' => 1000,
            'status' => 'Paid',
            'paid_amount' => 1200,
            'paid_date' => '2026-08-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('app:backfill-receivable-payments', ['--commit' => true])
            ->assertExitCode(1);

        $this->assertDatabaseHas('receivable_payments', [
            'source_type' => 'manual',
            'source_id' => 1,
            'amount' => 300,
            'recorded_by_code' => 'BACKFILL',
        ]);
        $this->assertDatabaseHas('manual_debtors', [
            'id' => 1,
            'status' => 'Partially Paid',
            'paid_amount' => 300,
        ]);
        $this->assertDatabaseMissing('receivable_payments', [
            'source_type' => 'manual',
            'source_id' => 2,
        ]);
    }

    private function createTables(): void
    {
        foreach (['invoice_payment_reminder_logs', 'invoice_breakdown', 'receivable_audit_events', 'receivable_payments', 'user_activities', 'manual_debtors', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id')->nullable();
            $table->string('invoice_ref_no');
            $table->date('invoice_date');
            $table->decimal('grand_total', 15, 2);
            $table->string('status')->default('Pending');
            $table->string('payment_method')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_breakdown', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
        });
        Schema::create('invoice_payment_reminder_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
        });
        Schema::create('manual_debtors', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('invoice_ref_no')->unique();
            $table->string('client_name');
            $table->date('invoice_date');
            $table->decimal('grand_total', 15, 2);
            $table->string('status')->default('Open');
            $table->string('payment_method')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();
        });
        Schema::create('receivable_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('source_type');
            $table->unsignedInteger('source_id');
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->string('request_token')->unique();
            $table->unsignedInteger('recorded_by_staff_id')->nullable();
            $table->string('recorded_by_code')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedInteger('reversed_by_staff_id')->nullable();
            $table->string('reversed_by_code')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('receivable_audit_events', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('source_type');
            $table->unsignedInteger('source_id')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->string('event_type');
            $table->unsignedInteger('actor_staff_id')->nullable();
            $table->string('actor_code')->nullable();
            $table->text('reason')->nullable();
            $table->text('before_state')->nullable();
            $table->text('after_state')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedReceivables(): void
    {
        DB::table('manual_debtors')->insert([
            'id' => 1,
            'invoice_ref_no' => 'MAN-PARTIAL-001',
            'client_name' => 'Partial Client',
            'invoice_date' => '2026-08-01',
            'grand_total' => 1000,
            'status' => 'Open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'id' => 1,
            'invoice_ref_no' => 'INV-PARTIAL-001',
            'invoice_date' => '2026-08-01',
            'grand_total' => 800,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingSession()
    {
        return $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'EMP',
            '_token' => 'test-token',
        ])->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
