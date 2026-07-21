<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuoteApprovalMigrationTest extends TestCase
{
    private const QUOTE_TABLES = [
        'quotes_training', 'quotes_ih', 'quotes_manpower', 'quotes_equipment', 'quotes_special',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'quote_approval_requests', 'workflow_step_recipients', 'workflow_template_steps',
            'workflow_templates', 'system_users', ...self::QUOTE_TABLES,
        ] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (self::QUOTE_TABLES as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->decimal('grand_total', 15, 2)->default(0);
                if (in_array($tableName, ['quotes_training', 'quotes_ih', 'quotes_equipment'], true)) {
                    $table->decimal('estimated_total_cost', 15, 2)->nullable();
                    $table->string('traffic_light_rule_version')->nullable();
                }
            });
        }

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
            $table->unsignedBigInteger('template_id');
            $table->string('step_key');
            $table->unsignedInteger('level_no')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('label');
            $table->string('action_label');
            $table->json('fallback_roles')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['template_id', 'step_key', 'level_no']);
        });
        Schema::create('workflow_step_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('step_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['step_id', 'staff_id']);
        });
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('email');
        });
        DB::table('system_users')->insert([
            ['staff_id' => 11, 'email' => 'azlin@amiosh.com'],
            ['staff_id' => 22, 'email' => 'kamarul@amiosh.com'],
        ]);
    }

    public function test_it_creates_the_workflow_and_seeds_default_approvers(): void
    {
        $migration = require database_path('migrations/2026_07_20_010000_create_quote_approval_requests.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('quote_approval_requests'));
        foreach (self::QUOTE_TABLES as $tableName) {
            $this->assertTrue(Schema::hasColumns($tableName, [
                'approval_request_id', 'approval_zone', 'approval_status', 'approval_fingerprint',
            ]));
        }
        $this->assertTrue(Schema::hasColumn('quotes_manpower', 'estimated_total_cost'));

        $templateId = DB::table('workflow_templates')->where('process_key', 'quote-approval')->value('id');
        $this->assertNotNull($templateId);
        $this->assertSame(
            [11, 22],
            DB::table('workflow_step_recipients')->orderBy('staff_id')->pluck('staff_id')->map(fn ($id) => (int) $id)->all(),
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('quote_approval_requests'));
        $this->assertFalse(Schema::hasColumn('quotes_manpower', 'estimated_total_cost'));
    }
}
