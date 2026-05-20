<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_leave_workflow_recipients')) {
            return;
        }

        Schema::create('hr_leave_workflow_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('stage_key', 120)->index();
            $table->unsignedInteger('staff_id')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('created_by')->nullable()->index();
            $table->unsignedInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['stage_key', 'staff_id'], 'hr_leave_workflow_stage_staff_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_workflow_recipients');
    }
};
