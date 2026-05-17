<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payment_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('stage', 40);
            $table->unsignedBigInteger('triggered_by_staff_id')->nullable();
            $table->string('triggered_by_name', 191)->nullable();
            $table->string('triggered_by_code', 50)->nullable();
            $table->string('to_email', 191);
            $table->string('to_name', 191)->nullable();
            $table->json('cc')->nullable();
            $table->string('subject', 255);
            $table->longText('body_snapshot')->nullable();
            $table->string('status', 40)->default('sent');
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'stage'], 'invoice_reminders_invoice_stage_idx');
            $table->index(['stage', 'sent_at'], 'invoice_reminders_stage_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_reminder_logs');
    }
};
