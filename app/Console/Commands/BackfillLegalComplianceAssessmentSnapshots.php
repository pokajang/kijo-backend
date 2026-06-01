<?php

namespace App\Console\Commands;

use App\Services\LegalComplianceAssessmentSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLegalComplianceAssessmentSnapshots extends Command
{
    protected $signature = 'legal-compliance:backfill-assessment-snapshots {--commit} {--id=*} {--limit=}';

    protected $description = 'Backfill missing legal compliance assessment template snapshots from immutable template versions.';

    public function handle(LegalComplianceAssessmentSnapshotService $snapshotService): int
    {
        $commit = (bool) $this->option('commit');
        $ids = collect($this->option('id') ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = DB::table('legal_compliance_assessments')
            ->select([
                'id',
                'template_id',
                'template_version_id',
                'template_version',
                'template_snapshot',
                'assessment_date',
                'submitted_at',
                'created_at',
            ])
            ->orderBy('id');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $counts = [
            'existing_valid' => 0,
            'version_id' => 0,
            'version_number' => 0,
            'date_match' => 0,
            'legacy_default_v1' => 0,
            'unresolved' => 0,
            'updated' => 0,
        ];

        $records = $query->get();
        foreach ($records as $record) {
            $resolution = $snapshotService->resolve($record);
            $source = (string) ($resolution['source'] ?? 'unresolved');
            $counts[$source] = ($counts[$source] ?? 0) + 1;

            if ($source === 'existing_valid' || ! empty($resolution['unresolved'])) {
                continue;
            }

            if (! $commit) {
                continue;
            }

            $updates = $this->updatesForRecord($record, $resolution);
            if (empty($updates)) {
                continue;
            }

            DB::table('legal_compliance_assessments')
                ->where('id', $record->id)
                ->update($updates);

            $counts['updated']++;
        }

        $this->line($commit ? 'Mode: commit' : 'Mode: dry run');
        $this->line('Records scanned: '.$records->count());
        foreach (['existing_valid', 'version_id', 'version_number', 'date_match', 'legacy_default_v1', 'unresolved'] as $source) {
            $this->line(str_replace('_', ' ', $source).': '.$counts[$source]);
        }
        $this->line('Rows updated: '.$counts['updated']);

        if (! $commit) {
            $this->warn('Dry run only. Re-run with --commit to write missing snapshots.');
        }

        return self::SUCCESS;
    }

    private function updatesForRecord(object $record, array $resolution): array
    {
        $templateVersion = $resolution['template_version'] ?? null;
        $updates = [
            'template_snapshot' => json_encode($resolution['snapshot'] ?? []),
        ];

        if ($templateVersion) {
            if (empty($record->template_id)) {
                $updates['template_id'] = $templateVersion->template_id;
            }

            if (empty($record->template_version_id)) {
                $updates['template_version_id'] = $templateVersion->id;
            }

            if (trim((string) ($record->template_version ?? '')) === '') {
                $updates['template_version'] = 'v'.$templateVersion->version_number;
            }
        }

        return $updates;
    }
}
