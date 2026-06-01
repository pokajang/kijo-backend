<?php

namespace App\Services\Stats;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsManualHelpers
{
    private function monitoringManualEntries(array $context, array $staffFilter): array
    {
        $empty = [
            'events' => [],
            'serviceEvents' => [],
            'rows' => [],
        ];

        if (! $this->monitoringManualPipelineEntriesReady()) {
            return $empty;
        }

        $query = DB::table('monitoring_manual_pipeline_entries')
            ->whereBetween('entry_date', [$context['monthStart'], $context['monthEnd']])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if (! empty($staffFilter['code'])) {
            $query->whereRaw('UPPER(owner_staff_code) = ?', [$staffFilter['code']]);
        }

        $events = [];
        $serviceEvents = [];
        $rows = [];

        foreach ($query->get() as $entry) {
            $label = $this->monitoringManualEntryTypeToToolLabel((string) $entry->entry_type);
            $classification = $this->normalizeMonitoringManualClassification($entry->segment_type);
            $segment = $classification ?: 'individual';
            $estimatedRm = $this->normalizeMonitoringManualEstimatedRm($entry->estimated_rm);
            $contributor = $this->monitoringManualContributor(
                $entry,
                $label ?: (string) $entry->entry_type,
                $classification,
                $estimatedRm
            );
            $event = [
                'date' => (string) $entry->entry_date,
                'key' => 'manual:'.$entry->entry_type.':'.(int) $entry->id,
                'segment' => $segment,
                'contributor' => $contributor,
            ];
            if ($estimatedRm !== null) {
                $event['value'] = $estimatedRm;
            }

            $isCompleteClosedRevenue = (string) $entry->entry_type === 'closed'
                && $this->isMonitoringManualClosedRevenueComplete($entry);

            if ($label !== null && ($label !== 'CLOSED' || $isCompleteClosedRevenue)) {
                $events[$label][] = $event;
            }

            $serviceLabel = $this->monitoringManualServiceCategoryToStatusLabel($entry->service_category);
            if (
                $serviceLabel !== null &&
                $isCompleteClosedRevenue
            ) {
                $serviceEvents[$serviceLabel][] = $event;
            }

            $rows[] = [
                'id' => (int) $entry->id,
                'entryType' => (string) $entry->entry_type,
                'prospectName' => (string) $entry->prospect_name,
                'entryDate' => (string) $entry->entry_date,
                'source' => (string) ($entry->source ?? ''),
                'segmentType' => (string) ($classification ?? ''),
                'serviceCategory' => (string) ($this->normalizeMonitoringManualServiceCategory($entry->service_category) ?? ''),
                'estimatedRm' => $estimatedRm,
                'notes' => (string) ($entry->notes ?? ''),
                'photoUrl' => ! empty($entry->photo_path)
                    ? $this->monitoringManualEntryPhotoUrl((int) $entry->id)
                    : null,
                'photoOriginalName' => (string) ($entry->photo_original_name ?? ''),
                'ownerStaffCode' => (string) ($entry->owner_staff_code ?? ''),
                'ownerStaffName' => (string) ($entry->owner_staff_name ?? ''),
                'createdByCode' => (string) ($entry->created_by_code ?? ''),
            ];
        }

        return [
            'events' => $events,
            'serviceEvents' => $serviceEvents,
            'rows' => $rows,
        ];
    }

