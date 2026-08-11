<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_first_touch_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('status', 32)->default('current');
            $table->boolean('is_current')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('source_group', 80);
            $table->string('source_value', 120);
            $table->string('channel', 120);
            $table->string('method', 120);
            $table->date('occurred_on');
            $table->time('occurred_time')->nullable();
            $table->string('occurrence_precision', 20)->default('date');
            $table->string('occurrence_timezone', 80)->default('Asia/Kuala_Lumpur');
            $table->boolean('chronology_needs_review')->default(false);
            $table->string('client_contact', 255)->nullable();
            $table->string('contact_mode', 32)->default('named');
            $table->unsignedBigInteger('amiosh_contact_staff_id')->nullable();
            $table->string('amiosh_contact_name', 255)->nullable();
            $table->string('amiosh_contact_code', 40)->nullable();
            $table->unsignedBigInteger('referrer_staff_id')->nullable();
            $table->string('referrer_name', 255)->nullable();
            $table->string('referrer_code', 40)->nullable();
            $table->string('employment_context', 40)->nullable();
            $table->string('employment_boundary', 40)->nullable();
            $table->date('employment_ended_on')->nullable();
            $table->string('employment_departure_type', 40)->nullable();
            $table->string('inquiry_ref', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('privacy_confirmed')->default(false);
            $table->unsignedBigInteger('submitted_by_staff_id');
            $table->string('submitted_by_name', 255);
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('updated_by_staff_id')->nullable();
            $table->string('updated_by_name', 255)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'is_current'], 'client_first_touch_current_idx');
            $table->index(['client_id', 'status'], 'client_first_touch_status_idx');
            $table->index('occurred_on', 'client_first_touch_occurred_idx');
        });

        Schema::create('client_first_touch_disputes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('claim_id');
            $table->string('reason', 80);
            $table->text('explanation');
            $table->string('status', 32)->default('open');
            $table->string('resolution', 40)->nullable();
            $table->unsignedBigInteger('submitted_by_staff_id');
            $table->string('submitted_by_name', 255);
            $table->timestamp('submitted_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status'], 'client_first_touch_dispute_status_idx');
            $table->index('claim_id', 'client_first_touch_dispute_claim_idx');
        });

        Schema::create('client_first_touch_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('current_claim_id')->nullable();
            $table->string('status', 40)->default('open');
            $table->string('resolution', 40)->nullable();
            $table->text('comment')->nullable();
            $table->string('clarification_recipient', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by_staff_id')->nullable();
            $table->string('reviewed_by_name', 255)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('resolved_by_staff_id')->nullable();
            $table->string('resolved_by_name', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status'], 'client_first_touch_conflict_status_idx');
        });

        Schema::create('client_first_touch_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('platform', 120)->nullable();
            $table->string('author', 255)->nullable();
            $table->date('evidence_date')->nullable();
            $table->unsignedBigInteger('uploaded_by_staff_id');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'client_first_touch_evidence_owner_idx');
        });

        Schema::create('client_first_touch_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('claim_id');
            $table->text('reason');
            $table->json('previous_snapshot');
            $table->unsignedBigInteger('revised_by_staff_id');
            $table->string('revised_by_name', 255);
            $table->timestamp('revised_at');
            $table->timestamps();

            $table->index('claim_id', 'client_first_touch_revision_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_first_touch_revisions');
        Schema::dropIfExists('client_first_touch_evidence');
        Schema::dropIfExists('client_first_touch_conflicts');
        Schema::dropIfExists('client_first_touch_disputes');
        Schema::dropIfExists('client_first_touch_claims');
    }
};
