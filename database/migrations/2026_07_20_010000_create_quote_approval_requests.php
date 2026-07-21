<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const QUOTE_TABLES = [
        'quotes_training', 'quotes_ih', 'quotes_manpower', 'quotes_equipment', 'quotes_special',
    ];

    public function up(): void
    {
        Schema::create('quote_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 30);
            $table->unsignedBigInteger('quote_id');
            $table->string('quote_ref_no')->nullable();
            $table->unsignedInteger('revision_no')->default(0);
            $table->string('commercial_fingerprint', 64);
            $table->string('rule_version', 80);
            $table->string('zone', 16);
            $table->string('status', 20);
            $table->string('required_step', 20)->nullable();
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

            $table->index(['service', 'quote_id', 'is_current'], 'quote_approval_quote_current_idx');
            $table->index(['status', 'required_step'], 'quote_approval_pending_step_idx');
        });

        foreach (self::QUOTE_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('approval_request_id')->nullable()->index();
                $table->string('approval_zone', 16)->nullable()->index();
                $table->string('approval_status', 20)->nullable()->index();
                $table->string('approval_fingerprint', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('quotes_manpower', 'estimated_total_cost')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->decimal('estimated_total_cost', 15, 2)->nullable();
            });
        }
        if (! Schema::hasColumn('quotes_manpower', 'traffic_light_rule_version')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->string('traffic_light_rule_version', 80)->nullable();
            });
        }

        if (Schema::hasTable('workflow_templates') && Schema::hasTable('workflow_template_steps')) {
            $now = now();
            $templateId = DB::table('workflow_templates')->where('process_key', 'quote-approval')->value('id');
            if (! $templateId) {
                $templateId = DB::table('workflow_templates')->insertGetId([
                    'process_key' => 'quote-approval',
                    'label' => 'Quotation Approval',
                    'module_key' => 'crm',
                    'route_pattern' => '/crm/records?approval_scope=mine',
                    'enabled' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ([
                ['hod', 10, 'HOD Approval', 'azlin@amiosh.com'],
                ['bd', 20, 'BD Final Approval', 'kamarul@amiosh.com'],
            ] as [$key, $order, $label, $defaultEmail]) {
                DB::table('workflow_template_steps')->updateOrInsert(
                    ['template_id' => $templateId, 'step_key' => $key, 'level_no' => 1],
                    [
                        'sort_order' => $order,
                        'label' => $label,
                        'action_label' => 'Approve',
                        'fallback_roles' => json_encode([]),
                        'active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                if (Schema::hasTable('system_users') && Schema::hasTable('workflow_step_recipients')) {
                    $stepId = DB::table('workflow_template_steps')
                        ->where('template_id', $templateId)
                        ->where('step_key', $key)
                        ->where('level_no', 1)
                        ->value('id');
                    $staffId = DB::table('system_users')
                        ->whereRaw('LOWER(email) = ?', [strtolower($defaultEmail)])
                        ->value('staff_id');
                    if ($stepId && $staffId) {
                        DB::table('workflow_step_recipients')->updateOrInsert(
                            ['step_id' => $stepId, 'staff_id' => $staffId],
                            ['sort_order' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
                        );
                    }
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::QUOTE_TABLES as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropIndex($tableName.'_approval_request_id_index');
                    $table->dropIndex($tableName.'_approval_zone_index');
                    $table->dropIndex($tableName.'_approval_status_index');
                    $table->dropColumn([
                        'approval_request_id', 'approval_zone', 'approval_status', 'approval_fingerprint',
                    ]);
                });
            }
        }
        if (Schema::hasColumn('quotes_manpower', 'traffic_light_rule_version')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->dropColumn('traffic_light_rule_version');
            });
        }
        if (Schema::hasColumn('quotes_manpower', 'estimated_total_cost')) {
            Schema::table('quotes_manpower', function (Blueprint $table): void {
                $table->dropColumn('estimated_total_cost');
            });
        }
        Schema::dropIfExists('quote_approval_requests');

        if (Schema::hasTable('workflow_templates')) {
            DB::table('workflow_templates')->where('process_key', 'quote-approval')->delete();
        }
    }
};
