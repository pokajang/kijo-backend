<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HandbookController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        Schema::dropIfExists('hr_handbook_versions');
        Schema::dropIfExists('hr_handbook_sign');

        Schema::create('hr_handbook_versions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('version_label', 80);
            $table->longText('content_json');
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
        $this->assertSame('V3 - ' . now()->toDateString(), $body['data']['version_label']);
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
        $this->assertSame('V3 - ' . now()->toDateString(), $body['data']['version_label']);
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
            . '<table class="table shadow-sm" data-test="x"><tbody><tr><td colspan=2 onclick=evil>A</td></tr></tbody></table>';

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

        $third = $controller->sign(
            $this->makeRequest('POST', ['staff_id' => 7, 'name_code' => 'ST7'], [
                'full_name' => 'Jane Doe',
                'ic_number' => '900101-01-1234',
            ]),
        )->getData(true);
        $this->assertTrue($third['success']);
        $this->assertSame(2, DB::table('hr_handbook_sign')->where('staff_id', 7)->count());
        $signedVersionIds = DB::table('hr_handbook_sign')
            ->where('staff_id', 7)
            ->pluck('handbook_version_id')
            ->unique()
            ->values();

        $this->assertCount(2, $signedVersionIds);
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

    public function test_reactivate_previous_version_sets_only_that_version_current_and_preserves_signatures(): void
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

        $this->assertTrue($body['success']);
        $this->assertSame(1, DB::table('hr_handbook_versions')->where('is_current', true)->count());
        $this->assertTrue((bool) DB::table('hr_handbook_versions')->where('id', $oldVersionId)->value('is_current'));
        $this->assertFalse((bool) DB::table('hr_handbook_versions')->where('id', $newVersionId)->value('is_current'));
        $this->assertSame(1, DB::table('hr_handbook_sign')->where('handbook_version_id', $oldVersionId)->count());
        $this->assertSame(1, DB::table('hr_handbook_change_logs')->where('action', 'reactivate')->count());

        $current = $controller->current(
            $this->makeRequest('GET', ['staff_id' => 7, 'name_code' => 'ST7']),
        )->getData(true);
        $this->assertTrue($current['current_signature']['signed']);
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
        $oldVersionId = DB::table('hr_handbook_versions')->where('version_label', 'V2 - 2024-01-05')->value('id');
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

        $this->assertTrue($response->getData(true)['success']);
        $action = DB::table('user_activities')->where('staff_id', 23)->value('action');
        $this->assertStringContainsString("#{$oldVersionId} (V2 - 2024-01-05)", $action);
        $this->assertStringContainsString("#{$newVersionId}", $action);
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
