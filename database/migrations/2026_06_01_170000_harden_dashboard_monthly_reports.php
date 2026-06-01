<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_monthly_reports')) {
            $missingColumns = array_filter(
                ['payload_json', 'payload_hash', 'generation_duration_ms'],
                fn (string $column): bool => ! Schema::hasColumn('dashboard_monthly_reports', $column)
            );

            if ($missingColumns !== []) {
                Schema::table('dashboard_monthly_reports', function (Blueprint $table) use ($missingColumns): void {
                    if (in_array('payload_json', $missingColumns, true)) {
                        $table->longText('payload_json')->nullable()->after('recipients_json');
                    }
                    if (in_array('payload_hash', $missingColumns, true)) {
                        $table->string('payload_hash', 64)->nullable()->after('payload_json');
                    }
                    if (in_array('generation_duration_ms', $missingColumns, true)) {
                        $table->unsignedInteger('generation_duration_ms')->nullable()->after('payload_hash');
                    }
                });
            }
        }

        if (! Schema::hasTable('dashboard_monthly_report_email_logs')) {
            Schema::create('dashboard_monthly_report_email_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('report_id')->nullable()->index();
                $table->string('report_month', 7)->index();
                $table->string('recipient_email');
                $table->string('recipient_name')->nullable();
                $table->string('send_type', 20)->default('production')->index();
                $table->string('status', 20)->default('pending')->index();
                $table->text('public_url')->nullable();
                $table->timestamp('public_token_expires_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable()->index();
                $table->timestamps();

                $table->index(['report_month', 'send_type', 'status'], 'dmr_email_logs_report_type_status_idx');
                $table->index(['recipient_email', 'sent_at'], 'dmr_email_logs_recipient_sent_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_monthly_report_email_logs');

        if (Schema::hasTable('dashboard_monthly_reports')) {
            $existingColumns = array_values(array_filter(
                ['generation_duration_ms', 'payload_hash', 'payload_json'],
                fn (string $column): bool => Schema::hasColumn('dashboard_monthly_reports', $column)
            ));

            if ($existingColumns !== []) {
                Schema::table('dashboard_monthly_reports', function (Blueprint $table) use ($existingColumns): void {
                    $table->dropColumn($existingColumns);
                });
            }
        }
    }
};
