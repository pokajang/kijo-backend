<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('process_key')->unique();
            $table->string('label');
            $table->string('module_key');
            $table->string('route_pattern')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('workflow_template_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->string('step_key');
            $table->unsignedInteger('level_no')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('label');
            $table->string('action_label');
            $table->json('fallback_roles')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['template_id', 'step_key', 'level_no'], 'workflow_steps_template_key_level_unique');
        });

        Schema::create('workflow_step_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('step_id')->constrained('workflow_template_steps')->cascadeOnDelete();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['step_id', 'staff_id']);
            $table->index('staff_id');
        });

        Schema::create('workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('current_step_id')->nullable()->constrained('workflow_template_steps')->nullOnDelete();
            $table->string('status')->default('Prepared');
            $table->unsignedBigInteger('maker_staff_id')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['template_id', 'status']);
            $table->index('maker_staff_id');
        });

        Schema::create('workflow_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained('workflow_template_steps')->nullOnDelete();
            $table->string('action');
            $table->string('status_from')->nullable();
            $table->string('status_to')->nullable();
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['instance_id', 'action']);
            $table->index('actor_staff_id');
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_step_recipients');
        Schema::dropIfExists('workflow_template_steps');
        Schema::dropIfExists('workflow_templates');
    }

    private function seedDefaults(): void
    {
        $now = now();
        $salaryTemplateId = DB::table('workflow_templates')->insertGetId([
            'process_key' => 'salary-application',
            'label' => 'Salary',
            'module_key' => 'salary',
            'route_pattern' => '/financial/salary-records',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_template_steps')->insert([
            [
                'template_id' => $salaryTemplateId,
                'step_key' => 'check',
                'level_no' => 1,
                'sort_order' => 10,
                'label' => 'Check',
                'action_label' => 'Check',
                'fallback_roles' => json_encode(['Finance', 'Account', 'HR', 'Manager', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $salaryTemplateId,
                'step_key' => 'approve',
                'level_no' => 1,
                'sort_order' => 20,
                'label' => 'Approve',
                'action_label' => 'Approve',
                'fallback_roles' => json_encode(['Manager', 'Finance', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $vendorTemplateId = DB::table('workflow_templates')->insertGetId([
            'process_key' => 'vendor-payment',
            'label' => 'Vendor Payment',
            'module_key' => 'vendor',
            'route_pattern' => '/vendor/payment-records/{id}',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_template_steps')->insert([
            [
                'template_id' => $vendorTemplateId,
                'step_key' => 'review',
                'level_no' => 1,
                'sort_order' => 11,
                'label' => 'Review',
                'action_label' => 'Review',
                'fallback_roles' => json_encode(['Manager', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $vendorTemplateId,
                'step_key' => 'approval',
                'level_no' => 1,
                'sort_order' => 31,
                'label' => 'Approval',
                'action_label' => 'Approve',
                'fallback_roles' => json_encode(['Manager', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $vendorTemplateId,
                'step_key' => 'finance',
                'level_no' => 1,
                'sort_order' => 60,
                'label' => 'Finance',
                'action_label' => 'Mark Paid',
                'fallback_roles' => json_encode(['Finance', 'Account', 'Bank', 'Manager', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $leaveTemplateId = DB::table('workflow_templates')->insertGetId([
            'process_key' => 'leave-application',
            'label' => 'Leave Application',
            'module_key' => 'leave',
            'route_pattern' => '/staff/leaves/records/{id}',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_template_steps')->insert([
            [
                'template_id' => $leaveTemplateId,
                'step_key' => 'leave.submitted.recommenders',
                'level_no' => 1,
                'sort_order' => 10,
                'label' => 'New Application',
                'action_label' => 'Recommend',
                'fallback_roles' => json_encode(['Manager', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $leaveTemplateId,
                'step_key' => 'leave.recommended.approvers',
                'level_no' => 1,
                'sort_order' => 20,
                'label' => 'Recommended Leave',
                'action_label' => 'Approve',
                'fallback_roles' => json_encode(['HR', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $leaveTemplateId,
                'step_key' => 'leave.approved.notify',
                'level_no' => 1,
                'sort_order' => 30,
                'label' => 'Approved Leave',
                'action_label' => 'Notify',
                'fallback_roles' => json_encode([]),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $leaveTemplateId,
                'step_key' => 'leave.rejected.notify',
                'level_no' => 1,
                'sort_order' => 40,
                'label' => 'Rejected Leave',
                'action_label' => 'Notify',
                'fallback_roles' => json_encode([]),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $leaveTemplateId,
                'step_key' => 'leave.cancelled.notify',
                'level_no' => 1,
                'sort_order' => 50,
                'label' => 'Cancelled Leave',
                'action_label' => 'Notify',
                'fallback_roles' => json_encode([]),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'template_id' => $leaveTemplateId,
                'step_key' => 'leave.revoked.notify',
                'level_no' => 1,
                'sort_order' => 60,
                'label' => 'Revoked Leave',
                'action_label' => 'Notify',
                'fallback_roles' => json_encode([]),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $negotiationTemplateId = DB::table('workflow_templates')->insertGetId([
            'process_key' => 'quote-price-exception',
            'label' => 'Negotiation',
            'module_key' => 'crm',
            'route_pattern' => '/crm/price-exceptions/{id}',
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('workflow_template_steps')->insert([
            [
                'template_id' => $negotiationTemplateId,
                'step_key' => 'approve',
                'level_no' => 1,
                'sort_order' => 10,
                'label' => 'Approval',
                'action_label' => 'Approve',
                'fallback_roles' => json_encode(['Manager', 'System Admin']),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
};
