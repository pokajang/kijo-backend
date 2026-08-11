<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use App\Services\Clients\FirstTouch\ClientFirstTouchNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientFirstTouchNotificationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'in_app_notifications',
            'client_first_touch_evidence',
            'client_first_touch_clarifications',
            'client_first_touch_disputes',
            'client_first_touch_claims',
            'client_first_touch_conflicts',
            'client_company',
            'system_users',
            'staff_general',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->string('full_name');
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('Active');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('client_company', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->primary();
            $table->string('company_name');
        });
        Schema::create('client_first_touch_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('current_claim_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('client_first_touch_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('occurred_on');
            $table->time('occurred_time')->nullable();
            $table->string('source_value');
            $table->string('amiosh_contact_name')->nullable();
            $table->string('referrer_name')->nullable();
            $table->unsignedBigInteger('submitted_by_staff_id');
            $table->string('submitted_by_name');
            $table->timestamp('submitted_at');
        });
        Schema::create('client_first_touch_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('submitted_by_staff_id');
            $table->timestamp('submitted_at');
        });
        Schema::create('client_first_touch_clarifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conflict_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('requested_from_staff_id');
            $table->string('requested_from_name');
            $table->unsignedBigInteger('requested_by_staff_id');
            $table->string('requested_by_name');
            $table->text('request_note');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('client_first_touch_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
        });
        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_staff_id');
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('module_key');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('type');
            $table->string('dedupe_key')->nullable();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity');
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['recipient_staff_id', 'dedupe_key']);
        });

        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'full_name' => 'Independent Manager', 'name_code' => 'MGR', 'email' => 'manager@example.test', 'status' => 'Active'],
            ['staff_id' => 20, 'full_name' => 'Evidence Submitter', 'name_code' => 'SUB', 'email' => 'submitter@example.test', 'status' => 'Active'],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 10, 'email' => 'manager@example.test', 'role' => 'Manager', 'is_active' => 1],
            ['staff_id' => 20, 'email' => 'submitter@example.test', 'role' => 'Staff', 'is_active' => 1],
        ]);
        DB::table('client_company')->insert(['company_id' => 399, 'company_name' => 'Notification Client']);
        DB::table('client_first_touch_conflicts')->insert([
            'id' => 91,
            'client_id' => 399,
            'current_claim_id' => 51,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('client_first_touch_claims')->insert([
            'id' => 51,
            'client_id' => 399,
            'occurred_on' => '2025-01-01',
            'occurred_time' => '09:00:00',
            'source_value' => 'Phone Call',
            'amiosh_contact_name' => 'Aminah',
            'submitted_by_staff_id' => 20,
            'submitted_by_name' => 'Evidence Submitter',
            'submitted_at' => now(),
        ]);
    }

    public function test_conflict_clarification_and_resolution_notifications_follow_the_action_lifecycle(): void
    {
        Queue::fake();
        $service = app(ClientFirstTouchNotificationService::class);

        $service->conflictNeedsReview(91, 20, 'claim:52', true);

        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 10,
            'module_key' => 'client.first-touch',
            'entity_type' => 'client_first_touch_conflict',
            'entity_id' => 91,
            'type' => 'first_touch.conflict.opened',
            'resolved_at' => null,
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'recipient_staff_id' => 20,
            'type' => 'first_touch.conflict.opened',
        ]);

        DB::table('client_first_touch_clarifications')->insert([
            'id' => 71,
            'conflict_id' => 91,
            'client_id' => 399,
            'requested_from_staff_id' => 20,
            'requested_from_name' => 'Evidence Submitter',
            'requested_by_staff_id' => 10,
            'requested_by_name' => 'Independent Manager',
            'request_note' => 'Confirm the encounter date.',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service->clarificationRequested(91, 71, 10);
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 20,
            'entity_type' => 'client_first_touch_clarification',
            'entity_id' => 71,
            'type' => 'first_touch.clarification.requested',
        ]);

        DB::table('client_first_touch_clarifications')->where('id', 71)->update(['status' => 'responded']);
        $service->clarificationResponded(91, 71, 20);
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 10,
            'entity_type' => 'client_first_touch_conflict',
            'entity_id' => 91,
            'type' => 'first_touch.clarification.responded',
            'resolved_at' => null,
        ]);
        $this->assertNotNull(DB::table('in_app_notifications')
            ->where('entity_type', 'client_first_touch_clarification')
            ->where('entity_id', 71)
            ->value('resolved_at'));

        DB::table('client_first_touch_conflicts')->where('id', 91)->update(['status' => 'resolved']);
        $service->conflictResolved(91, 10, 'uphold_current');
        $this->assertDatabaseHas('in_app_notifications', [
            'recipient_staff_id' => 20,
            'module_key' => 'client.first-touch.activity',
            'entity_type' => 'client_first_touch_conflict',
            'entity_id' => 91,
            'type' => 'first_touch.conflict.resolved',
            'resolved_at' => null,
        ]);
        $this->assertSame(0, DB::table('in_app_notifications')
            ->where('recipient_staff_id', 10)
            ->where('module_key', 'client.first-touch')
            ->whereNull('resolved_at')
            ->count());
        Queue::assertPushed(SendHtmlMailJob::class, 4);
    }
}
