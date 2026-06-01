<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_salary_profiles')) {
            Schema::create('hr_salary_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('staff_id')->unique();
                $table->decimal('basic_salary', 12, 2)->default(0);
                $table->char('effective_month', 7);
                $table->string('vehicle', 120)->nullable();
                $table->decimal('default_mileage_rate', 8, 2)->default(0);
                $table->decimal('yearly_medical_claim', 12, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_salary_recurring_allowances')) {
            Schema::create('hr_salary_recurring_allowances', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('profile_id')->constrained('hr_salary_profiles')->cascadeOnDelete();
                $table->string('description');
                $table->decimal('amount', 12, 2);
                $table->char('start_month', 7)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_salary_applications')) {
            Schema::create('hr_salary_applications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('staff_id')->index();
                $table->char('salary_month', 7);
                $table->string('salary_month_label');
                $table->decimal('basic_salary', 12, 2);
                $table->decimal('claims_total', 12, 2)->default(0);
                $table->decimal('employee_deductions', 12, 2)->default(0);
                $table->decimal('employer_contributions', 12, 2)->default(0);
                $table->decimal('payable_salary', 12, 2)->default(0);
                $table->string('status', 32)->default('Submitted');
                $table->json('deductions_json')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('checked_by')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->string('checked_status', 32)->nullable();
                $table->text('checked_remarks')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('approved_status', 32)->nullable();
                $table->text('approved_remarks')->nullable();
                $table->timestamps();
                $table->unique(['staff_id', 'salary_month'], 'hr_salary_staff_month_unique');
            });
        }

        if (! Schema::hasTable('hr_salary_claims')) {
            Schema::create('hr_salary_claims', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')->constrained('hr_salary_applications')->cascadeOnDelete();
                $table->string('client_claim_id')->nullable();
                $table->string('type', 32);
                $table->date('claim_date')->nullable();
                $table->string('description');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('meta')->nullable();
                $table->decimal('km', 10, 2)->nullable();
                $table->string('start_location')->nullable();
                $table->string('end_location')->nullable();
                $table->string('source')->nullable();
                $table->string('source_label')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_salary_claim_attachments')) {
            Schema::create('hr_salary_claim_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('claim_id')->constrained('hr_salary_claims')->cascadeOnDelete();
                $table->unsignedBigInteger('staff_id')->index();
                $table->string('stored_path');
                $table->string('original_name');
                $table->string('mime_type', 191)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_claim_attachments');
        Schema::dropIfExists('hr_salary_claims');
        Schema::dropIfExists('hr_salary_applications');
        Schema::dropIfExists('hr_salary_recurring_allowances');
        Schema::dropIfExists('hr_salary_profiles');
    }
};
