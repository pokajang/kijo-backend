<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_salary_applications', 'hr_other_claim_applications'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('approved_remarks');
                }
                if (! Schema::hasColumn($tableName, 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn($tableName, 'cancel_reason')) {
                    $table->text('cancel_reason')->nullable()->after('cancelled_by');
                }
            });
        }

        if (Schema::hasTable('hr_salary_applications')) {
            try {
                Schema::table('hr_salary_applications', function (Blueprint $table): void {
                    $table->dropUnique('hr_salary_staff_month_unique');
                });
            } catch (\Throwable) {
                // Fresh or drifted databases may not have the legacy unique index.
            }

            try {
                Schema::table('hr_salary_applications', function (Blueprint $table): void {
                    $table->index(['staff_id', 'salary_month', 'status'], 'hr_salary_staff_month_status_idx');
                });
            } catch (\Throwable) {
                // The supporting lookup index may already exist in drifted databases.
            }
        }

        if (! Schema::hasTable('hr_salary_workflow_events')) {
            Schema::create('hr_salary_workflow_events', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_type', 120);
                $table->unsignedBigInteger('subject_id');
                $table->string('action', 80);
                $table->unsignedBigInteger('actor_staff_id')->nullable();
                $table->string('status_from', 40)->nullable();
                $table->string('status_to', 40)->nullable();
                $table->text('reason')->nullable();
                $table->json('previous_snapshot_json')->nullable();
                $table->timestamp('acted_at');
                $table->timestamps();

                $table->index(['subject_type', 'subject_id'], 'salary_workflow_events_subject_idx');
                $table->index(['action', 'acted_at'], 'salary_workflow_events_action_idx');
                $table->index('actor_staff_id', 'salary_workflow_events_actor_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_workflow_events');

        if (Schema::hasTable('hr_salary_applications')) {
            try {
                Schema::table('hr_salary_applications', function (Blueprint $table): void {
                    $table->dropIndex('hr_salary_staff_month_status_idx');
                });
            } catch (\Throwable) {
                // Drifted databases may not have the supporting lookup index.
            }

            try {
                Schema::table('hr_salary_applications', function (Blueprint $table): void {
                    $table->unique(['staff_id', 'salary_month'], 'hr_salary_staff_month_unique');
                });
            } catch (\Throwable) {
                // Existing duplicate cancelled rows cannot safely recreate this index.
            }
        }

        foreach (['hr_other_claim_applications', 'hr_salary_applications'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                foreach (['cancel_reason', 'cancelled_by', 'cancelled_at'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
