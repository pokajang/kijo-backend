<?php

namespace Tests\Feature;

use App\Jobs\SendHtmlMailJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemGeneratedEmailFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'https://kijo.amiosh.com',
            'app.url' => 'https://api.amiosh.com',
        ]);

        foreach (['system_feedbacks', 'tool_requests', 'system_users', 'staff_general', 'user_activities'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('system_feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->text('feedback');
            $table->unsignedInteger('reported_by')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('date_reported')->nullable();
            $table->date('action_date')->nullable();
            $table->text('remarks')->nullable();
        });

        Schema::create('tool_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id')->nullable();
            $table->string('equipment_detail');
            $table->date('use_start_date');
            $table->date('use_end_date');
            $table->text('purpose');
            $table->text('remarks')->nullable();
            $table->text('achievement')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedInteger('staff_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
            $table->string('email')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('staff_general')->insert([
            'staff_id' => 10,
            'full_name' => 'Requester User',
            'name_code' => 'REQ',
            'email' => 'requester@example.test',
        ]);

        DB::table('system_users')->insert([
            'id' => 10,
            'staff_id' => 10,
            'email' => 'requester@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);
    }

    public function test_feedback_submission_queues_standardized_system_ticket_email(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $this->actingSession()
            ->postJson('/feedback', [
                'feedback' => 'Please review <script>alert("x")</script>',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Bus::assertDispatched(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            $body = (string) $this->jobProperty($job, 'body');
            $presentation = (array) $this->jobProperty($job, 'presentation');

            return $this->jobProperty($job, 'to') === 'azam@amiosh.com'
                && $this->jobProperty($job, 'subject') === 'New System Ticket Submitted'
                && str_contains($body, 'Ticket Details')
                && str_contains($body, 'New Ticket')
                && str_contains($body, 'Open system ticket')
                && str_contains($body, 'href="https://kijo.amiosh.com/support/feedback/1"')
                && str_contains($body, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;')
                && ! str_contains($body, '<script>alert("x")</script>')
                && ! str_contains($body, 'https://api.amiosh.com')
                && ($presentation['headerLabel'] ?? null) === 'System Ticket';
        });
    }

    public function test_tool_request_submission_queues_standardized_email_with_existing_cc(): void
    {
        Bus::fake([SendHtmlMailJob::class]);

        $this->actingSession()
            ->postJson('/tool-requests', [
                'equipmentDetail' => 'Gas detector',
                'useStartDate' => '2026-06-02',
                'useEndDate' => '2026-06-03',
                'purpose' => 'Site inspection',
                'remarks' => 'Bring calibration certificate',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Bus::assertDispatched(SendHtmlMailJob::class, function (SendHtmlMailJob $job): bool {
            $body = (string) $this->jobProperty($job, 'body');
            $presentation = (array) $this->jobProperty($job, 'presentation');

            return $this->jobProperty($job, 'to') === 'azam@amiosh.com'
                && $this->jobProperty($job, 'cc') === ['hr.amiosh@gmail.com', 'kamarul@amiosh.com']
                && str_contains($body, 'Tool Request Details')
                && str_contains($body, 'Open tool request')
                && str_contains($body, 'href="https://kijo.amiosh.com/support/requests/1"')
                && str_contains($body, 'Company equipment is for work-related use only.')
                && ! str_contains($body, 'https://api.amiosh.com')
                && ($presentation['headerLabel'] ?? null) === 'Tool Request';
        });
    }

    private function actingSession(): self
    {
        return $this
            ->withSession([
                '_token' => 'test-token',
                'user_id' => 10,
                'staff_id' => 10,
                'name_code' => 'REQ',
                'full_name' => 'Requester User',
                'roles' => ['Staff'],
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }

    private function jobProperty(object $job, string $property): mixed
    {
        $reflection = new \ReflectionClass($job);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($job);
    }
}
