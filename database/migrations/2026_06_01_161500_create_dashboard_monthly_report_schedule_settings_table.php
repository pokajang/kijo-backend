<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_monthly_report_schedule_settings')) {
            return;
        }

        Schema::create('dashboard_monthly_report_schedule_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('interval_value')->default(1);
            $table->string('interval_unit', 20)->default('months');
            $table->date('start_date');
            $table->string('send_time', 5)->default('08:30');
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('updated_by_staff_id')->nullable();
            $table->string('updated_by_code', 20)->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_send_at'], 'dmr_schedule_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_monthly_report_schedule_settings');
    }
};
