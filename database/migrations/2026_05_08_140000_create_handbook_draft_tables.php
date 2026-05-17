<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_handbook_drafts')) {
            Schema::create('hr_handbook_drafts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('base_handbook_version_id');
                $table->unsignedInteger('published_handbook_version_id')->nullable();
                $table->string('status', 30)->default('active');
                $table->longText('content_json');
                $table->unsignedInteger('created_by_staff_id')->nullable();
                $table->string('created_by_name_code', 50)->nullable();
                $table->unsignedInteger('updated_by_staff_id')->nullable();
                $table->string('updated_by_name_code', 50)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'base_handbook_version_id'], 'idx_hr_handbook_drafts_active_base');
            });
        }

        if (!Schema::hasTable('hr_handbook_draft_changes')) {
            Schema::create('hr_handbook_draft_changes', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('handbook_draft_id');
                $table->string('section_id', 80)->nullable();
                $table->string('section_title')->nullable();
                $table->text('summary');
                $table->unsignedInteger('changed_by_staff_id')->nullable();
                $table->string('changed_by_name_code', 50)->nullable();
                $table->timestamp('changed_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index('handbook_draft_id', 'idx_hr_handbook_draft_changes_draft');
                $table->index('changed_at', 'idx_hr_handbook_draft_changes_changed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_handbook_draft_changes');
        Schema::dropIfExists('hr_handbook_drafts');
    }
};
