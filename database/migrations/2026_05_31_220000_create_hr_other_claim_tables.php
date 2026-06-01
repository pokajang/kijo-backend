<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_other_claim_applications')) {
            Schema::create('hr_other_claim_applications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('staff_id')->index();
                $table->char('claim_month', 7);
                $table->string('claim_month_label');
                $table->decimal('claims_total', 12, 2)->default(0);
                $table->string('status', 32)->default('Submitted');
                $table->json('draft_payload_json')->nullable();
                $table->timestamp('draft_saved_at')->nullable();
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
            });
        }

        if (! Schema::hasTable('hr_other_claim_items')) {
            Schema::create('hr_other_claim_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')->constrained('hr_other_claim_applications')->cascadeOnDelete();
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

        if (! Schema::hasTable('hr_other_claim_attachments')) {
            Schema::create('hr_other_claim_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('claim_id')->constrained('hr_other_claim_items')->cascadeOnDelete();
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
        Schema::dropIfExists('hr_other_claim_attachments');
        Schema::dropIfExists('hr_other_claim_items');
        Schema::dropIfExists('hr_other_claim_applications');
    }
};
