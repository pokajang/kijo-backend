<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

class Jd14ProjectTypeValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            RequireAuth::class,
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);

        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('project_progress');
        Schema::dropIfExists('invoices_jd14form');
        Schema::dropIfExists('projects_main');

        Schema::create('projects_main', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('project_type');
            $table->string('project_name')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices_jd14form', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->string('employer_name');
            $table->text('employer_address');
            $table->string('approval_no')->unique();
            $table->string('employer_code')->nullable();
            $table->string('group_approved')->nullable();
            $table->string('group_claimed')->nullable();
            $table->string('course_title');
            $table->text('training_venue');
            $table->date('commenced_date');
            $table->date('end_date');
            $table->unsignedInteger('no_of_pax')->nullable();
            $table->decimal('total_fee_approved', 12, 2)->nullable();
            $table->decimal('total_fee_claimed', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('project_progress', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('project_id');
            $table->date('progress_date');
            $table->text('progress_text');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamp('updated_on')->nullable();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('projects_main')->insert([
            [
                'id' => 10,
                'project_type' => 'Training',
                'project_name' => 'Training Project',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 20,
                'project_type' => 'Equipment Supply',
                'project_name' => 'Equipment Project',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_training_project_can_create_jd14(): void
    {
        $this->actingSession()
            ->postJson('/jd14-forms', $this->validPayload(['project_id' => 10]))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['form_number']);

        $this->assertDatabaseHas('invoices_jd14form', [
            'project_id' => 10,
            'approval_no' => 'JD14-APP-001',
        ]);
    }

    public function test_non_training_project_cannot_create_jd14(): void
    {
        $this->actingSession()
            ->postJson('/jd14-forms', $this->validPayload(['project_id' => 20]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'JD14 forms can only be generated for Training projects.');

        $this->assertDatabaseMissing('invoices_jd14form', [
            'project_id' => 20,
        ]);
    }

    public function test_missing_project_cannot_create_jd14(): void
    {
        $this->actingSession()
            ->postJson('/jd14-forms', $this->validPayload(['project_id' => 999]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'Project not found.');
    }

    public function test_jd14_project_cannot_be_changed_on_update(): void
    {
        $id = DB::table('invoices_jd14form')->insertGetId(
            $this->jd14Row(['project_id' => 10, 'approval_no' => 'JD14-APP-EDIT']),
        );

        $this->actingSession()
            ->putJson("/jd14-forms/{$id}", $this->validPayload([
                'project_id' => 20,
                'approval_no' => 'JD14-APP-EDIT',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'JD14 project cannot be changed.');

        $this->assertDatabaseHas('invoices_jd14form', [
            'id' => $id,
            'project_id' => 10,
        ]);
    }

    public function test_jd14_linked_to_non_training_project_cannot_be_updated(): void
    {
        $id = DB::table('invoices_jd14form')->insertGetId(
            $this->jd14Row(['project_id' => 20, 'approval_no' => 'JD14-APP-INVALID']),
        );

        $this->actingSession()
            ->putJson("/jd14-forms/{$id}", $this->validPayload([
                'project_id' => 20,
                'approval_no' => 'JD14-APP-INVALID',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['project_id'])
            ->assertJsonPath('message', 'JD14 forms can only be generated for Training projects.');
    }

    public function test_jd14_pdf_uses_the_blade_renderer_and_remains_a_single_page_form(): void
    {
        $id = DB::table('invoices_jd14form')->insertGetId($this->jd14Row([
            'approval_no' => 'JD14-PDF-001',
            'employer_address' => "1 Training Road\nTaman Safety",
        ]));

        $response = $this->actingSession()->get("/jd14-forms/{$id}/pdf");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="JD14-JD14-PDF-001.pdf"');

        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, preg_match_all('/\\/Type\\s*\\/Page(?!s)/', $pdf));
        $this->assertStringNotContainsString('HrdJd14', $pdf);
    }

    public function test_jd14_word_download_contains_the_form_content_and_embedded_assets(): void
    {
        $id = DB::table('invoices_jd14form')->insertGetId($this->jd14Row([
            'approval_no' => 'JD14-WORD-001',
            'employer_address' => "1 Training Road\nTaman Safety",
        ]));

        $response = $this->actingSession()->get("/jd14-forms/{$id}/word");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        self::assertStringContainsString(
            'filename="JD14-JD14-WORD-001.docx"',
            (string) $response->headers->get('Content-Disposition'),
        );

        $path = tempnam(sys_get_temp_dir(), 'jd14-word-');
        self::assertNotFalse($path);
        file_put_contents($path, $response->getContent());
        $archive = new ZipArchive;
        self::assertTrue($archive->open($path) === true);
        $text = html_entity_decode(strip_tags((string) $archive->getFromName('word/document.xml')));
        foreach (['PSMB/SBL-KHAS /JD/14', "PART 1 - EMPLOYER'S PARTICULAR", 'PART 2 - CLAIM FOR COURSE FEE', 'PART 3 - JOINT DECLARATION', 'Training Client Sdn Bhd', 'Taman Safety', 'MUHAMMAD AMIN ROZAK'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringContainsString('w:gridSpan w:val="8"', (string) $archive->getFromName('word/document.xml'));
        self::assertStringNotContainsString('<w:noWrap', (string) $archive->getFromName('word/document.xml'));
        $footer = html_entity_decode(strip_tags((string) $archive->getFromName('word/footer1.xml')));
        self::assertStringContainsString('REMINDER:', $footer);
        $media = array_filter(range(0, $archive->numFiles - 1), static fn (int $index): bool => str_starts_with((string) $archive->getNameIndex($index), 'word/media/'));
        self::assertGreaterThanOrEqual(2, count($media));
        $archive->close();
        unlink($path);
    }

    public function test_jd14_word_download_rejects_invalid_and_missing_records(): void
    {
        $this->actingSession()
            ->get('/jd14-forms/0/word')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid or missing ID');

        $this->actingSession()
            ->get('/jd14-forms/999/word')
            ->assertStatus(404)
            ->assertJsonPath('message', 'Record not found');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 10,
            'employer_name' => 'Training Client Sdn Bhd',
            'employer_address' => '1 Training Road',
            'approval_no' => 'JD14-APP-001',
            'employer_code' => 'JD14',
            'group_approved' => '10',
            'group_claimed' => '10',
            'course_title' => 'Safety Training',
            'training_venue' => 'Training Client Sdn Bhd, 1 Training Road',
            'commenced_date' => '2026-05-20',
            'end_date' => '2026-05-21',
            'no_of_pax' => 10,
            'total_fee_approved' => 1000,
            'total_fee_claimed' => 1000,
        ], $overrides);
    }

    private function jd14Row(array $overrides = []): array
    {
        return array_merge([
            'project_id' => 10,
            'created_by' => 10,
            'employer_name' => 'Training Client Sdn Bhd',
            'employer_address' => '1 Training Road',
            'approval_no' => 'JD14-APP-ROW',
            'employer_code' => 'JD14',
            'group_approved' => '10',
            'group_claimed' => '10',
            'course_title' => 'Safety Training',
            'training_venue' => 'Training Client Sdn Bhd, 1 Training Road',
            'commenced_date' => '2026-05-20',
            'end_date' => '2026-05-21',
            'no_of_pax' => 10,
            'total_fee_approved' => 1000,
            'total_fee_claimed' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function actingSession()
    {
        $this->app['session']->start();
        $this->app['session']->put([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'EMP',
            '_token' => 'test-token',
        ]);

        return $this
            ->withSession([
                'user_id' => 1,
                'staff_id' => 10,
                'name_code' => 'EMP',
                '_token' => 'test-token',
            ])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
