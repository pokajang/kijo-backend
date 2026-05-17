<?php

namespace Tests\Feature;

use App\Services\Meetings\MeetingActionItemService;
use App\Services\Meetings\MeetingPdfService;
use App\Services\Meetings\MeetingQueryService;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\MeetingVerificationService;
use App\Http\Controllers\Api\MeetingController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeetingDraftFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('meeting_minute_comments');
        Schema::dropIfExists('meeting_minute_audit_logs');
        Schema::dropIfExists('meeting_minute_attendees');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('staff_general');

        Schema::create('staff_general', function (Blueprint $table) {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name');
            $table->string('name_code');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('meeting_title');
            $table->string('meeting_type', 20)->default('Ad Hoc');
            $table->dateTime('meeting_datetime');
            $table->string('venue')->nullable();
            $table->text('guest_attendees_text')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('minutes_text');
            $table->text('action_items')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedInteger('created_by');
            $table->string('created_name', 120);
            $table->string('created_code', 50);
            $table->unsignedInteger('updated_by')->nullable();
            $table->string('updated_name', 120)->nullable();
            $table->string('updated_code', 50)->nullable();
            $table->string('record_status', 20)->default('Complete');
            $table->string('draft_key', 64)->nullable();
            $table->string('verification_status', 30)->default('Pending');
            $table->unsignedInteger('verified_by')->nullable();
            $table->string('verified_name', 120)->nullable();
            $table->string('verified_code', 50)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedInteger('concurred_by')->nullable();
            $table->string('concurred_name', 120)->nullable();
            $table->string('concurred_code', 50)->nullable();
            $table->dateTime('concurred_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'draft_key']);
        });

        Schema::create('meeting_minute_attendees', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_id');
            $table->unsignedInteger('staff_id');
            $table->string('staff_name');
            $table->string('staff_code', 50)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('meeting_minute_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_id');
            $table->string('action_type', 60);
            $table->string('action_summary')->nullable();
            $table->text('changed_fields')->nullable();
            $table->unsignedInteger('actor_id');
            $table->string('actor_name', 120);
            $table->string('actor_code', 50);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('meeting_minute_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_id');
            $table->string('comment_type', 60);
            $table->text('comment_text');
            $table->unsignedInteger('actor_id');
            $table->string('actor_name', 120);
            $table->string('actor_code', 50);
            $table->timestamp('created_at')->nullable();
        });

        DB::table('staff_general')->insert([
            ['staff_id' => 42, 'full_name' => 'Creator User', 'name_code' => 'CU'],
            ['staff_id' => 77, 'full_name' => 'Other User', 'name_code' => 'OU'],
        ]);
    }

    public function test_details_stage_create_returns_draft(): void
    {
        $response = app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-001',
        ]));
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Draft', $body['record_status']);
        $this->assertTrue($body['is_draft']);
        $this->assertDatabaseHas('meeting_minutes', [
            'id' => $body['id'],
            'record_status' => 'Draft',
            'draft_key' => 'draft-key-001',
        ]);
    }

    public function test_same_creator_and_draft_key_updates_existing_draft(): void
    {
        $service = app(MeetingService::class);
        $first = $service->store($this->meetingRequest([
            'meeting_title' => 'Original Draft',
            'form_stage' => 'details',
            'draft_key' => 'draft-key-002',
        ]))->getData(true);
        $second = $service->store($this->meetingRequest([
            'meeting_title' => 'Updated Draft',
            'form_stage' => 'details',
            'draft_key' => 'draft-key-002',
        ]))->getData(true);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DB::table('meeting_minutes')->count());
        $this->assertDatabaseHas('meeting_minutes', [
            'id' => $first['id'],
            'meeting_title' => 'Updated Draft',
        ]);
    }

    public function test_same_draft_key_from_different_creators_does_not_collide(): void
    {
        app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'shared-key',
        ]));
        app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'shared-key',
        ], ['staff_id' => 77, 'full_name' => 'Other User', 'name_code' => 'OU']));

        $this->assertSame(2, DB::table('meeting_minutes')->count());
    }

    public function test_notes_stage_finalizes_draft(): void
    {
        $created = app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-003',
        ]))->getData(true);

        $response = app(MeetingService::class)->update($this->meetingRequest([
            'form_stage' => 'notes',
            'minutes_text' => '<p>Final minutes</p>',
            'draft_key' => 'draft-key-003',
        ]), (int) $created['id']);
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Complete', $body['record_status']);
        $this->assertFalse($body['is_draft']);
        $this->assertDatabaseHas('meeting_minutes', [
            'id' => $created['id'],
            'record_status' => 'Complete',
            'draft_key' => null,
        ]);
    }

    public function test_non_creator_cannot_query_creator_draft(): void
    {
        $created = app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-004',
        ]))->getData(true);

        $listBody = app(MeetingQueryService::class)
            ->index($this->request('GET', [], ['staff_id' => 77, 'full_name' => 'Other User', 'name_code' => 'OU']))
            ->getData(true);
        $showBody = app(MeetingQueryService::class)
            ->show($this->request('GET', [], ['staff_id' => 77, 'full_name' => 'Other User', 'name_code' => 'OU']), (int) $created['id'])
            ->getData(true);

        $this->assertSame([], $listBody['items']);
        $this->assertSame([], $showBody['items']);
    }

    public function test_draft_rejects_pdf_verification_and_action_item_mutations(): void
    {
        $created = app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-005',
        ]))->getData(true);

        $pdf = app(MeetingPdfService::class)->export($this->request('GET'), (int) $created['id']);
        $verification = app(MeetingVerificationService::class)->update($this->request('POST', [
            'meeting_id' => $created['id'],
            'action' => 'verify',
        ], ['roles' => ['Admin']]));
        $action = app(MeetingActionItemService::class)->add($this->request('POST', [
            'meeting_id' => $created['id'],
            'action_text' => 'Follow up',
        ]));

        $this->assertSame(400, $pdf->getStatusCode());
        $this->assertSame(400, $verification->getStatusCode());
        $this->assertSame(400, $action->getStatusCode());
    }

    public function test_discard_draft_keeps_audit_but_hides_record(): void
    {
        $created = app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-006',
        ]))->getData(true);

        $response = app(MeetingService::class)->destroy($this->request('DELETE'), (int) $created['id']);
        $visibleBody = app(MeetingQueryService::class)
            ->show($this->request('GET'), (int) $created['id'])
            ->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('meeting_minutes', [
            'id' => $created['id'],
            'record_status' => 'Discarded',
            'draft_key' => null,
            'meeting_title' => '[Discarded draft]',
        ]);
        $this->assertDatabaseHas('meeting_minute_audit_logs', [
            'meeting_id' => $created['id'],
            'action_type' => 'DISCARD_DRAFT',
        ]);
        $this->assertSame([], $visibleBody['items']);
    }

    public function test_discarded_draft_rejects_mutations(): void
    {
        $created = app(MeetingService::class)->store($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-007',
        ]))->getData(true);
        app(MeetingService::class)->destroy($this->request('DELETE'), (int) $created['id']);

        $update = app(MeetingService::class)->update($this->meetingRequest([
            'form_stage' => 'details',
            'draft_key' => 'draft-key-007',
        ]), (int) $created['id']);
        $pdf = app(MeetingPdfService::class)->export($this->request('GET'), (int) $created['id']);

        $this->assertSame(404, $update->getStatusCode());
        $this->assertSame(400, $pdf->getStatusCode());
    }

    public function test_invalid_payloads_still_fail(): void
    {
        $missingTitle = app(MeetingService::class)->store($this->meetingRequest([
            'meeting_title' => '',
            'form_stage' => 'details',
        ]));
        $invalidDate = app(MeetingService::class)->store($this->meetingRequest([
            'meeting_datetime' => '17-05-2026',
            'form_stage' => 'details',
        ]));
        $invalidAttendee = app(MeetingService::class)->store($this->meetingRequest([
            'attendee_ids' => json_encode([999]),
            'form_stage' => 'details',
        ]));

        $this->assertSame(400, $missingTitle->getStatusCode());
        $this->assertSame(400, $invalidDate->getStatusCode());
        $this->assertSame(400, $invalidAttendee->getStatusCode());
    }

    public function test_controller_bootstrap_repairs_missing_draft_columns(): void
    {
        $this->recreateLegacyMeetingTablesWithoutDraftColumns();

        DB::table('meeting_minutes')->insert([
            'id' => 1,
            'meeting_title' => 'Legacy Meeting',
            'meeting_type' => 'Ad Hoc',
            'meeting_datetime' => '2026-05-17 09:30:00',
            'venue' => 'Room A',
            'guest_attendees_text' => null,
            'agenda' => null,
            'minutes_text' => 'Legacy complete minutes',
            'action_items' => null,
            'attachment_path' => null,
            'attachment_name' => null,
            'attachment_size' => null,
            'attachment_mime' => null,
            'created_by' => 42,
            'created_name' => 'Creator User',
            'created_code' => 'CU',
            'updated_by' => 42,
            'updated_name' => 'Creator User',
            'updated_code' => 'CU',
            'verification_status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = app(MeetingController::class)->index($this->request('GET'));
        $body = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(Schema::hasColumn('meeting_minutes', 'record_status'));
        $this->assertTrue(Schema::hasColumn('meeting_minutes', 'draft_key'));
        $this->assertSame('Complete', $body['items'][0]['record_status']);
        $this->assertFalse($body['items'][0]['is_draft']);
    }

    public function test_controller_bootstrap_clears_duplicate_draft_keys_before_unique_index(): void
    {
        $this->recreateLegacyMeetingTablesWithoutDraftColumns(withDraftColumns: true);

        foreach ([1, 2] as $id) {
            DB::table('meeting_minutes')->insert([
                'id' => $id,
                'meeting_title' => "Draft {$id}",
                'meeting_type' => 'Ad Hoc',
                'meeting_datetime' => '2026-05-17 09:30:00',
                'venue' => 'Room A',
                'guest_attendees_text' => null,
                'agenda' => null,
                'minutes_text' => '',
                'action_items' => null,
                'attachment_path' => null,
                'attachment_name' => null,
                'attachment_size' => null,
                'attachment_mime' => null,
                'created_by' => 42,
                'created_name' => 'Creator User',
                'created_code' => 'CU',
                'updated_by' => 42,
                'updated_name' => 'Creator User',
                'updated_code' => 'CU',
                'record_status' => 'Draft',
                'draft_key' => 'duplicate-key',
                'verification_status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = app(MeetingController::class)->index($this->request('GET'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(DB::table('meeting_minutes')->where('id', 1)->value('draft_key'));
        $this->assertSame('duplicate-key', DB::table('meeting_minutes')->where('id', 2)->value('draft_key'));
    }

    private function meetingRequest(array $payload = [], array $session = []): Request
    {
        return $this->request('POST', array_merge([
            'meeting_title' => 'Operations Sync',
            'meeting_type' => 'Ad Hoc',
            'meeting_datetime' => '2026-05-17T09:30',
            'venue' => 'Room A',
            'guest_attendees_text' => '',
            'agenda' => '',
            'minutes_text' => '',
            'action_items' => '',
            'attendee_ids' => json_encode([42]),
            'form_stage' => 'details',
        ], $payload), $session);
    }

    private function request(string $method, array $payload = [], array $session = []): Request
    {
        $request = Request::create('/meetings', $method, $payload);
        $store = app('session')->driver();
        $store->start();
        foreach (array_merge([
            'staff_id' => 42,
            'full_name' => 'Creator User',
            'name_code' => 'CU',
            'roles' => ['Staff'],
        ], $session) as $key => $value) {
            $store->put($key, $value);
        }
        $request->setLaravelSession($store);

        return $request;
    }

    private function recreateLegacyMeetingTablesWithoutDraftColumns(bool $withDraftColumns = false): void
    {
        Schema::dropIfExists('meeting_minute_comments');
        Schema::dropIfExists('meeting_minute_audit_logs');
        Schema::dropIfExists('meeting_minute_attendees');
        Schema::dropIfExists('meeting_minutes');

        Schema::create('meeting_minutes', function (Blueprint $table) use ($withDraftColumns) {
            $table->increments('id');
            $table->string('meeting_title');
            $table->string('meeting_type', 20)->default('Ad Hoc');
            $table->dateTime('meeting_datetime');
            $table->string('venue')->nullable();
            $table->text('guest_attendees_text')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('minutes_text');
            $table->text('action_items')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedInteger('created_by');
            $table->string('created_name', 120);
            $table->string('created_code', 50);
            $table->unsignedInteger('updated_by')->nullable();
            $table->string('updated_name', 120)->nullable();
            $table->string('updated_code', 50)->nullable();
            if ($withDraftColumns) {
                $table->string('record_status', 20)->default('Complete');
                $table->string('draft_key', 64)->nullable();
            }
            $table->string('verification_status', 30)->default('Pending');
            $table->unsignedInteger('verified_by')->nullable();
            $table->string('verified_name', 120)->nullable();
            $table->string('verified_code', 50)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedInteger('concurred_by')->nullable();
            $table->string('concurred_name', 120)->nullable();
            $table->string('concurred_code', 50)->nullable();
            $table->dateTime('concurred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_minute_attendees', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_id');
            $table->unsignedInteger('staff_id');
            $table->string('staff_name');
            $table->string('staff_code', 50)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('meeting_minute_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_id');
            $table->string('action_type', 60);
            $table->string('action_summary')->nullable();
            $table->text('changed_fields')->nullable();
            $table->unsignedInteger('actor_id');
            $table->string('actor_name', 120);
            $table->string('actor_code', 50);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('meeting_minute_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_id');
            $table->string('comment_type', 60);
            $table->text('comment_text');
            $table->unsignedInteger('actor_id');
            $table->string('actor_name', 120);
            $table->string('actor_code', 50);
            $table->timestamp('created_at')->nullable();
        });
    }
}
