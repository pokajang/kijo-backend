<?php

namespace Tests\Feature;

use App\Jobs\EnrichTaskClassificationWithAiJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('task_classification_examples');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('project_progress');
        Schema::dropIfExists('project_collaborators');
        Schema::dropIfExists('projects_main');
        Schema::dropIfExists('staff_general');
        Schema::dropIfExists('system_users');

        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('staff_general', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedInteger('project_id')->nullable();
            $table->unsignedInteger('project_progress_id')->nullable();
            $table->string('title');
            $table->string('task_category')->default('uncategorised');
            $table->decimal('effort_score', 4, 1)->default(1);
            $table->string('classification_confidence')->nullable();
            $table->string('classification_source')->default('system');
            $table->boolean('user_override')->default(false);
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->default('unclear');
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->string('ai_classification_status')->nullable();
            $table->timestamp('ai_classification_queued_at')->nullable();
            $table->timestamp('ai_classification_started_at')->nullable();
            $table->timestamp('ai_classification_completed_at')->nullable();
            $table->string('ai_classification_error')->nullable();
            $table->string('status');
            $table->date('due_date');
            $table->timestamp('created_at')->nullable();
            $table->date('completed_at')->nullable();
        });

        Schema::create('task_classification_examples', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_title_hash', 64)->unique();
            $table->string('normalized_title', 255);
            $table->string('sample_title', 255);
            $table->string('task_category');
            $table->decimal('effort_score', 4, 1);
            $table->string('classification_confidence');
            $table->string('classification_source')->default('ai');
            $table->string('matched_pattern')->nullable();
            $table->string('work_type')->default('unclear');
            $table->string('work_type_confidence')->nullable();
            $table->string('work_type_matched_pattern')->nullable();
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->text('comment');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('projects_main', function (Blueprint $table) {
            $table->increments('id');
            $table->string('project_name');
            $table->string('status');
        });

        Schema::create('project_collaborators', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('project_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('project_role');
        });

        Schema::create('project_progress', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('progress_date');
            $table->longText('progress_text');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_task_id')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 42,
            'email' => 'tasks@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);

        DB::table('staff_general')->insert([
            'staff_id' => 42,
            'full_name' => 'Task Tester',
            'name_code' => 'TST',
        ]);

        DB::table('projects_main')->insert([
            ['id' => 100, 'project_name' => 'Active Project', 'status' => 'Active'],
            ['id' => 101, 'project_name' => 'Closed Project', 'status' => 'Completed'],
            ['id' => 102, 'project_name' => 'Unrelated Active Project', 'status' => 'Active'],
            ['id' => 104, 'project_name' => 'Owner Active Project', 'status' => 'Active'],
        ]);

        DB::table('project_collaborators')->insert([
            ['project_id' => 100, 'staff_id' => 42, 'project_role' => 'Leader'],
            ['project_id' => 101, 'staff_id' => 42, 'project_role' => 'Assistant'],
            ['project_id' => 102, 'staff_id' => 99, 'project_role' => 'Collaborator'],
            ['project_id' => 104, 'staff_id' => 42, 'project_role' => 'Owner'],
        ]);
    }

    public function test_batch_create_tasks_persists_multiple_tasks(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Follow up clients', 'due_date' => '2026-05-14'],
                    ['title' => 'Prepare report', 'due_date' => '2026-05-15'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Follow up clients')
            ->assertJsonPath('tasks.1.title', 'Prepare report');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Follow up clients',
            'due_date' => '2026-05-14',
            'status' => 'Ongoing',
        ]);
        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Prepare report',
            'due_date' => '2026-05-15',
            'status' => 'Ongoing',
        ]);
    }

    public function test_classify_task_previews_backend_classification(): void
    {
        $this->authenticated()
            ->postJson('/tasks/classify', ['title' => 'Create training module'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('classification.taskCategory', 'real_effort')
            ->assertJsonPath('classification.taskCategoryLabel', 'Real Effort')
            ->assertJsonPath('classification.effortScore', 3)
            ->assertJsonPath('classification.classificationConfidence', 'high')
            ->assertJsonPath('classification.classificationSource', 'system')
            ->assertJsonPath('classification.userOverride', false)
            ->assertJsonPath('classification.matchedPattern', 'rule:real_effort')
            ->assertJsonPath('classification.workType', 'training_delivery')
            ->assertJsonPath('classification.workTypeLabel', 'Training / Delivery')
            ->assertJsonPath('classification.workTypeConfidence', 'high');
    }

    public function test_classify_task_preview_defaults_empty_titles(): void
    {
        foreach (['', '   '] as $title) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', 'uncategorised')
                ->assertJsonPath('classification.taskCategoryLabel', 'General Task')
                ->assertJsonPath('classification.effortScore', 1)
                ->assertJsonPath('classification.classificationConfidence', 'low')
                ->assertJsonPath('classification.matchedPattern', null);
        }
    }

    public function test_classify_task_preview_marks_unclear_titles_as_not_graded(): void
    {
        foreach (['random unknown noun', 'abc thing', 'api hr po sop', 'ok', 'hmm'] as $title) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', 'unclear_unrated')
                ->assertJsonPath('classification.taskCategoryLabel', 'Unclear / Not graded')
                ->assertJsonPath('classification.effortScore', 0)
                ->assertJsonPath('classification.classificationConfidence', 'low')
                ->assertJsonPath('classification.matchedPattern', 'unclear:no_work_signal')
                ->assertJsonPath('classification.workType', 'unclear')
                ->assertJsonPath('classification.workTypeLabel', 'Unclear');
        }
    }

    public function test_classify_task_preview_marks_clear_non_work_as_non_rated(): void
    {
        foreach ([
            'watching tv',
            'makan nasi ayam',
            'main game',
            'personal errand',
            'tidur',
            'pergi makan',
            'minum kopi',
            'tengok youtube',
            'urusan peribadi',
            'beli barang',
            'ubi kayu',
            'tapioca',
        ] as $title) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', 'non_work')
                ->assertJsonPath('classification.taskCategoryLabel', 'Non-rated / Not graded')
                ->assertJsonPath('classification.effortScore', 0)
                ->assertJsonPath('classification.classificationConfidence', 'high')
                ->assertJsonPath('classification.workType', 'non_work')
                ->assertJsonPath('classification.workTypeLabel', 'Non-work');
        }
    }

    public function test_classify_task_preview_marks_gibberish_and_trash_as_non_rated(): void
    {
        foreach (['?????', '$$$$', 'asdfasdf', 'qwerty', 'zz ok', '111111', 'xxxxx'] as $title) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', 'non_work')
                ->assertJsonPath('classification.taskCategoryLabel', 'Non-rated / Not graded')
                ->assertJsonPath('classification.effortScore', 0)
                ->assertJsonPath('classification.classificationConfidence', 'high');
        }
    }

    public function test_classify_task_preview_does_not_discount_work_context_with_non_work_words(): void
    {
        foreach (['lunch meeting with client', 'prepare proposal for ubi kayu supplier'] as $title) {
            $response = $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success');

            $this->assertNotSame('non_work', $response->json('classification.taskCategory'));
        }
    }

    public function test_classify_task_preview_keeps_weak_but_work_related_tasks_scored(): void
    {
        $cases = [
            ['check document', ['administrative', 'uncategorised'], 1],
            ['update record', ['administrative', 'uncategorised'], 1],
            ['admin task', ['administrative', 'uncategorised'], 1],
            ['review deck', ['uncategorised'], 1],
        ];

        foreach ($cases as [$title, $allowedCategories, $effortScore]) {
            $response = $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.effortScore', $effortScore);

            $this->assertContains($response->json('classification.taskCategory'), $allowedCategories);
        }
    }

    public function test_classify_task_preview_uses_fuzzy_fallback(): void
    {
        $response = $this->authenticated()
            ->postJson('/tasks/classify', ['title' => 'create trainng mdle'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('classification.taskCategory', 'real_effort')
            ->assertJsonPath('classification.taskCategoryLabel', 'Real Effort')
            ->assertJsonPath('classification.effortScore', 3);

        $this->assertContains(
            $response->json('classification.classificationConfidence'),
            ['high', 'medium'],
        );
        $this->assertContains(
            $response->json('classification.matchedPattern'),
            ['create training module', 'rule:real_effort'],
        );
    }

    public function test_classify_task_preview_corrects_safe_workload_typos(): void
    {
        $cases = [
            ['prpe custom new propoosal', 'real_effort', 3],
            ['develop new featre in kijo', 'deep_work', 5],
            ['reconcle supplier acount', 'real_effort', 3],
            ['prodction login outage', 'critical_escalation', 4],
        ];

        foreach ($cases as [$title, $category, $effortScore]) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', $category)
                ->assertJsonPath('classification.effortScore', $effortScore);
        }
    }

    public function test_classify_task_preview_does_not_overcorrect_short_or_protected_tokens(): void
    {
        foreach (['api hr po sop', 'ok', 'hmm'] as $title) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', 'unclear_unrated')
                ->assertJsonPath('classification.taskCategoryLabel', 'Unclear / Not graded')
                ->assertJsonPath('classification.effortScore', 0)
                ->assertJsonPath('classification.classificationConfidence', 'low')
                ->assertJsonPath('classification.matchedPattern', 'unclear:no_work_signal');
        }
    }

    public function test_classify_task_preview_covers_common_office_workloads(): void
    {
        $cases = [
            ['Develop new customer portal feature', 'deep_work', 5],
            ['Develop new feature in Kijo due in five days', 'deep_work', 5],
            ['Technical report analysis for client', 'deep_work', 5],
            ['Finance month end closing and cash flow forecast', 'deep_work', 5],
            ['HR workforce planning for next quarter', 'deep_work', 5],
            ['Manager design KPI framework for operations team', 'deep_work', 5],
            ['Refactor legacy module and optimize database performance', 'deep_work', 5],
            ['Debug payment gateway problem', 'real_effort', 3],
            ['Fix login bug', 'real_effort', 3],
            ['Develop new landing page for campaign', 'real_effort', 3],
            ['Reconcile supplier account', 'real_effort', 3],
            ['HR develop new SOP for onboarding', 'real_effort', 3],
            ['Write rules and regulations for leave policy', 'real_effort', 3],
            ['Provide 1 day training to client team', 'real_effort', 3],
            ['Outstation audit support for two nights', 'real_effort', 3],
            ['Prepare payroll report', 'real_effort', 3],
            ['Disciplinary investigation', 'deep_work', 5],
            ['Arrange vendor delivery schedule', 'coordination_follow_up', 2],
            ['Arrange interview', 'coordination_follow_up', 2],
            ['Update invoice record', 'administrative', 1],
            ['Waiting for HR approval', 'pending_waiting', 0.5],
            ['Waiting client approval', 'pending_waiting', 0.5],
            ['Production login outage', 'critical_escalation', 4],
            ['Production outage payment gateway down', 'critical_escalation', 4],
        ];

        foreach ($cases as [$title, $category, $effortScore]) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', $category)
                ->assertJsonPath('classification.effortScore', $effortScore)
                ->assertJsonPath('classification.classificationConfidence', 'high');
        }
    }

    public function test_classify_task_preview_groups_synonymous_work_phrases(): void
    {
        $cases = [
            ['Create custom proposal', 'real_effort', 3],
            ['Prepare new custom proposal from scratch', 'real_effort', 3],
            ['Draft tailored proposal for client', 'real_effort', 3],
            ['Prepare technical-commercial proposal', 'real_effort', 3],
            ['Write new proposal for client', 'real_effort', 3],
            ['Make technical proposal for client', 'real_effort', 3],
            ['Do commercial proposal costing', 'real_effort', 3],
            ['Generate price estimate for quotation', 'real_effort', 3],
            ['Compose scope of work and method statement', 'real_effort', 3],
            ['Prepare write-up for client submission', 'real_effort', 3],
            ['Report writing for client', 'real_effort', 3],
            ['Write client report', 'real_effort', 3],
            ['Prepare compliance report for client', 'real_effort', 3],
            ['Writing financial report for management', 'real_effort', 3],
            ['Reviewing audit report before submission', 'real_effort', 3],
            ['Draft MOM for management meeting', 'real_effort', 3],
            ['Buat cadangan komersial untuk client', 'real_effort', 3],
            ['Sediakan sebutharga dan costing', 'real_effort', 3],
        ];

        foreach ($cases as [$title, $category, $effortScore]) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.taskCategory', $category)
                ->assertJsonPath('classification.effortScore', $effortScore)
                ->assertJsonPath('classification.classificationConfidence', 'high');
        }
    }

    public function test_classify_task_preview_assigns_work_type_axis(): void
    {
        $cases = [
            ['Update invoice record', 'clerical_admin'],
            ['Arrange meeting with client', 'coordination_followup'],
            ['Prepare technical proposal for client', 'commercial_sales'],
            ['Coordinate outstation logistics', 'operations_logistics'],
            ['Prepare technical report for CHRA audit', 'technical_specialist'],
            ['Develop new feature in Kijo', 'software_it'],
            ['Month end closing', 'finance_hr'],
            ['Design KPI framework', 'management_strategy'],
            ['Conduct training for client', 'training_delivery'],
            ['Edit storyboard video', 'creative_content'],
            ['watching tv', 'non_work'],
            ['random unknown noun', 'unclear'],
        ];

        foreach ($cases as [$title, $workType]) {
            $this->authenticated()
                ->postJson('/tasks/classify', ['title' => $title])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('classification.workType', $workType)
                ->assertJsonPath('classification.workTypeConfidence', fn ($value) => is_string($value) && $value !== '');
        }
    }

    public function test_classify_task_preview_uses_learned_ai_classification_without_openai_call(): void
    {
        DB::table('task_classification_examples')->insert([
            'normalized_title_hash' => hash('sha256', 'random unknown noun'),
            'normalized_title' => 'random unknown noun',
            'sample_title' => 'random unknown noun',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'medium',
            'classification_source' => 'ai',
            'matched_pattern' => 'ai:previously learned classification',
            'work_type' => 'commercial_sales',
            'work_type_confidence' => 'medium',
            'work_type_matched_pattern' => 'ai:previously learned classification',
            'usage_count' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticated()
            ->postJson('/tasks/classify', ['title' => 'random unknown noun'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('classification.taskCategory', 'real_effort')
            ->assertJsonPath('classification.effortScore', 3)
            ->assertJsonPath('classification.classificationSource', 'ai_cache')
            ->assertJsonPath('classification.matchedPattern', 'ai:previously learned classification')
            ->assertJsonPath('classification.workType', 'commercial_sales');
    }

    public function test_batch_create_tasks_persists_classification_metadata(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    [
                        'title' => 'Create training module',
                        'due_date' => '2026-05-14',
                        'task_category' => 'critical_escalation',
                        'effort_score' => 99,
                        'classification_confidence' => 'certain',
                        'classification_source' => 'user',
                        'user_override' => true,
                        'matched_pattern' => 'server down',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.taskCategory', 'real_effort')
            ->assertJsonPath('tasks.0.effortScore', 3)
            ->assertJsonPath('tasks.0.classificationConfidence', 'high')
            ->assertJsonPath('tasks.0.classificationSource', 'system')
            ->assertJsonPath('tasks.0.aiClassificationStatus', 'not_applicable')
            ->assertJsonPath('tasks.0.userOverride', false)
            ->assertJsonPath('tasks.0.matchedPattern', 'rule:real_effort');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Create training module',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_confidence' => 'high',
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'rule:real_effort',
        ]);
    }

    public function test_batch_create_tasks_classifies_phrases_with_extra_descriptive_words(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Create training new custom module', 'due_date' => '2026-05-14'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.taskCategory', 'real_effort')
            ->assertJsonPath('tasks.0.effortScore', 3)
            ->assertJsonPath('tasks.0.classificationConfidence', 'high')
            ->assertJsonPath('tasks.0.matchedPattern', 'rule:real_effort');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Create training new custom module',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'matched_pattern' => 'rule:real_effort',
        ]);
    }

    public function test_batch_create_tasks_classifies_mixed_language_typos_and_abbreviations(): void
    {
        $cases = [
            ['Create traning modle', 'real_effort', 3, 'rule:real_effort'],
            ['Sediakan proposal baru', 'real_effort', 3, 'rule:real_effort'],
            ['Tunggu aproval boss', 'pending_waiting', 0.5, 'rule:pending_waiting'],
            ['Fup po client', 'coordination_follow_up', 2, 'rule:coordination_follow_up'],
            ['Followup payment customer', 'coordination_follow_up', 2, 'rule:coordination_follow_up'],
            ['Tak boleh login system', 'critical_escalation', 4, 'rule:critical_escalation'],
        ];

        foreach ($cases as [$title, $category, $effortScore, $matchedPattern]) {
            $this->authenticated()
                ->postJson('/tasks/batch', [
                    'tasks' => [
                        ['title' => $title, 'due_date' => '2026-05-14'],
                    ],
                ])
                ->assertOk()
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('tasks.0.taskCategory', $category)
                ->assertJsonPath('tasks.0.effortScore', $effortScore)
                ->assertJsonPath('tasks.0.classificationConfidence', 'high')
                ->assertJsonPath('tasks.0.matchedPattern', $matchedPattern);
        }
    }

    public function test_batch_create_tasks_defaults_missing_classification_metadata(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Random unknown noun', 'due_date' => '2026-05-14'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.taskCategory', 'unclear_unrated')
            ->assertJsonPath('tasks.0.effortScore', 0)
            ->assertJsonPath('tasks.0.classificationConfidence', 'low')
            ->assertJsonPath('tasks.0.classificationSource', 'system')
            ->assertJsonPath('tasks.0.userOverride', false)
            ->assertJsonPath('tasks.0.matchedPattern', 'unclear:no_work_signal');
    }

    public function test_batch_create_tasks_ignores_tampered_classification_metadata(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    [
                        'title' => 'Email document to client',
                        'due_date' => '2026-05-14',
                        'task_category' => 'critical_escalation',
                        'effort_score' => 4,
                        'classification_confidence' => 'certain',
                        'classification_source' => 'user',
                        'user_override' => true,
                        'matched_pattern' => 'server down',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.taskCategory', 'administrative')
            ->assertJsonPath('tasks.0.effortScore', 1)
            ->assertJsonPath('tasks.0.classificationConfidence', 'high')
            ->assertJsonPath('tasks.0.classificationSource', 'system')
            ->assertJsonPath('tasks.0.userOverride', false)
            ->assertJsonPath('tasks.0.matchedPattern', 'rule:administrative');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Email document to client',
            'task_category' => 'administrative',
            'effort_score' => 1,
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'rule:administrative',
        ]);
        $this->assertDatabaseMissing('tasks', [
            'staff_id' => 42,
            'title' => 'Email document to client',
            'task_category' => 'critical_escalation',
            'user_override' => 1,
        ]);
    }

    public function test_batch_create_tasks_ignores_malformed_client_classification_metadata(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    [
                        'title' => 'Prepare report',
                        'due_date' => '2026-05-14',
                        'task_category' => ['malformed'],
                        'effort_score' => 'not-a-number',
                        'classification_confidence' => str_repeat('x', 100),
                        'classification_source' => ['user'],
                        'user_override' => 'definitely',
                        'matched_pattern' => str_repeat('server down', 100),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.taskCategory', 'real_effort')
            ->assertJsonPath('tasks.0.effortScore', 3)
            ->assertJsonPath('tasks.0.classificationSource', 'system')
            ->assertJsonPath('tasks.0.userOverride', false)
            ->assertJsonPath('tasks.0.matchedPattern', 'rule:real_effort');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Prepare report',
            'task_category' => 'real_effort',
            'effort_score' => 3,
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'rule:real_effort',
        ]);
    }

    public function test_batch_create_tasks_validates_each_row(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => '', 'due_date' => '2026-05-14'],
                    ['title' => 'Prepare report', 'due_date' => '14-05-2026'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['tasks.0.title', 'tasks.1.due_date']);
    }

    public function test_batch_create_tasks_rejects_whitespace_only_titles(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => '   ', 'due_date' => '2026-05-14'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['tasks.0.title']);
    }

    public function test_batch_create_task_can_link_active_project(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Award vendor', 'due_date' => '2026-05-14', 'project_id' => 100],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.projectId', 100)
            ->assertJsonPath('tasks.0.projectProgressId', 1);

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Award vendor',
            'project_id' => 100,
            'project_progress_id' => 1,
        ]);

        $this->assertDatabaseHas('project_progress', [
            'id' => 1,
            'project_id' => 100,
            'progress_text' => 'Ongoing task: Award vendor',
            'updated_by' => 42,
            'source_type' => 'task',
        ]);
    }

    public function test_batch_create_task_can_link_owner_role_project(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Owner project follow up', 'due_date' => '2026-05-14', 'project_id' => 104],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.projectId', 104);

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Owner project follow up',
            'project_id' => 104,
        ]);
    }

    public function test_batch_create_task_accepts_project_with_legacy_spaced_active_status(): void
    {
        DB::table('projects_main')->insert([
            'id' => 103,
            'project_name' => 'Legacy Spaced Active Project',
            'status' => 'Active ',
        ]);
        DB::table('project_collaborators')->insert([
            'project_id' => 103,
            'staff_id' => 42,
            'project_role' => 'Leader ',
        ]);

        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    [
                        'title' => 'Follow up legacy project',
                        'due_date' => '2026-05-14',
                        'project_id' => 103,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.projectId', 103);
    }

    public function test_single_create_task_can_link_active_project_and_create_ongoing_progress(): void
    {
        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'Develop new landing page',
                'due_date' => '2026-05-14',
                'project_id' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.projectId', 100)
            ->assertJsonPath('task.projectProgressId', 1)
            ->assertJsonPath('task.taskCategory', 'real_effort')
            ->assertJsonPath('task.effortScore', 3)
            ->assertJsonPath('task.matchedPattern', 'rule:real_effort');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Develop new landing page',
            'project_id' => 100,
            'project_progress_id' => 1,
            'task_category' => 'real_effort',
            'effort_score' => 3,
        ]);

        $this->assertDatabaseHas('project_progress', [
            'id' => 1,
            'project_id' => 100,
            'progress_text' => 'Ongoing task: Develop new landing page',
            'updated_by' => 42,
            'source_type' => 'task',
            'source_task_id' => 1,
        ]);
    }

    public function test_single_create_task_allows_non_work_task_but_persists_zero_score(): void
    {
        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'watching tv',
                'due_date' => '2026-05-14',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.taskCategory', 'non_work')
            ->assertJsonPath('task.effortScore', 0)
            ->assertJsonPath('task.classificationSource', 'system');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'watching tv',
            'task_category' => 'non_work',
            'effort_score' => 0,
        ]);
    }

    public function test_single_create_task_allows_unclear_task_but_persists_zero_score(): void
    {
        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'random unknown noun',
                'due_date' => '2026-05-14',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.taskCategory', 'unclear_unrated')
            ->assertJsonPath('task.effortScore', 0)
            ->assertJsonPath('task.classificationSource', 'system');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
        ]);
    }

    public function test_single_create_task_queues_ai_enrichment_for_unclear_tasks_when_enabled(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);
        Queue::fake();

        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'random unknown noun',
                'due_date' => '2026-05-14',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.taskCategory', 'unclear_unrated')
            ->assertJsonPath('task.classificationSource', 'system')
            ->assertJsonPath('task.aiClassificationStatus', 'queued');

        $this->assertDatabaseHas('tasks', [
            'id' => 1,
            'ai_classification_status' => 'queued',
        ]);

        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => $job->taskId() === 1,
        );
    }

    public function test_single_create_task_does_not_queue_ai_enrichment_for_strong_local_classification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);
        Queue::fake();

        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'Create training module',
                'due_date' => '2026-05-14',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.taskCategory', 'real_effort')
            ->assertJsonPath('task.aiClassificationStatus', 'not_applicable');

        Queue::assertNotPushed(EnrichTaskClassificationWithAiJob::class);
    }

    public function test_batch_create_tasks_returns_pending_ai_classification_status_when_queued(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);
        Queue::fake();

        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'random unknown noun', 'due_date' => '2026-05-14'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('tasks.0.taskCategory', 'unclear_unrated')
            ->assertJsonPath('tasks.0.classificationSource', 'system')
            ->assertJsonPath('tasks.0.aiClassificationStatus', 'queued');

        Queue::assertPushed(
            EnrichTaskClassificationWithAiJob::class,
            fn (EnrichTaskClassificationWithAiJob $job): bool => $job->taskId() === 1,
        );
    }

    public function test_personal_task_list_returns_ai_classification_statuses(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);

        DB::table('tasks')->insert([
            [
                'staff_id' => 42,
                'title' => 'AI classified task',
                'task_category' => 'administrative',
                'effort_score' => 1,
                'classification_confidence' => 'high',
                'classification_source' => 'ai',
                'user_override' => 0,
                'work_type' => 'clerical_admin',
                'work_type_confidence' => 'high',
                'status' => 'Ongoing',
                'due_date' => '2026-05-14',
                'created_at' => '2026-05-12 10:00:00',
            ],
            [
                'staff_id' => 42,
                'title' => 'Cached classified task',
                'task_category' => 'real_effort',
                'effort_score' => 3,
                'classification_confidence' => 'high',
                'classification_source' => 'ai_cache',
                'user_override' => 0,
                'work_type' => 'commercial_sales',
                'work_type_confidence' => 'high',
                'status' => 'Ongoing',
                'due_date' => '2026-05-14',
                'created_at' => '2026-05-11 10:00:00',
            ],
            [
                'staff_id' => 42,
                'title' => 'Pending classified task',
                'task_category' => 'unclear_unrated',
                'effort_score' => 0,
                'classification_confidence' => 'low',
                'classification_source' => 'system',
                'user_override' => 0,
                'work_type' => 'unclear',
                'work_type_confidence' => 'low',
                'status' => 'Ongoing',
                'due_date' => '2026-05-14',
                'created_at' => '2026-05-10 10:00:00',
            ],
        ]);

        $tasks = collect($this->authenticated()->getJson('/tasks/personal')->assertOk()->json('tasks'));

        $this->assertSame('applied', $tasks->firstWhere('title', 'AI classified task')['aiClassificationStatus']);
        $this->assertSame('cached', $tasks->firstWhere('title', 'Cached classified task')['aiClassificationStatus']);
        $this->assertSame('pending', $tasks->firstWhere('title', 'Pending classified task')['aiClassificationStatus']);
    }

    public function test_ai_enrichment_job_marks_task_applied_when_openai_returns_valid_classification(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);

        Http::fake([
            '*' => Http::response([
                'output' => [
                    [
                        'content' => [
                            [
                                'text' => json_encode([
                                    'task_category' => 'administrative',
                                    'effort_score' => 1,
                                    'work_type' => 'clerical_admin',
                                    'confidence' => 'high',
                                    'reason' => 'handover summary preparation',
                                ]),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        DB::table('tasks')->insert([
            'id' => 501,
            'staff_id' => 42,
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'unclear:no_work_signal',
            'work_type' => 'unclear',
            'work_type_confidence' => 'low',
            'work_type_matched_pattern' => 'unclear:no_work_signal',
            'ai_classification_status' => 'queued',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => '2026-05-10 10:00:00',
        ]);

        app(EnrichTaskClassificationWithAiJob::class, ['taskId' => 501])->handle(
            app(\App\Services\Tasks\TaskAiClassificationService::class),
            app(\App\Services\Tasks\TaskClassificationService::class),
            app(\App\Services\Tasks\TaskLearnedClassificationService::class),
        );

        $task = DB::table('tasks')->where('id', 501)->first();
        $this->assertSame('ai', $task->classification_source);
        $this->assertSame('applied', $task->ai_classification_status);
        $this->assertNotNull($task->ai_classification_started_at);
        $this->assertNotNull($task->ai_classification_completed_at);
    }

    public function test_ai_enrichment_job_marks_no_result_when_openai_result_is_not_usable(): void
    {
        config([
            'services.openai.key' => 'test-key',
            'services.workload_ai_classification.enabled' => true,
        ]);

        Http::fake([
            '*' => Http::response([
                'output' => [
                    [
                        'content' => [
                            [
                                'text' => json_encode([
                                    'task_category' => 'administrative',
                                    'effort_score' => 1,
                                    'work_type' => 'clerical_admin',
                                    'confidence' => 'low',
                                    'reason' => 'not confident enough',
                                ]),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        DB::table('tasks')->insert([
            'id' => 502,
            'staff_id' => 42,
            'title' => 'random unknown noun',
            'task_category' => 'unclear_unrated',
            'effort_score' => 0,
            'classification_confidence' => 'low',
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'unclear:no_work_signal',
            'work_type' => 'unclear',
            'work_type_confidence' => 'low',
            'work_type_matched_pattern' => 'unclear:no_work_signal',
            'ai_classification_status' => 'queued',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => '2026-05-10 10:00:00',
        ]);

        app(EnrichTaskClassificationWithAiJob::class, ['taskId' => 502])->handle(
            app(\App\Services\Tasks\TaskAiClassificationService::class),
            app(\App\Services\Tasks\TaskClassificationService::class),
            app(\App\Services\Tasks\TaskLearnedClassificationService::class),
        );

        $task = DB::table('tasks')->where('id', 502)->first();
        $this->assertSame('system', $task->classification_source);
        $this->assertSame('no_result', $task->ai_classification_status);
        $this->assertNotNull($task->ai_classification_completed_at);
    }

    public function test_single_create_task_ignores_user_classification_override(): void
    {
        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'Email document to client',
                'due_date' => '2026-05-14',
                'task_category' => 'critical_escalation',
                'effort_score' => 4,
                'classification_confidence' => 'high',
                'classification_source' => 'user',
                'user_override' => true,
                'matched_pattern' => 'server down',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.taskCategory', 'administrative')
            ->assertJsonPath('task.effortScore', 1)
            ->assertJsonPath('task.classificationSource', 'system')
            ->assertJsonPath('task.userOverride', false)
            ->assertJsonPath('task.matchedPattern', 'rule:administrative');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Email document to client',
            'task_category' => 'administrative',
            'effort_score' => 1,
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'rule:administrative',
        ]);
    }

    public function test_single_create_task_ignores_malformed_client_classification_metadata(): void
    {
        $this->authenticated()
            ->postJson('/tasks', [
                'title' => 'Email document to client',
                'due_date' => '2026-05-14',
                'task_category' => ['malformed'],
                'effort_score' => 'not-a-number',
                'classification_confidence' => str_repeat('x', 100),
                'classification_source' => ['user'],
                'user_override' => 'definitely',
                'matched_pattern' => str_repeat('server down', 100),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('task.taskCategory', 'administrative')
            ->assertJsonPath('task.effortScore', 1)
            ->assertJsonPath('task.classificationSource', 'system')
            ->assertJsonPath('task.userOverride', false)
            ->assertJsonPath('task.matchedPattern', 'rule:administrative');

        $this->assertDatabaseHas('tasks', [
            'staff_id' => 42,
            'title' => 'Email document to client',
            'task_category' => 'administrative',
            'effort_score' => 1,
            'classification_source' => 'system',
            'user_override' => 0,
            'matched_pattern' => 'rule:administrative',
        ]);
    }

    public function test_batch_create_task_rejects_inactive_project(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Award vendor', 'due_date' => '2026-05-14', 'project_id' => 101],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tasks.0.project_id']);
    }

    public function test_batch_create_task_rejects_unrelated_active_project(): void
    {
        $this->authenticated()
            ->postJson('/tasks/batch', [
                'tasks' => [
                    ['title' => 'Award vendor', 'due_date' => '2026-05-14', 'project_id' => 102],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tasks.0.project_id']);
    }

    public function test_completing_project_tagged_task_creates_project_progress_once(): void
    {
        $taskId = DB::table('tasks')->insertGetId([
            'staff_id' => 42,
            'project_id' => 100,
            'project_progress_id' => null,
            'title' => 'Award vendor',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => now(),
            'completed_at' => null,
        ]);

        $this->authenticated()
            ->patchJson("/tasks/{$taskId}/complete", ['task_id' => $taskId])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('project_progress', [
            'project_id' => 100,
            'progress_text' => 'Completed task: Award vendor',
            'updated_by' => 42,
            'source_type' => 'task',
            'source_task_id' => $taskId,
        ]);

        $this->authenticated()
            ->patchJson("/tasks/{$taskId}/complete", ['task_id' => $taskId])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(1, DB::table('project_progress')->where('source_task_id', $taskId)->count());
    }

    public function test_completing_project_tagged_task_updates_existing_ongoing_progress(): void
    {
        $taskId = DB::table('tasks')->insertGetId([
            'staff_id' => 42,
            'project_id' => 100,
            'project_progress_id' => null,
            'title' => 'Award vendor',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => now(),
            'completed_at' => null,
        ]);

        $progressId = DB::table('project_progress')->insertGetId([
            'project_id' => 100,
            'progress_date' => '2026-05-01',
            'progress_text' => 'Ongoing task: Award vendor',
            'updated_by' => 42,
            'updated_on' => now(),
            'source_type' => 'task',
            'source_task_id' => $taskId,
        ]);

        DB::table('tasks')->where('id', $taskId)->update(['project_progress_id' => $progressId]);

        $this->authenticated()
            ->patchJson("/tasks/{$taskId}/complete", ['task_id' => $taskId])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('project_progress_id', $progressId);

        $this->assertSame(1, DB::table('project_progress')->where('source_task_id', $taskId)->count());
        $this->assertDatabaseHas('project_progress', [
            'id' => $progressId,
            'project_id' => 100,
            'progress_text' => 'Completed task: Award vendor',
            'updated_by' => 42,
            'source_type' => 'task',
            'source_task_id' => $taskId,
        ]);
    }

    public function test_completing_untagged_task_does_not_create_project_progress(): void
    {
        $taskId = DB::table('tasks')->insertGetId([
            'staff_id' => 42,
            'project_id' => null,
            'project_progress_id' => null,
            'title' => 'Prepare report',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => now(),
            'completed_at' => null,
        ]);

        $this->authenticated()
            ->patchJson("/tasks/{$taskId}/complete", ['task_id' => $taskId])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(0, DB::table('project_progress')->count());
    }

    public function test_delete_task_with_project_progress_deletes_linked_progress(): void
    {
        $taskId = DB::table('tasks')->insertGetId([
            'staff_id' => 42,
            'project_id' => 100,
            'project_progress_id' => null,
            'title' => 'Award vendor',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => now(),
            'completed_at' => null,
        ]);

        $progressId = DB::table('project_progress')->insertGetId([
            'project_id' => 100,
            'progress_date' => '2026-05-01',
            'progress_text' => 'Ongoing task: Award vendor',
            'updated_by' => 42,
            'updated_on' => now(),
            'source_type' => 'task',
            'source_task_id' => $taskId,
        ]);

        DB::table('tasks')->where('id', $taskId)->update(['project_progress_id' => $progressId]);

        $this->authenticated()
            ->deleteJson("/tasks/{$taskId}", ['task_id' => $taskId])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
        $this->assertDatabaseMissing('project_progress', ['id' => $progressId]);
    }

    public function test_project_progress_edit_delete_rejects_task_linked_progress(): void
    {
        $taskId = DB::table('tasks')->insertGetId([
            'staff_id' => 42,
            'project_id' => 100,
            'project_progress_id' => null,
            'title' => 'Award vendor',
            'status' => 'Ongoing',
            'due_date' => '2026-05-14',
            'created_at' => now(),
            'completed_at' => null,
        ]);

        $progressId = DB::table('project_progress')->insertGetId([
            'project_id' => 100,
            'progress_date' => '2026-05-01',
            'progress_text' => 'Ongoing task: Award vendor',
            'updated_by' => 42,
            'updated_on' => now(),
            'source_type' => 'task',
            'source_task_id' => $taskId,
        ]);

        DB::table('tasks')->where('id', $taskId)->update(['project_progress_id' => $progressId]);

        $this->authenticated()
            ->putJson("/projects/100/progress/{$progressId}", [
                'date' => '2026-05-02',
                'update' => 'Manual edit should fail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->authenticated()
            ->deleteJson("/projects/100/progress/{$progressId}")
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('project_progress', [
            'id' => $progressId,
            'progress_text' => 'Ongoing task: Award vendor',
        ]);
    }

    private function authenticated()
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 42,
                'roles' => ['Staff'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
