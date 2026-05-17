<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoicePaidStateValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\RequireAuth::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        ]);

        Schema::dropIfExists('user_activities');
        Schema::dropIfExists('invoices');

        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status')->default('Pending');
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('name_code', 20);
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_mark_paid_requires_payment_date_and_positive_amount(): void
    {
        DB::table('invoices')->insert([
            'id' => 1,
            'status' => 'Pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingSession($this->sessionPayload())
            ->patchJson('/invoices/1/mark-paid', [
                'paid_date' => '15-05-2026',
                'paid_amount' => 0,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paid_date', 'paid_amount']);

        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'status' => 'Pending',
            'paid_date' => null,
            'paid_amount' => null,
        ]);

        $this->actingSession($this->sessionPayload())
            ->patchJson('/invoices/1/mark-paid', [
                'paid_date' => '2026-05-15',
                'paid_amount' => 250.75,
                'paid_remarks' => 'Bank transfer',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('invoices', [
            'id' => 1,
            'status' => 'Paid',
            'paid_date' => '2026-05-15',
            'paid_amount' => 250.75,
            'paid_remarks' => 'Bank transfer',
        ]);
    }

    private function sessionPayload(): array
    {
        return ['user_id' => 1, 'staff_id' => 10, 'name_code' => 'ACC1'];
    }

    private function actingSession(array $session)
    {
        $this->app['session']->start();
        $this->app['session']->put($session + ['_token' => 'test-token']);

        return $this
            ->withSession($session + ['_token' => 'test-token'])
            ->withCookie(config('session.cookie'), $this->app['session']->getId())
            ->withHeader('X-CSRF-TOKEN', 'test-token');
    }
}