    private function monitoringManualPipelineEntriesReady(): bool
    {
        if (! Schema::hasTable('monitoring_manual_pipeline_entries')) {
            return false;
        }

        $requiredColumns = [
            'entry_type',
            'prospect_name',
            'entry_date',
            'source',
            'segment_type',
            'service_category',
            'estimated_rm',
            'notes',
            'photo_path',
            'photo_original_name',
            'photo_mime_type',
            'owner_staff_id',
            'owner_staff_code',
            'owner_staff_name',
            'created_by',
            'created_by_code',
        ];

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('monitoring_manual_pipeline_entries', $column)) {
                return false;
            }
        }

        return true;
    }

    private function isMonitoringManualClosedRevenueComplete($entry): bool
    {
        return $this->validateMonitoringManualClosedRevenue([
            'entry_type' => (string) ($entry->entry_type ?? ''),
            'service_category' => $entry->service_category ?? null,
            'estimated_rm' => $entry->estimated_rm ?? null,
        ]) === null;
    }

    private function monitoringManualContributor($entry, string $label, ?string $classification, ?float $estimatedRm): array
    {
        return [
            'sourceType' => 'manual',
            'sourceId' => 'manual:'.(string) ($entry->entry_type ?? '').':'.(int) ($entry->id ?? 0),
            'eventType' => $label,
            'date' => (string) $entry->entry_date,
            'clientName' => $this->monitoringCleanText($entry->prospect_name ?? ''),
            'serviceType' => $this->monitoringCleanText($this->normalizeMonitoringManualServiceCategory($entry->service_category) ?? ''),
            'subject' => '',
            'value' => $estimatedRm,
            'source' => $this->monitoringCleanText($entry->source ?? ''),
            'notes' => $this->monitoringCleanText($entry->notes ?? ''),
            'ownerStaffCode' => $this->monitoringCleanText($entry->owner_staff_code ?? ''),
            'ownerStaffName' => $this->monitoringCleanText($entry->owner_staff_name ?? ''),
            'segment' => $classification ?: 'individual',
            'photoUrl' => ! empty($entry->photo_path)
                ? $this->monitoringManualEntryPhotoUrl((int) $entry->id)
                : null,
            'photoOriginalName' => $this->monitoringCleanText($entry->photo_original_name ?? ''),
        ];
    }

    private function monitoringManualEntryPhotoUrl(int $id): string
    {
        return url('stats/monitoring-manual-pipeline-entry/'.$id.'/photo');
    }

    private function monitoringManualEntryTypeToToolLabel(string $entryType): ?string
    {
        return match ($entryType) {
            'lead' => 'LEADS',
            'qualified' => 'QUALIFIED',
            'meeting_pitching' => 'MEETING/ PITCHING',
            'proposal' => 'PROPOSAL',
            'negotiation' => 'NEGOTIATION',
            'closed' => 'CLOSED',
            default => null,
        };
    }

    private function monitoringManualServiceCategoryToStatusLabel($serviceCategory): ?string
    {
        $serviceCategory = $this->normalizeMonitoringManualServiceCategory($serviceCategory);

        return $serviceCategory !== null
            ? self::MONITORING_MANUAL_SERVICE_CATEGORIES[$serviceCategory]
            : null;
    }

    private function normalizeMonitoringManualClassification($segmentType): ?string
    {
        $segmentType = trim((string) ($segmentType ?? ''));

        if ($segmentType === '' || $segmentType === 'individual') {
            return null;
        }

        return $segmentType;
    }

    private function normalizeMonitoringManualEstimatedRm($estimatedRm): ?float
    {
        if ($estimatedRm === null || $estimatedRm === '') {
            return null;
        }

        return is_numeric($estimatedRm) ? max(0.0, (float) $estimatedRm) : null;
    }

    private function normalizeMonitoringManualServiceCategory($serviceCategory): ?string
    {
        $serviceCategory = trim((string) ($serviceCategory ?? ''));

        return array_key_exists($serviceCategory, self::MONITORING_MANUAL_SERVICE_CATEGORIES)
            ? $serviceCategory
            : null;
    }

    private function validateMonitoringManualClosedRevenue(array $entry): ?string
    {
        if (($entry['entry_type'] ?? null) !== 'closed') {
            return null;
        }

        if ($this->normalizeMonitoringManualServiceCategory($entry['service_category'] ?? null) === null) {
            return 'Closed manual entries require a service category.';
        }

        $estimatedRm = $this->normalizeMonitoringManualEstimatedRm($entry['estimated_rm'] ?? null);
        if ($estimatedRm === null || $estimatedRm <= 0) {
            return 'Closed manual entries require Estimated RM greater than zero.';
        }

        return null;
    }
}
