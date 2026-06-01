<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workload_daily_snapshots')) {
            Schema::create('workload_daily_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->date('snapshot_date')->unique();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->unsignedInteger('staff_count')->default(0);
                $table->decimal('total_score', 12, 2)->default(0);
                $table->decimal('avg_score', 12, 2)->default(0);
                $table->unsignedInteger('total_active_tasks')->default(0);
                $table->unsignedInteger('total_overdue_tasks')->default(0);
                $table->unsignedInteger('total_due_soon_tasks')->default(0);
                $table->unsignedInteger('total_completed_in_period')->default(0);
                $table->longText('payload_json');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workload_daily_staff_snapshots')) {
            Schema::create('workload_daily_staff_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->date('snapshot_date')->index();
                $table->unsignedBigInteger('staff_id')->nullable()->index();
                $table->string('staff_key')->index();
                $table->string('staff_code')->nullable();
                $table->string('staff_name')->nullable();
                $table->decimal('score', 12, 2)->default(0);
                $table->unsignedInteger('active_tasks')->default(0);
                $table->unsignedInteger('overdue_tasks')->default(0);
                $table->unsignedInteger('due_soon_tasks')->default(0);
                $table->unsignedInteger('project_tagged_active_tasks')->default(0);
                $table->unsignedInteger('project_group_count')->default(0);
                $table->unsignedInteger('completed_in_period')->default(0);
                $table->unsignedInteger('late_completed_in_period')->default(0);
                $table->integer('avg_days_lapsed')->default(0);
                $table->longText('score_breakdown_json')->nullable();
                $table->longText('work_type_breakdown_json')->nullable();
                $table->longText('row_payload_json')->nullable();
                $table->timestamps();

                $table->unique(['snapshot_date', 'staff_key'], 'workload_daily_staff_snapshot_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workload_daily_staff_snapshots');
        Schema::dropIfExists('workload_daily_snapshots');
    }
};
