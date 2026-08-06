<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IhCommercialCycleDatabase
{
    public static function create(): void
    {
        CommercialCycleDatabaseGuard::assertSqliteMemory();
        self::registerSqliteFunctions();
        self::dropTables();
        self::createIdentityTables();
        self::createClientTables();
        self::createQuoteTables();
        self::createProjectTables();
        self::createCommercialTables();
        self::seedReferenceData();
    }

    private static function registerSqliteFunctions(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('GET_LOCK', fn () => 1, -1);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', fn () => 1, -1);
        $pdo->sqliteCreateFunction('FIELD', function (string $value, string ...$values): int {
            $index = array_search($value, $values, true);

            return $index === false ? 0 : $index + 1;
        }, -1);
        $pdo->sqliteCreateFunction('SUBSTRING_INDEX', function (string $value, string $delimiter, int $count): string {
            $parts = explode($delimiter, $value);

            return implode($delimiter, $count < 0
                ? array_slice($parts, $count)
                : array_slice($parts, 0, $count));
        }, 3);
    }

    private static function dropTables(): void
    {
        foreach ([
            'user_activities',
            'supplier_po_items',
            'supplier_po_main',
            'invoices_jd14form',
            'project_vendors',
            'vendor_main_details',
            'do_breakdown',
            'do_details',
            'invoice_breakdown',
            'invoices',
            'project_closing_details',
            'project_collaborators',
            'project_progress',
            'projects_main',
            'quote_approval_requests',
            'quote_followups',
            'quote_inquiry_sources',
            'quote_price_exception_requests',
            'quotes_special_proposal_snapshots',
            'proposal_template_special',
            'quotes_special_items',
            'quotes_equipment_items',
            'catalog_items',
            'quotes_ih_items',
            'quotes_equipment',
            'quotes_special',
            'quotes_manpower',
            'quotes_training',
            'quotes_ih',
            'client_pic',
            'client_company',
            'staff_general',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private static function createIdentityTables(): void
    {
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
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('name_code', 20)->nullable();
            $table->text('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private static function createClientTables(): void
    {
        Schema::create('client_company', function (Blueprint $table): void {
            $table->unsignedInteger('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('ssm_number')->nullable();
            $table->string('tax_id_no_tin')->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('client_status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('client_pic', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('position')->nullable();
            $table->string('status')->nullable();
        });
    }

    private static function createQuoteTables(): void
    {
        Schema::create('quotes_ih', function (Blueprint $table): void {
            $table->id();
            $table->string('service_group')->nullable();
            $table->unsignedInteger('quote_running_no')->nullable();
            $table->string('quote_ref_no')->nullable();
            $table->unsignedInteger('revision_no')->default(0);
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
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->string('approval_zone')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('approval_fingerprint', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('quotes_ih_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->string('item_description');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        foreach (['quotes_training', 'quotes_manpower', 'quotes_special', 'quotes_equipment'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
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
            });
        }

        Schema::create('quote_price_exception_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_type')->nullable();
            $table->string('service_group')->nullable();
            $table->unsignedBigInteger('quote_id')->nullable();
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

        Schema::create('quote_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('service');
            $table->unsignedBigInteger('quote_id');
            $table->string('quote_ref_no')->nullable();
            $table->unsignedInteger('revision_no')->default(0);
            $table->string('commercial_fingerprint', 64);
            $table->string('rule_version');
            $table->string('zone');
            $table->string('status');
            $table->string('required_step')->nullable();
            $table->decimal('quoted_total', 15, 2)->nullable();
            $table->decimal('estimated_cost', 15, 2)->nullable();
            $table->decimal('margin_percent', 8, 2)->nullable();
            $table->json('trigger_reasons')->nullable();
            $table->boolean('is_current')->default(true);
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->unsignedBigInteger('decided_by_id')->nullable();
            $table->string('decided_by_name')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    private static function createProjectTables(): void
    {
        Schema::create('projects_main', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('quote_type')->nullable();
            $table->string('project_name')->nullable();
            $table->string('project_type')->nullable();
            $table->string('po_loa_number')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->decimal('quote_value', 15, 2)->nullable();
            $table->decimal('current_project_value', 15, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
            $table->string('proposal_language')->nullable();
            $table->timestamps();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->date('progress_date')->nullable();
            $table->text('progress_text')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('project_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role')->nullable();
            $table->text('role_description')->nullable();
        });

        Schema::create('project_closing_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->date('close_date')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::create('vendor_main_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->string('vendor_name');
            $table->string('contact_person_name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('project_vendors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('vendor_id');
            $table->decimal('award_value', 15, 2)->nullable();
            $table->date('award_date')->nullable();
            $table->unsignedBigInteger('awarded_by')->nullable();
            $table->string('position', 1000)->nullable();
            $table->text('remarks')->nullable();
            $table->text('services_description')->nullable();
            $table->text('venue_details')->nullable();
            $table->text('fee_breakdown')->nullable();
            $table->text('payment_terms')->nullable();
            $table->unsignedInteger('loa_running_no')->nullable();
            $table->string('loa_ref_no')->nullable();
        });
    }

    private static function createCommercialTables(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->unsignedInteger('invoice_running_no')->nullable();
            $table->string('invoice_loa_no')->nullable();
            $table->string('invoice_client_name')->nullable();
            $table->string('invoice_client_ssm')->nullable();
            $table->string('invoice_client_tin')->nullable();
            $table->string('invoice_client_address')->nullable();
            $table->string('invoice_client_city')->nullable();
            $table->string('invoice_client_state')->nullable();
            $table->string('invoice_client_zip')->nullable();
            $table->string('invoice_pic_name')->nullable();
            $table->string('invoice_pic_phone')->nullable();
            $table->string('invoice_pic_email')->nullable();
            $table->string('invoice_pic_position')->nullable();
            $table->string('service_type');
            $table->string('invoice_purpose')->nullable();
            $table->date('invoice_date')->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('sst_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('grant_approval_no')->nullable();
            $table->text('remarks')->nullable();
            $table->text('quotation_remarks')->nullable();
            $table->string('status')->nullable();
            $table->string('receipt_no')->nullable();
            $table->string('document_language')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_breakdown', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('item_description')->nullable();
            $table->text('description')->nullable();
            $table->text('item_remarks')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->unsignedInteger('sort_order')->nullable();
        });

        Schema::create('do_details', function (Blueprint $table): void {
            $table->id();
            $table->string('do_number')->nullable();
            $table->string('client_name');
            $table->text('client_address');
            $table->text('client_contact_name');
            $table->text('client_contact_position');
            $table->text('client_contact_email');
            $table->text('client_contact_phone');
            $table->string('company_contact_name');
            $table->string('company_contact_email')->nullable();
            $table->string('company_contact_phone')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('project_name');
            $table->string('project_code');
            $table->date('project_award_date');
            $table->string('project_type')->nullable();
            $table->text('project_description')->nullable();
            $table->text('quotation_remarks')->nullable();
            $table->string('project_service_period')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('document_language')->nullable();
            $table->timestamps();
        });

        Schema::create('do_breakdown', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('do_id');
            $table->string('item_name');
            $table->text('description');
            $table->text('item_remarks')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->string('unit')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_po_main', function (Blueprint $table): void {
            $table->id('po_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('supplier_name');
            $table->text('supplier_address')->nullable();
            $table->string('supplier_contact_name')->nullable();
            $table->string('supplier_contact_number')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('delivery_charge', 15, 2)->default(0);
            $table->decimal('sst_percent', 15, 2)->default(0);
            $table->decimal('sst_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->unsignedInteger('po_running_no')->nullable();
            $table->string('po_ref_no')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status')->nullable();
            $table->text('quotation_remarks')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('supplier_po_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('po_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->text('item_remarks')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('invoices_jd14form', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('employer_name');
            $table->text('employer_address');
            $table->string('approval_no')->unique();
            $table->string('employer_code')->nullable();
            $table->string('group_approved')->nullable();
            $table->string('group_claimed')->nullable();
            $table->string('course_title');
            $table->text('training_venue');
            $table->date('commenced_date');
            $table->date('end_date');
            $table->unsignedInteger('no_of_pax')->nullable();
            $table->decimal('total_fee_approved', 15, 2)->nullable();
            $table->decimal('total_fee_claimed', 15, 2)->nullable();
            $table->timestamps();
        });
    }

    private static function seedReferenceData(): void
    {
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
            'email' => 'sysadmin@example.test',
            'mobile_number' => '601100000000',
        ]);

        DB::table('client_company')->insert([
            'company_id' => 1,
            'company_name' => 'Client A',
            'ssm_number' => 'SSM-1',
            'tax_id_no_tin' => 'TIN-1',
            'payment_terms_days' => 30,
            'address' => '1 Test Road',
            'city' => 'Test City',
            'state' => 'Test State',
            'zip' => '12345',
            'client_status' => 'Active',
        ]);

        DB::table('vendor_main_details')->insert([
            'vendor_id' => 7,
            'vendor_name' => 'Laboratory Supplier',
            'contact_person_name' => 'Vendor PIC',
            'mobile_number' => '60112223333',
            'email' => 'vendor@example.test',
            'address' => '7 Lab Road',
            'city' => 'Test City',
            'state' => 'Test State',
            'zip' => '12345',
            'status' => 'Active',
        ]);
    }
}
