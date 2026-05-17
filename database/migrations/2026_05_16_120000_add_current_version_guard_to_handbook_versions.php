<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_handbook_versions')) {
            return;
        }

        if (!Schema::hasColumn('hr_handbook_versions', 'current_version_guard')) {
            Schema::table('hr_handbook_versions', function (Blueprint $table) {
                $table->unsignedTinyInteger('current_version_guard')->nullable()->after('is_current');
            });
        }

        $currentIds = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->pluck('id');

        if ($currentIds->count() > 1) {
            DB::table('hr_handbook_versions')
                ->whereIn('id', $currentIds->slice(1)->values()->all())
                ->update([
                    'is_current' => false,
                    'current_version_guard' => null,
                    'updated_at' => now(),
                ]);
        }

        DB::table('hr_handbook_versions')->where('is_current', 0)->update([
            'current_version_guard' => null,
        ]);

        if ($currentIds->isNotEmpty()) {
            DB::table('hr_handbook_versions')->where('id', $currentIds->first())->update([
                'is_current' => true,
                'current_version_guard' => 1,
            ]);
        }

        Schema::table('hr_handbook_versions', function (Blueprint $table) {
            $table->unique('current_version_guard', 'uniq_hr_handbook_versions_one_current');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_handbook_versions') || !Schema::hasColumn('hr_handbook_versions', 'current_version_guard')) {
            return;
        }

        Schema::table('hr_handbook_versions', function (Blueprint $table) {
            $table->dropUnique('uniq_hr_handbook_versions_one_current');
            $table->dropColumn('current_version_guard');
        });
    }
};
