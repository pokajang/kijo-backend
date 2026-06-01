<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_monthly_report_test_logs')) {
            return;
        }

        Schema::create('dashboard_monthly_report_test_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('report_month', 7);
            $table->string('recipient_email');
            $table->string('status', 20);
            $table->text('response_message')->nullable();
            $table->text('public_url')->nullable();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('name_code', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['report_month', 'status']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_monthly_report_test_logs');
    }
};
