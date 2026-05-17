<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $summary = 'Updated Common Rules lunch break wording to remove em dashes.';

    public function up(): void
    {
        if (!Schema::hasTable('hr_handbook_versions') || !Schema::hasTable('hr_handbook_change_logs')) {
            return;
        }

        $current = DB::table('hr_handbook_versions')
            ->where('is_current', 1)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if (!$current) {
            return;
        }

        $content = json_decode((string) $current->content_json, true);
        if (!is_array($content) || !isset($content['chapters']) || !is_array($content['chapters'])) {
            return;
        }

        $changed = false;
        foreach ($content['chapters'] as &$chapter) {
            if (($chapter['id'] ?? null) !== 'chapter-09') {
                continue;
            }

            $original = (string) ($chapter['bodyHtml'] ?? '');
            $updated = str_replace([
                'Snacks in the kitchen are for quick energy boosts—not meal replacements.',
                'Cooking facilities are available—please clean up after yourself to keep our shared space tidy.',
            ], [
                'Snacks in the kitchen are for quick energy boosts. They are not meal replacements.',
                'Cooking facilities are available. Please clean up after yourself to keep our shared space tidy.',
            ], $original);

            if ($updated !== $original) {
                $chapter['bodyHtml'] = $updated;
                $changed = true;
            }
        }
        unset($chapter);

        if (!$changed) {
            return;
        }

        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($encoded, $now) {
            DB::table('hr_handbook_versions')->where('is_current', 1)->update([
                'is_current' => false,
                'updated_at' => $now,
            ]);

            $versionId = DB::table('hr_handbook_versions')->insertGetId([
                'version_label' => $this->nextVersionLabel(),
                'content_json' => $encoded,
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
                'action' => 'publish',
                'section_id' => 'chapter-09',
                'section_title' => '9.0 Common Rules',
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

        $version = DB::table('hr_handbook_versions')
            ->where('change_summary', $this->summary)
            ->where('published_by_name_code', 'SYSTEM')
            ->orderByDesc('id')
            ->first();

        if (!$version || !(bool) $version->is_current) {
            return;
        }

        DB::transaction(function () use ($version) {
            DB::table('hr_handbook_change_logs')
                ->where('handbook_version_id', $version->id)
                ->where('summary', $this->summary)
                ->delete();

            DB::table('hr_handbook_versions')->where('id', $version->id)->delete();

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
