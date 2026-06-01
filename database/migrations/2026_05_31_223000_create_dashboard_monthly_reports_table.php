<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_monthly_reports')) {
            return;
        }

        Schema::create('dashboard_monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_month', 7)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('stored_path')->nullable();
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->timestamp('public_token_expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->json('recipients_json')->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_monthly_reports');
    }
};
