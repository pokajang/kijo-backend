<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_diagnostic_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('status', 20);
            $table->string('mailer', 80)->nullable();
            $table->string('transport', 40)->nullable();
            $table->string('from_address')->nullable();
            $table->string('expected_from_address')->nullable();
            $table->string('recipient_email');
            $table->string('attachment_name')->nullable();
            $table->text('response_message')->nullable();
            $table->json('missing_config')->nullable();
            $table->string('error_class')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('name_code', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_diagnostic_logs');
    }
};
