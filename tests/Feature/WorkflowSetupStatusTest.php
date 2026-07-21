<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkflowSetupStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'workflow_actions',
            'workflow_instances',
            'workflow_step_recipients',
            'workflow_template_steps',
            'workflow_templates',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'admin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_05_30_140000_create_workflow_tables.php',
            '--realpath' => false,
        ])->run();
    }

    public function test_setup_status_returns_zero_when_every_active_step_has_recipient(): void
    {
        $this->actingSession()->getJson('/workflows/setup-status')->assertOk();
        $this->addRecipientToEveryActiveStep();

        $this->actingSession()
            ->getJson('/workflows/setup-status')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_missing', 0)
            ->assertJsonPath('data.templates.salary-application.missing', 0)
            ->assertJsonPath('data.templates.vendor-payment.missing', 0)
            ->assertJsonPath('data.templates.leave-application.missing', 0)
            ->assertJsonPath('data.templates.quote-price-exception.missing', 0)
            ->assertJsonPath('data.templates.quote-approval.missing', 0);
    }

    public function test_setup_status_counts_active_steps_without_configured_recipients(): void
    {
        $this->actingSession()
            ->getJson('/workflows/setup-status')
            ->assertOk()
            ->assertJsonPath('data.total_missing', 14)
            ->assertJsonPath('data.templates.salary-application.missing', 2)
            ->assertJsonPath('data.templates.vendor-payment.missing', 3)
            ->assertJsonPath('data.templates.leave-application.missing', 6)
            ->assertJsonPath('data.templates.quote-price-exception.missing', 1)
            ->assertJsonPath('data.templates.quote-approval.missing', 2);
    }

    public function test_setup_status_ignores_inactive_recipients_and_fallback_roles(): void
    {
        $checkStepId = $this->stepId('salary-application', 'check');
        DB::table('workflow_step_recipients')->insert([
            'step_id' => $checkStepId,
            'staff_id' => 30,
            'sort_order' => 0,
            'active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingSession()
            ->getJson('/workflows/setup-status')
            ->assertOk()
            ->assertJsonPath('data.templates.salary-application.missing', 2);
    }

    public function test_setup_status_excludes_inactive_vendor_steps(): void
    {
        DB::table('workflow_template_steps')
            ->whereIn('step_key', ['review', 'approval'])
            ->where('template_id', DB::table('workflow_templates')->where('process_key', 'vendor-payment')->value('id'))
            ->update(['active' => 0]);

        $this->actingSession()
            ->getJson('/workflows/setup-status')
            ->assertOk()
            ->assertJsonPath('data.templates.vendor-payment.missing', 1);
    }

    private function addRecipientToEveryActiveStep(): void
    {
        DB::table('workflow_template_steps')
            ->where('active', 1)
            ->pluck('id')
            ->each(function ($stepId): void {
                DB::table('workflow_step_recipients')->insert([
                    'step_id' => (int) $stepId,
                    'staff_id' => 20 + (int) $stepId,
                    'sort_order' => 0,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    private function stepId(string $templateKey, string $stepKey): int
    {
        return (int) DB::table('workflow_template_steps as step')
            ->join('workflow_templates as template', 'template.id', '=', 'step.template_id')
            ->where('template.process_key', $templateKey)
            ->where('step.step_key', $stepKey)
            ->value('step.id');
    }

    private function actingSession()
    {
        return $this->withSession([
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
        ]);
    }
}
