<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_vendor_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
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
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'deleted_at'], 'client_vendor_reg_client_deleted_idx');
            $table->index(['valid_until', 'deleted_at'], 'client_vendor_reg_expiry_idx');
        });

        Schema::create('client_vendor_registration_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('staff_id');
            $table->timestamps();

            $table->unique(['registration_id', 'staff_id'], 'client_vendor_reg_recipient_unique');
            $table->index('staff_id', 'client_vendor_reg_recipient_staff_idx');
        });

        Schema::create('client_vendor_registration_reminder_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('stage', 40);
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->text('body_snapshot')->nullable();
            $table->string('status', 40)->default('sent');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['registration_id', 'staff_id', 'stage', 'status'], 'client_vendor_reg_reminder_unique');
            $table->index(['stage', 'sent_at'], 'client_vendor_reg_reminder_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_vendor_registration_reminder_logs');
        Schema::dropIfExists('client_vendor_registration_recipients');
        Schema::dropIfExists('client_vendor_registrations');
    }
};
