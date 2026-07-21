<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuoteRecordEmailControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('system_users');
        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('account_locked_until')->nullable();
            $table->boolean('total_lock')->default(false);
        });

        Schema::dropIfExists('quotes_training');
        Schema::create('quotes_training', function (Blueprint $table): void {
            $table->id();
            $table->string('quote_ref_no')->nullable();
            $table->unsignedInteger('revision_no')->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('estimated_total_cost', 15, 2)->nullable();
            $table->string('client_name')->nullable();
            $table->string('pic_name')->nullable();
            $table->text('pic_email')->nullable();
            $table->string('status')->default('Open');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->string('approval_zone')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('approval_fingerprint', 64)->nullable();
        });

        Schema::dropIfExists('quote_approval_requests');
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

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'sysadmin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
            'account_locked_until' => null,
            'total_lock' => 0,
        ]);
    }

    public function test_quote_email_reports_incomplete_smtp_credentials(): void
    {
        $this->configureQuoteMailer(['password' => '']);

        $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/quote-records/training/42/email', $this->emailPayload())
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Quotation SMTP configuration is incomplete. Missing: password.');
    }

    public function test_quote_email_rejects_invalid_staff_session_email(): void
    {
        $this->configureQuoteMailer();
        DB::table('system_users')->where('id', 1)->update(['email' => 'not-an-email']);

        $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/quote-records/training/42/email', $this->emailPayload())
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Your staff email in the current session is invalid.');
    }

    public function test_quote_email_rejects_invalid_client_recipient_email(): void
    {
        $this->configureQuoteMailer();
        $this->insertTrainingQuote('not-an-email');

        $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/quote-records/training/42/email', $this->emailPayload())
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'This quotation has no valid client recipient email.');
    }

    public function test_quote_email_blocks_an_unapproved_quote_after_request_validation(): void
    {
        $this->configureQuoteMailer();
        $this->insertTrainingQuote('client@example.test', [
            'grand_total' => 130,
            'estimated_total_cost' => 100,
        ]);

        $this->withSession($this->authenticatedSession())
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
            ->postJson('/quote-records/training/42/email', $this->emailPayload())
            ->assertStatus(409)
            ->assertJsonPath('code', 'QUOTE_APPROVAL_REQUIRED');
    }

    private function authenticatedSession(): array
    {
        return [
            '_token' => 'test-csrf-token',
            'user_id' => 1,
            'staff_id' => 10,
            'roles' => ['System Admin'],
            'full_name' => 'System Admin',
        ];
    }

    private function emailPayload(): array
    {
        return [
            'subject' => 'Quotation test',
            'body' => 'Please review this quotation.',
        ];
    }

    private function configureQuoteMailer(array $overrides = []): void
    {
        config([
            'mail.quote.mailer' => 'quote_smtp',
            'mail.quote.from.address' => 'info.admin@amiosh.com',
            'mail.quote.from.name' => 'AMIOSH Admin',
            'mail.mailers.quote_smtp' => array_merge([
                'transport' => 'smtp',
                'host' => 'work.amiosh.com',
                'port' => 465,
                'username' => 'info.admin@amiosh.com',
                'password' => 'secret',
            ], $overrides),
        ]);
    }

    private function insertTrainingQuote(string $picEmail, array $overrides = []): void
    {
        DB::table('quotes_training')->insert(array_merge([
            'id' => 42,
            'quote_ref_no' => 'Q-42',
            'client_name' => 'Client Sdn Bhd',
            'pic_name' => 'Client PIC',
            'pic_email' => $picEmail,
        ], $overrides));
    }
}
