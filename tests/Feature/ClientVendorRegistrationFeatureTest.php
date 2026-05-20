<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientVendorRegistrationFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-19 09:00:00');
        Storage::fake('private');
        Storage::fake('public');

        $this->withoutMiddleware([
            \App\Http\Middleware\RequireAuth::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        $this->createTables();
        $this->seedRows();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_vendor_registration_can_be_created_without_certificate_and_listed(): void
    {
        $response = $this->postJson('/client-vendor-registrations', [
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'recipient_staff_ids' => [10, 11],
            'portal_url' => 'https://vendor.example.test/login',
            'portal_username' => 'amiosh-admin',
            'portal_password' => 'secret-pass',
            'remarks' => 'Initial registration',
        ])->assertCreated()->assertJsonPath('status', 'success');

        $this->assertSame('Alpha Client', $response->json('data.client_name'));
        $this->assertSame('https://vendor.example.test/login', $response->json('data.portal_url'));
        $this->assertSame('amiosh-admin', $response->json('data.portal_username'));
        $this->assertSame('secret-pass', $response->json('data.portal_password'));
        $this->assertSame('missing_certificate', $response->json('data.status'));
        $this->assertCount(2, $response->json('data.recipients'));
        $this->assertNotSame(
            'secret-pass',
            DB::table('client_vendor_registrations')->where('id', $response->json('data.id'))->value('portal_password')
        );

        $list = $this->getJson('/client-vendor-registrations')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data.rows');

        $this->assertCount(1, $list);
        $this->assertSame('Alpha Client', $list[0]['client_name']);
        $this->assertArrayNotHasKey('portal_password', $list[0]);

        $detail = $this->getJson("/client-vendor-registrations/{$response->json('data.id')}")
            ->assertOk()
            ->json('data');
        $this->assertSame('secret-pass', $detail['portal_password']);
    }

    public function test_expired_registration_status_takes_precedence_over_missing_certificate(): void
    {
        $id = DB::table('client_vendor_registrations')->insertGetId([
            'client_id' => 1,
            'valid_from' => '2025-01-01',
            'valid_until' => '2026-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('client_vendor_registration_recipients')->insert([
            'registration_id' => $id,
            'staff_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->getJson('/client-vendor-registrations')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame('expired', $row['status']);
        $this->assertFalse($row['has_certificate']);
    }

    public function test_expiring_soon_status_takes_precedence_over_missing_certificate(): void
    {
        $id = DB::table('client_vendor_registrations')->insertGetId([
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('client_vendor_registration_recipients')->insert([
            'registration_id' => $id,
            'staff_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->getJson('/client-vendor-registrations')
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame('expiring_soon', $row['status']);
        $this->assertFalse($row['has_certificate']);
    }

    public function test_vendor_registration_attention_count_counts_expired_only(): void
    {
        $this->getJson('/client-vendor-registrations/attention-count')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.expired_count', 0)
            ->assertJsonPath('data.expiring_soon_count', 0);

        DB::table('client_vendor_registrations')->insert([
            [
                'client_id' => 1,
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'client_id' => 1,
                'valid_from' => '2025-01-01',
                'valid_until' => '2026-05-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => now(),
            ],
            [
                'client_id' => 1,
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-06-15',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'client_id' => 1,
                'valid_from' => '2026-01-01',
                'valid_until' => '2026-09-01',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ]);

        $this->getJson('/client-vendor-registrations/attention-count')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.expired_count', 1)
            ->assertJsonPath('data.expiring_soon_count', 1);
    }

    public function test_vendor_registration_certificate_can_be_uploaded_replaced_and_streamed(): void
    {
        $create = $this->post('/client-vendor-registrations', [
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'recipient_staff_ids' => [10],
            'certificate' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertSame('active', $create['status']);
        $this->assertSame('registration.pdf', $create['certificate_original_name']);
        $firstPath = DB::table('client_vendor_registrations')->where('id', $create['id'])->value('certificate_path');
        Storage::disk('private')->assertExists($firstPath);

        $update = $this->post("/client-vendor-registrations/{$create['id']}", [
            'client_id' => 1,
            'valid_from' => '2026-02-01',
            'valid_until' => '2026-11-30',
            'recipient_staff_ids' => [10],
            'certificate' => UploadedFile::fake()->image('replacement.png'),
        ])->assertOk()->json('data');

        $this->assertSame('replacement.png', $update['certificate_original_name']);
        $secondPath = DB::table('client_vendor_registrations')->where('id', $create['id'])->value('certificate_path');
        Storage::disk('private')->assertMissing($firstPath);
        Storage::disk('private')->assertExists($secondPath);

        $this->get("/client-vendor-registrations/{$create['id']}/certificate")->assertOk();
    }

    public function test_vendor_registration_rejects_invalid_payloads(): void
    {
        $this->postJson('/client-vendor-registrations', [
            'client_id' => 999,
            'valid_from' => '2026-01-01',
            'valid_until' => '2025-12-31',
            'recipient_staff_ids' => [],
        ])->assertStatus(422);

        $this->post('/client-vendor-registrations', [
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'recipient_staff_ids' => [12],
            'certificate' => UploadedFile::fake()->create('bad.txt', 12, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_vendor_registration_post_upserts_by_client(): void
    {
        $first = $this->postJson('/client-vendor-registrations', [
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'recipient_staff_ids' => [10],
        ])->assertCreated()->json('data.id');

        $second = $this->postJson('/client-vendor-registrations', [
            'client_id' => 1,
            'valid_from' => '2026-02-01',
            'valid_until' => '2026-10-31',
            'recipient_staff_ids' => [11],
        ])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('client_vendor_registrations')->whereNull('deleted_at')->count());
        $this->assertSame('2026-10-31', DB::table('client_vendor_registrations')->where('id', $first)->value('valid_until'));
    }

    public function test_vendor_registration_delete_soft_deletes_and_removes_certificate(): void
    {
        $record = $this->post('/client-vendor-registrations', [
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-12-31',
            'recipient_staff_ids' => [10],
            'certificate' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
        ])->assertCreated()->json('data');

        $path = DB::table('client_vendor_registrations')->where('id', $record['id'])->value('certificate_path');
        $this->deleteJson("/client-vendor-registrations/{$record['id']}")->assertOk();

        $this->assertNotNull(DB::table('client_vendor_registrations')->where('id', $record['id'])->value('deleted_at'));
        Storage::disk('private')->assertMissing($path);
    }

    public function test_vendor_registration_reminder_command_sends_logs_and_skips_duplicates(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.from.address' => 'admin@example.test']);

        $id = DB::table('client_vendor_registrations')->insertGetId([
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-06-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('client_vendor_registration_recipients')->insert([
            'registration_id' => $id,
            'staff_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('app:send-client-vendor-registration-reminders'));
        $this->assertSame(1, DB::table('client_vendor_registration_reminder_logs')->where('status', 'sent')->count());
        $this->assertSame('30_days', DB::table('client_vendor_registration_reminder_logs')->value('stage'));

        $this->assertSame(0, Artisan::call('app:send-client-vendor-registration-reminders'));
        $this->assertSame(1, DB::table('client_vendor_registration_reminder_logs')->where('status', 'sent')->count());

        $this->assertSame(0, Artisan::call('app:send-client-vendor-registration-reminders --dry-run'));
        $this->assertStringContainsString('Mode=dry-run', Artisan::output());
    }

    public function test_vendor_registration_reminder_limit_keeps_all_recipients_for_limited_registration(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.from.address' => 'admin@example.test']);

        $firstId = DB::table('client_vendor_registrations')->insertGetId([
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-06-10',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondId = DB::table('client_vendor_registrations')->insertGetId([
            'client_id' => 1,
            'valid_from' => '2026-01-01',
            'valid_until' => '2026-06-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('client_vendor_registration_recipients')->insert([
            ['registration_id' => $firstId, 'staff_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['registration_id' => $firstId, 'staff_id' => 11, 'created_at' => now(), 'updated_at' => now()],
            ['registration_id' => $secondId, 'staff_id' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(0, Artisan::call('app:send-client-vendor-registration-reminders --limit=1'));
        $this->assertSame(2, DB::table('client_vendor_registration_reminder_logs')->where('registration_id', $firstId)->count());
        $this->assertSame(0, DB::table('client_vendor_registration_reminder_logs')->where('registration_id', $secondId)->count());
    }

    private function createTables(): void
    {
        foreach ([
            'client_vendor_registration_reminder_logs',
            'client_vendor_registration_recipients',
            'client_vendor_registrations',
            'staff_general',
            'client_company',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('client_status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->integer('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('client_vendor_registrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id');
            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('certificate_path')->nullable();
            $table->string('certificate_original_name')->nullable();
            $table->string('certificate_mime_type')->nullable();
            $table->unsignedBigInteger('certificate_size')->nullable();
            $table->string('portal_url', 2048)->nullable();
            $table->string('portal_username')->nullable();
            $table->string('portal_password')->nullable();
            $table->text('remarks')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('client_vendor_registration_recipients', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('registration_id');
            $table->integer('staff_id');
            $table->timestamps();
        });

        Schema::create('client_vendor_registration_reminder_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('registration_id');
            $table->integer('staff_id');
            $table->string('stage');
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->text('body_snapshot')->nullable();
            $table->string('status')->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedRows(): void
    {
        DB::table('client_company')->insert([
            ['company_id' => 1, 'company_name' => 'Alpha Client', 'client_status' => 'New', 'deleted_at' => null],
        ]);

        DB::table('staff_general')->insert([
            ['staff_id' => 10, 'full_name' => 'Aminah Active', 'name_code' => 'AA', 'email' => 'aminah@example.test', 'status' => 'Active', 'deleted_at' => null],
            ['staff_id' => 11, 'full_name' => 'Ben Active', 'name_code' => 'BA', 'email' => 'ben@example.test', 'status' => 'Active', 'deleted_at' => null],
            ['staff_id' => 12, 'full_name' => 'Inactive Staff', 'name_code' => 'IS', 'email' => 'inactive@example.test', 'status' => 'Inactive', 'deleted_at' => null],
        ]);
    }
}
