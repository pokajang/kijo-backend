<?php

namespace Tests\Feature;

use App\Services\Assistant\AssistantFeedbackMemory;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantRetrievalPlanner;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\Sources\ClientContextProvider;
use App\Services\Assistant\Sources\ClientVendorRegistrationContextProvider;
use App\Services\Assistant\Sources\DebtorContextProvider;
use App\Services\Assistant\Sources\DetailRecordContextProvider;
use App\Services\Assistant\Sources\InvoiceContextProvider;
use App\Services\Assistant\Sources\LeaveContextProvider;
use App\Services\Assistant\Sources\ProposalTemplateContextProvider;
use App\Services\Assistant\Sources\QuoteRecordContextProvider;
use App\Services\Assistant\Sources\SalesInquiryContextProvider;
use App\Services\Assistant\Sources\TaskContextProvider;
use App\Services\Knowledge\KnowledgeAssistantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KnowledgeAssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.knowledge_assistant.planner_enabled' => false]);

        Schema::dropIfExists('knowledge_assistant_messages');
        Schema::dropIfExists('knowledge_assistant_thread_contexts');
        Schema::dropIfExists('knowledge_assistant_threads');
        Schema::dropIfExists('assistant_provider_feedback_memory');
        Schema::dropIfExists('assistant_source_gaps');
        Schema::dropIfExists('assistant_response_feedback');
        Schema::dropIfExists('assistant_query_plan_cache');
        Schema::dropIfExists('assistant_live_result_cache');
        Schema::dropIfExists('assistant_answer_cache');
        Schema::dropIfExists('system_feedbacks');
        Schema::dropIfExists('procedures');
        Schema::dropIfExists('legal_compliance_assessments');
        Schema::dropIfExists('legal_compliance_templates');
        Schema::dropIfExists('whats_new_notes');
        Schema::dropIfExists('hr_handbook_versions');
        Schema::dropIfExists('knowledge_articles');
        Schema::dropIfExists('hr_appraisal');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('hr_leaves_allocation');
        Schema::dropIfExists('hr_leaves_application');
        Schema::dropIfExists('sales_inquiry_proofs');
        Schema::dropIfExists('sales_inquiries');
        Schema::dropIfExists('client_vendor_registration_recipients');
        Schema::dropIfExists('client_vendor_registrations');
        Schema::dropIfExists('manual_debtors');
        Schema::dropIfExists('invoice_breakdown');
        Schema::dropIfExists('vendor_other_services_details');
        Schema::dropIfExists('vendor_consultancy_details');
        Schema::dropIfExists('vendor_supplies_details');
        Schema::dropIfExists('vendor_competency_details');
        Schema::dropIfExists('vendor_training_details');
        Schema::dropIfExists('vendor_categories');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('vendor_main_details');
        Schema::dropIfExists('project_closing_details');
        Schema::dropIfExists('project_vendors');
        Schema::dropIfExists('project_collaborators');
        Schema::dropIfExists('project_progress');
        Schema::dropIfExists('project_expenses');
        Schema::dropIfExists('projects_main');
        Schema::dropIfExists('quotes_equipment_items');
        Schema::dropIfExists('quotes_special_items');
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('quotes_equipment');
        Schema::dropIfExists('quotes_special');
        Schema::dropIfExists('quotes_manpower');
        Schema::dropIfExists('quotes_ih');
        Schema::dropIfExists('quotes_training');
        Schema::dropIfExists('proposal_special_attachments');
        Schema::dropIfExists('proposal_template_special_history');
        Schema::dropIfExists('proposal_template_manpower_history');
        Schema::dropIfExists('proposal_template_ih_history');
        Schema::dropIfExists('proposal_template_training_history');
        Schema::dropIfExists('proposal_template_training_agenda');
        Schema::dropIfExists('proposal_template_special');
        Schema::dropIfExists('proposal_template_manpower');
        Schema::dropIfExists('proposal_template_ih');
        Schema::dropIfExists('proposal_template_training_main');
        Schema::dropIfExists('client_pic');
        Schema::dropIfExists('client_company_branch');
        Schema::dropIfExists('client_company');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('staff_general');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('hr_appraisal', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('staff_id');
            $table->string('section')->nullable();
            $table->text('feedback')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('system_feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->text('feedback');
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->timestamp('date_reported')->nullable();
            $table->string('status')->nullable();
            $table->date('action_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('procedures', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('content')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_compliance_templates', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_compliance_assessments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedInteger('template_id')->nullable();
            $table->string('company_name')->nullable();
            $table->string('stage')->nullable();
            $table->text('nature_of_company')->nullable();
            $table->timestamps();
        });

        Schema::create('whats_new_notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('version')->nullable();
            $table->longText('body')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('client_company', function (Blueprint $table): void {
            $table->increments('company_id');
            $table->string('company_name');
            $table->string('ssm_number')->nullable();
            $table->string('tax_id_no_tin')->nullable();
            $table->string('client_status')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_company_branch', function (Blueprint $table): void {
            $table->increments('branch_id');
            $table->unsignedInteger('company_id');
            $table->string('branch_name')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_pic', function (Blueprint $table): void {
            $table->increments('pic_id');
            $table->unsignedInteger('company_id')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('position')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_name');
            $table->string('project_type')->nullable();
            $table->string('po_loa_number')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->decimal('quote_value', 12, 2)->default(0);
            $table->date('service_start_date')->nullable();
            $table->date('service_end_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->date('award_date')->nullable();
            $table->unsignedInteger('client_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        foreach (['quotes_training', 'quotes_ih', 'quotes_manpower', 'quotes_special', 'quotes_equipment'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->string('quote_ref_no')->nullable();
                $table->string('service_group')->nullable();
                $table->string('client_name')->nullable();
                $table->string('client_ssm')->nullable();
                $table->text('client_address')->nullable();
                $table->string('client_city')->nullable();
                $table->string('client_state')->nullable();
                $table->string('client_zip')->nullable();
                $table->string('pic_name')->nullable();
                $table->string('pic_email')->nullable();
                $table->string('pic_phone')->nullable();
                $table->string('pic_position')->nullable();
                $table->decimal('quote_value', 12, 2)->default(0);
                $table->decimal('grand_total', 12, 2)->nullable();
                $table->decimal('sub_total', 12, 2)->nullable();
                $table->decimal('discount', 12, 2)->nullable();
                $table->decimal('sst_amount', 12, 2)->nullable();
                $table->decimal('sst_percent', 8, 2)->nullable();
                $table->string('service_title')->nullable();
                $table->string('service_code')->nullable();
                $table->string('training_title')->nullable();
                $table->text('general_remarks')->nullable();
                $table->text('inquiry_remarks')->nullable();
                $table->unsignedInteger('proposal_id')->nullable();
                $table->boolean('attach_proposal')->default(false);
                $table->string('proposal_language')->nullable();
                $table->unsignedInteger('revision_no')->default(0);
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->string('created_by_code')->nullable();
                $table->string('status')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('item_name')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('supplier_price', 12, 2)->nullable();
        });

        Schema::create('quotes_equipment_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('quote_id');
            $table->unsignedInteger('item_id')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('marked_up_price', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('quotes_special_items', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('quote_id');
            $table->unsignedInteger('service_id')->nullable();
            $table->string('line_item_title')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('proposal_template_training_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('training_title')->nullable();
            $table->string('training_code')->nullable();
            $table->string('hrd_no')->nullable();
            $table->longText('introduction')->nullable();
            $table->longText('objectives')->nullable();
            $table->longText('modules')->nullable();
            $table->longText('training_requirements')->nullable();
            $table->longText('additional_requirements')->nullable();
            $table->longText('training_materials')->nullable();
            $table->longText('lecture_medium')->nullable();
            $table->string('duration')->nullable();
            $table->text('method_theory_desc')->nullable();
            $table->text('method_practical_desc')->nullable();
            $table->text('remarks')->nullable();
            $table->string('proposal_language')->nullable();
            $table->unsignedInteger('source_template_id')->nullable();
            $table->string('translation_status')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        Schema::create('proposal_template_ih', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->longText('introduction')->nullable();
            $table->longText('objectives')->nullable();
            $table->longText('work_scope')->nullable();
            $table->longText('schedule')->nullable();
            $table->longText('reference')->nullable();
            $table->longText('other_fields')->nullable();
            $table->text('remarks')->nullable();
            $table->string('proposal_language')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        Schema::create('proposal_template_manpower', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->longText('introduction')->nullable();
            $table->longText('service_deliverables')->nullable();
            $table->longText('supplied_manpower_deliverables')->nullable();
            $table->longText('custom_section')->nullable();
            $table->text('remarks')->nullable();
            $table->string('proposal_language')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        Schema::create('proposal_template_special', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->string('service_type')->nullable();
            $table->longText('content')->nullable();
            $table->text('remarks')->nullable();
            $table->string('proposal_language')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        Schema::create('proposal_template_training_agenda', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('template_id');
            $table->unsignedInteger('day')->default(1);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->longText('topic')->nullable();
        });

        foreach ([
            'proposal_template_training_history',
            'proposal_template_ih_history',
            'proposal_template_manpower_history',
            'proposal_template_special_history',
        ] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('template_id');
                $table->text('remarks')->nullable();
                $table->string('action')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        Schema::create('proposal_special_attachments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('template_id');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->timestamps();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('progress_date')->nullable();
            $table->text('progress_text')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('project_collaborators', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role')->nullable();
        });

        Schema::create('vendor_main_details', function (Blueprint $table): void {
            $table->increments('vendor_id');
            $table->string('vendor_name');
            $table->string('ssm_number')->nullable();
            $table->string('sst_number')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('contact_person_name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        foreach ([
            'vendor_categories' => 'category',
            'vendor_training_details' => 'topic',
            'vendor_competency_details' => 'competency',
            'vendor_supplies_details' => 'product_name',
            'vendor_consultancy_details' => 'consulting_area',
            'vendor_other_services_details' => 'service_name',
        ] as $tableName => $columnName) {
            Schema::create($tableName, function (Blueprint $table) use ($columnName): void {
                $table->increments('id');
                $table->unsignedInteger('vendor_id');
                $table->string($columnName)->nullable();
            });
        }

        Schema::create('project_vendors', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('vendor_id');
            $table->decimal('award_value', 12, 2)->nullable();
            $table->string('position')->nullable();
            $table->text('remarks')->nullable();
            $table->text('services_description')->nullable();
            $table->text('venue_details')->nullable();
            $table->text('fee_breakdown')->nullable();
            $table->string('payment_terms')->nullable();
        });

        Schema::create('project_closing_details', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('close_date')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
        });

        Schema::create('project_expenses', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('vendor_id')->nullable();
            $table->unsignedInteger('project_id')->nullable();
            $table->string('payment_context')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('method')->nullable();
            $table->string('status')->nullable();
            $table->date('date_approved')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('receipt_path')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_full_name')->nullable();
            $table->string('created_by_name_code')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('client_id')->nullable();
            $table->unsignedInteger('project_id')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->string('invoice_ref_no')->nullable();
            $table->string('invoice_loa_no')->nullable();
            $table->string('service_type')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->string('status')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->date('due_date')->nullable();
            $table->string('invoice_client_name')->nullable();
            $table->string('invoice_client_ssm')->nullable();
            $table->string('invoice_client_tin')->nullable();
            $table->text('invoice_client_address')->nullable();
            $table->string('invoice_client_city')->nullable();
            $table->string('invoice_client_state')->nullable();
            $table->string('invoice_client_zip')->nullable();
            $table->string('invoice_pic_name')->nullable();
            $table->string('invoice_pic_email')->nullable();
            $table->string('invoice_pic_phone')->nullable();
            $table->string('invoice_pic_position')->nullable();
            $table->string('invoice_purpose')->nullable();
            $table->string('hrd_claim_ref')->nullable();
            $table->string('paid_remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_breakdown', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->string('item_description')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);
        });

        Schema::create('manual_debtors', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('invoice_ref_no')->nullable();
            $table->unsignedInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('pic_name')->nullable();
            $table->string('service_type')->nullable();
            $table->string('purpose')->nullable();
            $table->date('invoice_date')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('payment_terms_source')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->string('status')->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('created_by_code')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime_type')->nullable();
            $table->timestamps();
        });

        Schema::create('client_vendor_registrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('certificate_original_name')->nullable();
            $table->string('certificate_mime_type')->nullable();
            $table->unsignedInteger('certificate_size')->nullable();
            $table->string('portal_url')->nullable();
            $table->string('portal_username')->nullable();
            $table->text('portal_password')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_vendor_registration_recipients', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('registration_id');
            $table->unsignedBigInteger('staff_id');
        });

        Schema::create('sales_inquiries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('company_name');
            $table->string('ssm_number')->nullable();
            $table->string('tax_id_no_tin')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('service_required')->nullable();
            $table->string('source')->nullable();
            $table->text('source_remarks')->nullable();
            $table->date('inquiry_date')->nullable();
            $table->string('status')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('client_id')->nullable();
            $table->string('client_name')->nullable();
            $table->unsignedInteger('quote_id')->nullable();
            $table->string('quote_ref_no')->nullable();
            $table->string('quote_service_type')->nullable();
            $table->unsignedBigInteger('owner_staff_id')->nullable();
            $table->string('owner_staff_code')->nullable();
            $table->string('owner_staff_name')->nullable();
            $table->unsignedBigInteger('owner_assigned_by_id')->nullable();
            $table->string('owner_assigned_by_code')->nullable();
            $table->string('owner_assigned_by_name')->nullable();
            $table->timestamp('owner_assigned_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_code')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_inquiry_proofs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('sales_inquiry_id');
            $table->string('proof_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_leaves_application', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('staff_id');
            $table->string('type');
            $table->text('reason')->nullable();
            $table->date('start_date');
            $table->time('start_time')->nullable();
            $table->date('end_date');
            $table->time('end_time')->nullable();
            $table->decimal('duration_days', 5, 2)->default(1);
            $table->string('status')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
        });

        Schema::create('hr_leaves_allocation', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('staff_id');
            $table->string('leave_type');
            $table->integer('year');
            $table->decimal('total_days', 5, 2)->default(0);
            $table->decimal('used_days', 5, 2)->default(0);
            $table->text('remarks')->nullable();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('project_id')->nullable();
            $table->unsignedInteger('project_progress_id')->nullable();
            $table->string('title');
            $table->string('status')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('task_category')->nullable();
            $table->decimal('effort_score', 8, 2)->default(1);
            $table->string('classification_confidence')->nullable();
            $table->string('classification_source')->nullable();
            $table->boolean('user_override')->default(false);
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->nullable();
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->timestamps();
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('task_id');
            $table->text('comment');
            $table->timestamps();
        });

        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 191);
            $table->string('slug', 191)->unique();
            $table->text('summary')->nullable();
            $table->longText('body_html');
            $table->string('category', 80);
            $table->json('tags')->nullable();
            $table->string('related_route', 255)->nullable();
            $table->text('contributor_note')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_assistant_threads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->string('title', 191)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('knowledge_assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('role', 20);
            $table->longText('content');
            $table->json('sources_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_assistant_thread_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id')->unique();
            $table->json('context_json');
            $table->unsignedBigInteger('last_processed_message_id')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_answer_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('question_hash', 64)->index();
            $table->string('normalized_question', 500);
            $table->string('source_fingerprint', 64)->index();
            $table->json('answer_json');
            $table->string('answer_signature', 64)->nullable()->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('assistant_live_result_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('question_hash', 64)->index();
            $table->string('normalized_question', 500);
            $table->string('provider_key', 191)->index();
            $table->string('scope_hash', 64)->index();
            $table->string('route_hash', 64)->nullable()->index();
            $table->string('source_fingerprint', 64)->index();
            $table->json('sources_json')->nullable();
            $table->json('answer_json');
            $table->string('answer_signature', 64)->nullable()->index();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();
        });

        Schema::create('assistant_response_feedback', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id')->index();
            $table->unsignedBigInteger('thread_id')->index();
            $table->unsignedBigInteger('staff_id')->index();
            $table->string('rating', 20)->index();
            $table->json('reasons_json')->nullable();
            $table->text('note')->nullable();
            $table->text('question')->nullable();
            $table->text('answer_excerpt')->nullable();
            $table->json('sources_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->string('answer_mode', 20)->nullable();
            $table->string('current_route', 255)->nullable();
            $table->string('answer_signature', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('assistant_provider_feedback_memory', function (Blueprint $table): void {
            $table->id();
            $table->string('memory_key', 64)->unique();
            $table->string('question_hash', 64)->index();
            $table->string('normalized_question', 500);
            $table->string('provider_key', 191)->index();
            $table->string('source_type', 80)->index();
            $table->string('source_slug', 255)->nullable()->index();
            $table->string('route_hash', 64)->nullable()->index();
            $table->string('scope_hash', 64)->index();
            $table->unsignedInteger('positive_count')->default(0);
            $table->unsignedInteger('negative_count')->default(0);
            $table->timestamp('last_feedback_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('assistant_source_gaps', function (Blueprint $table): void {
            $table->id();
            $table->string('gap_key', 64)->unique();
            $table->string('normalized_intent', 500);
            $table->text('sample_question')->nullable();
            $table->string('current_route', 255)->nullable();
            $table->json('source_types_json')->nullable();
            $table->json('provider_keys_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->string('answer_mode', 20)->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_query_plan_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('question_hash', 64)->index();
            $table->string('normalized_question', 500);
            $table->json('provider_keys_json');
            $table->string('answer_mode', 20)->index();
            $table->string('scope_hash', 64)->nullable()->index();
            $table->string('source_fingerprint', 64)->nullable()->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('hr_handbook_versions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('version_label', 80);
            $table->longText('content_json');
            $table->text('change_summary')->nullable();
            $table->unsignedInteger('published_by_staff_id')->nullable();
            $table->string('published_by_name_code', 50)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        DB::table('system_users')->insert([
            ['id' => 1, 'staff_id' => 7, 'email' => 'staff7@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
            ['id' => 2, 'staff_id' => 8, 'email' => 'staff8@example.test', 'role' => json_encode(['Staff']), 'is_active' => 1],
        ]);
        DB::table('staff_general')->insert([
            ['staff_id' => 7, 'full_name' => 'Test Staff', 'name_code' => 'ST7', 'email' => 'staff7@example.test', 'status' => 'active'],
            ['staff_id' => 8, 'full_name' => 'Other Staff', 'name_code' => 'ST8', 'email' => 'staff8@example.test', 'status' => 'active'],
        ]);

        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'how-to-create-a-quotation',
            'summary' => 'Start a quotation from CRM and select the correct service form.',
            'body_html' => '<ol><li>Open Quotations.</li><li>Select the client and PIC.</li><li>Choose the service type.</li><li>Save the quotation.</li></ol>',
            'category' => 'CRM',
            'tags' => ['crm', 'quotation', 'quote'],
            'related_route' => '/crm/quotes',
        ]);

        $this->insertArticle([
            'title' => 'How to Apply Leave',
            'slug' => 'how-to-apply-leave',
            'summary' => 'Submit personal leave requests.',
            'body_html' => '<ol><li>Open My Leaves.</li><li>Choose dates.</li></ol>',
            'category' => 'Leave & HR',
            'tags' => ['leave'],
            'related_route' => '/my/leaves/apply',
        ]);
    }

    public function test_quotation_question_returns_grounded_answer_and_stores_messages(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => "To create a quotation:\n1. Open Quotations.\n2. Select the client and PIC.\n3. Choose the service type.\n4. Save the quotation.",
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => ['quotation records'],
                ]),
                'usage' => ['input_tokens' => 1200, 'output_tokens' => 140],
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'How do I create quotation?',
                'current_route' => '/crm/quotes',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('assistant.beta', true)
            ->assertJsonPath('assistant.model', 'gpt-5-nano')
            ->assertJsonPath('answer.confidence', 'high')
            ->assertJsonPath('answer.sources.0.slug', 'how-to-create-a-quotation');

        $this->assertDatabaseHas('knowledge_assistant_threads', ['staff_id' => 7]);
        $this->assertSame(2, DB::table('knowledge_assistant_messages')->count());
        $this->assertDatabaseHas('knowledge_assistant_messages', [
            'role' => 'assistant',
            'confidence' => 'high',
            'input_tokens' => 1200,
            'output_tokens' => 140,
        ]);
    }

    public function test_inline_route_token_returns_validated_route_refs(): void
    {
        config(['services.openai.key' => 'test-key']);
        $routeId = 'route_'.substr(sha1('how-to-create-a-quotation|/crm/quotes'), 0, 12);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => "Open [[kijo-route:$routeId|Quotations]] and save the quotation.",
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                    'route_refs' => [[
                        'id' => $routeId,
                        'label' => 'Quotations',
                        'related_route' => '/crm/quotes',
                        'source_slug' => 'how-to-create-a-quotation',
                    ]],
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'high')
            ->assertJsonPath('answer.route_refs.0.id', $routeId)
            ->assertJsonPath('answer.route_refs.0.related_route', '/crm/quotes')
            ->assertJsonPath('messages.1.route_refs.0.source_slug', 'how-to-create-a-quotation');

        Http::assertSent(function ($request) use ($routeId) {
            $body = $request->data();
            $messages = $body['input'] ?? [];
            $userMessage = collect($messages)->firstWhere('role', 'user');
            $payload = json_decode((string) ($userMessage['content'] ?? ''), true);

            return ($payload['route_candidates'][0]['id'] ?? null) === $routeId
                && ($payload['route_candidates'][0]['related_route'] ?? null) === '/crm/quotes';
        });
    }

    public function test_unknown_inline_route_token_returns_safe_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open [[kijo-route:route_missing|Quotations]] and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.route_refs', [])
            ->assertJsonPath('answer.content', 'I found related Kijo sources, but could not verify an inline app link in the AI response.

- How to Create a Quotation');
    }

    public function test_raw_inline_route_returns_safe_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open /crm/quotes and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.route_refs', [])
            ->assertJsonPath('answer.content', 'I found related Kijo sources, but could not verify a route or link in the AI response.

- How to Create a Quotation');
    }

    public function test_single_segment_raw_inline_route_returns_safe_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open /dashboard to inspect performance.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                    'route_refs' => [],
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.route_refs', [])
            ->assertJsonPath('answer.content', 'I found related Kijo sources, but could not verify a route or link in the AI response.

- How to Create a Quotation');
    }

    public function test_external_inline_url_returns_safe_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open www.example.com for the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                    'route_refs' => [],
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.route_refs', [])
            ->assertJsonPath('answer.content', 'I found related Kijo sources, but could not verify a route or link in the AI response.

- How to Create a Quotation');
    }

    public function test_no_match_skips_openai_and_returns_no_source_answer(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'How do I order lunch?',
            ])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources', []);

        Http::assertNothingSent();
        $this->assertSame(2, DB::table('knowledge_assistant_messages')->count());
        $this->assertSame(1, DB::table('assistant_source_gaps')->where('status', 'open')->count());
    }

    public function test_kijo_sounding_missing_policy_does_not_answer_without_source(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'what is our bonus policy?',
            ])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources', [])
            ->assertJsonPath('answer.ai_status', 'ok');

        Http::assertNothingSent();

        $answerContent = (string) DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->value('content');

        $this->assertStringContainsString('I could not find an approved Kijo source', $answerContent);
        $this->assertStringNotContainsString('bonus is', strtolower($answerContent));
        $this->assertSame('bonus policy', (string) DB::table('assistant_source_gaps')->value('normalized_intent'));
    }

    public function test_typo_heavy_missing_policy_records_normalized_source_gap_without_guessing(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'what is bonus policie?',
            ])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources', []);

        Http::assertNothingSent();

        $gapIntent = (string) DB::table('assistant_source_gaps')->value('normalized_intent');
        $this->assertStringContainsString('bonus', $gapIntent);
        $this->assertStringContainsString('policy', $gapIntent);
        $this->assertStringNotContainsString('policie', $gapIntent);
    }

    public function test_openai_usage_limit_returns_transparent_source_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'insufficient_quota: You exceeded your current quota.'],
            ], 429),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.ai_status', 'usage_limit')
            ->assertJsonPath('answer.degraded_reason', 'usage_limit')
            ->assertJsonPath('answer.ai_failure_stage', 'answer_generation')
            ->assertJsonPath('messages.1.ai_status', 'usage_limit')
            ->assertJsonPath('messages.1.ai_failure_stage', 'answer_generation')
            ->assertJsonPath('answer.sources.0.slug', 'how-to-create-a-quotation')
            ->assertJsonMissing(['content' => 'insufficient_quota: You exceeded your current quota.']);

        $answerContent = (string) DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->value('content');

        $this->assertStringContainsString('AI answer generation is temporarily unavailable because the AI usage limit or credit budget has been reached.', $answerContent);
        $this->assertStringNotContainsString('insufficient_quota', $answerContent);
    }

    public function test_openai_timeout_returns_generic_source_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake(fn () => throw new \RuntimeException('connection timed out'));

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.ai_status', 'temporary_unavailable')
            ->assertJsonPath('answer.degraded_reason', 'temporary_unavailable')
            ->assertJsonPath('answer.ai_failure_stage', 'answer_generation')
            ->assertJsonPath('answer.sources.0.slug', 'how-to-create-a-quotation')
            ->assertJsonMissing(['content' => 'connection timed out']);

        $answerContent = (string) DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->value('content');

        $this->assertStringContainsString('AI answer generation is temporarily unavailable. I found these approved Kijo sources that may help.', $answerContent);
        $this->assertStringNotContainsString('connection timed out', $answerContent);
    }

    public function test_planner_usage_limit_with_no_source_returns_transparent_clarification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.knowledge_assistant.planner_enabled' => true,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'error' => ['message' => 'Your account has no remaining credit.'],
            ], 429),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'where can i park nearby'])
            ->assertOk()
            ->assertJsonPath('answer.ai_status', 'usage_limit')
            ->assertJsonPath('answer.ai_failure_stage', 'retrieval_planning')
            ->assertJsonPath('answer.sources', [])
            ->assertJsonPath('messages.1.ai_status', 'usage_limit');

        $answerContent = (string) DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->value('content');

        $this->assertStringContainsString('AI answer generation is temporarily unavailable because the AI usage limit or credit budget has been reached.', $answerContent);
        $this->assertStringContainsString('I also could not find an approved Kijo source', $answerContent);
        $this->assertStringNotContainsString('remaining credit', $answerContent);
    }

    public function test_planner_non_limit_failure_with_no_source_returns_normal_clarification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.knowledge_assistant.planner_enabled' => true,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['output_text' => '{not-json'], 200),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'where can i park nearby'])
            ->assertOk()
            ->assertJsonPath('answer.ai_status', 'ok')
            ->assertJsonPath('answer.degraded_reason', null)
            ->assertJsonPath('answer.sources', [])
            ->assertJsonPath('messages.1.ai_status', 'ok');

        $answerContent = (string) DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->value('content');

        $this->assertStringContainsString('I could not find an approved Kijo source', $answerContent);
        $this->assertStringNotContainsString('AI answer generation is temporarily unavailable', $answerContent);
    }

    public function test_planner_usage_limit_falls_back_to_rule_based_retrieval(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.knowledge_assistant.planner_enabled' => true,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::sequence()
                ->push(['error' => ['message' => 'billing hard limit reached']], 429)
                ->push([
                    'output_text' => json_encode([
                        'answer_markdown' => 'Open Quotations and save the quotation.',
                        'confidence' => 'high',
                        'source_slugs' => ['how-to-create-a-quotation'],
                        'suggested_queries' => [],
                        'freshness_label' => null,
                        'answer_mode' => 'static',
                        'route_refs' => [],
                    ]),
                ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.ai_status', 'ok')
            ->assertJsonPath('answer.sources.0.slug', 'how-to-create-a-quotation')
            ->assertJsonPath('answer.content', 'Open Quotations and save the quotation.');
    }

    public function test_follow_up_quote_this_service_uses_previous_proposal_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');
        $this->insertArticle([
            'title' => 'How to Request and Apply Quote Negotiations',
            'slug' => 'how-to-request-and-apply-quote-negotiations',
            'summary' => 'Request discount approvals for existing quotations.',
            'body_html' => '<p>Open the quote row actions and choose Negotiate.</p>',
            'category' => 'CRM',
            'tags' => ['quote', 'negotiation', 'discount'],
            'related_route' => '/crm/price-exceptions/negotiations',
        ]);

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $sources = $decoded['sources'] ?? [];
            $slugs = array_column($sources, 'slug');

            if ($call === 1) {
                $this->assertContains("proposal-template:ih:{$cemId}", $slugs);

                return Http::response([
                    'output_text' => json_encode([
                        'answer_markdown' => 'CEM is Chemical Exposure Monitoring.',
                        'confidence' => 'high',
                        'source_slugs' => ["proposal-template:ih:{$cemId}"],
                        'suggested_queries' => [],
                        'freshness_label' => $sources[0]['freshness_label'] ?? null,
                        'answer_mode' => 'live',
                        'route_refs' => [],
                    ]),
                ]);
            }

            $this->assertSame('how to quote this service', $decoded['question']);
            $this->assertStringContainsString('Chemical Exposure Monitoring', $decoded['conversation_context']['retrieval_question'] ?? '');
            $this->assertContains("proposal-template:ih:{$cemId}", $slugs);
            $this->assertContains('how-to-create-a-quotation', $slugs);
            $this->assertNotContains('how-to-request-and-apply-quote-negotiations', $slugs);

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Create an Industrial Hygiene quotation for CEM and use the CEM proposal scope.',
                    'confidence' => 'high',
                    'source_slugs' => array_values(array_intersect($slugs, ["proposal-template:ih:{$cemId}", 'how-to-create-a-quotation'])),
                    'suggested_queries' => [],
                    'freshness_label' => $sources[0]['freshness_label'] ?? null,
                    'answer_mode' => 'mixed',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'how to quote this service',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Create an Industrial Hygiene quotation for CEM and use the CEM proposal scope.');

        $this->assertSame(2, $call);
    }

    public function test_bm_follow_up_quote_service_uses_previous_proposal_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $slugs = array_column($decoded['sources'] ?? [], 'slug');

            if ($call === 2) {
                $this->assertSame('macam mana nak quote service ni?', $decoded['question']);
                $this->assertStringContainsString('Chemical Exposure Monitoring', $decoded['conversation_context']['retrieval_question'] ?? '');
                $this->assertContains("proposal-template:ih:{$cemId}", $slugs);
            }

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => $call === 1 ? 'CEM is Chemical Exposure Monitoring.' : 'Buat quotation IH untuk CEM.',
                    'confidence' => 'high',
                    'source_slugs' => [$slugs[0] ?? "proposal-template:ih:{$cemId}"],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'macam mana nak quote service ni?',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Buat quotation IH untuk CEM.');

        $this->assertSame(2, $call);
    }

    public function test_typo_follow_up_quote_service_uses_previous_proposal_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $slugs = array_column($decoded['sources'] ?? [], 'slug');

            if ($call === 2) {
                $this->assertSame('hot to qoute this servis', $decoded['question']);
                $this->assertStringContainsString('how to quote this service', $decoded['conversation_context']['retrieval_question'] ?? '');
                $this->assertContains("proposal-template:ih:{$cemId}", $slugs);
            }

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => $call === 1 ? 'CEM is Chemical Exposure Monitoring.' : 'Create an IH quotation for CEM.',
                    'confidence' => 'high',
                    'source_slugs' => [$slugs[0] ?? "proposal-template:ih:{$cemId}"],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem serviece'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'hot to qoute this servis',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Create an IH quotation for CEM.');
    }

    public function test_current_detail_route_overrides_previous_service_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');
        $chraId = $this->insertIhProposal('Chemical Health Risk Assessment (CHRA)', 'CHRA');

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId, $chraId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $slugs = array_column($decoded['sources'] ?? [], 'slug');

            if ($call === 2) {
                $this->assertContains("proposal-template:ih:{$chraId}", $slugs);
                $this->assertNotContains("proposal-template:ih:{$cemId}", $slugs);
                $this->assertStringNotContainsString('Chemical Exposure Monitoring', $decoded['conversation_context']['retrieval_question'] ?? '');
            }

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => $call === 1 ? 'CEM is Chemical Exposure Monitoring.' : 'CHRA route detail wins.',
                    'confidence' => 'high',
                    'source_slugs' => [$call === 1 ? "proposal-template:ih:{$cemId}" : "proposal-template:ih:{$chraId}"],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'summarize this service',
                'current_route' => "/templates/proposals/industrial-hygiene/{$chraId}",
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'CHRA route detail wins.');
    }

    public function test_exact_quote_ref_overrides_previous_service_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');
        DB::table('quotes_training')->insert([
            'id' => 31,
            'quote_ref_no' => 'Q-TR-31',
            'client_name' => 'Exact Quote Client',
            'quote_value' => 9000,
            'status' => 'Open',
            'remarks' => 'Exact ref quote should win over service memory.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $slugs = array_column($decoded['sources'] ?? [], 'slug');

            if ($call === 2) {
                $this->assertContains('quote-record:training:31', $slugs);
                $this->assertNotContains("proposal-template:ih:{$cemId}", $slugs);
                $this->assertStringNotContainsString('Chemical Exposure Monitoring', $decoded['conversation_context']['retrieval_question'] ?? '');
            }

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => $call === 1 ? 'CEM is Chemical Exposure Monitoring.' : 'Quote Q-TR-31 is Open.',
                    'confidence' => 'high',
                    'source_slugs' => [$call === 1 ? "proposal-template:ih:{$cemId}" : 'quote-record:training:31'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'explain this quote Q-TR-31',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Quote Q-TR-31 is Open.');
    }

    public function test_exact_quote_ref_ranks_quote_detail_above_generic_quote_articles(): void
    {
        DB::table('quotes_training')->insert([
            'id' => 33,
            'quote_ref_no' => 'Q-TR-33',
            'client_name' => 'Ranking Quote Client',
            'quote_value' => 15000,
            'status' => 'Open',
            'remarks' => 'Exact quote detail should outrank generic quote articles.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'generic-quotation-ranking-guide',
            'summary' => 'Generic quotation steps.',
            'body_html' => '<p>Use CRM quotations to prepare quote records.</p>',
            'category' => 'CRM',
            'tags' => ['quote', 'quotation'],
            'related_route' => '/crm/quotes',
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $this->assertSame('quote-record:training:33', $decoded['sources'][0]['slug'] ?? null);

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Quote Q-TR-33 is Open.',
                    'confidence' => 'high',
                    'source_slugs' => ['quote-record:training:33'],
                    'suggested_queries' => [],
                    'freshness_label' => $decoded['sources'][0]['freshness_label'] ?? null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'explain quote Q-TR-33'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.slug', 'quote-record:training:33');
    }

    public function test_quote_status_follow_up_prefers_quote_context_over_service_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');
        DB::table('quotes_training')->insert([
            'id' => 32,
            'quote_ref_no' => 'Q-TR-32',
            'client_name' => 'Status Quote Client',
            'quote_value' => 12000,
            'status' => 'Pending',
            'remarks' => 'Status follow-up should use this quote.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $slugs = array_column($decoded['sources'] ?? [], 'slug');

            if ($call === 3) {
                $this->assertContains('quote-record:training:32', $slugs);
                $this->assertNotContains("proposal-template:ih:{$cemId}", $slugs);
                $this->assertStringContainsString('Q-TR-32', $decoded['conversation_context']['retrieval_question'] ?? '');
            }

            $slug = match ($call) {
                1 => "proposal-template:ih:{$cemId}",
                default => 'quote-record:training:32',
            };

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => match ($call) {
                        1 => 'CEM is Chemical Exposure Monitoring.',
                        2 => 'Quote Q-TR-32 is Pending.',
                        default => 'This quote status is Pending.',
                    },
                    'confidence' => 'high',
                    'source_slugs' => [$slug],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'explain quote Q-TR-32',
            ])
            ->assertOk();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'what is the status of this quote',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'This quote status is Pending.');
    }

    public function test_quote_detail_route_status_ranks_quote_detail_first(): void
    {
        DB::table('quotes_training')->insert([
            'id' => 34,
            'quote_ref_no' => 'Q-TR-34',
            'client_name' => 'Route Quote Client',
            'quote_value' => 18000,
            'status' => 'Pending',
            'remarks' => 'Route quote detail should outrank dashboards.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertArticle([
            'title' => 'CRM dashboard metrics',
            'slug' => 'crm-dashboard-metrics-ranking',
            'summary' => 'Dashboard status and quote statistics.',
            'body_html' => '<p>Read CRM quote status from dashboard metrics.</p>',
            'category' => 'CRM',
            'tags' => ['quote', 'status', 'dashboard'],
            'related_route' => '/dashboard/crm',
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $this->assertSame('quote-record:training:34', $decoded['sources'][0]['slug'] ?? null);

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'This quote status is Pending.',
                    'confidence' => 'high',
                    'source_slugs' => ['quote-record:training:34'],
                    'suggested_queries' => [],
                    'freshness_label' => $decoded['sources'][0]['freshness_label'] ?? null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'what is status of this quote',
                'current_route' => '/crm/quotes?service=training&edit=true&quoteId=34',
            ])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.slug', 'quote-record:training:34');
    }

    public function test_same_follow_up_cache_stays_separate_by_thread_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');
        $chraId = $this->insertIhProposal('Chemical Health Risk Assessment (CHRA)', 'CHRA');

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId, $chraId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $retrievalQuestion = (string) ($decoded['conversation_context']['retrieval_question'] ?? $decoded['question'] ?? '');
            $slugs = array_column($decoded['sources'] ?? [], 'slug');
            $isChra = str_contains($retrievalQuestion, 'Chemical Health Risk Assessment') || in_array("proposal-template:ih:{$chraId}", $slugs, true);
            $sourceSlug = $isChra ? "proposal-template:ih:{$chraId}" : "proposal-template:ih:{$cemId}";

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => $isChra ? 'Follow-up answer for CHRA.' : 'Follow-up answer for CEM.',
                    'confidence' => 'high',
                    'source_slugs' => [$sourceSlug],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $cemThreadId = $this->authenticated()
            ->postJson('/knowledge/assistant/thread')
            ->assertOk()
            ->json('thread.id');
        $chraThreadId = $this->authenticated()
            ->postJson('/knowledge/assistant/thread')
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $cemThreadId,
                'question' => 'what is cem service',
            ])
            ->assertOk();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $cemThreadId,
                'question' => 'how to quote this service',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Follow-up answer for CEM.');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $chraThreadId,
                'question' => 'what is chra service',
            ])
            ->assertOk();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $chraThreadId,
                'question' => 'how to quote this service',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Follow-up answer for CHRA.');

        $this->assertSame(4, $call);
        $this->assertSame(4, DB::table('assistant_live_result_cache')->count());
        $this->assertSame(1, DB::table('assistant_live_result_cache')->where('normalized_question', 'like', '%Chemical Exposure Monitoring%')->count());
        $this->assertSame(1, DB::table('assistant_live_result_cache')->where('normalized_question', 'like', '%Chemical Health Risk Assessment%')->count());
    }

    public function test_service_explanation_ranks_proposal_detail_above_generic_proposal_how_to(): void
    {
        $chraId = $this->insertIhProposal('Chemical Health Risk Assessment (CHRA)', 'CHRA');
        $this->insertArticle([
            'title' => 'How to Create Legal Compliance Templates and Manage Proposal Templates',
            'slug' => 'generic-proposal-template-ranking-guide',
            'summary' => 'Generic proposal template setup.',
            'body_html' => '<p>Proposal templates may include CHRA service descriptions.</p>',
            'category' => 'Templates',
            'tags' => ['proposal', 'template', 'chra'],
            'related_route' => '/templates/proposals',
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) use ($chraId) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $this->assertSame("proposal-template:ih:{$chraId}", $decoded['sources'][0]['slug'] ?? null);

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'CHRA is described by the proposal template detail.',
                    'confidence' => 'high',
                    'source_slugs' => ["proposal-template:ih:{$chraId}"],
                    'suggested_queries' => [],
                    'freshness_label' => $decoded['sources'][0]['freshness_label'] ?? null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'explain chra service'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.slug', "proposal-template:ih:{$chraId}");
    }

    public function test_action_request_cannot_claim_write_action_was_performed(): void
    {
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'read-only-action-quotation-guide',
            'summary' => 'Create quotation steps.',
            'body_html' => '<p>Open Quotations and select the client.</p>',
            'category' => 'CRM',
            'tags' => ['quote', 'quotation', 'create'],
            'related_route' => '/crm/quotes',
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'I created the quotation for you.',
                    'confidence' => 'high',
                    'source_slugs' => ['read-only-action-quotation-guide'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                    'route_refs' => [],
                ]),
            ]),
        ]);

        $response = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation for me now'])
            ->assertOk()
            ->assertJsonPath('answer.read_only_notice', true)
            ->assertJsonPath('answer.confidence', 'low');

        $this->assertStringStartsWith(
            'I cannot perform actions from this chat.',
            (string) $response->json('answer.content'),
        );
        $this->assertStringContainsString(
            'I found related Kijo sources, but the AI response claimed an action that this read-only assistant cannot perform.',
            (string) $response->json('answer.content'),
        );
    }

    public function test_action_request_gets_read_only_notice_even_when_answer_only_gives_steps(): void
    {
        $this->insertArticle([
            'title' => 'How to Create a Quotation',
            'slug' => 'read-only-action-steps-guide',
            'summary' => 'Create quotation steps.',
            'body_html' => '<p>Open Quotations and select the client.</p>',
            'category' => 'CRM',
            'tags' => ['quote', 'quotation', 'create'],
            'related_route' => '/crm/quotes',
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations, select the client, choose the service, and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['read-only-action-steps-guide'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                    'route_refs' => [],
                ]),
            ]),
        ]);

        $response = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'can you create quotation for me now'])
            ->assertOk()
            ->assertJsonPath('answer.read_only_notice', true)
            ->assertJsonPath('messages.1.read_only_notice', true);

        $this->assertStringStartsWith(
            'I cannot perform actions from this chat.',
            (string) $response->json('answer.content'),
        );
        $this->assertStringContainsString('Open Quotations', (string) $response->json('answer.content'));
    }

    public function test_action_request_without_source_still_gets_read_only_notice(): void
    {
        $response = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'delete this for me now'])
            ->assertOk()
            ->assertJsonPath('answer.read_only_notice', true)
            ->assertJsonPath('answer.sources', []);

        $this->assertStringStartsWith(
            'I cannot perform actions from this chat.',
            (string) $response->json('answer.content'),
        );
        $this->assertStringContainsString(
            'I could not find an approved Kijo source',
            (string) $response->json('answer.content'),
        );
    }

    public function test_follow_up_context_survives_trimmed_messages_from_thread_context(): void
    {
        $cemId = $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');

        config(['services.openai.key' => 'test-key']);
        $call = 0;
        Http::fake(function ($request) use (&$call, $cemId) {
            $call++;
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $slugs = array_column($decoded['sources'] ?? [], 'slug');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => $call === 1 ? 'CEM is Chemical Exposure Monitoring.' : 'Create an IH quotation for CEM.',
                    'confidence' => 'high',
                    'source_slugs' => [$slugs[0] ?? "proposal-template:ih:{$cemId}"],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        DB::table('knowledge_assistant_messages')->where('thread_id', $threadId)->delete();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'how to quote this service',
            ])
            ->assertOk()
            ->assertJsonPath('answer.content', 'Create an IH quotation for CEM.');

        $this->assertSame(2, $call);
    }

    public function test_follow_up_with_multiple_service_contexts_asks_for_clarification(): void
    {
        $this->insertIhProposal('Chemical Exposure Monitoring (CEM)', 'CEM');
        $this->insertIhProposal('Chemical Health Risk Assessment (CHRA)', 'CHRA');

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = $decoded['sources'][0] ?? [];

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => ($source['title'] ?? 'Service').' details.',
                    'confidence' => 'high',
                    'source_slugs' => [$source['slug'] ?? ''],
                    'suggested_queries' => [],
                    'freshness_label' => $source['freshness_label'] ?? null,
                    'answer_mode' => 'live',
                    'route_refs' => [],
                ]),
            ]);
        });

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is cem service'])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'what is chra service',
            ])
            ->assertOk();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'how to quote this service',
            ])
            ->assertOk()
            ->assertJsonPath('answer.sources', [])
            ->assertJsonFragment(['content' => 'Which previous item should I use for this follow-up: Chemical Health Risk Assessment (CHRA), Chemical Exposure Monitoring (CEM)?']);
    }

    public function test_negotiation_article_still_matches_negotiation_intent(): void
    {
        $this->insertArticle([
            'title' => 'How to Request and Apply Quote Negotiations',
            'slug' => 'how-to-request-and-apply-quote-negotiations',
            'summary' => 'Request discount approvals for existing quotations.',
            'body_html' => '<p>Open the quote row actions and choose Negotiate.</p>',
            'category' => 'CRM',
            'tags' => ['quote', 'negotiation', 'discount'],
            'related_route' => '/crm/price-exceptions/negotiations',
        ]);

        $sources = app(KnowledgeAssistantService::class)->rankArticles('how to negotiate this quote');

        $this->assertContains('how-to-request-and-apply-quote-negotiations', array_column($sources, 'slug'));
    }

    public function test_assistant_meta_question_returns_direct_help_answer_without_openai(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'how do i use this ai chat',
            ])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'high')
            ->assertJsonPath('answer.sources.0.source_type', 'assistant_help')
            ->assertJsonPath('answer.sources.0.slug', 'assistant-help:capabilities')
            ->assertJsonFragment(['content' => 'You can ask Learn Kijo AI questions about Kijo workflows, Knowledge guides, Handbook policies, dashboards, projects, clients, vendors, invoices, debtors, quotations, inquiries, leave, tasks, and other supported app records.

I answer from Kijo sources and live app data where available. If I cannot verify enough information, I will say so instead of guessing. I am read-only, so I can guide or summarize but I cannot create, update, approve, submit, or delete records.

Try asking:
- How do I create a quotation?
- Who is our top returning client now?
- Show unpaid invoices.
- Explain this page.']);

        Http::assertNothingSent();
        $this->assertSame(2, DB::table('knowledge_assistant_messages')->count());
        $this->assertDatabaseHas('knowledge_assistant_messages', [
            'role' => 'assistant',
            'confidence' => 'high',
        ]);
    }

    public function test_assistant_capability_question_returns_assistant_help_source(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'what can you answer',
            ])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'assistant_help')
            ->assertJsonPath('answer.sources.0.title', 'Learn Kijo AI Assistant')
            ->assertJsonPath('answer.answer_mode', 'static');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'assistant',
            ])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'assistant_help')
            ->assertJsonPath('answer.confidence', 'high');

        Http::assertNothingSent();
    }

    public function test_assistant_help_provider_does_not_hijack_normal_workflow_questions(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'help me create quotation',
            ])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'knowledge')
            ->assertJsonPath('answer.sources.0.slug', 'how-to-create-a-quotation');

        Http::assertSentCount(1);
    }

    public function test_bahasa_malaysia_assistant_meta_question_returns_bm_help_answer(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'apa yang boleh saya tanya',
            ])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'high')
            ->assertJsonPath('answer.sources.0.source_type', 'assistant_help')
            ->assertJsonFragment(['content' => 'Anda boleh tanya Learn Kijo AI tentang workflow Kijo, Knowledge guides, polisi Handbook, dashboard, projek, client, vendor, invoice, debtor, quotation, inquiry, leave, task, dan rekod app lain yang disokong.

Saya akan jawab berdasarkan sumber Kijo dan data live app yang tersedia. Jika maklumat tidak cukup untuk disahkan, saya akan beritahu dan tidak akan meneka. Saya hanya read-only, jadi saya boleh beri panduan atau ringkasan tetapi tidak boleh create, update, approve, submit, atau delete rekod.

Contoh soalan:
- Macam mana nak buat quotation?
- Siapa client returning paling tinggi sekarang?
- Tunjukkan unpaid invoices.
- Explain this page.']);

        Http::assertNothingSent();
    }

    public function test_meta_question_thread_keeps_assistant_help_title_after_follow_up(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $threadId = $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'how do i use this ai chat',
            ])
            ->assertOk()
            ->assertJsonPath('thread.title', 'Learn Kijo AI help')
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $threadId,
                'question' => 'create quotation',
            ])
            ->assertOk()
            ->assertJsonPath('thread.id', $threadId)
            ->assertJsonPath('thread.title', 'Learn Kijo AI help');

        $this->assertDatabaseHas('knowledge_assistant_threads', [
            'id' => $threadId,
            'title' => 'Learn Kijo AI help',
        ]);
        Http::assertSentCount(1);
    }

    public function test_legacy_meta_question_thread_displays_assistant_help_title(): void
    {
        $threadId = (int) DB::table('knowledge_assistant_threads')->insertGetId([
            'staff_id' => 7,
            'title' => 'how do i use this ai chat',
            'expires_at' => now()->addDay(),
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('knowledge_assistant_messages')->insert([
            [
                'thread_id' => $threadId,
                'role' => 'user',
                'content' => 'how do i use this ai chat',
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2),
            ],
            [
                'thread_id' => $threadId,
                'role' => 'assistant',
                'content' => 'You can ask Learn Kijo AI questions.',
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'thread_id' => $threadId,
                'role' => 'user',
                'content' => 'betul ke',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->authenticated()
            ->getJson('/knowledge/assistant/thread')
            ->assertOk()
            ->assertJsonPath('threads.0.id', $threadId)
            ->assertJsonPath('threads.0.title', 'Learn Kijo AI help')
            ->assertJsonPath('thread.title', 'Learn Kijo AI help');
    }

    public function test_out_of_scope_question_on_dashboard_does_not_attach_dashboard_source(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake();
        $question = 'lapar perut nak makan nasi kat mana ekk';
        DB::table('assistant_live_result_cache')->insert([
            'cache_key' => 'stale-out-of-scope-dashboard-answer',
            'question_hash' => sha1(app(AssistantText::class)->normalizedQuestionKey($question)),
            'normalized_question' => app(AssistantText::class)->normalizedQuestionKey($question),
            'provider_key' => 'dashboard',
            'scope_hash' => sha1(json_encode([
                'staff_id' => 7,
                'roles' => ['Staff'],
                'name_code' => 'ST7',
            ], JSON_UNESCAPED_SLASHES) ?: ''),
            'route_hash' => sha1('/dashboard/sales'),
            'source_fingerprint' => sha1('stale-source'),
            'sources_json' => json_encode([['slug' => 'dashboard:sales', 'source_type' => 'live_metric']]),
            'answer_json' => json_encode([
                'answer_markdown' => 'Stale dashboard answer that should not be reused.',
                'confidence' => 'low',
                'source_slugs' => ['dashboard:sales'],
                'answer_mode' => 'live',
            ]),
            'refreshed_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => $question,
                'current_route' => '/dashboard/sales',
            ])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources', []);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('assistant_live_result_cache')->where('hit_count', '>', 0)->count());
        $this->assertSame(1, DB::table('assistant_source_gaps')->where('status', 'open')->count());
    }

    public function test_generic_payment_question_does_not_leak_vendor_records(): void
    {
        $vendorId = $this->insertVendor('Safe Vendor Sdn Bhd');
        DB::table('vendor_payments')->insert([
            'vendor_id' => $vendorId,
            'payment_context' => 'Training support',
            'amount' => 1200,
            'status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What is the payment status?'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources', []);

        Http::assertNothingSent();
    }

    public function test_ai_answer_normalizes_escaped_newlines_before_storage(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => '1. Open Quotations.\\n2. Select the client.\\n3. Save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk();

        $content = (string) DB::table('knowledge_assistant_messages')
            ->where('role', 'assistant')
            ->value('content');

        $this->assertStringContainsString("Open Quotations.\n2. Select the client.", $content);
        $this->assertStringNotContainsString('\\n2.', $content);
    }

    public function test_bahasa_malaysia_question_sends_language_hint_and_can_return_bm_answer(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => "Untuk buat sebut harga:\n1. Buka Quotations.\n2. Pilih client dan PIC.\n3. Simpan quotation.",
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => ['sebut harga'],
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'macam mana nak buat sebut harga?'])
            ->assertOk()
            ->assertJsonPath('answer.content', "Untuk buat sebut harga:\n1. Buka Quotations.\n2. Pilih client dan PIC.\n3. Simpan quotation.");

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $input = $payload['input'][1]['content'] ?? '';
            $decoded = json_decode($input, true);

            return ($decoded['language_hint'] ?? null) === 'bahasa_malaysia'
                && ($decoded['question'] ?? null) === 'macam mana nak buat sebut harga?'
                && ($decoded['sources'][0]['slug'] ?? null) === 'how-to-create-a-quotation';
        });
    }

    public function test_handbook_question_uses_current_published_handbook_source(): void
    {
        $versionId = $this->insertHandbookVersion([
            'chapters' => [[
                'id' => 'hr',
                'title' => 'HR Policies',
                'sections' => [[
                    'id' => 'medical-leave',
                    'title' => 'Medical Leave',
                    'body' => 'Staff must submit medical certificates according to the current handbook policy.',
                ]],
            ]],
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Submit the medical certificate according to the current handbook policy.',
                    'confidence' => 'high',
                    'source_slugs' => ["handbook:{$versionId}:medical-leave"],
                    'suggested_queries' => ['medical leave'],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What is the medical leave policy in handbook?'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'handbook')
            ->assertJsonPath('answer.sources.0.related_route', '/handbook');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $input = $payload['input'][1]['content'] ?? '';
            $decoded = json_decode($input, true);

            return ($decoded['sources'][0]['source_type'] ?? null) === 'handbook';
        });
    }

    public function test_static_answer_cache_reuses_grounded_answers(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'How do I create quotation?'])
            ->assertOk()
            ->assertJsonPath('answer.cached', false);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'How do I create quotation?'])
            ->assertOk()
            ->assertJsonPath('answer.cached', true);

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('assistant_answer_cache')->count());
    }

    public function test_user_can_submit_helpful_assistant_feedback_for_own_message(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $response = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'How do I create quotation?'])
            ->assertOk();
        $messageId = collect($response->json('messages'))->firstWhere('role', 'assistant')['id'];

        $this->authenticated()
            ->postJson("/knowledge/assistant/messages/{$messageId}/feedback", [
                'rating' => 'helpful',
                'current_route' => '/crm/quotes',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('assistant_response_feedback', [
            'message_id' => $messageId,
            'staff_id' => 7,
            'rating' => 'helpful',
            'current_route' => '/crm/quotes',
        ]);
        $this->assertSame(1, DB::table('assistant_provider_feedback_memory')->where('positive_count', 1)->count());
    }

    public function test_user_cannot_submit_feedback_for_another_staff_message(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $response = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'How do I create quotation?'])
            ->assertOk();
        $messageId = collect($response->json('messages'))->firstWhere('role', 'assistant')['id'];

        $this->authenticated(2, 8)
            ->postJson("/knowledge/assistant/messages/{$messageId}/feedback", ['rating' => 'bad'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('assistant_response_feedback')->count());
    }

    public function test_bad_feedback_blocks_matching_answer_signature_and_purges_cache(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $response = $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'How do I create quotation?'])
            ->assertOk()
            ->assertJsonPath('answer.cached', false);
        $messageId = collect($response->json('messages'))->firstWhere('role', 'assistant')['id'];
        $signature = $response->json('answer.answer_signature');
        $this->assertSame(1, DB::table('assistant_answer_cache')->where('answer_signature', $signature)->count());

        $this->authenticated()
            ->postJson("/knowledge/assistant/messages/{$messageId}/feedback", [
                'rating' => 'bad',
                'reasons' => ['Wrong information'],
                'note' => 'This answer skipped required details.',
                'current_route' => '/crm/quotes',
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('assistant_answer_cache')->where('answer_signature', $signature)->count());
        $this->assertDatabaseHas('system_feedbacks', ['reported_by' => 7, 'status' => 'Pending']);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'How do I create quotation?'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.content', 'I found related Kijo sources, but a previous matching response was marked unhelpful. Please check these sources or ask with more detail.

- How to Create a Quotation');

        Http::assertSentCount(2);
    }

    public function test_provider_feedback_memory_adjusts_source_scores_without_removing_sources(): void
    {
        $request = $this->assistantRequest();
        $sources = [
            [
                'slug' => 'source-a',
                'source_type' => 'knowledge',
                'score' => 100,
            ],
            [
                'slug' => 'source-b',
                'source_type' => 'knowledge',
                'score' => 100,
            ],
        ];

        $memory = app(AssistantFeedbackMemory::class);
        $memory->recordProviderFeedback('How do I create quotation?', '/crm/quotes', $request, [$sources[1]], 'helpful');
        $boosted = $memory->applySourceScores('How do I create quotation?', '/crm/quotes', $request, $sources);
        $this->assertGreaterThan($boosted[0]['score'], $boosted[1]['score']);

        for ($i = 0; $i < 20; $i++) {
            $memory->recordProviderFeedback('How do I create quotation?', '/crm/quotes', $request, [$sources[1]], 'bad');
        }
        $penalized = $memory->applySourceScores('How do I create quotation?', '/crm/quotes', $request, $sources);
        $this->assertGreaterThan(0, $penalized[1]['score']);
        $this->assertLessThan($penalized[0]['score'], $penalized[1]['score']);
    }

    public function test_live_dashboard_answer_cache_uses_five_minute_ttl(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'As of now, use the Sales dashboard source for current sales metrics.',
                    'confidence' => 'medium',
                    'source_slugs' => ['dashboard:sales'],
                    'suggested_queries' => [],
                    'freshness_label' => 'As of 29 May 2026, 16:40',
                    'answer_mode' => 'live',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'What are our current sales dashboard numbers?',
                'current_route' => '/dashboard/sales',
            ])
            ->assertOk()
            ->assertJsonPath('answer.answer_mode', 'live')
            ->assertJsonPath('answer.cached', false);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'What are our current sales dashboard numbers?',
                'current_route' => '/dashboard/sales',
            ])
            ->assertOk()
            ->assertJsonPath('answer.cached', true)
            ->assertJsonPath('answer.freshness_label', 'As of 29 May 2026, 16:40');

        Http::assertSentCount(1);
        $expiresAt = DB::table('assistant_live_result_cache')->value('expires_at');
        $this->assertTrue(strtotime((string) $expiresAt) <= now()->addMinutes(5)->addSeconds(5)->timestamp);
    }

    public function test_live_dashboard_question_matches_sales_without_dashboard_route(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Use the Sales dashboard source for current sales metrics.',
                    'confidence' => 'medium',
                    'source_slugs' => ['dashboard:sales'],
                    'suggested_queries' => [],
                    'freshness_label' => 'As of 29 May 2026, 16:40',
                    'answer_mode' => 'live',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'What are our current sales numbers?',
            ])
            ->assertOk()
            ->assertJsonPath('answer.answer_mode', 'live')
            ->assertJsonPath('answer.sources.0.slug', 'dashboard:sales');
    }

    public function test_project_question_resolves_live_project_source(): void
    {
        $projectId = $this->insertProject('Alpha Safety Training', 'Active', 1);
        DB::table('project_progress')->insert([
            'project_id' => $projectId,
            'progress_date' => '2026-05-20',
            'progress_text' => 'Training materials completed.',
            'updated_by' => 7,
            'updated_on' => now(),
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', 'project');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'As of now, Alpha Safety Training is active and the latest progress is training materials completed.',
                    'confidence' => 'high',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => [],
                    'freshness_label' => $source['freshness_label'],
                    'answer_mode' => 'live',
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'What is the status of Alpha Safety Training project?',
                'current_route' => '/project/manage',
            ])
            ->assertOk()
            ->assertJsonPath('answer.answer_mode', 'live')
            ->assertJsonPath('answer.sources.0.source_type', 'project')
            ->assertJsonPath('answer.sources.0.related_route', "/project/manage/{$projectId}");
    }

    public function test_current_project_detail_route_uses_full_detail_context(): void
    {
        $clientId = $this->insertClient('Detail Client Sdn Bhd');
        $projectId = $this->insertProject('Detail Project Expansion', 'Active', $clientId);
        DB::table('project_progress')->insert([
            'project_id' => $projectId,
            'progress_date' => '2026-05-25',
            'progress_text' => 'Detail route progress entry with mobilization notes.',
            'updated_by' => 7,
            'updated_on' => now(),
        ]);
        DB::table('project_expenses')->insert([
            'project_id' => $projectId,
            'date' => '2026-05-26',
            'amount' => 345.67,
            'remarks' => 'Detail route expense for consumables.',
            'file_path' => 'private/project-expense.pdf',
            'created_by' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sawDetailContext = false;
        $this->fakeOpenAiForSource('project', function (array $decoded, ?array $source = null) use (&$sawDetailContext): void {
            $excerpt = (string) ($source['excerpt'] ?? '');
            $sawDetailContext = str_contains($excerpt, 'Detail route progress entry')
                && str_contains($excerpt, 'Detail route expense for consumables')
                && str_contains($excerpt, 'Detail Client Sdn Bhd')
                && ! str_contains($excerpt, 'private/project-expense.pdf')
                && ! str_contains($excerpt, 'file_path');
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'Summarize this project detail record',
                'current_route' => "/project/manage/{$projectId}",
            ])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'project')
            ->assertJsonPath('answer.sources.0.related_route', "/project/manage/{$projectId}");

        $this->assertTrue($sawDetailContext);
    }

    public function test_ambiguous_project_question_returns_clarification_source(): void
    {
        $this->insertProject('Alpha Safety Training North', 'Active', 1);
        $this->insertProject('Alpha Safety Training South', 'Active', 1);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', 'live_entity');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'I found multiple project matches. Please specify North or South.',
                    'confidence' => 'high',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => ['Alpha Safety Training North'],
                    'freshness_label' => $source['freshness_label'],
                    'answer_mode' => 'live',
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What is the status of Alpha Safety Training project?'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources.0.source_type', 'live_entity')
            ->assertJsonPath('answer.sources.0.title', 'Ambiguous project matches');
    }

    public function test_top_returning_client_uses_client_roi_source_and_live_cache(): void
    {
        $clientId = $this->insertClient('Returning Client Sdn Bhd');
        $otherClientId = $this->insertClient('Small Client Sdn Bhd');
        $this->insertProject('Returning Client Project', 'Completed', $clientId, 50000);
        $this->insertProject('Small Client Project', 'Completed', $otherClientId, 1000);
        DB::table('invoices')->insert([
            'client_id' => $clientId,
            'invoice_ref_no' => 'INV-1',
            'invoice_date' => now()->toDateString(),
            'grand_total' => 50000,
            'status' => 'Paid',
            'paid_date' => now()->toDateString(),
            'paid_amount' => 50000,
        ]);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', 'client');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'As of now, Returning Client Sdn Bhd is the top returning client by awarded value.',
                    'confidence' => 'high',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => [],
                    'freshness_label' => $source['freshness_label'],
                    'answer_mode' => 'live',
                ]),
            ]);
        });

        $payload = [
            'question' => 'Who is our number 1 returning client now?',
            'current_route' => '/dashboard/sales',
        ];

        $this->authenticated()
            ->postJson('/knowledge/assistant', $payload)
            ->assertOk()
            ->assertJsonPath('answer.answer_mode', 'live')
            ->assertJsonPath('answer.cached', false)
            ->assertJsonPath('answer.sources.0.source_type', 'client');

        $this->authenticated()
            ->postJson('/knowledge/assistant', $payload)
            ->assertOk()
            ->assertJsonPath('answer.cached', true);

        Http::assertSentCount(1);
    }

    public function test_client_detail_source_uses_frontend_client_route(): void
    {
        $clientId = $this->insertClient('Route Client Sdn Bhd');
        $providerResult = app(ClientContextProvider::class)
            ->retrieve('Tell me about Route Client Sdn Bhd', '', request());
        $this->assertNotEmpty($providerResult->sources);
        $this->assertSame('client', $providerResult->sources[0]['source_type'] ?? null);

        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', 'client');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Route Client is active.',
                    'confidence' => 'high',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => [],
                    'freshness_label' => $source['freshness_label'],
                    'answer_mode' => 'live',
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'Tell me about Route Client Sdn Bhd'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'client')
            ->assertJsonPath('answer.sources.0.related_route', "/client/manage/{$clientId}");
    }

    public function test_vendor_payment_context_is_sanitized_before_openai(): void
    {
        $projectId = $this->insertProject('Vendor Support Project', 'Active', 1);
        $vendorId = $this->insertVendor('Safe Vendor Sdn Bhd');
        DB::table('vendor_payments')->insert([
            'vendor_id' => $vendorId,
            'project_id' => $projectId,
            'payment_context' => 'Training support',
            'remarks' => 'For completed support',
            'amount' => 1200,
            'method' => 'Bank Transfer',
            'status' => 'Approved',
            'payment_type' => 'Project',
            'receipt_path' => 'private/receipt.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sawSanitizedContext = false;
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) use (&$sawSanitizedContext) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', 'vendor');
            $excerpt = (string) ($source['excerpt'] ?? '');
            $sawSanitizedContext = ! str_contains($excerpt, 'private/receipt.pdf')
                && ! str_contains($excerpt, 'safe@example.test')
                && ! str_contains($excerpt, '0123456789');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'As of now, Safe Vendor has an approved payment for training support.',
                    'confidence' => 'medium',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => [],
                    'freshness_label' => $source['freshness_label'],
                    'answer_mode' => 'live',
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What is Safe Vendor payment status?'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'vendor')
            ->assertJsonPath('answer.sources.0.related_route', "/vendor/paid/{$vendorId}");

        $this->assertTrue($sawSanitizedContext);
    }

    public function test_invoice_question_resolves_invoice_source(): void
    {
        $clientId = $this->insertClient('Invoice Client Sdn Bhd');
        $projectId = $this->insertProject('Invoice Project', 'Active', $clientId);
        DB::table('invoices')->insert([
            'id' => 88,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'invoice_ref_no' => 'INV-2026-088',
            'service_type' => 'Training',
            'invoice_date' => now()->subDays(5)->toDateString(),
            'grand_total' => 12500,
            'status' => 'Pending',
            'invoice_client_name' => 'Invoice Client Sdn Bhd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_breakdown')->insert([
            'invoice_id' => 88,
            'item_description' => 'Training service',
            'quantity' => 1,
            'unit_price' => 12500,
            'subtotal' => 12500,
        ]);
        $providerResult = app(InvoiceContextProvider::class)->retrieve(
            'What is invoice INV-2026-088 status?',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('invoice', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/commercial/invoice/88', $providerResult->sources[0]['related_route'] ?? null);
        $this->fakeOpenAiForSource('invoice');

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What is invoice INV-2026-088 status?'])
            ->assertOk()
            ->assertJsonPath('answer.answer_mode', 'live')
            ->assertJsonFragment(['source_type' => 'invoice'])
            ->assertJsonFragment(['related_route' => '/commercial/invoice/88']);
    }

    public function test_current_invoice_detail_route_includes_breakdown_and_linked_project(): void
    {
        $clientId = $this->insertClient('Detail Invoice Client Sdn Bhd');
        $projectId = $this->insertProject('Detail Invoice Project', 'Active', $clientId);
        DB::table('invoices')->insert([
            'id' => 188,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'invoice_ref_no' => 'INV-DETAIL-188',
            'service_type' => 'Training',
            'invoice_date' => now()->subDays(2)->toDateString(),
            'grand_total' => 43210.55,
            'status' => 'Pending',
            'invoice_client_name' => 'Detail Invoice Client Sdn Bhd',
            'invoice_pic_email' => 'finance@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_breakdown')->insert([
            'invoice_id' => 188,
            'item_description' => 'Detailed invoice line item',
            'description' => 'Line item from invoice detail route',
            'quantity' => 2,
            'unit_price' => 21605.275,
            'subtotal' => 43210.55,
        ]);

        $providerResult = app(DetailRecordContextProvider::class)->retrieve(
            'Explain this invoice',
            '/commercial/invoice/188',
            $this->assistantRequest(),
        );

        $this->assertSame('invoice', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/commercial/invoice/188', $providerResult->sources[0]['related_route'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Detailed invoice line item', $sourceJson);
        $this->assertStringContainsString('Detail Invoice Project', $sourceJson);
        $this->assertStringContainsString('finance@example.test', $sourceJson);
    }

    public function test_debtor_question_returns_open_debtor_source(): void
    {
        $clientId = $this->insertClient('Debtor Client Sdn Bhd');
        $projectId = $this->insertProject('Debtor Project', 'Active', $clientId);
        DB::table('invoices')->insert([
            'id' => 89,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'invoice_ref_no' => 'INV-DEBT-1',
            'invoice_date' => now()->subDays(45)->toDateString(),
            'due_date' => now()->subDays(15)->toDateString(),
            'grand_total' => 5000,
            'status' => 'Pending',
            'invoice_client_name' => 'Debtor Client Sdn Bhd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $providerResult = app(DebtorContextProvider::class)->retrieve(
            'Show overdue debtors for Debtor Client',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('debtor', $providerResult->sources[0]['source_type'] ?? null);
        $this->fakeOpenAiForSource('debtor');

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'Show overdue debtors for Debtor Client'])
            ->assertOk()
            ->assertJsonFragment(['source_type' => 'debtor'])
            ->assertJsonFragment(['related_route' => '/commercial/debtors']);
    }

    public function test_vendor_registration_question_omits_portal_password(): void
    {
        $clientId = $this->insertClient('Registration Client Sdn Bhd');
        DB::table('client_vendor_registrations')->insert([
            'id' => 20,
            'client_id' => $clientId,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'certificate_path' => 'secret/path.pdf',
            'certificate_original_name' => 'registration.pdf',
            'portal_url' => 'https://portal.example.test',
            'portal_username' => 'registration-user',
            'portal_password' => 'super-secret-password',
            'remarks' => 'Renew soon',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('client_vendor_registration_recipients')->insert([
            'registration_id' => 20,
            'staff_id' => 7,
        ]);
        $providerResult = app(ClientVendorRegistrationContextProvider::class)->retrieve(
            'When does Registration Client vendor registration expire?',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('vendor_registration', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/client/vendor-registration/20', $providerResult->sources[0]['related_route'] ?? null);
        $this->assertStringNotContainsString('super-secret-password', json_encode($providerResult->sources));
        $this->assertStringNotContainsString('portal_password', json_encode($providerResult->sources));
        $this->fakeOpenAiForSource('vendor_registration');

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'When does Registration Client vendor registration expire?'])
            ->assertOk()
            ->assertJsonFragment(['source_type' => 'vendor_registration'])
            ->assertJsonFragment(['related_route' => '/client/vendor-registration/20']);
    }

    public function test_current_vendor_registration_detail_redacts_credentials_but_keeps_attachment_metadata(): void
    {
        $clientId = $this->insertClient('Route Registration Client Sdn Bhd');
        DB::table('client_vendor_registrations')->insert([
            'id' => 120,
            'client_id' => $clientId,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
            'certificate_path' => 'private/client-registration.pdf',
            'certificate_original_name' => 'client-registration.pdf',
            'certificate_mime_type' => 'application/pdf',
            'certificate_size' => 12345,
            'portal_url' => 'https://vendor.example.test',
            'portal_username' => 'route-registration-user',
            'portal_password' => 'do-not-send-this',
            'remarks' => 'Route detail renewal context',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(DetailRecordContextProvider::class)->retrieve(
            'Summarize this vendor registration',
            '/client/vendor-registration/120',
            $this->assistantRequest(),
        );

        $this->assertSame('vendor_registration', $providerResult->sources[0]['source_type'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('client-registration.pdf', $sourceJson);
        $this->assertStringContainsString('application/pdf', $sourceJson);
        $this->assertStringContainsString('route-registration-user', $sourceJson);
        $this->assertStringNotContainsString('do-not-send-this', $sourceJson);
        $this->assertStringNotContainsString('private/client-registration.pdf', $sourceJson);
        $this->assertStringNotContainsString('certificate_path', $sourceJson);
    }

    public function test_quote_record_question_searches_service_quote_records(): void
    {
        DB::table('quotes_training')->insert([
            'id' => 31,
            'quote_ref_no' => 'Q-TR-31',
            'client_name' => 'Quote Client',
            'quote_value' => 9000,
            'status' => 'Open',
            'remarks' => 'Follow up next week',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $providerResult = app(QuoteRecordContextProvider::class)->retrieve(
            'What is the status of quote Q-TR-31?',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('quote_record', $providerResult->sources[0]['source_type'] ?? null);
        $this->fakeOpenAiForSource('quote_record');

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What is the status of quote Q-TR-31?'])
            ->assertOk()
            ->assertJsonFragment(['source_type' => 'quote_record'])
            ->assertJsonFragment(['related_route' => '/crm/quotes?service=training&edit=true&quoteId=31']);
    }

    public function test_proposal_template_context_uses_detail_content_from_current_route(): void
    {
        DB::table('proposal_template_training_main')->insert([
            'id' => 71,
            'training_title' => 'Confined Space Safety Training',
            'training_code' => 'TR-CS-01',
            'hrd_no' => 'HRD-777',
            'introduction' => '<p>Detailed confined space introduction.</p>',
            'objectives' => '<p>Identify atmospheric hazards and permit controls.</p>',
            'modules' => '<p>Module A and Module B.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_template_training_agenda')->insert([
            'template_id' => 71,
            'day' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:30:00',
            'topic' => 'Permit-to-work briefing',
        ]);

        $providerResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'Summarize this proposal',
            '/templates/proposals/training/71',
            $this->assistantRequest(),
        );

        $this->assertSame('proposal_template', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/templates/proposals/training/71', $providerResult->sources[0]['related_route'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0]);
        $this->assertStringContainsString('Detailed confined space introduction', $sourceJson);
        $this->assertStringContainsString('Permit-to-work briefing', $sourceJson);
    }

    public function test_proposal_template_context_resolves_exact_title_and_current_routes(): void
    {
        DB::table('proposal_template_ih')->insert([
            'id' => 72,
            'service_title' => 'Noise Exposure Monitoring Proposal',
            'service_code' => 'IH-NOISE',
            'introduction' => '<p>Noise survey background.</p>',
            'work_scope' => '<p>Dosimeter monitoring and area sampling.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'What is inside Noise Exposure Monitoring Proposal?',
            '',
            $this->assistantRequest(),
        );

        $this->assertSame('proposal_template', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/templates/proposals/industrial-hygiene/72', $providerResult->sources[0]['related_route'] ?? null);
        $this->assertStringContainsString('Dosimeter monitoring', json_encode($providerResult->sources[0]));
    }

    public function test_proposal_template_context_resolves_natural_service_code_to_detail(): void
    {
        DB::table('proposal_template_ih')->insert([
            'id' => 73,
            'service_title' => 'Chemical Health Risk Assessment',
            'service_code' => 'CHRA',
            'introduction' => '<p>CHRA identifies chemical exposure risk for workers.</p>',
            'objectives' => '<p>Classify health risk and recommend control measures.</p>',
            'work_scope' => '<p>Review chemical register, workplace assessment, and exposure controls.</p>',
            'proposal_language' => 'en',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'explain chra service',
            '',
            $this->assistantRequest(),
        );

        $this->assertSame('proposal_template', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/templates/proposals/industrial-hygiene/73', $providerResult->sources[0]['related_route'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0]);
        $this->assertStringContainsString('Chemical Health Risk Assessment', $sourceJson);
        $this->assertStringContainsString('Review chemical register', $sourceJson);
        $this->assertStringContainsString('Active', $sourceJson);
    }

    public function test_proposal_template_context_resolves_codes_across_proposal_types(): void
    {
        DB::table('proposal_template_training_main')->insert([
            'id' => 74,
            'training_title' => 'Confined Space Rescue Training',
            'training_code' => 'TR-CSRT',
            'introduction' => '<p>Training code detail.</p>',
            'modules' => '<p>Rescue plan and tripod setup.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_template_manpower')->insert([
            'id' => 75,
            'service_title' => 'Safety Supervisor Supply',
            'service_code' => 'MP-SSS',
            'introduction' => '<p>Manpower code detail.</p>',
            'service_deliverables' => '<p>Supply competent supervisor.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_template_special')->insert([
            'id' => 76,
            'title' => 'Emergency Drill Support',
            'service_title' => 'Emergency Drill',
            'service_code' => 'SP-DRILL',
            'service_type' => 'Special Service',
            'content' => '<p>Special code detail.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cases = [
            ['Tell me about TR-CSRT', '/templates/proposals/training/74', 'Rescue plan and tripod setup'],
            ['What is MP-SSS service?', '/templates/proposals/manpower/75', 'Supply competent supervisor'],
            ['Explain SP-DRILL', '/templates/proposals/special-service/76', 'Special code detail'],
        ];

        foreach ($cases as [$question, $route, $expectedText]) {
            $providerResult = app(ProposalTemplateContextProvider::class)->retrieve(
                $question,
                '',
                $this->assistantRequest(),
            );

            $this->assertSame('proposal_template', $providerResult->sources[0]['source_type'] ?? null);
            $this->assertSame($route, $providerResult->sources[0]['related_route'] ?? null);
            $this->assertStringContainsString($expectedText, json_encode($providerResult->sources[0]));
        }
    }

    public function test_proposal_template_context_uses_body_match_but_prefers_exact_code(): void
    {
        DB::table('proposal_template_ih')->insert([
            'id' => 77,
            'service_title' => 'Area Monitoring Body Match',
            'service_code' => 'BODY-AREA',
            'work_scope' => '<p>Contains unique benzene badge sampling workflow.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_template_ih')->insert([
            'id' => 78,
            'service_title' => 'Exact Code Service',
            'service_code' => 'EXACT-BZ',
            'work_scope' => '<p>Exact code content should win.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bodyResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'What proposal covers unique benzene badge sampling workflow?',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('/templates/proposals/industrial-hygiene/77', $bodyResult->sources[0]['related_route'] ?? null);

        $codeResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'Explain EXACT-BZ',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('/templates/proposals/industrial-hygiene/78', $codeResult->sources[0]['related_route'] ?? null);
        $this->assertStringContainsString('Exact code content should win', json_encode($codeResult->sources[0]));
    }

    public function test_proposal_template_context_returns_ambiguity_for_duplicate_codes(): void
    {
        DB::table('proposal_template_ih')->insert([
            'id' => 79,
            'service_title' => 'Duplicate Code IH',
            'service_code' => 'DUP-SVC',
            'work_scope' => '<p>IH duplicate.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_template_special')->insert([
            'id' => 80,
            'title' => 'Duplicate Code Special',
            'service_title' => 'Duplicate Special',
            'service_code' => 'DUP-SVC',
            'content' => '<p>Special duplicate.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'Explain DUP-SVC',
            '',
            $this->assistantRequest(),
        );

        $this->assertSame('live_entity', $providerResult->sources[0]['source_type'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Duplicate Code IH', $sourceJson);
        $this->assertStringContainsString('Duplicate Code Special', $sourceJson);
        $this->assertStringContainsString('/templates/proposals/industrial-hygiene/79', $sourceJson);
        $this->assertStringContainsString('/templates/proposals/special-service/80', $sourceJson);
    }

    public function test_proposal_template_context_uses_special_service_title_when_title_is_empty(): void
    {
        DB::table('proposal_template_special')->insert([
            'id' => 84,
            'title' => null,
            'service_title' => 'Dropped Object Prevention Study',
            'service_code' => 'SP-DOPS',
            'service_type' => 'Special Service',
            'content' => '<p>Inspect worksite dropped object risks and controls.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'what is dropped object prevention study',
            '',
            $this->assistantRequest(),
        );

        $this->assertSame('proposal_template', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('Dropped Object Prevention Study', $providerResult->sources[0]['title'] ?? null);
        $this->assertSame('/templates/proposals/special-service/84', $providerResult->sources[0]['related_route'] ?? null);
        $this->assertStringContainsString('Inspect worksite dropped object risks', json_encode($providerResult->sources[0]));
    }

    public function test_proposal_template_context_includes_deleted_metadata_and_ignores_unrelated_questions(): void
    {
        DB::table('proposal_template_ih')->insert([
            'id' => 83,
            'service_title' => 'Deleted Legacy Hygiene Review',
            'service_code' => 'OLD-HYG',
            'work_scope' => '<p>Legacy deleted proposal scope.</p>',
            'proposal_language' => 'en',
            'status' => 'Legacy',
            'is_deleted' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'Explain OLD-HYG',
            '',
            $this->assistantRequest(),
        );
        $deletedJson = json_encode($deletedResult->sources[0]);
        $this->assertSame('proposal_template', $deletedResult->sources[0]['source_type'] ?? null);
        $this->assertStringContainsString('Legacy deleted proposal scope', $deletedJson);
        $this->assertStringContainsString('isDeleted', $deletedJson);
        $this->assertStringContainsString('Legacy', $deletedJson);

        $unrelatedResult = app(ProposalTemplateContextProvider::class)->retrieve(
            'explain payroll service warranty rules',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame([], $unrelatedResult->sources);
    }

    public function test_quote_context_uses_current_route_detail_line_items_and_linked_proposal(): void
    {
        DB::table('proposal_template_special')->insert([
            'id' => 81,
            'title' => 'Rescue Team Proposal',
            'service_title' => 'Rescue Team',
            'service_code' => 'SP-RESCUE',
            'service_type' => 'Special Service',
            'content' => '<p>Provide standby rescue team and equipment.</p>',
            'proposal_language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('quotes_special')->insert([
            'id' => 82,
            'quote_ref_no' => 'QSS26-0082ST7',
            'client_name' => 'Linked Proposal Client',
            'service_title' => 'Rescue Team',
            'service_code' => 'SP-RESCUE',
            'status' => 'Open',
            'general_remarks' => 'Urgent mobilization',
            'grand_total' => 12345.67,
            'discount' => 100,
            'attach_proposal' => true,
            'proposal_id' => 81,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('quotes_special_items')->insert([
            'quote_id' => 82,
            'line_item_title' => 'Standby rescue team',
            'description' => 'Two rescuers for one shift',
            'unit' => 'shift',
            'quantity' => 1,
            'unit_price' => 12000,
            'line_total' => 12000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(QuoteRecordContextProvider::class)->retrieve(
            'Explain this quotation',
            '/crm/quotes?service=special&edit=true&quoteId=82',
            $this->assistantRequest(),
        );

        $this->assertSame('quote_record', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/crm/quotes?service=special&edit=true&quoteId=82', $providerResult->sources[0]['related_route'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0]);
        $this->assertStringContainsString('Standby rescue team', $sourceJson);
        $this->assertStringContainsString('12345.67', $sourceJson);
        $this->assertStringContainsString('Provide standby rescue team and equipment', $sourceJson);
    }

    public function test_quote_context_resolves_exact_ref_with_equipment_items(): void
    {
        DB::table('catalog_items')->insert([
            'id' => 91,
            'item_name' => 'Gas Detector',
            'description' => 'Four gas detector with calibration',
            'unit' => 'unit',
            'supplier_name' => 'Detector Vendor',
            'supplier_price' => 500,
        ]);
        DB::table('quotes_equipment')->insert([
            'id' => 92,
            'quote_ref_no' => 'QES26-0092ST7',
            'client_name' => 'Equipment Client',
            'status' => 'Open',
            'remarks' => 'Includes delivery',
            'grand_total' => 650,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('quotes_equipment_items')->insert([
            'quote_id' => 92,
            'item_id' => 91,
            'unit_price' => 650,
            'quantity' => 1,
            'marked_up_price' => 650,
            'line_total' => 650,
        ]);

        $providerResult = app(QuoteRecordContextProvider::class)->retrieve(
            'What is in quotation QES26-0092ST7?',
            '',
            $this->assistantRequest(),
        );

        $this->assertSame('quote_record', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/crm/quotes?service=equipment&edit=true&quoteId=92', $providerResult->sources[0]['related_route'] ?? null);
        $sourceJson = json_encode($providerResult->sources[0]);
        $this->assertStringContainsString('Gas Detector', $sourceJson);
        $this->assertStringContainsString('Includes delivery', $sourceJson);
    }

    public function test_sales_inquiry_question_returns_linked_context(): void
    {
        DB::table('sales_inquiries')->insert([
            'id' => 41,
            'company_name' => 'Inquiry Client Sdn Bhd',
            'service_required' => 'training',
            'source' => 'Website',
            'inquiry_date' => now()->toDateString(),
            'status' => 'quote_created',
            'quote_id' => 31,
            'quote_ref_no' => 'Q-TR-31',
            'quote_service_type' => 'training',
            'client_name' => 'Inquiry Client Sdn Bhd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $providerResult = app(SalesInquiryContextProvider::class)->retrieve(
            'What happened to Inquiry Client sales inquiry?',
            '',
            $this->assistantRequest(),
        );
        $this->assertSame('sales_inquiry', $providerResult->sources[0]['source_type'] ?? null);
        $this->fakeOpenAiForSource('sales_inquiry');

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'What happened to Inquiry Client sales inquiry?'])
            ->assertOk()
            ->assertJsonFragment(['source_type' => 'sales_inquiry'])
            ->assertJsonFragment(['related_route' => '/pipeline/inquiries/41']);
    }

    public function test_leave_provider_scopes_personal_and_privileged_records(): void
    {
        DB::table('hr_leaves_application')->insert([
            [
                'id' => 51,
                'staff_id' => 7,
                'type' => 'Annual Leave',
                'reason' => 'Personal',
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
                'duration_days' => 1,
                'status' => 'Pending',
                'applied_at' => now(),
            ],
            [
                'id' => 52,
                'staff_id' => 8,
                'type' => 'Medical Leave',
                'reason' => 'Other Staff Private',
                'start_date' => now()->addDays(2)->toDateString(),
                'end_date' => now()->addDays(2)->toDateString(),
                'duration_days' => 1,
                'status' => 'Approved',
                'applied_at' => now(),
            ],
        ]);
        DB::table('hr_leaves_allocation')->insert([
            'staff_id' => 7,
            'leave_type' => 'Annual Leave',
            'year' => now()->year,
            'total_days' => 14,
            'used_days' => 2,
        ]);
        $normalResult = app(LeaveContextProvider::class)->retrieve(
            'normal staff show all staff leave records',
            '',
            $this->assistantRequest(),
        );
        $managerResult = app(LeaveContextProvider::class)->retrieve(
            'manager show all staff leave records',
            '',
            $this->assistantRequest(7, ['Manager']),
        );
        $this->assertSame('/my/leaves', $normalResult->sources[0]['related_route'] ?? null);
        $this->assertSame('/staff/leaves', $managerResult->sources[0]['related_route'] ?? null);
        $this->assertStringNotContainsString('Other Staff Private', json_encode($normalResult->sources));
        $this->assertStringContainsString('Other Staff', json_encode($managerResult->sources));
        $this->fakeOpenAiForSource('leave', function (array $decoded): void {
            $question = (string) ($decoded['question'] ?? '');
            if (str_contains($question, 'normal staff')) {
                $this->assertStringNotContainsString('Other Staff Private', json_encode($decoded));
            }
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'normal staff show all staff leave records'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'leave')
            ->assertJsonPath('answer.sources.0.related_route', '/my/leaves');

        $this->authenticated(1, 7, ['Manager'])
            ->postJson('/knowledge/assistant', ['question' => 'manager show all staff leave records'])
            ->assertOk()
            ->assertJsonFragment(['source_type' => 'leave']);
    }

    public function test_task_provider_scopes_personal_and_manager_records(): void
    {
        DB::table('tasks')->insert([
            [
                'id' => 61,
                'staff_id' => 7,
                'title' => 'Prepare personal report',
                'status' => 'Open',
                'due_date' => now()->addDay()->toDateString(),
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 62,
                'staff_id' => 8,
                'title' => 'Hidden admin task',
                'status' => 'Open',
                'due_date' => now()->addDays(3)->toDateString(),
                'task_category' => 'administrative',
                'effort_score' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('task_comments')->insert([
            'task_id' => 61,
            'comment' => 'Need draft today',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $normalResult = app(TaskContextProvider::class)->retrieve(
            'normal staff show all staff tasks',
            '',
            $this->assistantRequest(),
        );
        $managerResult = app(TaskContextProvider::class)->retrieve(
            'manager show all staff tasks',
            '',
            $this->assistantRequest(7, ['Manager']),
        );
        $this->assertSame('/task-manager', $normalResult->sources[0]['related_route'] ?? null);
        $this->assertSame('/staff/tasks', $managerResult->sources[0]['related_route'] ?? null);
        $this->assertStringNotContainsString('Hidden admin task', json_encode($normalResult->sources));
        $this->assertStringContainsString('Hidden admin task', json_encode($managerResult->sources));
        $this->fakeOpenAiForSource('task', function (array $decoded): void {
            $question = (string) ($decoded['question'] ?? '');
            if (str_contains($question, 'normal staff')) {
                $this->assertStringNotContainsString('Hidden admin task', json_encode($decoded));
            }
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'normal staff show all staff tasks'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'task')
            ->assertJsonPath('answer.sources.0.related_route', '/task-manager');

        $this->authenticated(1, 7, ['Manager'])
            ->postJson('/knowledge/assistant', ['question' => 'manager show all staff tasks'])
            ->assertOk()
            ->assertJsonFragment(['source_type' => 'task']);
    }

    public function test_current_task_detail_route_respects_personal_scope(): void
    {
        DB::table('tasks')->insert([
            [
                'id' => 161,
                'staff_id' => 7,
                'title' => 'Detail personal task',
                'status' => 'Open',
                'due_date' => now()->addDay()->toDateString(),
                'task_category' => 'real_effort',
                'effort_score' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 162,
                'staff_id' => 8,
                'title' => 'Other staff private task',
                'status' => 'Open',
                'due_date' => now()->addDays(2)->toDateString(),
                'task_category' => 'real_effort',
                'effort_score' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('task_comments')->insert([
            'task_id' => 161,
            'comment' => 'Personal detail route comment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provider = app(DetailRecordContextProvider::class);
        $ownResult = $provider->retrieve(
            'Summarize this task',
            '/task-manager/161',
            $this->assistantRequest(7),
        );
        $otherResult = $provider->retrieve(
            'Summarize this task',
            '/task-manager/162',
            $this->assistantRequest(7),
        );
        $managerResult = $provider->retrieve(
            'Summarize this staff task',
            '/staff/tasks/162',
            $this->assistantRequest(7, ['Manager']),
        );

        $this->assertSame('task', $ownResult->sources[0]['source_type'] ?? null);
        $this->assertStringContainsString('Personal detail route comment', json_encode($ownResult->sources[0]));
        $this->assertSame([], $otherResult->sources);
        $this->assertSame('task', $managerResult->sources[0]['source_type'] ?? null);
        $this->assertStringContainsString('Other staff private task', json_encode($managerResult->sources[0]));
    }

    public function test_current_appraisal_detail_route_respects_personal_and_staff_scopes(): void
    {
        DB::table('hr_appraisal')->insert([
            [
                'id' => 171,
                'staff_id' => 7,
                'section' => 'Performance',
                'feedback' => 'Personal appraisal detail feedback',
                'status' => 'Draft',
                'created_by' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 172,
                'staff_id' => 8,
                'section' => 'Performance',
                'feedback' => 'Other staff appraisal feedback',
                'status' => 'Draft',
                'created_by' => 8,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $provider = app(DetailRecordContextProvider::class);
        $ownResult = $provider->retrieve(
            'Summarize this appraisal',
            '/appraisal/records/171',
            $this->assistantRequest(7),
        );
        $otherPersonalResult = $provider->retrieve(
            'Summarize this appraisal',
            '/appraisal/records/172',
            $this->assistantRequest(7),
        );
        $staffRouteDenied = $provider->retrieve(
            'Summarize this staff appraisal',
            '/staff/appraise/records/172',
            $this->assistantRequest(7),
        );
        $staffRouteManager = $provider->retrieve(
            'Summarize this staff appraisal',
            '/staff/appraise/records/172',
            $this->assistantRequest(7, ['Manager']),
        );

        $this->assertSame('appraisal', $ownResult->sources[0]['source_type'] ?? null);
        $this->assertStringContainsString('Personal appraisal detail feedback', json_encode($ownResult->sources[0]));
        $this->assertSame([], $otherPersonalResult->sources);
        $this->assertSame([], $staffRouteDenied->sources);
        $this->assertSame('appraisal', $staffRouteManager->sources[0]['source_type'] ?? null);
        $this->assertStringContainsString('Other staff appraisal feedback', json_encode($staffRouteManager->sources[0]));
    }

    public function test_current_procedure_detail_route_matches_frontend_route_shape(): void
    {
        DB::table('procedures')->insert([
            'id' => 181,
            'title' => 'Procedure Detail Route',
            'description' => 'Procedure detail description.',
            'content' => 'Procedure detail body from frontend route.',
            'status' => 'Published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(DetailRecordContextProvider::class)->retrieve(
            'Summarize this procedure',
            '/administration/procedures/view/181',
            $this->assistantRequest(),
        );

        $this->assertSame('procedure', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/procedures/181', $providerResult->sources[0]['related_route'] ?? null);
        $this->assertStringContainsString('Procedure detail body from frontend route', json_encode($providerResult->sources[0]));
    }

    public function test_current_legal_assessment_query_route_resolves_detail_record(): void
    {
        DB::table('legal_compliance_templates')->insert([
            'id' => 191,
            'name' => 'Smoke Legal Template',
            'description' => 'Template detail linked to legal assessment.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_compliance_assessments')->insert([
            'id' => 192,
            'staff_id' => 7,
            'template_id' => 191,
            'company_name' => 'Smoke Legal Assessment Company',
            'stage' => 'review',
            'nature_of_company' => 'Legal assessment detail body.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(DetailRecordContextProvider::class)->retrieve(
            'Summarize this legal compliance assessment',
            '/internal-tools/legal-compliance?assessmentId=192&mode=review',
            $this->assistantRequest(7),
        );

        $this->assertSame('legal_compliance', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame(
            '/internal-tools/legal-compliance?assessmentId=192',
            $providerResult->sources[0]['related_route'] ?? null,
        );
        $sourceJson = json_encode($providerResult->sources[0]);
        $this->assertStringContainsString('Smoke Legal Assessment Company', $sourceJson);
        $this->assertStringContainsString('Smoke Legal Template', $sourceJson);
    }

    public function test_current_system_admin_whats_new_edit_route_resolves_detail_record(): void
    {
        DB::table('whats_new_notes')->insert([
            'id' => 193,
            'title' => 'Smoke WhatsNew Detail',
            'version' => 'v-smoke',
            'body' => 'WhatsNew edit route detail body.',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $providerResult = app(DetailRecordContextProvider::class)->retrieve(
            'Summarize this release note',
            '/system-admin/whats-new/193/edit',
            $this->assistantRequest(7, ['System Admin']),
        );

        $this->assertSame('whats_new', $providerResult->sources[0]['source_type'] ?? null);
        $this->assertSame('/whats-new/193', $providerResult->sources[0]['related_route'] ?? null);
        $this->assertStringContainsString('WhatsNew edit route detail body', json_encode($providerResult->sources[0]));
    }

    public function test_detail_sanitizer_redacts_sensitive_dynamic_keys(): void
    {
        $payload = app(AssistantContextSanitizer::class)->detail([
            'portal_url' => 'https://portal.example.test',
            'document_path' => 'private/document.pdf',
            'downloadUrl' => 'https://storage.example.test/private/document.pdf',
            'apiSecret' => 'do-not-send',
            'nested' => [
                'accessToken' => 'nested-token',
                'filePath' => 'private/nested.pdf',
                'attachment_original_name' => 'safe-name.pdf',
            ],
        ]);

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('https://portal.example.test', $encoded);
        $this->assertStringContainsString('safe-name.pdf', $encoded);
        $this->assertStringNotContainsString('private/document.pdf', $encoded);
        $this->assertStringNotContainsString('https://storage.example.test/private/document.pdf', $encoded);
        $this->assertStringNotContainsString('do-not-send', $encoded);
        $this->assertStringNotContainsString('nested-token', $encoded);
        $this->assertStringNotContainsString('private/nested.pdf', $encoded);
    }

    public function test_missing_chat_tables_returns_controlled_response(): void
    {
        Schema::dropIfExists('knowledge_assistant_messages');
        Schema::dropIfExists('knowledge_assistant_threads');

        $this->authenticated()
            ->getJson('/knowledge/assistant/thread')
            ->assertOk()
            ->assertJsonPath('messages', []);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Knowledge assistant storage is not ready. Please run database migrations.');
    }

    public function test_new_ask_refreshes_expiry_and_message_cap_removes_oldest_messages(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and complete the quotation form.',
                    'confidence' => 'medium',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                ]),
            ]),
        ]);

        DB::table('knowledge_assistant_threads')->insert([
            'staff_id' => 7,
            'title' => 'Old',
            'expires_at' => now()->addDay(),
            'last_message_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $threadId = (int) DB::table('knowledge_assistant_threads')->where('staff_id', 7)->value('id');
        for ($index = 1; $index <= 20; $index += 1) {
            DB::table('knowledge_assistant_messages')->insert([
                'thread_id' => $threadId,
                'role' => $index % 2 ? 'user' : 'assistant',
                'content' => "old {$index}",
                'created_at' => now()->subMinutes(30 - $index),
                'updated_at' => now()->subMinutes(30 - $index),
            ]);
        }

        $beforeExpiry = DB::table('knowledge_assistant_threads')->where('id', $threadId)->value('expires_at');

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['thread_id' => $threadId, 'question' => 'create quotation'])
            ->assertOk();

        $afterExpiry = DB::table('knowledge_assistant_threads')->where('id', $threadId)->value('expires_at');
        $this->assertTrue(strtotime((string) $afterExpiry) >= strtotime((string) $beforeExpiry));
        $this->assertSame(20, DB::table('knowledge_assistant_messages')->where('thread_id', $threadId)->count());
        $this->assertDatabaseMissing('knowledge_assistant_messages', ['thread_id' => $threadId, 'content' => 'old 1']);
    }

    public function test_user_can_create_multiple_threads_and_ask_in_selected_thread(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Open Quotations and save the quotation.',
                    'confidence' => 'medium',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                ]),
            ]),
        ]);

        $firstThreadId = $this->authenticated()
            ->postJson('/knowledge/assistant/thread')
            ->assertOk()
            ->assertJsonPath('thread.title', 'New chat')
            ->json('thread.id');

        $secondThreadId = $this->authenticated()
            ->postJson('/knowledge/assistant/thread')
            ->assertOk()
            ->json('thread.id');

        $this->assertNotSame($firstThreadId, $secondThreadId);

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'thread_id' => $firstThreadId,
                'question' => 'create quotation',
            ])
            ->assertOk()
            ->assertJsonPath('thread.id', $firstThreadId)
            ->assertJsonPath('thread.title', 'create quotation');

        $this->assertSame(2, DB::table('knowledge_assistant_threads')->where('staff_id', 7)->count());
        $this->assertSame(2, DB::table('knowledge_assistant_messages')->where('thread_id', $firstThreadId)->count());
        $this->assertSame(0, DB::table('knowledge_assistant_messages')->where('thread_id', $secondThreadId)->count());

        $this->authenticated()
            ->getJson('/knowledge/assistant/thread?thread_id='.$firstThreadId)
            ->assertOk()
            ->assertJsonCount(2, 'threads')
            ->assertJsonPath('thread.id', $firstThreadId)
            ->assertJsonPath('messages.0.content', 'create quotation');
    }

    public function test_missing_thread_id_reuses_latest_active_thread(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->first();

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'Grounded answer.',
                    'confidence' => 'medium',
                    'source_slugs' => [$source['slug'] ?? 'how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]);
        });

        $firstThreadId = $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'create quotation',
            ])
            ->assertOk()
            ->json('thread.id');

        $this->authenticated()
            ->postJson('/knowledge/assistant', [
                'question' => 'apply leave',
            ])
            ->assertOk()
            ->assertJsonPath('thread.id', $firstThreadId)
            ->assertJsonCount(4, 'messages');

        $this->assertSame(1, DB::table('knowledge_assistant_threads')->where('staff_id', 7)->count());
        $this->assertSame(4, DB::table('knowledge_assistant_messages')->where('thread_id', $firstThreadId)->count());
        Http::assertSentCount(2);
    }

    public function test_clear_thread_deletes_only_current_user_thread(): void
    {
        $firstThreadId = $this->insertThread(7);
        $secondThreadId = $this->insertThread(8);

        $this->authenticated()
            ->deleteJson('/knowledge/assistant/thread')
            ->assertOk()
            ->assertJsonPath('messages', []);

        $this->assertDatabaseMissing('knowledge_assistant_threads', ['id' => $firstThreadId]);
        $this->assertDatabaseHas('knowledge_assistant_threads', ['id' => $secondThreadId]);
    }

    public function test_clear_specific_thread_keeps_other_current_user_threads(): void
    {
        $firstThreadId = $this->insertThread(7);
        $secondThreadId = $this->insertThread(7);

        $this->authenticated()
            ->deleteJson('/knowledge/assistant/thread/'.$firstThreadId)
            ->assertOk()
            ->assertJsonPath('thread.id', $secondThreadId);

        $this->assertDatabaseMissing('knowledge_assistant_threads', ['id' => $firstThreadId]);
        $this->assertDatabaseHas('knowledge_assistant_threads', ['id' => $secondThreadId]);
    }

    public function test_prune_command_deletes_expired_threads_only(): void
    {
        $expiredId = $this->insertThread(7, now()->subMinute());
        $activeId = $this->insertThread(8, now()->addDay());
        DB::table('assistant_live_result_cache')->insert([
            'cache_key' => sha1('expired-live'),
            'question_hash' => sha1('expired-live'),
            'normalized_question' => 'expired live',
            'provider_key' => 'dashboard',
            'scope_hash' => sha1('scope'),
            'route_hash' => sha1('/dashboard/sales'),
            'source_fingerprint' => sha1('source'),
            'sources_json' => json_encode([]),
            'answer_json' => json_encode(['answer_markdown' => 'old']),
            'refreshed_at' => now()->subMinutes(10),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $exitCode = Artisan::call('knowledge:prune-assistant-chats');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('knowledge_assistant_threads', ['id' => $expiredId]);
        $this->assertDatabaseHas('knowledge_assistant_threads', ['id' => $activeId]);
        $this->assertDatabaseMissing('assistant_live_result_cache', ['normalized_question' => 'expired live']);
    }

    public function test_invalid_openai_schema_returns_safe_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => '',
                    'confidence' => 'certain',
                    'source_slugs' => ['missing-source'],
                    'suggested_queries' => [],
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.sources.0.slug', 'how-to-create-a-quotation')
            ->assertJsonPath('answer.content', 'I found possible Kijo sources, but they do not directly verify an answer to this question. Try asking with a module name, record name, client/project/vendor name, dashboard metric, policy topic, or action.

- How to Create a Quotation');
    }

    public function test_write_action_claim_returns_safe_fallback(): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'I have created the quotation for you. Visit /unknown/generated-route to review it.',
                    'confidence' => 'high',
                    'source_slugs' => ['how-to-create-a-quotation'],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]),
        ]);

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'create quotation'])
            ->assertOk()
            ->assertJsonPath('answer.confidence', 'low')
            ->assertJsonPath('answer.content', 'I found related Kijo sources, but the AI response claimed an action that this read-only assistant cannot perform.

- How to Create a Quotation');
    }

    private function authenticated(int $userId = 1, int $staffId = 7, array $roles = ['Staff'])
    {
        return $this->withSession([
            '_token' => 'test-csrf-token',
            'user_id' => $userId,
            'staff_id' => $staffId,
            'roles' => $roles,
            'name_code' => 'ST'.$staffId,
        ])->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }

    private function assistantRequest(int $staffId = 7, array $roles = ['Staff']): Request
    {
        $request = Request::create('/assistant/test', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('staff_id', $staffId);
        $request->session()->put('roles', $roles);
        $request->session()->put('name_code', 'ST'.$staffId);

        return $request;
    }

    private function fakeOpenAiForSource(string $sourceType, ?callable $inspect = null): void
    {
        config(['services.openai.key' => 'test-key']);
        Http::fake(function ($request) use ($sourceType, $inspect) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', $sourceType)
                ?? collect($decoded['sources'] ?? [])->first();
            if ($inspect) {
                $inspect($decoded, $source);
            }

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'As of now, I found matching live Kijo data for the question.',
                    'confidence' => 'medium',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => [],
                    'freshness_label' => $source['freshness_label'] ?? null,
                    'answer_mode' => 'live',
                ]),
            ]);
        });
    }

    private function insertArticle(array $overrides): void
    {
        $payload = $overrides + [
            'summary' => '',
            'body_html' => '<p>Guide.</p>',
            'category' => 'System',
            'tags' => [],
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload['tags'] = json_encode($payload['tags']);

        DB::table('knowledge_articles')->insert($payload);
    }

    private function insertIhProposal(string $title, string $code): int
    {
        return (int) DB::table('proposal_template_ih')->insertGetId([
            'service_title' => $title,
            'service_code' => $code,
            'introduction' => "{$title} introduction and service background.",
            'objectives' => "{$code} objectives for workplace exposure monitoring.",
            'work_scope' => "{$code} scope, deliverables, reporting, and site activities.",
            'schedule' => 'Schedule to be agreed with client.',
            'reference' => 'Applicable DOSH and industrial hygiene references.',
            'remarks' => "{$code} quotation support content.",
            'proposal_language' => 'en',
            'status' => 'active',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertHandbookVersion(array $content): int
    {
        return (int) DB::table('hr_handbook_versions')->insertGetId([
            'version_label' => 'V1 - Test',
            'content_json' => json_encode(['title' => 'AMIOSH Employee Handbook'] + $content),
            'change_summary' => 'Test handbook.',
            'published_at' => now(),
            'is_current' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_working_time_question_uses_handbook_body_html_source(): void
    {
        $this->insertHandbookVersion([
            'chapters' => [[
                'id' => 'company-policies',
                'title' => '4.0 Company Policies',
                'bodyHtml' => '<h4>Office Hours</h4><p>Default working time is from 8:30 am to 5:30 pm, Monday through Friday.</p><p>Lunch Break is from 1:00 pm to 2:00 pm.</p>',
            ]],
        ]);

        config(['services.openai.key' => 'test-key']);
        $sawWorkingTimeSource = false;
        Http::fake(function ($request) use (&$sawWorkingTimeSource) {
            $decoded = json_decode($request->data()['input'][1]['content'] ?? '{}', true);
            $source = collect($decoded['sources'] ?? [])->firstWhere('source_type', 'handbook');
            $sawWorkingTimeSource = str_contains((string) ($source['excerpt'] ?? ''), 'Default working time is from 8:30 am to 5:30 pm');

            return Http::response([
                'output_text' => json_encode([
                    'answer_markdown' => 'AMIOSH default working time is from 8:30 am to 5:30 pm, Monday through Friday.',
                    'confidence' => 'high',
                    'source_slugs' => [$source['slug']],
                    'suggested_queries' => [],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                ]),
            ]);
        });

        $this->authenticated()
            ->postJson('/knowledge/assistant', ['question' => 'what is working time in amiosh'])
            ->assertOk()
            ->assertJsonPath('answer.sources.0.source_type', 'handbook')
            ->assertJsonPath('answer.confidence', 'high');

        $this->assertTrue($sawWorkingTimeSource);
    }

    public function test_ai_retrieval_planner_validates_domains_and_terms(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.knowledge_assistant.planner_enabled' => true,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'domains' => ['handbook', 'raw_sql', 'proposal_template'],
                    'search_terms' => ['working time', 'SELECT * FROM users', 'CHRA'],
                    'record_refs' => ['CHRA'],
                    'intent' => 'policy_question',
                    'confidence' => 'high',
                    'clarification_question' => null,
                ]),
            ]),
        ]);

        $plan = app(AssistantRetrievalPlanner::class)->plan(
            'what is working time in amiosh',
            '',
            $this->assistantRequest(),
        );

        $this->assertSame(['handbook', 'proposal_template'], $plan->domains);
        $this->assertContains('working time', $plan->searchTerms);
        $this->assertContains('CHRA', $plan->searchTerms);
        $this->assertNotContains('raw_sql', $plan->domains);
        $this->assertNotContains('SELECT * FROM users', $plan->searchTerms);
        $this->assertSame(['CHRA'], $plan->recordRefs);
    }

    public function test_ai_retrieval_planner_failure_falls_back_to_heuristic_plan(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.knowledge_assistant.planner_enabled' => true,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['output_text' => '{not-json'], 200),
        ]);

        $plan = app(AssistantRetrievalPlanner::class)->plan(
            'explain chra service',
            '',
            $this->assistantRequest(),
        );

        $this->assertContains('proposal_template', $plan->domains);
        $this->assertContains('CHRA', $plan->recordRefs);
    }

    public function test_ai_retrieval_planner_does_not_promote_generic_action_words_to_record_refs(): void
    {
        $plan = app(AssistantRetrievalPlanner::class)->plan(
            'How do I create quotation?',
            '',
            $this->assistantRequest(),
        );

        $this->assertNotContains('HOW', $plan->recordRefs);
        $this->assertNotContains('CREATE', $plan->recordRefs);
    }

    private function insertThread(int $staffId, mixed $expiresAt = null): int
    {
        $threadId = (int) DB::table('knowledge_assistant_threads')->insertGetId([
            'staff_id' => $staffId,
            'title' => 'Thread',
            'expires_at' => $expiresAt ?: now()->addDay(),
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('knowledge_assistant_messages')->insert([
            'thread_id' => $threadId,
            'role' => 'user',
            'content' => 'hello',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $threadId;
    }

    private function insertClient(string $name): int
    {
        return (int) DB::table('client_company')->insertGetId([
            'company_name' => $name,
            'client_status' => 'Active',
            'payment_terms_days' => 30,
            'city' => 'Kuala Lumpur',
            'state' => 'Selangor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProject(string $name, string $status, int $clientId, float $quoteValue = 10000): int
    {
        return (int) DB::table('projects_main')->insertGetId([
            'project_name' => $name,
            'project_type' => 'Training',
            'quote_value' => $quoteValue,
            'service_start_date' => now()->startOfMonth()->toDateString(),
            'service_end_date' => now()->endOfMonth()->toDateString(),
            'description' => 'Project for assistant tests.',
            'status' => $status,
            'award_date' => now()->toDateString(),
            'client_id' => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertVendor(string $name): int
    {
        $vendorId = (int) DB::table('vendor_main_details')->insertGetId([
            'vendor_name' => $name,
            'contact_person_name' => 'Vendor Person',
            'mobile_number' => '0123456789',
            'email' => 'safe@example.test',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vendor_categories')->insert([
            'vendor_id' => $vendorId,
            'category' => 'Training',
        ]);

        return $vendorId;
    }
}
