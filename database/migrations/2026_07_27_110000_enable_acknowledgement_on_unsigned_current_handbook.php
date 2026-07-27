<?php

use App\Services\Handbook\HandbookAcknowledgementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGE_SUMMARY = 'Enabled the current handbook acknowledgement evidence module before staff signing began.';

    public function up(): void
    {
        if (! $this->schemaIsReady()) {
            return;
        }

        DB::transaction(function (): void {
            $current = DB::table('hr_handbook_versions')
                ->where('is_current', true)
                ->lockForUpdate()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();

            if (! $current || (int) $current->acknowledgement_schema_version === HandbookAcknowledgementService::SCHEMA_VERSION) {
                return;
            }

            if (DB::table('hr_handbook_sign')->where('handbook_version_id', $current->id)->exists()) {
                throw new RuntimeException(
                    'The current handbook already has acknowledgement records and cannot be upgraded in place. Publish a new version instead.',
                );
            }

            $content = json_decode((string) $current->content_json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($content)) {
                throw new RuntimeException('The current handbook content is not a valid JSON object.');
            }

            if (isset($content['acknowledgement'])) {
                throw new RuntimeException(
                    'The current handbook contains an unknown legacy acknowledgement definition and requires manual review.',
                );
            }

            $acknowledgements = app(HandbookAcknowledgementService::class);
            $content['acknowledgement'] = $acknowledgements->materialize(
                $acknowledgements->defaultDefinition(),
                (string) $current->version_label,
            );
            $encoded = json_encode(
                $content,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $now = now();

            DB::table('hr_handbook_versions')->where('id', $current->id)->update([
                'content_json' => $encoded,
                'content_sha256' => hash('sha256', $encoded),
                'acknowledgement_schema_version' => HandbookAcknowledgementService::SCHEMA_VERSION,
                'acknowledgement_sha256' => $acknowledgements->hash($content['acknowledgement']),
                'updated_at' => $now,
            ]);

            if (Schema::hasTable('hr_handbook_change_logs')) {
                DB::table('hr_handbook_change_logs')->insert([
                    'handbook_version_id' => $current->id,
                    'action' => 'acknowledgement_upgrade',
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
            }
        });
    }

    public function down(): void
    {
        if (! $this->schemaIsReady()) {
            return;
        }

        DB::transaction(function (): void {
            $current = DB::table('hr_handbook_versions')
                ->where('is_current', true)
                ->lockForUpdate()
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->first();

            if (! $current || (int) $current->acknowledgement_schema_version !== HandbookAcknowledgementService::SCHEMA_VERSION) {
                return;
            }

            if (DB::table('hr_handbook_sign')->where('handbook_version_id', $current->id)->exists()) {
                throw new RuntimeException(
                    'Cannot remove the acknowledgement module after staff acknowledgements have been recorded.',
                );
            }

            $content = json_decode((string) $current->content_json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($content)) {
                throw new RuntimeException('The current handbook content is not a valid JSON object.');
            }

            unset($content['acknowledgement']);
            $encoded = json_encode(
                $content,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            DB::table('hr_handbook_versions')->where('id', $current->id)->update([
                'content_json' => $encoded,
                'content_sha256' => hash('sha256', $encoded),
                'acknowledgement_schema_version' => null,
                'acknowledgement_sha256' => null,
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('hr_handbook_change_logs')) {
                DB::table('hr_handbook_change_logs')
                    ->where('handbook_version_id', $current->id)
                    ->where('action', 'acknowledgement_upgrade')
                    ->where('summary', self::CHANGE_SUMMARY)
                    ->delete();
            }
        });
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasTable('hr_handbook_versions')
            && Schema::hasTable('hr_handbook_sign')
            && Schema::hasColumn('hr_handbook_versions', 'content_sha256')
            && Schema::hasColumn('hr_handbook_versions', 'acknowledgement_schema_version')
            && Schema::hasColumn('hr_handbook_versions', 'acknowledgement_sha256');
    }
};
