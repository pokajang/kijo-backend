<?php

namespace App\Services\Stats;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsLegalComplianceHelpers
{
    private const MONITORING_LEGAL_COMPLIANCE_SOURCE = 'Free Legal Compliance Assessment';

    private function monitoringLegalComplianceAssessmentEvents(array $context, array $staffFilter): array
    {
        $events = [];
        $start = $context['monthStart'] ?? null;
        $end = $context['monthEnd'] ?? null;
        $assessmentId = isset($context['assessmentId']) ? (int) $context['assessmentId'] : null;

        foreach ($this->monitoringLegalComplianceAssessmentRecords($start, $end, $assessmentId) as $record) {
            if (! $this->monitoringLegalComplianceIsFreeAssessment($record)) {
                continue;
            }

            $date = $this->monitoringLegalComplianceActivityDate($record);
            if ($date === null) {
                continue;
            }

            foreach ($this->monitoringLegalComplianceAssessors($record) as $assessor) {
                if (
                    ! empty($staffFilter['code']) &&
                    $this->monitoringLegalNormalizeStaffCode($assessor['staffCode'] ?? null) !== $staffFilter['code']
                ) {
                    continue;
                }

                $events[] = [
                    'date' => $date,
                    'key' => 'legal-compliance:'.(int) $record->id.':assessor:'.$assessor['key'],
                    'segment' => 'individual',
                    'contributor' => $this->monitoringLegalComplianceContributor($record, $assessor, $date),
                ];
            }
        }

        return $events;
    }

