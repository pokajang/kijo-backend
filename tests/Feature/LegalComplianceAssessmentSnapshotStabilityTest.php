<?php

namespace Tests\Feature;

use App\Services\LegalComplianceAssessmentSnapshotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegalComplianceAssessmentSnapshotStabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'legal_compliance_assessments',
            'legal_compliance_template_versions',
            'legal_compliance_templates',
            'staff_general',
            'system_users',
            'user_activities',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('legal_compliance_templates', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('active_version_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('legal_compliance_template_versions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('template_id');
            $table->unsignedInteger('version_number');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_compliance_assessments', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id')->nullable();
            $table->unsignedInteger('template_id')->nullable();
            $table->unsignedInteger('template_version_id')->nullable();
            $table->string('template_version')->nullable();
            $table->longText('template_snapshot')->nullable();
            $table->string('stage')->nullable();
            $table->unsignedInteger('parent_assessment_id')->nullable();
            $table->unsignedInteger('revision_number')->nullable();
            $table->unsignedInteger('superseded_by_assessment_id')->nullable();
            $table->string('company_name')->nullable();
            $table->text('site_location')->nullable();
            $table->unsignedInteger('client_company_id')->nullable();
            $table->unsignedInteger('client_branch_id')->nullable();
            $table->unsignedInteger('client_pic_id')->nullable();
            $table->string('client_pic_name')->nullable();
            $table->string('client_pic_email')->nullable();
            $table->unsignedInteger('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->date('assessment_date')->nullable();
            $table->string('assessor_name')->nullable();
            $table->string('assessor_email')->nullable();
            $table->text('nature_of_company')->nullable();
            $table->json('selected_assessors')->nullable();
            $table->longText('clause_responses')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('submitted_by_staff_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by_staff_id')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->text('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id')->nullable();
            $table->string('action')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function test_valid_stored_snapshot_wins(): void
    {
        [$templateId, $versionId] = $this->templateWithVersions([
            1 => ['label' => 'Version one', 'published_at' => '2026-01-01 00:00:00'],
        ]);

        $stored = $this->snapshot('Stored snapshot');
        $resolution = $this->snapshotService()->resolve((object) [
            'template_id' => $templateId,
            'template_version_id' => $versionId,
            'template_version' => 'v1',
            'template_snapshot' => json_encode($stored),
        ]);

        $this->assertSame('existing_valid', $resolution['source']);
        $this->assertSame('Stored snapshot', $resolution['snapshot']['groups'][0]['title']);
    }

    public function test_resolves_from_template_version_id(): void
    {
        [, $versionId] = $this->templateWithVersions([
            1 => ['label' => 'Version id snapshot', 'published_at' => '2026-01-01 00:00:00'],
        ]);

        $resolution = $this->snapshotService()->resolve((object) [
            'template_version_id' => $versionId,
            'template_snapshot' => null,
        ]);

        $this->assertSame('version_id', $resolution['source']);
        $this->assertSame('Version id snapshot', $resolution['snapshot']['groups'][0]['title']);
    }

    public function test_resolves_from_template_id_and_version_label(): void
    {
        [$templateId] = $this->templateWithVersions([
            1 => ['label' => 'First version', 'published_at' => '2026-01-01 00:00:00'],
            3 => ['label' => 'Third version', 'published_at' => '2026-03-01 00:00:00'],
        ]);

        $resolution = $this->snapshotService()->resolve((object) [
            'template_id' => $templateId,
            'template_version' => 'osha-1994-v3',
            'template_snapshot' => '',
        ]);

        $this->assertSame('version_number', $resolution['source']);
        $this->assertSame('Third version', $resolution['snapshot']['groups'][0]['title']);
    }

    public function test_resolves_from_assessment_date(): void
    {
        [$templateId] = $this->templateWithVersions([
            1 => ['label' => 'January version', 'published_at' => '2026-01-01 00:00:00'],
            2 => ['label' => 'March version', 'published_at' => '2026-03-01 00:00:00'],
            3 => ['label' => 'June version', 'published_at' => '2026-06-01 00:00:00'],
        ]);

        $resolution = $this->snapshotService()->resolve((object) [
            'template_id' => $templateId,
            'template_snapshot' => null,
            'assessment_date' => '2026-04-15',
        ]);

        $this->assertSame('date_match', $resolution['source']);
        $this->assertSame('March version', $resolution['snapshot']['groups'][0]['title']);
    }

    public function test_unresolved_record_does_not_use_active_or_default_template(): void
    {
        [$templateId] = $this->templateWithVersions([
            1 => ['label' => 'Default active version', 'published_at' => '2026-01-01 00:00:00'],
        ], true);

        $resolution = $this->snapshotService()->resolve((object) [
            'template_id' => null,
            'template_version' => 'unknown',
            'template_snapshot' => null,
            'created_at' => '2026-05-01 00:00:00',
        ]);

        $this->assertGreaterThan(0, $templateId);
        $this->assertSame('unresolved', $resolution['source']);
        $this->assertTrue($resolution['unresolved']);
        $this->assertSame([], $resolution['snapshot']);
    }

    public function test_legacy_default_version_resolves_default_template_version_one(): void
    {
        $this->templateWithVersions([
            1 => ['label' => 'Legacy default v1', 'published_at' => '2026-01-01 00:00:00'],
        ], true);

        $resolution = $this->snapshotService()->resolve((object) [
            'template_version' => 'osha-1994-v1',
            'template_snapshot' => null,
        ]);

        $this->assertSame('legacy_default_v1', $resolution['source']);
        $this->assertSame('Legacy default v1', $resolution['snapshot']['groups'][0]['title']);
    }

    public function test_backfill_command_dry_run_changes_no_rows(): void
    {
        [, $versionId] = $this->templateWithVersions([
            1 => ['label' => 'Command version', 'published_at' => '2026-01-01 00:00:00'],
        ]);
        $assessmentId = $this->assessment(['template_version_id' => $versionId]);

        $this->assertSame(0, Artisan::call('legal-compliance:backfill-assessment-snapshots'));

        $record = DB::table('legal_compliance_assessments')->where('id', $assessmentId)->first();
        $this->assertNull($record->template_snapshot);
    }

    public function test_backfill_command_commit_fills_snapshot_and_missing_version_fields(): void
    {
        [$templateId] = $this->templateWithVersions([
            2 => ['label' => 'Committed version', 'published_at' => '2026-02-01 00:00:00'],
        ]);
        $assessmentId = $this->assessment([
            'template_id' => $templateId,
            'template_version' => 'v2',
            'updated_at' => '2026-05-01 12:00:00',
        ]);

        $this->assertSame(0, Artisan::call('legal-compliance:backfill-assessment-snapshots', ['--commit' => true]));

        $record = DB::table('legal_compliance_assessments')->where('id', $assessmentId)->first();
        $snapshot = json_decode((string) $record->template_snapshot, true);

        $this->assertSame('Committed version', $snapshot['groups'][0]['title']);
        $this->assertSame($templateId, (int) $record->template_id);
        $this->assertNotNull($record->template_version_id);
        $this->assertSame('v2', $record->template_version);
        $this->assertSame('2026-05-01 12:00:00', $record->updated_at);
    }

    public function test_backfill_command_id_option_limits_updates(): void
    {
        [, $versionId] = $this->templateWithVersions([
            1 => ['label' => 'Limited version', 'published_at' => '2026-01-01 00:00:00'],
        ]);
        $firstId = $this->assessment(['template_version_id' => $versionId]);
        $secondId = $this->assessment(['template_version_id' => $versionId]);

        $this->assertSame(0, Artisan::call('legal-compliance:backfill-assessment-snapshots', [
            '--commit' => true,
            '--id' => [$firstId],
        ]));

        $this->assertNotNull(DB::table('legal_compliance_assessments')->where('id', $firstId)->value('template_snapshot'));
        $this->assertNull(DB::table('legal_compliance_assessments')->where('id', $secondId)->value('template_snapshot'));
    }

    public function test_backfill_command_does_not_mutate_unresolved_rows(): void
    {
        $assessmentId = $this->assessment([
            'template_id' => null,
            'template_version_id' => null,
            'template_version' => 'unknown',
        ]);

        $this->assertSame(0, Artisan::call('legal-compliance:backfill-assessment-snapshots', ['--commit' => true]));

        $record = DB::table('legal_compliance_assessments')->where('id', $assessmentId)->first();
        $this->assertNull($record->template_snapshot);
    }

    public function test_api_show_uses_historical_version_after_active_template_reorder(): void
    {
        $this->seedAuthenticatedUser();
        [$templateId, $versionOneId] = $this->templateWithVersions([
            1 => ['label' => 'Original first group', 'published_at' => '2026-01-01 00:00:00'],
            2 => ['label' => 'Reordered active group', 'published_at' => '2026-02-01 00:00:00'],
        ], true, 2);
        $assessmentId = $this->assessment([
            'staff_id' => 10,
            'template_id' => $templateId,
            'template_version_id' => $versionOneId,
            'template_version' => 'v1',
            'stage' => 'submitted',
        ]);

        $response = $this
            ->withSession(['user_id' => 1, 'staff_id' => 10, 'roles' => ['System Admin']])
            ->getJson("/legal-compliance-assessments/{$assessmentId}");

        $response->assertOk();
        $response->assertJsonPath('record.template_snapshot.groups.0.title', 'Original first group');
        $response->assertJsonPath('record.template_snapshot_resolution_source', 'version_id');
    }

    public function test_pdf_view_renders_unresolved_snapshot_warning(): void
    {
        $html = view('pdf.legal-compliance-assessment-report', [
            'record' => (object) [
                'id' => 99,
                'company_name' => 'Legacy Client',
                'site_location' => 'Legacy Site',
                'assessment_date' => '2026-05-01',
                'client_pic_name' => 'PIC',
                'client_pic_email' => 'pic@example.test',
                'nature_of_company' => '',
                'assessor_name' => 'Assessor',
                'assessor_email' => 'assessor@example.test',
                'project_name' => '',
                'revision_number' => 1,
                'submitted_by_name' => 'System Admin',
                'submitted_at' => '2026-05-01 00:00:00',
            ],
            'templateSnapshot' => [],
            'templateSnapshotUnresolved' => true,
            'templateSnapshotResolutionSource' => 'unresolved',
            'groups' => [],
            'clauseResponses' => [],
            'selectedAssessors' => [],
            'generatedDate' => '29 May 2026, 10:00 AM',
            'generatedByCode' => 'SA',
            'generatedById' => '1',
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('Template Snapshot Unresolved', $html);
        $this->assertStringContainsString('not inferred from the current active template', $html);
    }

    private function snapshotService(): LegalComplianceAssessmentSnapshotService
    {
        return app(LegalComplianceAssessmentSnapshotService::class);
    }

    private function templateWithVersions(array $versions, bool $isDefault = false, ?int $activeVersionNumber = null): array
    {
        $now = '2026-05-01 00:00:00';
        $templateId = DB::table('legal_compliance_templates')->insertGetId([
            'name' => 'Template',
            'slug' => 'template',
            'description' => 'Test template',
            'active_version_id' => null,
            'is_default' => $isDefault,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionIds = [];
        foreach ($versions as $number => $definition) {
            $versionIds[(int) $number] = DB::table('legal_compliance_template_versions')->insertGetId([
                'template_id' => $templateId,
                'version_number' => (int) $number,
                'content' => json_encode($this->snapshot($definition['label'])),
                'metadata' => null,
                'published_at' => $definition['published_at'] ?? $now,
                'created_at' => $definition['created_at'] ?? ($definition['published_at'] ?? $now),
                'updated_at' => $definition['updated_at'] ?? ($definition['published_at'] ?? $now),
            ]);
        }

        $activeVersionNumber ??= max(array_keys($versionIds));
        DB::table('legal_compliance_templates')
            ->where('id', $templateId)
            ->update(['active_version_id' => $versionIds[$activeVersionNumber] ?? reset($versionIds)]);

        return [$templateId, reset($versionIds), $versionIds];
    }

    private function snapshot(string $groupTitle): array
    {
        return [
            'assessment_tier' => 'free',
            'groups' => [[
                'id' => strtolower(str_replace(' ', '-', $groupTitle)),
                'title' => $groupTitle,
                'clauses' => [[
                    'id' => strtolower(str_replace(' ', '-', $groupTitle)).'-clause',
                    'title' => 'Clause',
                    'excerpt' => 'Excerpt',
                    'fields' => [[
                        'key' => 'finding',
                        'type' => 'textarea',
                        'required' => true,
                    ]],
                ]],
            ]],
        ];
    }

    private function assessment(array $overrides = []): int
    {
        $now = $overrides['created_at'] ?? '2026-05-01 00:00:00';

        return DB::table('legal_compliance_assessments')->insertGetId([
            'staff_id' => $overrides['staff_id'] ?? 10,
            'template_id' => $overrides['template_id'] ?? null,
            'template_version_id' => $overrides['template_version_id'] ?? null,
            'template_version' => $overrides['template_version'] ?? null,
            'template_snapshot' => $overrides['template_snapshot'] ?? null,
            'stage' => $overrides['stage'] ?? 'submitted',
            'parent_assessment_id' => null,
            'revision_number' => 1,
            'superseded_by_assessment_id' => null,
            'company_name' => 'Client',
            'site_location' => 'Site',
            'client_company_id' => null,
            'client_branch_id' => null,
            'client_pic_id' => null,
            'client_pic_name' => 'PIC',
            'client_pic_email' => 'pic@example.test',
            'project_id' => null,
            'project_name' => null,
            'assessment_date' => $overrides['assessment_date'] ?? '2026-05-01',
            'assessor_name' => 'Assessor',
            'assessor_email' => 'assessor@example.test',
            'nature_of_company' => null,
            'selected_assessors' => json_encode([]),
            'clause_responses' => json_encode([]),
            'submitted_at' => $overrides['submitted_at'] ?? '2026-05-01 00:00:00',
            'submitted_by_staff_id' => null,
            'deleted_at' => null,
            'deleted_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $overrides['updated_at'] ?? $now,
        ]);
    }

    private function seedAuthenticatedUser(): void
    {
        DB::table('staff_general')->insert([
            'staff_id' => 10,
            'full_name' => 'System Admin',
            'name_code' => 'SA',
            'email' => 'admin@example.test',
        ]);

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'admin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => true,
            'created_at' => '2026-05-01 00:00:00',
            'updated_at' => '2026-05-01 00:00:00',
        ]);
    }
}
