<?php

namespace Tests\Feature;

use App\Services\Translation\TranslationException;
use App\Services\Translation\TranslationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class ProposalTemplateBmCopyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createBmCopySchema();
        $this->app->instance(TranslationService::class, new FakeBmTranslationService());
    }

    public function test_it_creates_bm_copies_for_all_proposal_types_and_returns_bm_template_id(): void
    {
        foreach (['training', 'ih', 'manpower', 'special'] as $type) {
            $sourceId = $this->insertSourceTemplate($type);

            $response = $this->authenticated()
                ->postJson("/proposal-templates/{$type}/{$sourceId}/bm-copy");

            $response->assertCreated()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.sourceTemplateId', $sourceId)
                ->assertJsonPath('data.proposalLanguage', 'ms-MY');

            $bmTemplateId = $response->json('bmTemplateId');
            $this->assertIsInt($bmTemplateId);
            $this->assertSame($bmTemplateId, $response->json('id'));
            $this->assertSame($bmTemplateId, $response->json('data.id'));
            $this->assertSame($bmTemplateId, $response->json('data.bmTemplateId'));

            $row = DB::table($this->tableForType($type))->where('id', $bmTemplateId)->first();
            $this->assertSame('ms-MY', $row->proposal_language);
            $this->assertSame($sourceId, (int) $row->source_template_id);
            $this->assertSame('machine_draft', $row->translation_status);
            $this->assertNull($row->active_bm_source_template_id);
            $this->assertDatabaseCount($this->historyTableForType($type), 1);
        }

        $agenda = DB::table('proposal_template_training_agenda')
            ->where('template_id', 2)
            ->first();
        $this->assertSame('BM:Agenda topic', $agenda->topic);
    }

    public function test_active_duplicate_returns_existing_bm_template_id_without_creating_another_copy(): void
    {
        $sourceId = $this->insertSourceTemplate('manpower');
        $existingId = $this->insertBmTemplate('manpower', $sourceId, isDeleted: false);

        $response = $this->authenticated()
            ->postJson("/proposal-templates/manpower/{$sourceId}/bm-copy");

        $response->assertOk()
            ->assertJsonPath('message', 'BM copy already exists.')
            ->assertJsonPath('bmTemplateId', $existingId)
            ->assertJsonPath('data.bmTemplateId', $existingId);

        $this->assertSame(2, DB::table('proposal_template_manpower')->count());
        $this->assertDatabaseCount('proposal_template_manpower_history', 0);
    }

    public function test_soft_deleted_duplicate_does_not_block_creating_a_fresh_bm_copy(): void
    {
        foreach (['training', 'ih', 'manpower', 'special'] as $type) {
            $sourceId = $this->insertSourceTemplate($type);
            $existingId = $this->insertBmTemplate($type, $sourceId, isDeleted: true);

            $response = $this->authenticated()
                ->postJson("/proposal-templates/{$type}/{$sourceId}/bm-copy");

            $response->assertCreated()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('data.sourceTemplateId', $sourceId)
                ->assertJsonPath('data.proposalLanguage', 'ms-MY');

            $bmTemplateId = $response->json('bmTemplateId');
            $this->assertIsInt($bmTemplateId);
            $this->assertNotSame($existingId, $bmTemplateId);
            $this->assertSame(3, DB::table($this->tableForType($type))->count());
            $this->assertSame(
                1,
                DB::table($this->tableForType($type))
                    ->where('source_template_id', $sourceId)
                    ->where('proposal_language', 'ms-MY')
                    ->where('is_deleted', 0)
                    ->count()
            );
            $this->assertDatabaseCount($this->historyTableForType($type), 1);
        }
    }

    public function test_translation_failure_rolls_back_template_history_and_training_agenda(): void
    {
        $this->app->instance(TranslationService::class, new FailingBmTranslationService());
        $sourceId = $this->insertSourceTemplate('training');

        $response = $this->authenticated()
            ->postJson("/proposal-templates/training/{$sourceId}/bm-copy");

        $response->assertStatus(502)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('Translation provider unavailable.', $response->json('message'));

        $this->assertSame(1, DB::table('proposal_template_training_main')->count());
        $this->assertSame(1, DB::table('proposal_template_training_agenda')->count());
        $this->assertDatabaseCount('proposal_template_training_history', 0);
    }

    public function test_special_attachment_copy_is_deleted_when_database_insert_fails(): void
    {
        Storage::fake('public');
        Storage::fake('private');
        $this->recreateSpecialAttachmentsTable(requiredTargetColumn: true);

        $sourceId = $this->insertSourceTemplate('special');
        Storage::disk('public')->put('proposal-templates/special/source/source.pdf', 'pdf');
        DB::table('proposal_special_attachments')->insert([
            'template_id' => $sourceId,
            'original_filename' => 'source.pdf',
            'stored_path' => 'proposal-templates/special/source/source.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'required_col' => 'source-only',
            'created_at' => now(),
        ]);

        $response = $this->authenticated()
            ->postJson("/proposal-templates/special/{$sourceId}/bm-copy");

        $response->assertStatus(500)
            ->assertJsonPath('status', 'error');

        $this->assertSame(1, DB::table('proposal_template_special')->count());
        $this->assertDatabaseCount('proposal_template_special_history', 0);
        Storage::disk('public')->assertExists('proposal-templates/special/source/source.pdf');
        $this->assertSame(
            ['proposal-templates/special/source/source.pdf'],
            Storage::disk('public')->allFiles('proposal-templates/special')
        );
        $this->assertSame([], Storage::disk('private')->allFiles('proposal-templates/special'));
    }

    private function createBmCopySchema(): void
    {
        foreach ([
            'proposal_template_training_agenda',
            'proposal_special_attachments',
            'proposal_template_training_history',
            'proposal_template_ih_history',
            'proposal_template_manpower_history',
            'proposal_template_special_history',
            'proposal_template_training_main',
            'proposal_template_ih',
            'proposal_template_manpower',
            'proposal_template_special',
            'user_activities',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTemplateTable('proposal_template_training_main', 'training_title', [
            'training_code',
            'hrd_no',
            'introduction',
            'objectives',
            'modules',
            'training_requirements',
            'additional_training_requirements',
            'training_materials',
            'lecture_medium',
            'duration',
            'method_theory_desc',
            'method_practical_desc',
        ], includeMethods: true);
        $this->createTemplateTable('proposal_template_ih', 'service_title', [
            'service_code',
            'introduction',
            'objectives',
            'work_scope',
            'schedule',
            'reference',
            'other_fields',
        ]);
        $this->createTemplateTable('proposal_template_manpower', 'service_title', [
            'service_code',
            'introduction',
            'service_deliverables',
            'supplied_manpower_deliverables',
            'custom_section',
        ]);
        $this->createTemplateTable('proposal_template_special', 'service_title', [
            'service_code',
            'content',
        ]);

        foreach ([
            'proposal_template_training_history',
            'proposal_template_ih_history',
            'proposal_template_manpower_history',
            'proposal_template_special_history',
        ] as $historyTable) {
            Schema::create($historyTable, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->text('remarks')->nullable();
                $table->string('created_by')->nullable();
                $table->string('action')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        Schema::create('proposal_template_training_agenda', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->integer('day')->default(1);
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->text('topic')->nullable();
        });

        $this->recreateSpecialAttachmentsTable();

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->text('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('system_users')->insert([
            'id' => 10,
            'staff_id' => 7,
            'email' => 'proposal-admin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);
    }

    private function createTemplateTable(string $tableName, string $titleColumn, array $textColumns, bool $includeMethods = false): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($titleColumn, $textColumns, $includeMethods): void {
            $table->id();
            $table->string($titleColumn)->nullable();
            foreach ($textColumns as $column) {
                if ($column === $titleColumn) {
                    continue;
                }
                $table->text($column)->nullable();
            }
            if ($includeMethods) {
                $table->integer('method_theory')->default(0);
                $table->integer('method_practical')->default(0);
            }
            $table->string('proposal_language', 10)->default('en');
            $table->unsignedBigInteger('source_template_id')->nullable();
            $table->string('translation_provider', 50)->nullable();
            $table->string('translation_status', 50)->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->text('translation_notes')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->unsignedBigInteger('active_bm_source_template_id')->nullable();
        });
    }

    private function recreateSpecialAttachmentsTable(bool $requiredTargetColumn = false): void
    {
        Schema::dropIfExists('proposal_special_attachments');
        Schema::create('proposal_special_attachments', function (Blueprint $table) use ($requiredTargetColumn): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            if ($requiredTargetColumn) {
                $table->string('required_col');
            }
            $table->timestamp('created_at')->nullable();
        });
    }

    private function insertSourceTemplate(string $type): int
    {
        $table = $this->tableForType($type);
        $payload = $this->templatePayload($type, 'en', null, 0);
        $id = (int) DB::table($table)->insertGetId($payload);

        if ($type === 'training') {
            DB::table('proposal_template_training_agenda')->insert([
                'template_id' => $id,
                'day' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'topic' => 'Agenda topic',
            ]);
        }

        return $id;
    }

    private function insertBmTemplate(string $type, int $sourceId, bool $isDeleted): int
    {
        return (int) DB::table($this->tableForType($type))->insertGetId(
            $this->templatePayload($type, 'ms-MY', $sourceId, $isDeleted ? 1 : 0)
        );
    }

    private function templatePayload(string $type, string $language, ?int $sourceTemplateId, int $isDeleted): array
    {
        $base = [
            'proposal_language' => $language,
            'source_template_id' => $sourceTemplateId,
            'translation_status' => $language === 'ms-MY' ? 'machine_draft' : null,
            'is_deleted' => $isDeleted,
            'created_by' => 7,
            'created_at' => now(),
            'active_bm_source_template_id' => 999,
        ];

        return match ($type) {
            'training' => $base + [
                'training_title' => 'Safety Training',
                'training_code' => 'TRN-1',
                'hrd_no' => 'HRD-1',
                'introduction' => 'Intro',
                'objectives' => 'Objectives',
                'modules' => 'Modules',
                'training_requirements' => 'Requirements',
                'additional_training_requirements' => 'Additional',
                'training_materials' => 'Materials',
                'lecture_medium' => 'English',
                'duration' => '1 day',
                'method_theory' => 1,
                'method_theory_desc' => 'Theory',
                'method_practical' => 1,
                'method_practical_desc' => 'Practical',
            ],
            'ih' => $base + [
                'service_title' => 'IH Survey',
                'service_code' => 'IH-1',
                'introduction' => 'Intro',
                'objectives' => 'Objectives',
                'work_scope' => 'Scope',
                'schedule' => 'Schedule',
                'reference' => 'Reference',
                'other_fields' => 'Other',
            ],
            'manpower' => $base + [
                'service_title' => 'Manpower',
                'service_code' => 'MP-1',
                'introduction' => 'Intro',
                'service_deliverables' => 'Deliverables',
                'supplied_manpower_deliverables' => 'Supply',
                'custom_section' => 'Custom',
            ],
            'special' => $base + [
                'service_title' => 'Special',
                'service_code' => 'SP-1',
                'content' => 'Content',
            ],
        };
    }

    private function tableForType(string $type): string
    {
        return match ($type) {
            'training' => 'proposal_template_training_main',
            'ih' => 'proposal_template_ih',
            'manpower' => 'proposal_template_manpower',
            'special' => 'proposal_template_special',
        };
    }

    private function historyTableForType(string $type): string
    {
        return match ($type) {
            'training' => 'proposal_template_training_history',
            'ih' => 'proposal_template_ih_history',
            'manpower' => 'proposal_template_manpower_history',
            'special' => 'proposal_template_special_history',
        };
    }

    private function sessionPayload(): array
    {
        return [
            '_token' => 'test-csrf-token',
            'user_id' => 10,
            'staff_id' => 7,
            'name_code' => 'QA',
            'roles' => ['System Admin'],
        ];
    }

    private function authenticated()
    {
        return $this
            ->withSession($this->sessionPayload())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}

class FakeBmTranslationService implements TranslationService
{
    public function translateText(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string
    {
        return 'BM:' . $text;
    }

    public function translateHtml(string $html, string $targetLanguage, string $sourceLanguage = 'en'): string
    {
        return 'BM:' . $html;
    }
}

class FailingBmTranslationService implements TranslationService
{
    public function translateText(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string
    {
        throw new TranslationException('Translation provider unavailable.');
    }

    public function translateHtml(string $html, string $targetLanguage, string $sourceLanguage = 'en'): string
    {
        throw new TranslationException('Translation provider unavailable.');
    }
}
