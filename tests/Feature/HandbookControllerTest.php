<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HandbookController;
use App\Services\Handbook\HandbookAcknowledgementService;
use App\Services\Signatures\StaffSignatureService;
use App\Support\AppFilePaths;
use Database\Seeders\PublishHandbookRev02Seeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HandbookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('hr_handbook_draft_changes');
        Schema::dropIfExists('hr_handbook_drafts');
        Schema::dropIfExists('hr_handbook_change_logs');
        Schema::dropIfExists('hr_handbook_sign_declarations');
        Schema::dropIfExists('hr_handbook_versions');
        Schema::dropIfExists('hr_handbook_sign');
        Schema::dropIfExists('staff_profile');
        Schema::dropIfExists('staff_general');

        Schema::create('hr_handbook_versions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('version_label', 80);
            $table->longText('content_json');
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedSmallInteger('acknowledgement_schema_version')->nullable();
            $table->char('acknowledgement_sha256', 64)->nullable();
            $table->text('change_summary')->nullable();
            $table->unsignedInteger('published_by_staff_id')->nullable();
            $table->string('published_by_name_code', 50)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->unsignedTinyInteger('current_version_guard')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('hr_handbook_change_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('handbook_version_id');
            $table->string('action', 50);
            $table->string('section_id', 80)->nullable();
            $table->string('section_title')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('changed_by_staff_id')->nullable();
            $table->string('changed_by_name_code', 50)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_handbook_sign', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('handbook_version_id')->nullable();
            $table->unsignedInteger('staff_id');
            $table->string('full_name');
            $table->string('ic_number', 50);
            $table->timestamp('signed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('submission_uuid')->nullable()->unique();
            $table->unsignedSmallInteger('evidence_schema_version')->nullable();
            $table->string('employee_code_snapshot', 50)->nullable();
            $table->string('designation_snapshot')->nullable();
            $table->string('department_snapshot')->nullable();
            $table->text('identity_number_encrypted')->nullable();
            $table->string('signature_method', 50)->nullable();
            $table->string('signature_snapshot_path', 500)->nullable();
            $table->char('signature_sha256', 64)->nullable();
            $table->char('handbook_content_sha256', 64)->nullable();
            $table->char('acknowledgement_sha256', 64)->nullable();
            $table->longText('evidence_payload_json')->nullable();
            $table->char('signed_payload_sha256', 64)->nullable();
            $table->char('evidence_hmac', 64)->nullable();
            $table->string('evidence_key_id', 50)->nullable();
            $table->unique(['staff_id', 'handbook_version_id']);
        });

        Schema::create('hr_handbook_sign_declarations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('handbook_sign_id');
            $table->string('declaration_id', 80);
            $table->string('declaration_title_snapshot');
            $table->longText('declaration_text_snapshot');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['handbook_sign_id', 'declaration_id']);
        });

        Schema::create('staff_general', function (Blueprint $table) {
            $table->increments('staff_id');
            $table->string('full_name');
            $table->string('name_code', 50);
            $table->string('position');
            $table->string('department');
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('staff_profile', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('nric', 50);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('hr_handbook_drafts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('base_handbook_version_id');
            $table->unsignedInteger('published_handbook_version_id')->nullable();
            $table->string('status', 30)->default('active');
            $table->longText('content_json');
            $table->unsignedInteger('created_by_staff_id')->nullable();
            $table->string('created_by_name_code', 50)->nullable();
            $table->unsignedInteger('updated_by_staff_id')->nullable();
            $table->string('updated_by_name_code', 50)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_handbook_draft_changes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('handbook_draft_id');
            $table->string('section_id', 80)->nullable();
            $table->string('section_title')->nullable();
            $table->text('summary');
            $table->unsignedInteger('changed_by_staff_id')->nullable();
            $table->string('changed_by_name_code', 50)->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $this->insertVersion('V2 - 2024-01-05', true);
    }

    public function test_current_returns_versioned_handbook_and_manager_flag(): void
    {
        $response = app(HandbookController::class)->current(
            $this->makeRequest('GET', ['roles' => ['HR']]),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertTrue($body['can_manage']);
        $this->assertSame('V2 - 2024-01-05', $body['data']['version_label']);
        $this->assertSame('AMIOSH Employee Handbook', $body['data']['content']['title']);
        $this->assertFalse($body['current_signature']['signed']);
    }

    public function test_rev02_seeder_publishes_the_exact_snapshot_and_preserves_the_old_version(): void
    {
        $oldVersionId = DB::table('hr_handbook_versions')->where('is_current', true)->value('id');
        $oldContent = DB::table('hr_handbook_versions')->where('id', $oldVersionId)->value('content_json');
        $snapshot = file_get_contents(database_path('seeders/data/handbook_rev02_2026_07.json'));

        app(PublishHandbookRev02Seeder::class)->run();

        $current = DB::table('hr_handbook_versions')->where('is_current', true)->first();
        $this->assertSame(2, DB::table('hr_handbook_versions')->count());
        $this->assertSame('REV02 - 2026-07', $current->version_label);
        $this->assertSame($snapshot, $current->content_json);
        $this->assertFalse((bool) DB::table('hr_handbook_versions')->where('id', $oldVersionId)->value('is_current'));
        $this->assertSame($oldContent, DB::table('hr_handbook_versions')->where('id', $oldVersionId)->value('content_json'));
        $this->assertSame(1, DB::table('hr_handbook_change_logs')
            ->where('handbook_version_id', $current->id)
            ->where('action', 'publish')
            ->count());

        app(PublishHandbookRev02Seeder::class)->run();

        $this->assertSame(2, DB::table('hr_handbook_versions')->count());
        $this->assertSame($current->id, DB::table('hr_handbook_versions')->where('is_current', true)->value('id'));
    }

    public function test_current_returns_current_staff_signature_status(): void
    {
        $controller = app(HandbookController::class);
        $currentId = DB::table('hr_handbook_versions')->where('is_current', true)->value('id');
        $signed = $controller->sign(
            $this->makeRequest('POST', ['staff_id' => 7, 'name_code' => 'ST7'], [
                'full_name' => 'Jane Doe',
                'ic_number' => '900101-01-1234',
                'handbook_version_id' => $currentId,
            ]),
        )->getData(true);
        $this->assertTrue($signed['success']);

        $response = $controller->current(
            $this->makeRequest('GET', ['staff_id' => 7, 'name_code' => 'ST7']),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['current_signature']['signed']);
        $this->assertSame('Jane Doe', $body['current_signature']['full_name']);
        $this->assertNotEmpty($body['current_signature']['signed_at']);
    }

    public function test_acknowledgement_status_tracks_the_current_handbook_version(): void
    {
        $controller = app(HandbookController::class);
        $unauthenticated = $controller->acknowledgementStatus($this->makeRequest('GET'));
        $this->assertSame(401, $unauthenticated->getStatusCode());
        $this->assertFalse($unauthenticated->getData(true)['success']);

        $staffSession = ['staff_id' => 7, 'name_code' => 'ST7'];
        $currentId = (int) DB::table('hr_handbook_versions')->where('is_current', true)->value('id');

        $unsigned = $controller->acknowledgementStatus($this->makeRequest('GET', $staffSession))
            ->getData(true);
        $this->assertTrue($unsigned['success']);
        $this->assertSame($currentId, $unsigned['data']['version_id']);
        $this->assertSame('V2 - 2024-01-05', $unsigned['data']['version_label']);
        $this->assertFalse($unsigned['data']['acknowledged']);
        $this->assertNull($unsigned['data']['signed_at']);

        $signed = $controller->sign($this->makeRequest('POST', $staffSession, [
            'full_name' => 'Jane Doe',
            'ic_number' => '900101-01-1234',
            'handbook_version_id' => $currentId,
        ]))->getData(true);
        $this->assertTrue($signed['success']);

        $acknowledged = $controller->acknowledgementStatus($this->makeRequest('GET', $staffSession))
            ->getData(true);
        $this->assertTrue($acknowledged['data']['acknowledged']);
        $this->assertNotEmpty($acknowledged['data']['signed_at']);

        $published = $controller->publish($this->makeRequest(
            'POST',
            [...$staffSession, 'roles' => ['HR']],
            $this->publishPayload('Published a new handbook version.'),
        ))->getData(true);
        $this->assertTrue($published['success']);

        $newVersionStatus = $controller->acknowledgementStatus($this->makeRequest('GET', $staffSession))
            ->getData(true);
        $this->assertFalse($newVersionStatus['data']['acknowledged']);
        $this->assertNotSame($currentId, $newVersionStatus['data']['version_id']);
    }

    public function test_publish_requires_manager_role(): void
    {
        $response = app(HandbookController::class)->publish(
            $this->makeRequest('POST', ['roles' => ['Staff']], $this->publishPayload()),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(1, DB::table('hr_handbook_versions')->count());
    }

    public function test_publish_creates_new_current_version_and_change_log(): void
    {
        $response = app(HandbookController::class)->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload(),
            ),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertSame('V3 - '.now()->toDateString(), $body['data']['version_label']);
        $this->assertSame(2, DB::table('hr_handbook_versions')->count());
        $this->assertSame(1, DB::table('hr_handbook_versions')->where('is_current', true)->count());
        $this->assertSame(1, DB::table('hr_handbook_change_logs')->where('action', 'publish')->count());
        $this->assertSame(
            'chapter-01',
            DB::table('hr_handbook_change_logs')->where('action', 'publish')->value('section_id'),
        );

        $content = json_decode(
            DB::table('hr_handbook_versions')->where('is_current', true)->value('content_json'),
            true,
        );

        $this->assertStringNotContainsString('onclick', $content['chapters'][0]['bodyHtml']);
        $this->assertStringNotContainsString('style=', $content['chapters'][0]['bodyHtml']);
    }

    public function test_republishing_uses_the_new_version_label_in_canonical_acknowledgement_text(): void
    {
        $controller = app(HandbookController::class);
        $session = ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']];

        $first = $controller->publish(
            $this->makeRequest('POST', $session, $this->publishPayload('First refresh.')),
        )->getData(true)['data'];
        $second = $controller->publish(
            $this->makeRequest('POST', $session, $this->publishPayload('Second refresh.')),
        )->getData(true)['data'];

        $this->assertNotSame($first['version_label'], $second['version_label']);
        $receipt = collect($second['content']['acknowledgement']['declarations'])
            ->firstWhere('id', 'handbook_receipt');
        $this->assertStringContainsString(
            "({$second['version_label']})",
            $receipt['body'],
        );
        $this->assertStringNotContainsString(
            "({$first['version_label']})",
            $receipt['body'],
        );

        $stored = DB::table('hr_handbook_versions')->where('id', $second['id'])->first();
        $this->assertSame(hash('sha256', $stored->content_json), $stored->content_sha256);
        $this->assertSame(
            app(HandbookAcknowledgementService::class)->hash(
                $second['content']['acknowledgement'],
            ),
            $stored->acknowledgement_sha256,
        );
    }

    public function test_save_draft_section_does_not_create_official_version(): void
    {
        $response = app(HandbookController::class)->saveDraftSection(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->draftSectionPayload('Updated section as a draft.'),
            ),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertSame('Handbook section saved to draft.', $body['message']);
        $this->assertSame(1, DB::table('hr_handbook_versions')->count());
        $this->assertSame(1, DB::table('hr_handbook_drafts')->where('status', 'active')->count());
        $this->assertSame(1, DB::table('hr_handbook_draft_changes')->count());
        $this->assertSame(1, $body['data']['changes_count']);

        $current = app(HandbookController::class)->current(
            $this->makeRequest('GET', ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']]),
        )->getData(true);
        $this->assertSame(1, $current['draft']['changes_count']);
    }

    public function test_publish_draft_creates_one_new_version_with_section_change_rows(): void
    {
        $controller = app(HandbookController::class);
        $this->replaceCurrentContentWithTwoChapters();

        $controller->saveDraftSection(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->draftSectionPayload('Updated 1.0 section.'),
            ),
        );

        $payload = $this->draftSectionPayload('Updated 2.0 section.');
        $payload['section_id'] = 'chapter-02';
        $payload['section_title'] = '2.0 Onboarding';
        $payload['body_html'] = '<p>Updated onboarding</p>';
        $controller->saveDraftSection(
            $this->makeRequest('POST', ['staff_id' => 23, 'name_code' => 'HR2', 'roles' => ['HR']], $payload),
        );

        $response = $controller->publishDraft(
            $this->makeRequest('POST', ['staff_id' => 24, 'name_code' => 'HR3', 'roles' => ['HR']], [
                'change_summary' => 'Published May handbook draft.',
            ]),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertSame('V3 - '.now()->toDateString(), $body['data']['version_label']);
        $this->assertSame(2, DB::table('hr_handbook_versions')->count());
        $this->assertSame(1, DB::table('hr_handbook_versions')->where('is_current', true)->count());
        $this->assertSame(0, DB::table('hr_handbook_drafts')->where('status', 'active')->count());
        $this->assertSame(1, DB::table('hr_handbook_drafts')->where('status', 'published')->count());
        $this->assertSame(1, DB::table('hr_handbook_change_logs')->where('action', 'publish')->count());
        $this->assertSame(2, DB::table('hr_handbook_change_logs')->where('action', 'section')->count());
        $this->assertSame(
            ['chapter-01', 'chapter-02'],
            DB::table('hr_handbook_change_logs')
                ->where('action', 'section')
                ->orderBy('id')
                ->pluck('section_id')
                ->all(),
        );
    }

    public function test_publish_sanitizes_unquoted_attributes_script_blocks_and_decorative_classes(): void
    {
        $payload = $this->publishPayload();
        $payload['content']['chapters'][0]['bodyHtml'] =
            '<p onclick=evil style=color:red data-test="x">Updated<script>alert(1)</script></p>'
            .'<table class="table shadow-sm" data-test="x"><tbody><tr><td colspan=2 onclick=evil>A</td></tr></tbody></table>';

        $response = app(HandbookController::class)->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $payload,
            ),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);

        $content = json_decode(
            DB::table('hr_handbook_versions')->where('is_current', true)->value('content_json'),
            true,
        );
        $html = $content['chapters'][0]['bodyHtml'];

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('data-test', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('<table class="table">', $html);
        $this->assertStringContainsString('<td colspan="2">A</td>', $html);
        $this->assertStringNotContainsString('shadow-sm', $html);
    }

    public function test_signatures_are_unique_per_current_version(): void
    {
        $controller = app(HandbookController::class);
        $signatureRequest = $this->makeRequest('POST', ['staff_id' => 7, 'name_code' => 'ST7'], [
            'full_name' => 'Jane Doe',
            'ic_number' => '900101-01-1234',
        ]);

        $first = $controller->sign($signatureRequest)->getData(true);
        $second = $controller->sign($signatureRequest)->getData(true);
        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);

        $publish = $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Policy refresh.'),
            ),
        )->getData(true);
        $this->assertTrue($publish['success']);
        $this->assertSame(2, (int) DB::table('hr_handbook_versions')->where('is_current', 1)->value('id'));

        $this->seedStaffProfileAndSignature();
        $staffSession = ['staff_id' => 7, 'name_code' => 'ST7', 'roles' => ['Staff']];
        $current = $controller->current($this->makeRequest('GET', $staffSession))->getData(true);
        $third = $controller->sign($this->makeRequest(
            'POST',
            $staffSession,
            $this->evidenceSignaturePayload($current, '51ec0f87-f815-4ed9-96fa-eab03731f65c'),
        ))->getData(true);
        $this->assertTrue($third['success']);
        $this->assertSame(2, DB::table('hr_handbook_sign')->where('staff_id', 7)->count());
        $signedVersionIds = DB::table('hr_handbook_sign')
            ->where('staff_id', 7)
            ->pluck('handbook_version_id')
            ->unique()
            ->values();

        $this->assertCount(2, $signedVersionIds);
    }

    public function test_electronic_acknowledgement_preserves_all_declarations_and_signature_evidence(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        DB::table('staff_general')->insert([
            'staff_id' => 7,
            'full_name' => 'Jane Doe',
            'name_code' => 'ST7',
            'position' => 'Safety Executive',
            'department' => 'Operations',
        ]);
        DB::table('staff_profile')->insert([
            'staff_id' => 7,
            'nric' => '900101-01-1234',
        ]);
        Storage::disk('private')->put('signatures/7-ST7.png', 'test-signature-image');

        $controller = app(HandbookController::class);
        $published = $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Published acknowledgement evidence requirements.'),
            ),
        )->getData(true);
        $this->assertTrue($published['success']);

        $staffSession = ['staff_id' => 7, 'name_code' => 'ST7', 'roles' => ['Staff']];
        $current = $controller->current($this->makeRequest('GET', $staffSession))->getData(true);
        $context = $current['signing_context'];
        $signature = $context['personal_signature'];
        $declarationIds = $context['required_declaration_ids'];

        $this->assertTrue($context['available']);
        $this->assertCount(4, $declarationIds);
        $this->assertSame('900101-01-1234', $context['profile']['identity_number']);
        $this->assertStringEndsWith('1234', $context['profile']['identity_number_masked']);
        $this->assertStringNotContainsString('900101-01-1234', $context['profile']['identity_number_masked']);

        $incomplete = $controller->sign($this->makeRequest('POST', $staffSession, [
            'submission_uuid' => '70cb64e9-ea7e-4356-ac82-d704f99491cc',
            'handbook_version_id' => $current['data']['id'],
            'typed_legal_name' => 'Jane Doe',
            'accepted_declaration_ids' => array_slice($declarationIds, 0, 3),
            'acknowledgement_sha256' => $context['acknowledgement_sha256'],
            'personal_signature_sha256' => $signature['sha256'],
        ]));
        $this->assertSame(422, $incomplete->getStatusCode());
        $this->assertSame(0, DB::table('hr_handbook_sign')->count());

        $payload = [
            'submission_uuid' => '1f4861ea-d6b8-4f34-ac4a-f843b3f7d591',
            'handbook_version_id' => $current['data']['id'],
            'typed_legal_name' => 'Jane Doe',
            'accepted_declaration_ids' => $declarationIds,
            'acknowledgement_sha256' => $context['acknowledgement_sha256'],
            'personal_signature_sha256' => $signature['sha256'],
        ];
        $response = $controller->sign($this->makeRequest('POST', $staffSession, $payload));
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertSame(4, $body['data']['declarations_accepted']);
        $this->assertSame(3, $body['data']['evidence_schema_version']);

        $record = DB::table('hr_handbook_sign')->where('id', $body['data']['id'])->first();
        $this->assertSame('', $record->ic_number);
        $this->assertNotSame('900101-01-1234', $record->identity_number_encrypted);
        $this->assertSame('personal_signature_snapshot', $record->signature_method);
        $sealedPayload = json_decode($record->evidence_payload_json, true);
        $this->assertSame(3, $sealedPayload['evidence_schema_version']);
        $this->assertSame('127.0.0.1', $sealedPayload['audit']['ip_address']);
        $this->assertSame(4, DB::table('hr_handbook_sign_declarations')
            ->where('handbook_sign_id', $record->id)
            ->count());
        Storage::disk('private')->assertExists($record->signature_snapshot_path);

        $list = $controller->signatures(
            $this->makeRequest('GET', ['staff_id' => 22, 'roles' => ['HR']]),
        )->getData(true);
        $this->assertSame('complete', $list['data'][0]['evidence_status']);
        $this->assertSame(4, $list['data'][0]['declarations_accepted']);
        $this->assertSame('electronically_signed', $list['data'][0]['signature_status']);

        $managerList = $controller->signatures(
            $this->makeRequest('GET', ['staff_id' => 30, 'roles' => ['Manager']]),
        )->getData(true);
        $this->assertNull($managerList['data'][0]['ip_address']);
        $this->assertNull($managerList['data'][0]['user_agent']);
        $this->assertNull($managerList['data'][0]['submission_uuid']);

        $idempotent = $controller->sign(
            $this->makeRequest('POST', $staffSession, $payload),
        )->getData(true);
        $this->assertTrue($idempotent['success']);
        $this->assertTrue($idempotent['data']['idempotent']);
        $this->assertSame(1, DB::table('hr_handbook_sign')->count());
        $conflictingRetryPayload = $payload;
        $conflictingRetryPayload['personal_signature_sha256'] = str_repeat('c', 64);
        $conflictingRetry = $controller->sign(
            $this->makeRequest('POST', $staffSession, $conflictingRetryPayload),
        );
        $this->assertSame(409, $conflictingRetry->getStatusCode());

        $restricted = $controller->signatureEvidence(
            $this->makeRequest('GET', ['staff_id' => 30, 'roles' => ['Manager']]),
            (int) $record->id,
        );
        $this->assertSame(403, $restricted->getStatusCode());
        $restrictedImage = $controller->signatureEvidenceImage(
            $this->makeRequest('GET', ['staff_id' => 30, 'roles' => ['Manager']]),
            (int) $record->id,
        );
        $this->assertSame(403, $restrictedImage->getStatusCode());

        $detail = $controller->signatureEvidence(
            $this->makeRequest('GET', ['staff_id' => 22, 'roles' => ['HR']]),
            (int) $record->id,
        )->getData(true);
        $this->assertTrue($detail['data']['integrity_verified']);
        $this->assertSame('full_evidence', $detail['data']['integrity_scope']);
        $this->assertNotContains(false, $detail['data']['integrity_checks']);
        $this->assertCount(4, $detail['data']['declarations']);
        $this->assertStringEndsWith('1234', $detail['data']['profile']['identity_number_masked']);
        $this->assertNotEmpty($detail['data']['signature']['preview_url']);

        $originalKeyId = config('handbook.evidence_key_id');
        $originalKey = config('handbook.evidence_key');
        config([
            'handbook.evidence_key_id' => 'rotated-key-v2',
            'handbook.evidence_key' => str_repeat('r', 32),
            'handbook.evidence_previous_keys' => [$originalKeyId => $originalKey],
        ]);
        $rotatedKeyDetail = $controller->signatureEvidence(
            $this->makeRequest('GET', ['staff_id' => 22, 'roles' => ['HR']]),
            (int) $record->id,
        )->getData(true);
        $this->assertTrue($rotatedKeyDetail['data']['integrity_verified']);

        $image = $controller->signatureEvidenceImage(
            $this->makeRequest('GET', ['staff_id' => 22, 'roles' => ['HR']]),
            (int) $record->id,
        );
        $this->assertSame(200, $image->getStatusCode());

        DB::table('hr_handbook_sign_declarations')
            ->where('handbook_sign_id', $record->id)
            ->where('declaration_id', $declarationIds[0])
            ->update(['declaration_text_snapshot' => 'Tampered declaration text']);
        $tamperedDetail = $controller->signatureEvidence(
            $this->makeRequest('GET', ['staff_id' => 22, 'roles' => ['HR']]),
            (int) $record->id,
        )->getData(true);
        $this->assertFalse($tamperedDetail['data']['integrity_verified']);
        $this->assertFalse($tamperedDetail['data']['integrity_checks']['record_matches_payload']);
    }

    public function test_signing_is_blocked_when_the_published_handbook_content_is_tampered(): void
    {
        $this->seedStaffProfileAndSignature();
        $controller = app(HandbookController::class);
        $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Integrity test version.'),
            ),
        );

        $staffSession = ['staff_id' => 7, 'name_code' => 'ST7', 'roles' => ['Staff']];
        $current = $controller->current($this->makeRequest('GET', $staffSession))->getData(true);
        $payload = $this->evidenceSignaturePayload(
            $current,
            'cc51e56b-ee92-4a42-b4ba-e2781a62ad53',
        );
        $versionId = $current['data']['id'];
        $tamperedJson = str_replace(
            '<p>Updated</p>',
            '<p>Tampered after publication</p>',
            DB::table('hr_handbook_versions')->where('id', $versionId)->value('content_json'),
        );
        DB::table('hr_handbook_versions')->where('id', $versionId)->update([
            'content_json' => $tamperedJson,
        ]);

        $tamperedCurrent = $controller->current(
            $this->makeRequest('GET', $staffSession),
        )->getData(true);
        $this->assertFalse($tamperedCurrent['signing_context']['available']);
        $this->assertStringContainsString(
            'failed its integrity check',
            $tamperedCurrent['signing_context']['reason'],
        );

        $response = $controller->sign(
            $this->makeRequest('POST', $staffSession, $payload),
        );
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, DB::table('hr_handbook_sign')->count());
    }

    public function test_signature_snapshot_attempts_do_not_share_a_cleanup_path(): void
    {
        $this->seedStaffProfileAndSignature();
        $service = app(StaffSignatureService::class);
        $signature = $service->current(7, 'ST7');

        $first = $service->snapshot($signature, '90bb0349-1021-4c4f-a208-145d856bb6a6');
        $second = $service->snapshot($signature, '90bb0349-1021-4c4f-a208-145d856bb6a6');

        $this->assertNotSame($first['path'], $second['path']);
        AppFilePaths::deleteStoredPath($second['path']);
        $this->assertTrue($service->verifySnapshot($first['path'], $first['sha256']));
    }

    public function test_personal_signature_is_migrated_to_private_storage_and_replaced_safely(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        Storage::disk('public')->put('signatures/7-ST7.png', 'legacy-signature');
        $service = app(StaffSignatureService::class);

        $legacy = $service->current(7, 'ST7');
        $this->assertNotNull($legacy);
        Storage::disk('private')->assertExists('signatures/7-ST7.png');
        Storage::disk('public')->assertMissing('signatures/7-ST7.png');
        $this->assertStringContainsString('signature/file?v=', $legacy['url']);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $replacement = UploadedFile::fake()->createWithContent('replacement.png', $png);
        $stored = $service->store(7, 'ST7', $replacement);

        $this->assertNotSame($legacy['sha256'], $stored['sha256']);
        Storage::disk('private')->assertExists('signatures/7-ST7.png');
        Storage::disk('public')->assertMissing('signatures/7-ST7.png');
        $this->assertSame(
            hash('sha256', $png),
            hash_file(
                'sha256',
                Storage::disk('private')->path('signatures/7-ST7.png'),
            ),
        );
    }

    public function test_acknowledgement_schema_rejects_extra_declarations_or_missing_profile_fields(): void
    {
        $service = app(HandbookAcknowledgementService::class);
        $extraDeclaration = $service->defaultDefinition();
        $extraDeclaration['declarations'][] = [
            'id' => 'unexpected_consent',
            'title' => 'Unexpected',
            'body' => 'Unexpected declaration.',
            'required' => false,
            'order' => 5,
        ];

        try {
            $service->sanitize($extraDeclaration);
            $this->fail('An extra declaration should invalidate acknowledgement schema v2.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $missingProfileField = $service->defaultDefinition();
        array_pop($missingProfileField['profileFields']);
        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize($missingProfileField);
    }

    public function test_signing_rejects_stale_handbook_version_id(): void
    {
        $controller = app(HandbookController::class);
        $oldVersionId = DB::table('hr_handbook_versions')->where('is_current', true)->value('id');

        $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Policy refresh.'),
            ),
        );

        $response = $controller->sign(
            $this->makeRequest('POST', ['staff_id' => 7, 'name_code' => 'ST7'], [
                'full_name' => 'Jane Doe',
                'ic_number' => '900101-01-1234',
                'handbook_version_id' => $oldVersionId,
            ]),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, DB::table('hr_handbook_sign')->where('staff_id', 7)->count());
    }

    public function test_draft_section_save_merges_with_latest_active_draft(): void
    {
        $controller = app(HandbookController::class);
        $this->replaceCurrentContentWithTwoChapters();

        $controller->saveDraftSection(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->draftSectionPayload('Updated first section.', 'chapter-01', '1.0 First Updated', '<p>First updated</p>'),
            ),
        );

        $controller->saveDraftSection(
            $this->makeRequest(
                'POST',
                ['staff_id' => 23, 'name_code' => 'HR2', 'roles' => ['HR']],
                $this->draftSectionPayload('Updated second section.', 'chapter-02', '2.0 Second Updated', '<p>Second updated</p>'),
            ),
        );

        $draftContent = json_decode(DB::table('hr_handbook_drafts')->where('status', 'active')->value('content_json'), true);

        $this->assertSame('1.0 First Updated', $draftContent['chapters'][0]['title']);
        $this->assertSame('<p>First updated</p>', $draftContent['chapters'][0]['bodyHtml']);
        $this->assertSame('2.0 Second Updated', $draftContent['chapters'][1]['title']);
        $this->assertSame('<p>Second updated</p>', $draftContent['chapters'][1]['bodyHtml']);
        $this->assertSame(2, DB::table('hr_handbook_draft_changes')->count());
    }

    public function test_draft_section_save_rejects_stale_base_version(): void
    {
        $controller = app(HandbookController::class);
        $oldVersionId = DB::table('hr_handbook_versions')->where('is_current', true)->value('id');

        $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Policy refresh.'),
            ),
        );

        $payload = $this->draftSectionPayload('Stale draft save.');
        $payload['base_handbook_version_id'] = $oldVersionId;
        $response = $controller->saveDraftSection(
            $this->makeRequest('POST', ['staff_id' => 23, 'name_code' => 'HR2', 'roles' => ['HR']], $payload),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(0, DB::table('hr_handbook_drafts')->where('status', 'active')->count());
    }

    public function test_signatures_endpoint_does_not_expose_ic_numbers(): void
    {
        $controller = app(HandbookController::class);

        $signed = $controller->sign(
            $this->makeRequest('POST', ['staff_id' => 7, 'name_code' => 'ST7'], [
                'full_name' => 'Jane Doe',
                'ic_number' => '900101-01-1234',
            ]),
        )->getData(true);
        $this->assertTrue($signed['success']);

        $response = $controller->signatures(
            $this->makeRequest('GET', ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']]),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('ic_number', $body['data'][0]);
        $this->assertSame('Jane Doe', $body['data'][0]['full_name']);
    }

    public function test_versions_endpoint_returns_signature_counts(): void
    {
        $oldVersionId = DB::table('hr_handbook_versions')->where('version_label', 'V2 - 2024-01-05')->value('id');
        $newVersionId = $this->insertVersion('V3 - 2026-05-08', false);

        DB::table('hr_handbook_sign')->insert([
            [
                'handbook_version_id' => $oldVersionId,
                'staff_id' => 7,
                'full_name' => 'Jane Doe',
                'ic_number' => '900101-01-1234',
                'signed_at' => now(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
            ],
            [
                'handbook_version_id' => $newVersionId,
                'staff_id' => 8,
                'full_name' => 'John Doe',
                'ic_number' => '900101-01-5678',
                'signed_at' => now(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
            ],
            [
                'handbook_version_id' => $newVersionId,
                'staff_id' => 9,
                'full_name' => 'June Doe',
                'ic_number' => '900101-01-9999',
                'signed_at' => now(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test',
            ],
        ]);

        $response = app(HandbookController::class)->versions(
            $this->makeRequest('GET', ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']]),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $counts = collect($body['data'])->pluck('signature_count', 'id');
        $this->assertSame(1, $counts[$oldVersionId]);
        $this->assertSame(2, $counts[$newVersionId]);
        $this->assertArrayNotHasKey('content', $body['data'][0]);
    }

    public function test_versions_endpoint_supports_bounded_pagination(): void
    {
        $this->insertVersion('V3 - 2026-05-08', false);
        $this->insertVersion('V4 - 2026-05-09', false);

        $response = app(HandbookController::class)->versions(
            $this->makeRequest('GET', ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']], [
                'page' => 1,
                'per_page' => 2,
            ]),
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertCount(2, $body['data']);
        $this->assertSame(1, $body['pagination']['current_page']);
        $this->assertSame(2, $body['pagination']['per_page']);
        $this->assertSame(3, $body['pagination']['total']);
        $this->assertArrayNotHasKey('content', $body['data'][0]);
    }

    public function test_version_endpoint_returns_historical_content_snapshot(): void
    {
        $versionId = $this->insertVersion('V3 - 2026-05-08', false);

        $response = app(HandbookController::class)->version(
            $this->makeRequest('GET', ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']]),
            $versionId,
        );
        $body = $response->getData(true);

        $this->assertTrue($body['success']);
        $this->assertSame('V3 - 2026-05-08', $body['data']['version_label']);
        $this->assertSame('AMIOSH Employee Handbook', $body['data']['content']['title']);
        $this->assertSame(0, $body['data']['signature_count']);
    }

    public function test_legacy_version_cannot_replace_an_evidence_enabled_current_version(): void
    {
        $controller = app(HandbookController::class);
        $oldVersionId = DB::table('hr_handbook_versions')->where('version_label', 'V2 - 2024-01-05')->value('id');

        DB::table('hr_handbook_sign')->insert([
            'handbook_version_id' => $oldVersionId,
            'staff_id' => 7,
            'full_name' => 'Jane Doe',
            'ic_number' => '900101-01-1234',
            'signed_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
        ]);

        $newVersionId = $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Policy refresh.'),
            ),
        )->getData(true)['data']['id'];

        $response = $controller->reactivateVersion(
            $this->makeRequest('POST', ['staff_id' => 23, 'name_code' => 'HR2', 'roles' => ['HR']], [
                'change_summary' => 'Rollback to previous policy version.',
            ]),
            $oldVersionId,
        );
        $body = $response->getData(true);

        $this->assertFalse($body['success']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, DB::table('hr_handbook_versions')->where('is_current', true)->count());
        $this->assertFalse((bool) DB::table('hr_handbook_versions')->where('id', $oldVersionId)->value('is_current'));
        $this->assertTrue((bool) DB::table('hr_handbook_versions')->where('id', $newVersionId)->value('is_current'));
        $this->assertSame(1, DB::table('hr_handbook_sign')->where('handbook_version_id', $oldVersionId)->count());
        $this->assertSame(0, DB::table('hr_handbook_change_logs')->where('action', 'reactivate')->count());

        $current = $controller->current(
            $this->makeRequest('GET', ['staff_id' => 7, 'name_code' => 'ST7']),
        )->getData(true);
        $this->assertFalse($current['current_signature']['signed']);
    }

    public function test_reactivate_current_version_is_rejected(): void
    {
        $currentId = DB::table('hr_handbook_versions')->where('is_current', true)->value('id');

        $response = app(HandbookController::class)->reactivateVersion(
            $this->makeRequest('POST', ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']], [
                'change_summary' => 'Rollback to current version.',
            ]),
            $currentId,
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, DB::table('hr_handbook_change_logs')->where('action', 'reactivate')->count());
    }

    public function test_reactivate_previous_version_is_rejected_when_active_draft_exists(): void
    {
        $controller = app(HandbookController::class);
        $oldVersionId = DB::table('hr_handbook_versions')->where('version_label', 'V2 - 2024-01-05')->value('id');

        $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Policy refresh.'),
            ),
        );

        $controller->saveDraftSection(
            $this->makeRequest(
                'POST',
                ['staff_id' => 23, 'name_code' => 'HR2', 'roles' => ['HR']],
                $this->draftSectionPayload('Draft update after policy refresh.'),
            ),
        );

        $response = $controller->reactivateVersion(
            $this->makeRequest('POST', ['staff_id' => 24, 'name_code' => 'HR3', 'roles' => ['HR']], [
                'change_summary' => 'Rollback while draft exists.',
            ]),
            $oldVersionId,
        );
        $body = $response->getData(true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'Cannot reactivate a handbook version while an active handbook draft exists. Publish or discard the draft first.',
            $body['message'],
        );
        $this->assertFalse((bool) DB::table('hr_handbook_versions')->where('id', $oldVersionId)->value('is_current'));
        $this->assertSame(1, DB::table('hr_handbook_drafts')->where('status', 'active')->count());
        $this->assertSame(0, DB::table('hr_handbook_change_logs')->where('action', 'reactivate')->count());
    }

    public function test_reactivate_records_previous_and_target_versions_in_audit_log(): void
    {
        $controller = app(HandbookController::class);
        $targetVersion = $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Policy refresh.'),
            ),
        )->getData(true)['data'];
        $previousVersion = $controller->publish(
            $this->makeRequest(
                'POST',
                ['staff_id' => 22, 'name_code' => 'HR1', 'roles' => ['HR']],
                $this->publishPayload('Second policy refresh.'),
            ),
        )->getData(true)['data'];

        $response = $controller->reactivateVersion(
            $this->makeRequest('POST', ['staff_id' => 23, 'name_code' => 'HR2', 'roles' => ['HR']], [
                'change_summary' => 'Rollback to previous policy version.',
            ]),
            $targetVersion['id'],
        );

        $this->assertTrue($response->getData(true)['success']);
        $action = DB::table('user_activities')->where('staff_id', 23)->value('action');
        $this->assertStringContainsString(
            "#{$targetVersion['id']} ({$targetVersion['version_label']})",
            $action,
        );
        $this->assertStringContainsString("#{$previousVersion['id']}", $action);
    }

    public function test_version_history_endpoints_require_manager_role(): void
    {
        $controller = app(HandbookController::class);
        $currentId = DB::table('hr_handbook_versions')->where('is_current', true)->value('id');
        $request = $this->makeRequest('GET', ['staff_id' => 7, 'name_code' => 'ST7', 'roles' => ['Staff']]);

        $this->assertSame(403, $controller->versions($request)->getStatusCode());
        $this->assertSame(403, $controller->version($request, $currentId)->getStatusCode());
        $this->assertSame(
            403,
            $controller->reactivateVersion(
                $this->makeRequest('POST', ['staff_id' => 7, 'name_code' => 'ST7', 'roles' => ['Staff']], [
                    'change_summary' => 'Unauthorized rollback.',
                ]),
                $currentId,
            )->getStatusCode(),
        );
    }

    public function test_seed_handbook_content_is_normalized_document_flow(): void
    {
        $content = json_decode(
            file_get_contents(database_path('seeders/data/handbook_v2_2024_01_05.json')),
            true,
        );

        $this->assertSame('AMIOSH Employee Handbook', $content['title']);
        $this->assertNotEmpty($content['chapters']);

        $html = collect($content['chapters'])->pluck('bodyHtml')->implode('');
        preg_match_all('/class="([^"]+)"/', $html, $matches);
        $classes = collect($matches[1] ?? [])
            ->flatMap(fn ($value) => preg_split('/\s+/', $value) ?: [])
            ->values();

        $this->assertFalse($classes->contains(fn ($class) => in_array($class, [
            'card',
            'card-header',
            'card-body',
            'row',
            'shadow-sm',
            'h-100',
            'mt-3',
            'mb-0',
            'fw-semibold',
            'fst-italic',
            'text-center',
            'ms-3',
        ], true)));
        $this->assertFalse($classes->contains(fn ($class) => str_starts_with((string) $class, 'col-')));

        $tableCounts = collect($content['chapters'])->mapWithKeys(fn ($chapter) => [
            $chapter['title'] => substr_count($chapter['bodyHtml'], '<table'),
        ]);

        $this->assertSame(1, $tableCounts['4.0 Company Policies']);
        $this->assertSame(1, $tableCounts['12.0 Leave Entitlement']);
        $this->assertSame(1, $tableCounts['13.0 Company Expenses']);
        $this->assertSame(3, $tableCounts['17.0 Allowances']);

        $commonRules = collect($content['chapters'])->firstWhere('title', '9.0 Common Rules');
        $this->assertStringNotContainsString('—', $commonRules['bodyHtml']);
        $this->assertStringContainsString(
            'Snacks in the kitchen are for quick energy boosts. They are not meal replacements.',
            $commonRules['bodyHtml'],
        );
        $this->assertStringContainsString(
            'Cooking facilities are available. Please clean up after yourself to keep our shared space tidy.',
            $commonRules['bodyHtml'],
        );
    }

    private function seedStaffProfileAndSignature(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        DB::table('staff_general')->insert([
            'staff_id' => 7,
            'full_name' => 'Jane Doe',
            'name_code' => 'ST7',
            'position' => 'Safety Executive',
            'department' => 'Operations',
        ]);
        DB::table('staff_profile')->insert([
            'staff_id' => 7,
            'nric' => '900101-01-1234',
        ]);
        Storage::disk('private')->put('signatures/7-ST7.png', 'test-signature-image');
    }

    private function evidenceSignaturePayload(array $current, string $submissionUuid): array
    {
        $context = $current['signing_context'];

        return [
            'submission_uuid' => $submissionUuid,
            'handbook_version_id' => $current['data']['id'],
            'typed_legal_name' => 'Jane Doe',
            'accepted_declaration_ids' => $context['required_declaration_ids'],
            'acknowledgement_sha256' => $context['acknowledgement_sha256'],
            'personal_signature_sha256' => $context['personal_signature']['sha256'],
        ];
    }

    private function insertVersion(string $label, bool $current): int
    {
        return DB::table('hr_handbook_versions')->insertGetId([
            'version_label' => $label,
            'content_json' => json_encode([
                'title' => 'AMIOSH Employee Handbook',
                'chapters' => [
                    ['id' => 'chapter-01', 'title' => '1.0 Test', 'bodyHtml' => '<p>Test</p>'],
                ],
            ]),
            'change_summary' => 'Initial test version.',
            'published_by_staff_id' => null,
            'published_by_name_code' => 'SYSTEM',
            'published_at' => now(),
            'is_current' => $current,
            'current_version_guard' => $current ? 1 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function publishPayload(string $summary = 'Updated office hours.'): array
    {
        return [
            'content' => [
                'title' => 'AMIOSH Employee Handbook',
                'chapters' => [
                    [
                        'id' => 'chapter-01',
                        'title' => '1.0 Test',
                        'bodyHtml' => '<p onclick="evil()" style="color:red">Updated</p>',
                    ],
                ],
            ],
            'change_summary' => $summary,
            'section_id' => 'chapter-01',
            'section_title' => '1.0 Test',
        ];
    }

    private function draftSectionPayload(
        string $summary = 'Updated section draft.',
        string $sectionId = 'chapter-01',
        string $sectionTitle = '1.0 Test',
        string $bodyHtml = '<p>Updated</p>',
    ): array {
        return [
            'base_handbook_version_id' => DB::table('hr_handbook_versions')->where('is_current', true)->value('id'),
            'section_id' => $sectionId,
            'section_title' => $sectionTitle,
            'body_html' => $bodyHtml,
            'change_summary' => $summary,
        ];
    }

    private function replaceCurrentContentWithTwoChapters(): void
    {
        DB::table('hr_handbook_versions')->where('is_current', true)->update([
            'content_json' => json_encode([
                'title' => 'AMIOSH Employee Handbook',
                'chapters' => [
                    ['id' => 'chapter-01', 'title' => '1.0 First', 'bodyHtml' => '<p>First</p>'],
                    ['id' => 'chapter-02', 'title' => '2.0 Second', 'bodyHtml' => '<p>Second</p>'],
                ],
            ]),
        ]);
    }

    private function makeRequest(string $method, array $sessionData = [], array $payload = []): Request
    {
        $request = Request::create('/hr/handbook/test', $method, $payload);

        $session = app('session')->driver();
        $session->start();
        foreach ($sessionData as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        return $request;
    }
}
