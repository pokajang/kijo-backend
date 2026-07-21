<?php

namespace Tests\Feature;

use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\QuoteApprovals\QuoteApprovalRecipientService;
use App\Services\QuoteApprovals\QuoteApprovalService;
use App\Services\QuoteRecords\QuoteRecordTrainingSpecialService;
use App\Services\QuoteRecords\TrainingQuoteRecordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class QuoteApprovalServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('quote_approval_requests');
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('staff_general');
        Schema::dropIfExists('quotes_training');

        Schema::create('quotes_training', function (Blueprint $table): void {
            $table->id();
            $table->string('quote_ref_no')->nullable();
            $table->unsignedInteger('revision_no')->default(0);
            $table->decimal('grand_total', 15, 2);
            $table->decimal('estimated_total_cost', 15, 2)->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 8, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->string('training_rate_type')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_code')->nullable();
            $table->string('status')->default('Open');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->string('approval_zone')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('approval_fingerprint', 64)->nullable();
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

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_staff_id');
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('module_key');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('type');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('Active');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('email');
            $table->string('role')->nullable();
            $table->boolean('is_active')->default(true);
        });
        DB::table('staff_general')->insert([
            ['staff_id' => 11, 'full_name' => 'Azlin', 'name_code' => null, 'email' => 'azlin@amiosh.com'],
            ['staff_id' => 22, 'full_name' => 'Kamarul', 'name_code' => null, 'email' => 'kamarul@amiosh.com'],
            ['staff_id' => 33, 'full_name' => 'Requester', 'name_code' => 'REQ', 'email' => 'requester@example.com'],
            ['staff_id' => 44, 'full_name' => 'System Administrator', 'name_code' => null, 'email' => 'admin@example.com'],
            ['staff_id' => 45, 'full_name' => 'Admin Assistant', 'name_code' => null, 'email' => 'admin-assistant@example.com'],
        ]);
        DB::table('system_users')->insert([
            ['staff_id' => 11, 'email' => 'azlin@amiosh.com', 'role' => 'Manager'],
            ['staff_id' => 22, 'email' => 'kamarul@amiosh.com', 'role' => 'Manager'],
            ['staff_id' => 33, 'email' => 'requester-account@example.com', 'role' => 'Manager'],
            ['staff_id' => 44, 'email' => 'admin@example.com', 'role' => 'System Admin'],
            ['staff_id' => 45, 'email' => 'admin-assistant@example.com', 'role' => 'System Admin Assistant'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('quote_approval_requests');
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('system_users');
        Schema::dropIfExists('staff_general');
        Schema::dropIfExists('quotes_training');
        parent::tearDown();
    }

    public function test_green_quote_is_automatically_approved(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-001',
            'grand_total' => 140,
            'estimated_total_cost' => 100,
        ]);

        $approval = app(QuoteApprovalService::class)->current('training', $quoteId, false);

        $this->assertSame('green', $approval->zone);
        $this->assertSame('approved', $approval->status);
        $this->assertNull($approval->required_step);
        $this->assertNull(app(QuoteApprovalService::class)->issuanceDenial('training', $quoteId));
    }

    public function test_listing_approvals_does_not_backfill_or_notify_legacy_open_quotes(): void
    {
        DB::table('quotes_training')->insert([
            'quote_ref_no' => 'QTR-LEGACY',
            'grand_total' => 100,
            'estimated_total_cost' => null,
            'status' => 'Open',
        ]);

        $items = app(QuoteApprovalService::class)->listFor($this->requestForStaff(11));

        $this->assertSame([], $items);
        $this->assertSame(0, DB::table('quote_approval_requests')->count());
        $this->assertSame(0, DB::table('in_app_notifications')->count());
    }

    public function test_yellow_quote_requires_hod_and_commercial_change_supersedes_it(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-002',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $first = $service->current('training', $quoteId, false);

        $this->assertSame('yellow', $first->zone);
        $this->assertSame('pending', $first->status);
        $this->assertSame('hod', $first->required_step);
        $this->assertSame('QUOTE_APPROVAL_REQUIRED', $service->issuanceDenial('training', $quoteId)['code']);

        DB::table('quotes_training')->where('id', $quoteId)->update([
            'grand_total' => 145,
            'revision_no' => 1,
        ]);
        $second = $service->current('training', $quoteId, false);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('green', $second->zone);
        $this->assertSame('approved', $second->status);
        $this->assertFalse((bool) DB::table('quote_approval_requests')->where('id', $first->id)->value('is_current'));
    }

    public function test_training_discount_boundaries_and_special_pricing_follow_the_matrix(): void
    {
        $service = app(QuoteApprovalService::class);
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-DISCOUNT',
            'grand_total' => 150,
            'estimated_total_cost' => 100,
            'discount_type' => 'Special',
            'discount_value' => 30,
            'discount_amount' => 30,
            'subtotal' => 120,
        ]);

        $twentyPercent = $service->current('training', $quoteId, false);
        $this->assertSame('yellow', $twentyPercent->zone);

        DB::table('quotes_training')->where('id', $quoteId)->update([
            'discount_value' => 30.01,
            'discount_amount' => 30.01,
        ]);
        $aboveTwentyPercent = $service->current('training', $quoteId, false);
        $this->assertSame('red', $aboveTwentyPercent->zone);

        DB::table('quotes_training')->where('id', $quoteId)->update([
            'discount_value' => 0,
            'discount_amount' => 0,
            'subtotal' => 150,
            'training_rate_type' => 'client_site_special_approval',
        ]);
        $specialPricing = $service->current('training', $quoteId, false);
        $this->assertSame('red', $specialPricing->zone);
        $this->assertNotSame($aboveTwentyPercent->id, $specialPricing->id);
    }

    public function test_superseding_a_request_resolves_its_stale_notification(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-003',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $first = $service->current('training', $quoteId, false);
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 11,
            'module_key' => 'crm.quote-approvals',
            'entity_type' => 'quote_approval_request',
            'entity_id' => $first->id,
            'type' => 'quote.approval.pending',
            'title' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quotes_training')->where('id', $quoteId)->update([
            'grand_total' => 145,
            'revision_no' => 1,
        ]);
        $service->current('training', $quoteId, false);

        $this->assertNotNull(DB::table('in_app_notifications')->where('entity_id', $first->id)->value('resolved_at'));
    }

    public function test_failed_quote_cancels_pending_approval_and_resolves_notification(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-004',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);
        DB::table('in_app_notifications')->insert([
            'recipient_staff_id' => 11,
            'module_key' => 'crm.quote-approvals',
            'entity_type' => 'quote_approval_request',
            'entity_id' => $approval->id,
            'type' => 'quote.approval.pending',
            'title' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('quotes_training')->where('id', $quoteId)->update(['status' => 'Failed']);
        $service->cancelCurrent('training', $quoteId, 'Quotation marked as Failed.');

        $this->assertSame('cancelled', DB::table('quote_approval_requests')->where('id', $approval->id)->value('status'));
        $this->assertSame('cancelled', DB::table('quotes_training')->where('id', $quoteId)->value('approval_status'));
        $this->assertNotNull(DB::table('in_app_notifications')->where('entity_id', $approval->id)->value('resolved_at'));
        $this->assertSame($approval->id, $service->current('training', $quoteId, false)->id);
        $this->assertSame(1, DB::table('quote_approval_requests')->where('quote_id', $quoteId)->count());
    }

    public function test_reopened_quote_gets_a_new_request_after_cancellation(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-REOPEN',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $cancelled = $service->current('training', $quoteId, false);
        DB::table('quotes_training')->where('id', $quoteId)->update(['status' => 'Failed']);
        $service->cancelCurrent('training', $quoteId, 'Quotation marked as Failed.');

        DB::table('quotes_training')->where('id', $quoteId)->update(['status' => 'Open']);
        $reopened = $service->current('training', $quoteId, false);

        $this->assertNotSame($cancelled->id, $reopened->id);
        $this->assertSame('pending', $reopened->status);
        $this->assertFalse((bool) DB::table('quote_approval_requests')->where('id', $cancelled->id)->value('is_current'));
    }

    public function test_stale_request_cannot_be_approved_after_commercial_change(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-005',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);
        DB::table('quotes_training')->where('id', $quoteId)->update([
            'grand_total' => 145,
            'revision_no' => 1,
        ]);
        $request = Request::create('/quote-approvals/'.$approval->id.'/approve', 'PATCH');

        try {
            $service->decide((int) $approval->id, $request, 'approve');
            $this->fail('Expected stale approval request to be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('QUOTE_APPROVAL_STALE', $exception->getResponse()->getData(true)['code']);
        }

        $this->assertSame('pending', DB::table('quote_approval_requests')->where('id', $approval->id)->value('status'));
    }

    public function test_default_email_accounts_are_authorized_only_for_their_assigned_step(): void
    {
        $recipients = app(QuoteApprovalRecipientService::class);
        $hodRequest = $this->requestForStaff(11);
        $this->assertTrue($recipients->canDecide($hodRequest, 'hod'));
        $this->assertFalse($recipients->canDecide($hodRequest, 'bd'));

        $bdRequest = $this->requestForStaff(22);
        $this->assertTrue($recipients->canDecide($bdRequest, 'bd'));
        $this->assertFalse($recipients->canDecide($bdRequest, 'hod'));
    }

    public function test_system_admin_has_break_glass_approval_access(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-SYSADMIN',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);
        $systemAdmin = $this->requestForStaff(44, ['System Admin']);

        $listed = collect($service->listFor($systemAdmin))->firstWhere('id', (int) $approval->id);
        $this->assertTrue($listed['can_decide']);

        $service->decide((int) $approval->id, $systemAdmin, 'approve');
        $this->assertSame('approved', DB::table('quote_approval_requests')->where('id', $approval->id)->value('status'));
    }

    public function test_pending_request_notifies_step_recipient_and_system_admin(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-NOTIFY',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);
        $approval = app(QuoteApprovalService::class)->current('training', $quoteId);

        $notifiedStaff = DB::table('in_app_notifications')
            ->where('entity_id', $approval->id)
            ->orderBy('recipient_staff_id')
            ->pluck('recipient_staff_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->assertSame([11, 44], $notifiedStaff);

        Bus::assertDispatched(SendHtmlMailJob::class, 2);
        foreach (['azlin@amiosh.com', 'admin@example.com'] as $email) {
            Bus::assertDispatched(SendHtmlMailJob::class, fn (SendHtmlMailJob $job): bool => $this->jobProperty($job, 'to') === $email);
        }
    }

    public function test_approval_details_are_visible_only_to_owner_approver_or_system_admin(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-PRIVATE',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
            'created_by_id' => 33,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);

        $this->assertSame([], $service->listFor($this->requestForStaff(55)));
        $this->assertNull($service->show((int) $approval->id, $this->requestForStaff(55)));
        $this->assertNull($service->show((int) $approval->id, $this->requestForStaff(22)));
        $this->assertNotNull($service->show((int) $approval->id, $this->requestForStaff(33)));
        $this->assertNotNull($service->show((int) $approval->id, $this->requestForStaff(11)));
        $this->assertNotNull($service->show((int) $approval->id, $this->requestForStaff(44, ['System Admin'])));
    }

    public function test_pricing_changes_supersede_an_approved_request_and_recalculate_the_route(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-REPRICE',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
            'created_by_id' => 33,
        ]);
        $service = app(QuoteApprovalService::class);
        $yellow = $service->current('training', $quoteId, false);
        $service->decide((int) $yellow->id, $this->requestForStaff(11), 'approve');

        DB::table('quotes_training')->where('id', $quoteId)->update([
            'grand_total' => 119,
            'revision_no' => 1,
        ]);
        $red = $service->current('training', $quoteId, false);
        $this->assertNotSame($yellow->id, $red->id);
        $this->assertSame('red', $red->zone);
        $this->assertSame('bd', $red->required_step);
        $this->assertSame('pending', $red->status);

        DB::table('quotes_training')->where('id', $quoteId)->update([
            'grand_total' => 145,
            'revision_no' => 2,
        ]);
        $green = $service->current('training', $quoteId, false);
        $this->assertNotSame($red->id, $green->id);
        $this->assertSame('green', $green->zone);
        $this->assertSame('approved', $green->status);
    }

    public function test_reaward_is_blocked_while_repricing_approval_is_pending(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-REAWARD-GATE',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
            'status' => 'Awarded',
            'created_by_id' => 33,
        ]);
        app(QuoteApprovalService::class)->current('training', $quoteId, false);

        $request = AwardQuoteRequest::create('/quote-records/training/'.$quoteId.'/re-award', 'POST', [
            'quote_id' => $quoteId,
        ]);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('staff_id', 33);
        $request->session()->put('roles', ['Manager']);

        $workflow = Mockery::mock(TrainingQuoteRecordService::class);
        $workflow->shouldNotReceive('reAwardTraining');
        app()->instance(TrainingQuoteRecordService::class, $workflow);

        $response = app(QuoteRecordTrainingSpecialService::class)->reAwardTraining($request);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('QUOTE_APPROVAL_REQUIRED', $response->getData(true)['code']);
    }

    public function test_legacy_creator_code_is_resolved_to_a_preparer_identity(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-LEGACY-OWNER',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
            'created_by_id' => 999,
            'created_by_code' => 'req',
        ]);

        $approval = app(QuoteApprovalService::class)->current('training', $quoteId, false);

        $this->assertSame(33, (int) $approval->requested_by_id);
        $this->assertSame(33, (int) DB::table('quotes_training')->where('id', $quoteId)->value('created_by_id'));
    }

    public function test_only_the_hod_recipient_can_decide_a_yellow_request(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-006',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
            'created_by_id' => 33,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);

        $hodPreview = $this->requestForStaff(11);
        $hodPreview->query->set('approval_preview', '1');
        $this->assertNull($service->issuanceDenial('training', $quoteId, $hodPreview));
        $bdPreview = $this->requestForStaff(22);
        $bdPreview->query->set('approval_preview', '1');
        $this->assertSame(
            'QUOTE_APPROVAL_REQUIRED',
            $service->issuanceDenial('training', $quoteId, $bdPreview)['code'],
        );

        try {
            $service->decide((int) $approval->id, $this->requestForStaff(22), 'approve');
            $this->fail('Expected the BD recipient to be denied for an HOD step.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
        }
        $this->assertSame('pending', DB::table('quote_approval_requests')->where('id', $approval->id)->value('status'));

        $service->decide((int) $approval->id, $this->requestForStaff(11), 'approve');
        $this->assertSame('approved', DB::table('quote_approval_requests')->where('id', $approval->id)->value('status'));
        $this->assertSame('approved', DB::table('quotes_training')->where('id', $quoteId)->value('approval_status'));
        $requesterNotification = DB::table('in_app_notifications')
            ->where('recipient_staff_id', 33)
            ->where('entity_id', $approval->id)
            ->first();
        $this->assertNotNull($requesterNotification);
        $this->assertStringNotContainsString('approval_scope=mine', (string) $requesterNotification->route);
        Bus::assertDispatched(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            return $this->jobProperty($job, 'to') === 'requester@example.com'
                && str_contains((string) $this->jobProperty($job, 'subject'), 'Quotation Approved');
        });
    }

    public function test_decision_email_falls_back_to_the_preparer_login_account(): void
    {
        Bus::fake([SendHtmlMailJob::class]);
        DB::table('staff_general')->where('staff_id', 33)->update(['email' => null]);

        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-OWNER-EMAIL-FALLBACK',
            'grand_total' => 130,
            'estimated_total_cost' => 100,
            'created_by_id' => 33,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);

        $service->decide((int) $approval->id, $this->requestForStaff(11), 'approve');

        Bus::assertDispatched(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            return $this->jobProperty($job, 'to') === 'requester-account@example.com'
                && str_contains((string) $this->jobProperty($job, 'subject'), 'Quotation Approved');
        });
    }

    public function test_only_the_bd_recipient_can_decide_a_red_request(): void
    {
        $quoteId = DB::table('quotes_training')->insertGetId([
            'quote_ref_no' => 'QTR-RED',
            'grand_total' => 124,
            'estimated_total_cost' => 100,
        ]);
        $service = app(QuoteApprovalService::class);
        $approval = $service->current('training', $quoteId, false);

        $this->assertSame('red', $approval->zone);
        $this->assertSame('bd', $approval->required_step);

        try {
            $service->decide((int) $approval->id, $this->requestForStaff(11), 'approve');
            $this->fail('Expected the HOD recipient to be denied for a BD step.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
        }

        $service->decide((int) $approval->id, $this->requestForStaff(22), 'approve');
        $this->assertSame('approved', DB::table('quote_approval_requests')->where('id', $approval->id)->value('status'));
    }

    private function requestForStaff(int $staffId, array $roles = ['Manager']): Request
    {
        $request = Request::create('/quote-approvals', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('staff_id', $staffId);
        $request->session()->put('roles', $roles);

        return $request;
    }

    private function jobProperty(object $job, string $property): mixed
    {
        $reflection = new \ReflectionProperty($job, $property);

        return $reflection->getValue($job);
    }
}
