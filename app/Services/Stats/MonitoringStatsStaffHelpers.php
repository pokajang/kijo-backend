<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsStaffHelpers
{
    private function buildMonitoringStaffOptions(?Request $request = null): array
    {
        $options = $this->baseQuoteFactsQuery()
            ->selectRaw("
                COALESCE(NULLIF(staff_code, ''), 'UNASSIGNED') AS staff_code,
                COALESCE(NULLIF(staff_name, ''), 'Unassigned') AS staff_name
            ")
            ->groupByRaw("
                COALESCE(NULLIF(staff_code, ''), 'UNASSIGNED'),
                COALESCE(NULLIF(staff_name, ''), 'Unassigned')
            ")
            ->orderBy('staff_code')
            ->get()
            ->map(fn($row) => [
                'value' => strtoupper((string) $row->staff_code),
                'label' => trim(strtoupper((string) $row->staff_code) . ' - ' . $row->staff_name),
            ])
            ->all();

        if (Schema::hasTable('google_call_records')) {
            $callStaff = DB::table('google_call_records as gcr')
                ->leftJoin('staff_general as sg', DB::raw('UPPER(sg.name_code)'), '=', DB::raw('UPPER(gcr.called_by_code)'))
                ->whereNotNull('gcr.called_by_code')
                ->selectRaw("
                    UPPER(gcr.called_by_code) AS staff_code,
                    COALESCE(NULLIF(sg.full_name, ''), NULLIF(gcr.called_by_code, ''), 'Unassigned') AS staff_name
                ")
                ->groupByRaw('UPPER(gcr.called_by_code), sg.full_name, gcr.called_by_code')
                ->get();

            foreach ($callStaff as $row) {
                $options[] = [
                    'value' => strtoupper((string) $row->staff_code),
                    'label' => trim(strtoupper((string) $row->staff_code) . ' - ' . $row->staff_name),
                ];
            }
        }

        if ($this->monitoringManualPipelineEntriesReady()) {
            $manualStaff = DB::table('monitoring_manual_pipeline_entries')
                ->whereNotNull('owner_staff_code')
                ->selectRaw("
                    UPPER(owner_staff_code) AS staff_code,
                    COALESCE(NULLIF(owner_staff_name, ''), NULLIF(owner_staff_code, ''), 'Unassigned') AS staff_name
                ")
                ->groupByRaw('UPPER(owner_staff_code), owner_staff_name, owner_staff_code')
                ->get();

            foreach ($manualStaff as $row) {
                $options[] = [
                    'value' => strtoupper((string) $row->staff_code),
                    'label' => trim(strtoupper((string) $row->staff_code) . ' - ' . $row->staff_name),
                ];
            }
        }

        if ($this->monitoringQuoteNegotiationRequestsReady()) {
            $negotiationStaff = DB::table('quote_price_exception_requests')
                ->whereNotNull('requested_by_code')
                ->selectRaw("
                    UPPER(requested_by_code) AS staff_code,
                    COALESCE(NULLIF(requested_by_name, ''), NULLIF(requested_by_code, ''), 'Unassigned') AS staff_name
                ")
                ->groupByRaw('UPPER(requested_by_code), requested_by_name, requested_by_code')
                ->get();

            foreach ($negotiationStaff as $row) {
                $options[] = [
                    'value' => strtoupper((string) $row->staff_code),
                    'label' => trim(strtoupper((string) $row->staff_code) . ' - ' . $row->staff_name),
                ];
            }
        }

        if (method_exists($this, 'monitoringLegalComplianceStaffOptions')) {
            foreach ($this->monitoringLegalComplianceStaffOptions() as $option) {
                $options[] = $option;
            }
        }

        $options = collect($options)
            ->filter(fn($option) => trim((string) ($option['value'] ?? '')) !== '')
            ->unique('value')
            ->sortBy('value')
            ->values()
            ->all();

        if ($request === null || $this->canViewOtherMonitoringStaff($request)) {
            return $options;
        }

        $sessionStaffCode = $this->monitoringSessionStaffCode($request);
        if ($sessionStaffCode === null) {
            return [];
        }

        $ownOptions = collect($options)
            ->filter(fn($option) => strtoupper((string) ($option['value'] ?? '')) === $sessionStaffCode)
            ->values()
            ->all();

        if (!empty($ownOptions)) {
            return $ownOptions;
        }

        $sessionStaffName = trim((string) $request->session()->get('full_name', ''));

        return [[
            'value' => $sessionStaffCode,
            'label' => trim($sessionStaffCode . ($sessionStaffName !== '' ? ' - ' . $sessionStaffName : '')),
        ]];
    }

    private function monitoringStaffFilter(Request $request): array
    {
        $code = $this->normalizeStaffCode($request->input('staff_code'));
        $canViewOthers = $this->canViewOtherMonitoringStaff($request);

        if ($code === null && !$canViewOthers) {
            $code = $this->monitoringSessionStaffCode($request);
            if ($code === null) {
                return ['code' => null, 'staffId' => null, 'forbidden' => true];
            }
        }

        if ($code === null) {
            return ['code' => null, 'staffId' => null, 'forbidden' => false];
        }

        if (!$canViewOthers) {
            $sessionStaffCode = $this->monitoringSessionStaffCode($request);
            if ($sessionStaffCode === null || $sessionStaffCode !== $code) {
                return ['code' => $code, 'staffId' => null, 'forbidden' => true];
            }
        }

        $staffId = DB::table('staff_general')->whereRaw('UPPER(name_code) = ?', [$code])->value('staff_id');
        if ($staffId === null) {
            $staffId = $this->baseQuoteFactsQuery()
                ->whereRaw('UPPER(staff_code) = ?', [$code])
                ->value('staff_id');
        }

        return [
            'code' => $code,
            'staffId' => $staffId ? (int) $staffId : null,
            'forbidden' => false,
        ];
    }

    private function monitoringStaffForbiddenResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'You are not allowed to view monitoring data for another staff member.',
        ], 403);
    }

    private function canViewOtherMonitoringStaff(Request $request): bool
    {
        foreach ($this->monitoringSessionRoles($request) as $role) {
            $roleText = strtolower($role);
            if (
                str_contains($roleText, 'manager') ||
                str_contains($roleText, 'hr') ||
                str_contains($roleText, 'admin') ||
                str_contains($roleText, 'super')
            ) {
                return true;
            }
        }

        return false;
    }

    private function monitoringSessionStaffCode(Request $request): ?string
    {
        return $this->normalizeStaffCode($request->session()->get('name_code'));
    }

    private function normalizeStaffCode($value): ?string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '' || $code === 'ALL') {
            return null;
        }

        return $code;
    }

    private function monitoringContributorStaffCode(array $contributor): string
    {
        return $this->normalizeStaffCode($contributor['ownerStaffCode'] ?? null) ?: 'UNASSIGNED';
    }

    private function monitoringContributorStaffLabel(array $contributor, string $staffCode): string
    {
        if ($staffCode === 'UNASSIGNED') {
            return 'Unassigned';
        }

        return $this->monitoringContributorStaffName($contributor) ?: $staffCode;
    }

    private function monitoringContributorStaffName(array $contributor): string
    {
        return $this->monitoringCleanText($contributor['ownerStaffName'] ?? '');
    }

    private function monitoringFinalizeStaffMatrixRow(array $row): array
    {
        $stages = [];
        $stageDetails = [];

        foreach (self::MONITORING_PIPELINE_TOOL_ROWS as $stage) {
            $items = $row['stageItems'][$stage] ?? [];
            $stages[$stage] = count($items);
            $stageDetails[$stage] = $this->monitoringBoundDetails($items);
        }

        $segments = [];
        $segmentDetails = [];
        foreach (['individual', 'specialProject', 'tender'] as $segment) {
            $qtyItems = $row['segmentItems'][$segment]['qty'] ?? [];
            $rmItems = $row['segmentItems'][$segment]['rm'] ?? [];
            $rmValue = array_sum($row['segmentValues'][$segment] ?? []);
            $segments[$segment] = [
                'qty' => count($qtyItems),
                'rm' => $rmValue,
            ];
            $segmentDetails[$segment] = [
                'qty' => $this->monitoringBoundDetails($qtyItems),
                'rm' => $this->monitoringBoundDetails($rmItems, (float) $rmValue),
            ];
        }

        return [
            'staffCode' => $row['staffCode'],
            'staffName' => $row['staffName'],
            'staffLabel' => $row['staffLabel'],
            'stages' => $stages,
            'segments' => $segments,
            'details' => [
                'stages' => $stageDetails,
                'segments' => $segmentDetails,
            ],
        ];
    }

    private function monitoringStaffMatrixEmptyRow(string $staffCode, string $staffName, string $staffLabel): array
    {
        $stageItems = [];
        foreach (self::MONITORING_PIPELINE_TOOL_ROWS as $stage) {
            $stageItems[$stage] = [];
        }

        return [
            'staffCode' => $staffCode,
            'staffName' => $staffName,
            'staffLabel' => $staffLabel,
            'stageItems' => $stageItems,
            'segmentItems' => [
                'individual' => ['qty' => [], 'rm' => []],
                'specialProject' => ['qty' => [], 'rm' => []],
                'tender' => ['qty' => [], 'rm' => []],
            ],
            'segmentValues' => [
                'individual' => [],
                'specialProject' => [],
                'tender' => [],
            ],
        ];
    }
}
