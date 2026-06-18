<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectCloseReminderFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        $this->createTables();
    }

    public function test_project_list_marks_covered_active_projects_for_close_reminder(): void
    {
        DB::table('client_company')->insert([
            'company_id' => 1,
            'company_name' => 'Reminder Client',
        ]);

        DB::table('projects_main')->insert([
            [
                'id' => 701,
                'project_name' => 'Exact Match Project',
                'project_type' => 'Training',
                'quote_value' => 1000,
                'current_project_value' => null,
                'status' => 'Active',
                'award_date' => '2026-05-01',
                'client_id' => 1,
                'created_at' => '2026-05-01 08:00:00',
            ],
            [
                'id' => 702,
                'project_name' => 'Over Invoiced Project',
                'project_type' => 'Training',
                'quote_value' => 1000,
                'current_project_value' => null,
                'status' => 'Active',
                'award_date' => '2026-05-02',
                'client_id' => 1,
                'created_at' => '2026-05-02 08:00:00',
            ],
            [
                'id' => 703,
                'project_name' => 'Current Value Project',
                'project_type' => 'Training',
                'quote_value' => 1000,
                'current_project_value' => 1200,
                'status' => 'Active',
                'award_date' => '2026-05-03',
                'client_id' => 1,
                'created_at' => '2026-05-03 08:00:00',
            ],
            [
                'id' => 704,
                'project_name' => 'Completed Project',
                'project_type' => 'Training',
                'quote_value' => 800,
                'current_project_value' => null,
                'status' => 'Completed',
                'award_date' => '2026-05-04',
                'client_id' => 1,
                'created_at' => '2026-05-04 08:00:00',
            ],
            [
                'id' => 705,
                'project_name' => 'Cancelled Invoice Project',
                'project_type' => 'Training',
                'quote_value' => 900,
                'current_project_value' => null,
                'status' => 'Active',
                'award_date' => '2026-05-05',
                'client_id' => 1,
                'created_at' => '2026-05-05 08:00:00',
            ],
            [
                'id' => 706,
                'project_name' => 'Already Closed Detail Project',
                'project_type' => 'Training',
                'quote_value' => 700,
                'current_project_value' => null,
                'status' => 'Active',
                'award_date' => '2026-05-06',
                'client_id' => 1,
                'created_at' => '2026-05-06 08:00:00',
            ],
            [
                'id' => 707,
                'project_name' => 'Partial Invoice Project',
                'project_type' => 'Training',
                'quote_value' => 1000,
                'current_project_value' => null,
                'status' => 'Active',
                'award_date' => '2026-05-07',
                'client_id' => 1,
                'created_at' => '2026-05-07 08:00:00',
            ],
            [
                'id' => 708,
                'project_name' => 'Terminated Project',
                'project_type' => 'Training',
                'quote_value' => 1100,
                'current_project_value' => null,
                'status' => 'Terminated',
                'award_date' => '2026-05-08',
                'client_id' => 1,
                'created_at' => '2026-05-08 08:00:00',
            ],
        ]);

        DB::table('project_closing_details')->insert([
            'project_id' => 706,
            'close_date' => '2026-06-01',
            'reason' => 'Already closed.',
            'closed_at' => '2026-06-01 09:00:00',
        ]);

        DB::table('invoices')->insert([
            [
                'id' => 801,
                'project_id' => 701,
                'invoice_ref_no' => 'INV-801',
                'invoice_date' => '2026-05-10',
                'status' => 'Paid',
                'grand_total' => 400,
                'created_at' => '2026-05-10 08:00:00',
            ],
            [
                'id' => 802,
                'project_id' => 701,
                'invoice_ref_no' => 'INV-802',
                'invoice_date' => '2026-05-20',
                'status' => 'Pending',
                'grand_total' => 600,
                'created_at' => '2026-05-20 08:00:00',
            ],
            [
                'id' => 803,
                'project_id' => 702,
                'invoice_ref_no' => 'INV-803',
                'invoice_date' => '2026-05-21',
                'status' => 'Pending',
                'grand_total' => 1000.02,
                'created_at' => '2026-05-21 08:00:00',
            ],
            [
                'id' => 804,
                'project_id' => 703,
                'invoice_ref_no' => 'INV-804',
                'invoice_date' => '2026-05-22',
                'status' => 'Pending',
                'grand_total' => 1200,
                'created_at' => '2026-05-22 08:00:00',
            ],
            [
                'id' => 805,
                'project_id' => 704,
                'invoice_ref_no' => 'INV-805',
                'invoice_date' => '2026-05-23',
                'status' => 'Pending',
                'grand_total' => 800,
                'created_at' => '2026-05-23 08:00:00',
            ],
            [
                'id' => 806,
                'project_id' => 705,
                'invoice_ref_no' => 'INV-806',
                'invoice_date' => '2026-05-24',
                'status' => 'Cancelled',
                'grand_total' => 900,
                'created_at' => '2026-05-24 08:00:00',
            ],
            [
                'id' => 807,
                'project_id' => 706,
                'invoice_ref_no' => 'INV-807',
                'invoice_date' => '2026-05-25',
                'status' => 'Pending',
                'grand_total' => 700,
                'created_at' => '2026-05-25 08:00:00',
            ],
            [
                'id' => 808,
                'project_id' => 707,
                'invoice_ref_no' => 'INV-808',
                'invoice_date' => '2026-05-26',
                'status' => 'Pending',
                'grand_total' => 500,
                'created_at' => '2026-05-26 08:00:00',
            ],
            [
                'id' => 809,
                'project_id' => 708,
                'invoice_ref_no' => 'INV-809',
                'invoice_date' => '2026-05-27',
                'status' => 'Pending',
                'grand_total' => 1100,
                'created_at' => '2026-05-27 08:00:00',
            ],
        ]);

        $rows = collect($this->getJson('/projects')->assertOk()->json())->keyBy('id');

        $this->assertTrue($rows[701]['close_reminder_ready']);
        $this->assertSame('2026-05-20', $rows[701]['fully_invoiced_at']);
        $this->assertSame('matched', $rows[701]['close_reminder_billing_state']);
        $this->assertNotEmpty($rows[701]['close_reminder_signature']);

        $this->assertTrue($rows[702]['close_reminder_ready']);
        $this->assertSame('2026-05-21', $rows[702]['fully_invoiced_at']);
        $this->assertSame('exceeded', $rows[702]['close_reminder_billing_state']);
        $this->assertNotEmpty($rows[702]['close_reminder_signature']);

        $this->assertTrue($rows[703]['close_reminder_ready']);
        $this->assertSame('2026-05-22', $rows[703]['fully_invoiced_at']);
        $this->assertSame('matched', $rows[703]['close_reminder_billing_state']);

        $this->assertFalse($rows[704]['close_reminder_ready']);
        $this->assertFalse($rows[705]['close_reminder_ready']);
        $this->assertFalse($rows[706]['close_reminder_ready']);
        $this->assertFalse($rows[707]['close_reminder_ready']);
        $this->assertFalse($rows[708]['close_reminder_ready']);

        $originalSignature = $rows[701]['close_reminder_signature'];
        DB::table('invoices')->where('id', 802)->update(['status' => 'Paid']);

        $updatedRows = collect($this->getJson('/projects')->assertOk()->json())->keyBy('id');
        $this->assertTrue($updatedRows[701]['close_reminder_ready']);
        $this->assertNotSame($originalSignature, $updatedRows[701]['close_reminder_signature']);

        $statusSignature = $updatedRows[701]['close_reminder_signature'];
        DB::table('invoices')->where('id', 801)->update(['invoice_date' => '2026-05-11']);

        $dateRows = collect($this->getJson('/projects')->assertOk()->json())->keyBy('id');
        $this->assertTrue($dateRows[701]['close_reminder_ready']);
        $this->assertSame('2026-05-20', $dateRows[701]['fully_invoiced_at']);
        $this->assertNotSame($statusSignature, $dateRows[701]['close_reminder_signature']);

        $dateSignature = $dateRows[701]['close_reminder_signature'];
        DB::table('invoices')->where('id', 802)->update(['grand_total' => 601]);

        $exceededRows = collect($this->getJson('/projects')->assertOk()->json())->keyBy('id');
        $this->assertTrue($exceededRows[701]['close_reminder_ready']);
        $this->assertSame('exceeded', $exceededRows[701]['close_reminder_billing_state']);
        $this->assertNotSame($dateSignature, $exceededRows[701]['close_reminder_signature']);
    }

    private function createTables(): void
    {
        foreach ([
            'invoices',
            'client_pic',
            'project_closing_details',
            'project_vendors',
            'vendor_main_details',
            'project_collaborators',
            'system_users',
            'staff_general',
            'project_progress',
            'client_company',
            'quotes_equipment',
            'quotes_special',
            'quotes_manpower',
            'quotes_ih',
            'quotes_training',
            'projects_main',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_name')->nullable();
            $table->string('project_type')->nullable();
            $table->string('po_loa_number')->nullable();
            $table->integer('quote_id')->nullable();
            $table->decimal('quote_value', 15, 2)->nullable();
            $table->decimal('current_project_value', 15, 2)->nullable();
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->date('award_date')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->integer('client_id')->nullable();
        });

        foreach (['quotes_training', 'quotes_ih', 'quotes_manpower', 'quotes_special', 'quotes_equipment'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('client_name')->nullable();
                $table->string('client_ssm')->nullable();
                $table->string('client_address')->nullable();
                $table->string('client_city')->nullable();
                $table->string('client_state')->nullable();
                $table->string('client_zip')->nullable();
                $table->string('pic_name')->nullable();
                $table->string('pic_email')->nullable();
                $table->string('pic_phone')->nullable();
                $table->string('pic_position')->nullable();
            });
        }

        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('ssm_number')->nullable();
            $table->string('tax_id_no_tin')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->date('progress_date')->nullable();
            $table->text('progress_text')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->integer('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('staff_id')->nullable();
        });

        Schema::create('project_collaborators', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('staff_id');
            $table->string('project_role')->nullable();
        });

        Schema::create('vendor_main_details', function (Blueprint $table): void {
            $table->integer('vendor_id')->primary();
            $table->string('vendor_name')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('email')->nullable();
        });

        Schema::create('project_vendors', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->integer('vendor_id');
            $table->decimal('award_value', 15, 2)->nullable();
            $table->string('position')->nullable();
            $table->text('remarks')->nullable();
            $table->text('services_description')->nullable();
            $table->text('venue_details')->nullable();
            $table->text('fee_breakdown')->nullable();
            $table->text('payment_terms')->nullable();
        });

        Schema::create('project_closing_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->date('close_date')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('closed_by')->nullable();
        });

        Schema::create('client_pic', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('company_id');
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('position')->nullable();
            $table->string('status')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('project_id');
            $table->string('invoice_ref_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('status')->nullable();
            $table->decimal('grand_total', 15, 2)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
