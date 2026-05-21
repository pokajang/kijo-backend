<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProposalTemplateCreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_it_creates_all_proposal_template_types(): void
    {
        Storage::fake('private');

        $this->authenticated()
            ->postJson('/proposal-templates/training', $this->trainingPayload())
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->authenticated()
            ->postJson('/proposal-templates/ih', $this->ihPayload())
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->authenticated()
            ->postJson('/proposal-templates/manpower', $this->manpowerPayload())
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->authenticated()
            ->post('/proposal-templates/special', $this->specialPayload([
                'attachments' => [
                    UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('proposal_template_training_main', 1);
        $this->assertDatabaseCount('proposal_template_training_agenda', 1);
        $this->assertDatabaseCount('proposal_template_training_history', 1);
        $this->assertDatabaseCount('proposal_template_ih', 1);
        $this->assertDatabaseCount('proposal_template_ih_history', 1);
        $this->assertDatabaseCount('proposal_template_manpower', 1);
        $this->assertDatabaseCount('proposal_template_manpower_history', 1);
        $this->assertDatabaseCount('proposal_template_special', 1);
        $this->assertDatabaseCount('proposal_special_attachments', 1);
        $this->assertDatabaseCount('proposal_template_special_history', 1);
    }

    public function test_training_creation_validates_required_content_and_agenda_topic_length(): void
    {
        $payload = $this->trainingPayload([
            'introduction' => '',
            'objectives' => '',
            'agenda' => [
                [
                    'day' => 1,
                    'start' => '09:00',
                    'end' => '10:00',
                    'topic' => str_repeat('A', 501),
                ],
            ],
        ]);

        $this->authenticated()
            ->postJson('/proposal-templates/training', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['introduction', 'objectives', 'agenda.0.topic']);
    }

    public function test_ih_creation_requires_introduction(): void
    {
        $this->authenticated()
            ->postJson('/proposal-templates/ih', $this->ihPayload(['introduction' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['introduction']);
    }

    public function test_manpower_creation_requires_introduction_and_service_deliverables(): void
    {
        $this->authenticated()
            ->postJson('/proposal-templates/manpower', $this->manpowerPayload([
                'introduction' => '',
                'serviceDeliverables' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['introduction', 'serviceDeliverables']);
    }

    public function test_special_creation_validates_attachment_type_and_size(): void
    {
        Storage::fake('private');

        $this->authenticated()
            ->post('/proposal-templates/special', $this->specialPayload([
                'attachments' => [
                    UploadedFile::fake()->create('proposal.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                    UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0', 'attachments.1']);
    }

    private function createSchema(): void
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

        Schema::create('proposal_template_training_main', function (Blueprint $table): void {
            $table->id();
            $table->string('training_title')->nullable();
            $table->string('training_code')->nullable();
            $table->string('hrd_no')->nullable();
            $table->text('introduction')->nullable();
            $table->text('objectives')->nullable();
            $table->text('modules')->nullable();
            $table->text('training_requirements')->nullable();
            $table->text('additional_training_requirements')->nullable();
            $table->text('training_materials')->nullable();
            $table->string('lecture_medium')->nullable();
            $table->string('duration')->nullable();
            $table->integer('method_theory')->default(0);
            $table->text('method_theory_desc')->nullable();
            $table->integer('method_practical')->default(0);
            $table->text('method_practical_desc')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('proposal_template_training_agenda', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->integer('day')->default(1);
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->text('topic')->nullable();
        });

        Schema::create('proposal_template_ih', function (Blueprint $table): void {
            $table->id();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->text('introduction')->nullable();
            $table->text('objectives')->nullable();
            $table->text('work_scope')->nullable();
            $table->text('schedule')->nullable();
            $table->text('reference')->nullable();
            $table->text('other_fields')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('proposal_template_manpower', function (Blueprint $table): void {
            $table->id();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->text('introduction')->nullable();
            $table->text('service_deliverables')->nullable();
            $table->text('supplied_manpower_deliverables')->nullable();
            $table->text('custom_section')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('proposal_template_special', function (Blueprint $table): void {
            $table->id();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->text('content')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('proposal_special_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->timestamp('created_at')->nullable();
        });

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

    private function trainingPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'trainingTitle' => 'Safety Training',
            'trainingCode' => 'SAFE',
            'hrdNo' => 'HRD-1',
            'introduction' => '<p>Intro</p>',
            'objectives' => '<p>Objectives</p>',
            'modules' => '<p>Modules</p>',
            'trainingRequirements' => 'Training room',
            'additionalTrainingRequirements' => '',
            'trainingMaterials' => 'Slides',
            'lectureMedium' => 'English',
            'duration' => '1day',
            'method_theory' => true,
            'method_theory_desc' => 'Theory',
            'method_practical' => false,
            'method_practical_desc' => '',
            'agenda' => [
                [
                    'day' => 1,
                    'start' => '09:00',
                    'end' => '10:00',
                    'topic' => 'Introduction',
                ],
            ],
            'remarks' => '<p>Created for test</p>',
        ], $overrides);
    }

    private function ihPayload(array $overrides = []): array
    {
        return array_replace([
            'serviceTitle' => 'IH Survey',
            'serviceCode' => 'IH',
            'introduction' => '<p>Intro</p>',
            'objectives' => '<p>Objectives</p>',
            'workScope' => '<p>Scope</p>',
            'schedule' => '',
            'reference' => '',
            'otherFields' => '',
            'remarks' => '<p>Created for test</p>',
        ], $overrides);
    }

    private function manpowerPayload(array $overrides = []): array
    {
        return array_replace([
            'serviceTitle' => 'Manpower',
            'serviceCode' => 'MP',
            'introduction' => '<p>Intro</p>',
            'serviceDeliverables' => '<p>Deliverables</p>',
            'suppliedManpowerDeliverables' => '',
            'customSection' => '',
            'remarks' => '<p>Created for test</p>',
        ], $overrides);
    }

    private function specialPayload(array $overrides = []): array
    {
        return array_replace([
            'serviceTitle' => 'Special Service',
            'serviceCode' => 'SP',
            'content' => '<p>Content</p>',
            'remarks' => '<p>Created for test</p>',
        ], $overrides);
    }

    private function authenticated()
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 10,
                'staff_id' => 7,
                'name_code' => 'QA',
                'roles' => ['System Admin'],
            ])
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