    private function monitoringLegalCompliancePipelineEntries(
        Request $request,
        ?string $start,
        ?string $end,
        array $staffFilter
    ): array {
        $entryType = trim((string) $request->input('entry_type', ''));
        $source = trim((string) $request->input('source', ''));
        $segmentType = trim((string) $request->input('segment_type', ''));
        $serviceCategory = trim((string) $request->input('service_category', ''));
        $search = trim((string) $request->input('q', ''));

        if ($entryType !== '' && $entryType !== 'meeting_pitching') {
            return [];
        }

        if ($source !== '' && $source !== self::MONITORING_LEGAL_COMPLIANCE_SOURCE) {
            return [];
        }

        if ($segmentType !== '' || $serviceCategory !== '') {
            return [];
        }

        $entries = [];
        foreach ($this->monitoringLegalComplianceAssessmentEvents([
            'monthStart' => $start,
            'monthEnd' => $end,
        ], $staffFilter) as $event) {
            $contributor = $event['contributor'] ?? [];
            if (! is_array($contributor)) {
                continue;
            }

            $record = $contributor['record'] ?? null;
            if (! $record) {
                continue;
            }

            $entry = $this->monitoringLegalComplianceEntryFromEvent($event, $record);

            if ($search !== '' && ! $this->monitoringLegalComplianceEntryMatchesSearch($entry, $search)) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function monitoringLegalComplianceEntryFromEvent(array $event, $record): array
    {
        $contributor = is_array($event['contributor'] ?? null) ? $event['contributor'] : [];

        return [
            'id' => $event['key'],
            'recordSource' => 'legal_compliance',
            'legalAssessmentId' => (int) $record->id,
            'entryType' => 'meeting_pitching',
            'prospectName' => (string) ($record->company_name ?? ''),
            'entryDate' => (string) ($event['date'] ?? ''),
            'source' => self::MONITORING_LEGAL_COMPLIANCE_SOURCE,
            'segmentType' => '',
            'serviceCategory' => '',
            'estimatedRm' => null,
            'notes' => $this->monitoringLegalComplianceNotes($record),
            'photoUrl' => null,
            'photoOriginalName' => '',
            'ownerStaffCode' => (string) ($contributor['ownerStaffCode'] ?? ''),
            'ownerStaffName' => (string) ($contributor['ownerStaffName'] ?? ''),
            'createdByCode' => (string) ($record->creator_staff_code ?? ''),
            'createdAt' => (string) ($record->created_at ?? ''),
            'templateName' => (string) ($record->template_name ?? ''),
            'clientPicName' => (string) ($record->client_pic_name ?? ''),
            'clientPicEmail' => (string) ($record->client_pic_email ?? ''),
            'canUpdate' => false,
            'canDelete' => false,
        ];
    }

    private function monitoringLegalCompliancePipelineEntryById(Request $request, string $id): ?array
    {
        if (! preg_match('/^legal-compliance:(\d+):assessor:.+$/', $id, $matches)) {
            return null;
        }

        foreach ($this->monitoringLegalComplianceAssessmentEvents([
            'assessmentId' => (int) $matches[1],
        ], ['code' => null]) as $event) {
            if ((string) ($event['key'] ?? '') !== $id) {
                continue;
            }

            $record = $event['contributor']['record'] ?? null;
            if (! $record) {
                return null;
            }

            return $this->monitoringLegalComplianceEntryFromEvent($event, $record);
        }

        return null;
    }

    private function monitoringLegalComplianceStaffOptions(): array
    {
        $options = [];
        foreach ($this->monitoringLegalComplianceAssessmentRecords(null, null) as $record) {
            if (! $this->monitoringLegalComplianceIsFreeAssessment($record)) {
                continue;
            }

            foreach ($this->monitoringLegalComplianceAssessors($record) as $assessor) {
                $code = $this->monitoringLegalNormalizeStaffCode($assessor['staffCode'] ?? null);
                if ($code === null) {
                    continue;
                }

                $name = trim((string) ($assessor['staffName'] ?? ''));
                $options[] = [
                    'value' => $code,
                    'label' => trim($code.($name !== '' ? ' - '.$name : '')),
                ];
            }
        }

        return $options;
    }

    private function monitoringLegalComplianceAssessmentRecords(?string $start, ?string $end, ?int $assessmentId = null): array
    {
        if (! $this->monitoringLegalComplianceAssessmentsReady()) {
            return [];
        }

        $query = DB::table('legal_compliance_assessments as assessments')
            ->leftJoin('staff_general as creator_staff', 'creator_staff.staff_id', '=', 'assessments.staff_id')
            ->where('assessments.stage', 'submitted')
            ->whereNull('assessments.deleted_at')
            ->where(function ($nested) {
                $nested->whereNull('assessments.project_id')->orWhere('assessments.project_id', 0);
            })
            ->select([
                'assessments.id',
                'assessments.staff_id',
                'assessments.template_id',
                'assessments.template_version',
                'assessments.template_snapshot',
                'assessments.company_name',
                'assessments.site_location',
                'assessments.client_pic_name',
                'assessments.client_pic_email',
                'assessments.assessment_date',
                'assessments.assessor_name',
                'assessments.assessor_email',
                'assessments.selected_assessors',
                'assessments.submitted_at',
                'assessments.created_at',
                'creator_staff.name_code as creator_staff_code',
                'creator_staff.full_name as creator_staff_name',
            ]);

        if (Schema::hasTable('legal_compliance_templates')) {
            $query
                ->leftJoin('legal_compliance_templates as templates', 'templates.id', '=', 'assessments.template_id')
                ->addSelect('templates.name as template_name');
        } else {
            $query->selectRaw('NULL AS template_name');
        }

        if (Schema::hasColumn('legal_compliance_assessments', 'superseded_by_assessment_id')) {
            $query->whereNull('assessments.superseded_by_assessment_id');
        }

        if ($start && $end) {
            $query->whereBetween(DB::raw('DATE(COALESCE(assessments.assessment_date, assessments.submitted_at))'), [$start, $end]);
        }

        if ($assessmentId !== null && $assessmentId > 0) {
            $query->where('assessments.id', $assessmentId);
        }

        return $query->orderByDesc('assessments.assessment_date')
            ->orderByDesc('assessments.submitted_at')
            ->orderByDesc('assessments.id')
            ->get()
            ->all();
    }

    private function monitoringLegalComplianceAssessmentsReady(): bool
    {
        if (! Schema::hasTable('legal_compliance_assessments')) {
            return false;
        }

        $requiredColumns = [
            'id',
            'staff_id',
            'template_id',
            'template_version',
            'template_snapshot',
            'stage',
            'company_name',
            'site_location',
            'client_pic_name',
            'client_pic_email',
            'assessment_date',
            'assessor_name',
            'assessor_email',
            'selected_assessors',
            'submitted_at',
            'project_id',
            'deleted_at',
            'created_at',
        ];

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('legal_compliance_assessments', $column)) {
                return false;
            }
        }

        return true;
    }

    private function monitoringLegalComplianceIsFreeAssessment($record): bool
    {
        $snapshot = json_decode((string) ($record->template_snapshot ?? ''), true) ?: [];

        return strtolower(trim((string) ($snapshot['assessment_tier'] ?? ''))) === 'free';
    }

