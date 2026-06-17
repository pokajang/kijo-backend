<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_salary_payment_runs')) {
            Schema::create('hr_salary_payment_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('staff_id')->index();
                $table->char('payment_period', 7)->index();
                $table->decimal('salary_total', 12, 2)->default(0);
                $table->decimal('other_claim_total', 12, 2)->default(0);
                $table->decimal('total_paid', 12, 2)->default(0);
                $table->date('payment_date')->nullable();
                $table->string('payment_reference')->nullable();
                $table->string('payment_method')->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('actor_staff_id')->nullable()->index();
                $table->timestamp('paid_at');
                $table->json('snapshot_json')->nullable();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->timestamps();

                $table->index(['staff_id', 'payment_period'], 'salary_payment_runs_staff_period_idx');
            });
        }

        if (! Schema::hasTable('hr_salary_payment_run_items')) {
            Schema::create('hr_salary_payment_run_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_run_id')->constrained('hr_salary_payment_runs')->cascadeOnDelete();
                $table->string('subject_type', 120);
                $table->unsignedBigInteger('subject_id');
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->string('status_from', 40)->nullable();
                $table->string('status_to', 40)->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamps();

                $table->unique(['subject_type', 'subject_id'], 'salary_payment_run_subject_unique');
                $table->index(['subject_type', 'subject_id'], 'salary_payment_run_subject_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_payment_run_items');
        Schema::dropIfExists('hr_salary_payment_runs');
    }
};
