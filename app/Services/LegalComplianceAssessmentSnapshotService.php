<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LegalComplianceAssessmentSnapshotService
{
    public function resolve(object $record): array
    {
        $storedSnapshot = $this->decodeSnapshot($record->template_snapshot ?? null);
        if ($this->isValidSnapshot($storedSnapshot)) {
            return $this->result($storedSnapshot, 'existing_valid');
        }

        $templateVersion = null;
        $source = 'unresolved';

        if (! empty($record->template_version_id)) {
            $templateVersion = $this->versionById((int) $record->template_version_id);
            $source = $templateVersion ? 'version_id' : $source;
        }

        $templateId = (int) ($record->template_id ?? 0);
        $versionNumber = $this->parseVersionNumber($record->template_version ?? null);

        if (! $templateVersion && $templateId > 0 && $versionNumber !== null) {
            $templateVersion = $this->versionByTemplateAndNumber($templateId, $versionNumber);
            $source = $templateVersion ? 'version_number' : $source;
        }

        if (! $templateVersion && $templateId > 0) {
            $templateVersion = $this->versionByTemplateAndDate($templateId, $this->recordDate($record));
            $source = $templateVersion ? 'date_match' : $source;
        }

        if (! $templateVersion && $this->isLegacyDefaultVersion($record->template_version ?? null)) {
            $templateVersion = $this->defaultTemplateVersionOne();
            $source = $templateVersion ? 'legacy_default_v1' : $source;
        }

        if (! $templateVersion) {
            return $this->result([], 'unresolved', null, true);
        }

        $snapshot = $this->decodeSnapshot($templateVersion->content ?? null);
        if (! $this->isValidSnapshot($snapshot)) {
            return $this->result([], 'unresolved', null, true);
        }

        return $this->result($snapshot, $source, $templateVersion);
    }

    public function isValidSnapshot(array $snapshot): bool
    {
        return isset($snapshot['groups']) && is_array($snapshot['groups']) && ! empty($snapshot['groups']);
    }

    public function decodeSnapshot($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function parseVersionNumber($value): ?int
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        if (preg_match('/^v?(\d+)$/i', $text, $matches) === 1) {
            $number = (int) $matches[1];

            return $number > 0 ? $number : null;
        }

        if (preg_match('/[-_]v(\d+)$/i', $text, $matches) === 1) {
            $number = (int) $matches[1];

            return $number > 0 ? $number : null;
        }

        return null;
    }

    private function result(
        array $snapshot,
        string $source,
        ?object $templateVersion = null,
        bool $unresolved = false
    ): array {
        return [
            'snapshot' => $snapshot,
            'source' => $source,
            'template_version' => $templateVersion,
            'unresolved' => $unresolved,
        ];
    }

    private function versionById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        return DB::table('legal_compliance_template_versions')
            ->where('id', $id)
            ->first();
    }

    private function versionByTemplateAndNumber(int $templateId, int $versionNumber): ?object
    {
        return DB::table('legal_compliance_template_versions')
            ->where('template_id', $templateId)
            ->where('version_number', $versionNumber)
            ->first();
    }

    private function versionByTemplateAndDate(int $templateId, ?Carbon $date): ?object
    {
        if (! $date) {
            return null;
        }

        return DB::table('legal_compliance_template_versions')
            ->where('template_id', $templateId)
            ->whereRaw('COALESCE(published_at, created_at) <= ?', [$date->toDateTimeString()])
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->orderByDesc('version_number')
            ->first();
    }

    private function defaultTemplateVersionOne(): ?object
    {
        return DB::table('legal_compliance_templates as templates')
            ->join('legal_compliance_template_versions as versions', 'versions.template_id', '=', 'templates.id')
            ->where('templates.is_default', true)
            ->where('versions.version_number', 1)
            ->select('versions.*')
            ->first();
    }

    private function recordDate(object $record): ?Carbon
    {
        foreach (['assessment_date', 'submitted_at', 'created_at'] as $field) {
            $value = trim((string) ($record->{$field} ?? ''));
            if ($value === '') {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function isLegacyDefaultVersion($value): bool
    {
        return strtolower(trim((string) ($value ?? ''))) === 'osha-1994-v1';
    }
}