    private function monitoringLegalComplianceActivityDate($record): ?string
    {
        $rawDate = $record->assessment_date ?: $record->submitted_at;
        if (! $rawDate) {
            return null;
        }

        try {
            return Carbon::parse($rawDate)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function monitoringLegalComplianceAssessors($record): array
    {
        $selected = json_decode((string) ($record->selected_assessors ?? ''), true);
        $selected = is_array($selected) ? $selected : [];
        $assessors = [];

        foreach ($selected as $index => $option) {
            if (! is_array($option)) {
                continue;
            }

            $assessor = $this->monitoringLegalComplianceAssessorFromOption($option, $index);
            if ($assessor !== null) {
                $assessors[] = $assessor;
            }
        }

        if (! empty($assessors)) {
            return $assessors;
        }

        $creatorCode = $this->monitoringLegalNormalizeStaffCode($record->creator_staff_code ?? null);
        $creatorId = (int) ($record->staff_id ?? 0);
        $fallbackKey = $creatorCode ?: ($creatorId > 0 ? 'staff-'.$creatorId : 'unassigned');

        return [[
            'key' => $fallbackKey,
            'staffId' => $creatorId > 0 ? $creatorId : null,
            'staffCode' => $creatorCode ?: ($creatorId > 0 ? 'STAFF-'.$creatorId : ''),
            'staffName' => trim((string) ($record->creator_staff_name ?? $record->assessor_name ?? '')),
            'email' => trim((string) ($record->assessor_email ?? '')),
        ]];
    }

    private function monitoringLegalComplianceAssessorFromOption(array $option, int $index): ?array
    {
        $data = is_array($option['data'] ?? null) ? $option['data'] : [];
        $staffId = (int) ($data['staff_id'] ?? $data['id'] ?? $data['user_id'] ?? 0);
        $staff = $staffId > 0 ? $this->monitoringLegalComplianceStaffById($staffId) : null;
        $staffCode = $this->monitoringLegalNormalizeStaffCode(
            $data['name_code'] ?? $data['code'] ?? ($staff->name_code ?? null)
        );
        $staffName = trim((string) (
            $data['full_name'] ??
            $data['name'] ??
            $data['staff_name'] ??
            ($staff->full_name ?? '') ??
            ''
        ));
        $email = trim((string) ($data['email'] ?? $data['staff_email'] ?? ''));
        $label = trim((string) ($option['label'] ?? ''));
        $key = $staffCode ?: ($staffId > 0 ? 'staff-'.$staffId : md5($label.'|'.$email.'|'.$index));

        if ($staffName === '' && $label !== '') {
            $staffName = preg_replace('/\s+-\s+.*$/', '', $label) ?: $label;
            $staffName = trim(preg_replace('/\s+\([^)]*\)$/', '', $staffName) ?: $staffName);
        }

        if ($staffCode === null && $staffId <= 0 && $staffName === '' && $email === '') {
            return null;
        }

        return [
            'key' => $key,
            'staffId' => $staffId > 0 ? $staffId : null,
            'staffCode' => $staffCode ?: ($staffId > 0 ? 'STAFF-'.$staffId : ''),
            'staffName' => $staffName,
            'email' => $email,
        ];
    }

    private function monitoringLegalComplianceStaffById(int $staffId): ?object
    {
        static $staffById = [];

        if ($staffId <= 0) {
            return null;
        }

        if (array_key_exists($staffId, $staffById)) {
            return $staffById[$staffId];
        }

        $staffById[$staffId] = Schema::hasTable('staff_general')
            ? DB::table('staff_general')->where('staff_id', $staffId)->first()
            : null;

        return $staffById[$staffId];
    }

    private function monitoringLegalComplianceContributor($record, array $assessor, string $date): array
    {
        $templateName = trim((string) ($record->template_name ?? ''));
        $templateVersion = trim((string) ($record->template_version ?? ''));

        return [
            'sourceType' => 'legal_compliance',
            'sourceId' => 'legal-compliance-assessment:'.(int) ($record->id ?? 0).':assessor:'.$assessor['key'],
            'eventType' => 'meeting_pitching',
            'date' => $date,
            'clientName' => trim((string) ($record->company_name ?? '')),
            'serviceType' => '',
            'subject' => trim(implode(' ', array_filter([$templateName, $templateVersion]))),
            'value' => null,
            'source' => self::MONITORING_LEGAL_COMPLIANCE_SOURCE,
            'notes' => $this->monitoringLegalComplianceNotes($record),
            'ownerStaffCode' => (string) ($assessor['staffCode'] ?? ''),
            'ownerStaffName' => (string) ($assessor['staffName'] ?? ''),
            'segment' => 'individual',
            'assessmentId' => (int) ($record->id ?? 0),
            'record' => $record,
        ];
    }

    private function monitoringLegalComplianceNotes($record): string
    {
        return trim(implode(' | ', array_filter([
            (string) ($record->template_name ?? ''),
            (string) ($record->site_location ?? ''),
            (string) ($record->client_pic_name ?? ''),
            (string) ($record->client_pic_email ?? ''),
        ])));
    }

    private function monitoringLegalComplianceEntryMatchesSearch(array $entry, string $search): bool
    {
        $needle = strtolower(trim($search));
        if ($needle === '') {
            return true;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $entry['prospectName'] ?? '',
            $entry['source'] ?? '',
            $entry['ownerStaffCode'] ?? '',
            $entry['ownerStaffName'] ?? '',
            $entry['templateName'] ?? '',
            $entry['clientPicName'] ?? '',
            $entry['clientPicEmail'] ?? '',
            $entry['notes'] ?? '',
        ])));

        return str_contains($haystack, $needle);
    }

    private function monitoringLegalNormalizeStaffCode($value): ?string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '' || $code === 'ALL') {
            return null;
        }

        return $code;
    }
}
