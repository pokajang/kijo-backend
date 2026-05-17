<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_handbook_versions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('version_label', 80);
            $table->longText('content_json');
            $table->text('change_summary')->nullable();
            $table->unsignedInteger('published_by_staff_id')->nullable();
            $table->string('published_by_name_code', 50)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index('is_current', 'idx_hr_handbook_versions_current');
            $table->index('published_at', 'idx_hr_handbook_versions_published_at');
        });

        Schema::create('hr_handbook_change_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('handbook_version_id');
            $table->string('action', 50);
            $table->text('summary')->nullable();
            $table->unsignedInteger('changed_by_staff_id')->nullable();
            $table->string('changed_by_name_code', 50)->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('handbook_version_id', 'idx_hr_handbook_logs_version');
            $table->index('changed_at', 'idx_hr_handbook_logs_changed_at');
        });

        if (Schema::hasTable('hr_handbook_sign') && !Schema::hasColumn('hr_handbook_sign', 'handbook_version_id')) {
            Schema::table('hr_handbook_sign', function (Blueprint $table) {
                $table->unsignedInteger('handbook_version_id')->nullable()->after('id');
                $table->index('handbook_version_id', 'idx_hr_handbook_sign_version');
                $table->index(['staff_id', 'handbook_version_id'], 'idx_hr_handbook_sign_staff_version');
            });
        }

        $contentPath = database_path('seeders/data/handbook_v2_2024_01_05.json');
        $content = is_file($contentPath) ? file_get_contents($contentPath) : null;

        if (is_string($content) && trim($content) !== '') {
            $now = now();
            $versionId = DB::table('hr_handbook_versions')->insertGetId([
                'version_label' => 'V2 - 2024-01-05',
                'content_json' => $content,
                'change_summary' => 'Initial migrated handbook snapshot.',
                'published_by_staff_id' => null,
                'published_by_name_code' => 'SYSTEM',
                'published_at' => $now,
                'is_current' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('hr_handbook_change_logs')->insert([
                'handbook_version_id' => $versionId,
                'action' => 'migrate',
                'summary' => 'Initial static handbook content migrated into versioned snapshots.',
                'changed_by_staff_id' => null,
                'changed_by_name_code' => 'SYSTEM',
                'changed_at' => $now,
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (Schema::hasColumn('hr_handbook_sign', 'handbook_version_id')) {
                DB::table('hr_handbook_sign')
                    ->whereNull('handbook_version_id')
                    ->update(['handbook_version_id' => $versionId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_handbook_sign') && Schema::hasColumn('hr_handbook_sign', 'handbook_version_id')) {
            Schema::table('hr_handbook_sign', function (Blueprint $table) {
                $table->dropIndex('idx_hr_handbook_sign_staff_version');
                $table->dropIndex('idx_hr_handbook_sign_version');
                $table->dropColumn('handbook_version_id');
            });
        }

        Schema::dropIfExists('hr_handbook_change_logs');
        Schema::dropIfExists('hr_handbook_versions');
    }
};
