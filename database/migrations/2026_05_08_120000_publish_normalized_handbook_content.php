<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $summary = 'Normalized handbook document structure; no policy wording changes.';

    public function up(): void
    {
        if (!Schema::hasTable('hr_handbook_versions') || !Schema::hasTable('hr_handbook_change_logs')) {
            return;
        }

        $contentPath = database_path('seeders/data/handbook_v2_2024_01_05.json');
        $content = is_file($contentPath) ? file_get_contents($contentPath) : null;
        if (!is_string($content) || trim($content) === '' || !is_array(json_decode($content, true))) {
            return;
        }

        $current = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if ($current && hash('sha256', (string) $current->content_json) === hash('sha256', $content)) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($content, $now) {
            DB::table('hr_handbook_versions')->where('is_current', 1)->update([
                'is_current' => false,
                'updated_at' => $now,
            ]);

            $versionId = DB::table('hr_handbook_versions')->insertGetId([
                'version_label' => $this->nextVersionLabel(),
                'content_json' => $content,
                'change_summary' => $this->summary,
                'published_by_staff_id' => null,
                'published_by_name_code' => 'SYSTEM',
                'published_at' => $now,
                'is_current' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('hr_handbook_change_logs')->insert([
                'handbook_version_id' => $versionId,
                'action' => 'normalize',
                'section_id' => null,
                'section_title' => null,
                'summary' => $this->summary,
                'changed_by_staff_id' => null,
                'changed_by_name_code' => 'SYSTEM',
                'changed_at' => $now,
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_handbook_versions') || !Schema::hasTable('hr_handbook_change_logs')) {
            return;
        }

        $normalized = DB::table('hr_handbook_versions')
            ->where('change_summary', $this->summary)
            ->where('published_by_name_code', 'SYSTEM')
            ->orderByDesc('id')
            ->first();

        if (!$normalized || !(bool) $normalized->is_current) {
            return;
        }

        DB::transaction(function () use ($normalized) {
            DB::table('hr_handbook_change_logs')
                ->where('handbook_version_id', $normalized->id)
                ->where('summary', $this->summary)
                ->delete();

            DB::table('hr_handbook_versions')->where('id', $normalized->id)->delete();

            $previous = DB::table('hr_handbook_versions')->orderByDesc('id')->first();
            if ($previous) {
                DB::table('hr_handbook_versions')->where('id', $previous->id)->update([
                    'is_current' => true,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function nextVersionLabel(): string
    {
        $max = 0;
        foreach (DB::table('hr_handbook_versions')->pluck('version_label') as $label) {
            if (preg_match('/^V(\d+)/i', (string) $label, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return 'V' . ($max + 1) . ' - ' . now()->toDateString();
    }
};
