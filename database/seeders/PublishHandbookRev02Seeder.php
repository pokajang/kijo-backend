<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use LogicException;
use RuntimeException;

class PublishHandbookRev02Seeder extends Seeder
{
    private const VERSION_LABEL = 'REV02 - 2026-07';

    private const CHANGE_SUMMARY = 'Published HR-provided AMIOSH Employee Handbook & Culture Guide REV02 (07/2026) as a 12-section snapshot.';

    public function run(): void
    {
        $this->assertRequiredTablesExist();

        $content = $this->loadSnapshot();
        $existing = DB::table('hr_handbook_versions')
            ->where('version_label', self::VERSION_LABEL)
            ->where('change_summary', self::CHANGE_SUMMARY)
            ->first(['id', 'is_current']);

        if ($existing) {
            $this->command?->info(sprintf(
                'Handbook %s already exists as version #%d; no changes made.',
                self::VERSION_LABEL,
                $existing->id,
            ));

            return;
        }

        if (Schema::hasTable('hr_handbook_drafts')
            && DB::table('hr_handbook_drafts')->where('status', 'active')->exists()) {
            throw new LogicException(
                'Cannot publish Handbook REV02 while an active handbook draft exists. Publish or discard the draft first.',
            );
        }

        $now = now();

        $versionId = DB::transaction(function () use ($content, $now): int {
            DB::table('hr_handbook_versions')
                ->where('is_current', true)
                ->lockForUpdate()
                ->get(['id']);

            $archivedPayload = [
                'is_current' => false,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('hr_handbook_versions', 'current_version_guard')) {
                $archivedPayload['current_version_guard'] = null;
            }

            DB::table('hr_handbook_versions')
                ->where('is_current', true)
                ->update($archivedPayload);

            $versionPayload = [
                'version_label' => self::VERSION_LABEL,
                'content_json' => $content,
                'change_summary' => self::CHANGE_SUMMARY,
                'published_by_staff_id' => null,
                'published_by_name_code' => 'SYSTEM',
                'published_at' => $now,
                'is_current' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('hr_handbook_versions', 'current_version_guard')) {
                $versionPayload['current_version_guard'] = 1;
            }

            $versionId = DB::table('hr_handbook_versions')->insertGetId($versionPayload);

            DB::table('hr_handbook_change_logs')->insert([
                'handbook_version_id' => $versionId,
                'action' => 'publish',
                'section_id' => null,
                'section_title' => null,
                'summary' => self::CHANGE_SUMMARY,
                'changed_by_staff_id' => null,
                'changed_by_name_code' => 'SYSTEM',
                'changed_at' => $now,
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $versionId;
        });

        $this->command?->info(sprintf(
            'Published handbook %s as version #%d. Previous versions were preserved.',
            self::VERSION_LABEL,
            $versionId,
        ));
    }

    private function assertRequiredTablesExist(): void
    {
        foreach (['hr_handbook_versions', 'hr_handbook_change_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot publish Handbook REV02 because {$table} does not exist. Run migrations first.");
            }
        }
    }

    private function loadSnapshot(): string
    {
        $path = database_path('seeders/data/handbook_rev02_2026_07.json');
        $content = file_get_contents($path);
        if (! is_string($content) || $content === '') {
            throw new RuntimeException("Handbook REV02 snapshot is missing or empty: {$path}");
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Handbook REV02 snapshot is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)
            || ! is_array($decoded['chapters'] ?? null)
            || count($decoded['chapters']) !== 12) {
            throw new RuntimeException('Handbook REV02 snapshot must contain exactly 12 sections.');
        }

        return $content;
    }
}
