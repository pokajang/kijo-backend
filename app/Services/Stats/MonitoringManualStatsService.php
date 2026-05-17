<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitoringManualStatsService
{
    /**
     * Dashboard metric contract:
     * - Sales uses award_date for system AWARDED/WON quote facts plus revenue-complete manual closed entries.
     * - CRM uses quote created_at for quotation and inquiry-source facts.
     * - Financial uses invoice_date for invoiced/open receivables and paid_date for received cash.
     * - Monitoring uses selected-month activity dates; revenue status uses award_date/manual closed entry_date.
     */
    private const MONITORING_YEARLY_TARGET = 3400000.0;
    private const MONITORING_INDIVIDUAL_TARGET = 860000.0;
    private const MONITORING_DETAIL_LIMIT = 1000;

    private const MONITORING_PIPELINE_TOOL_ROWS = [
        'LEADS',
        'QUALIFIED',
        'MEETING/ PITCHING',
        'PROPOSAL',
        'NEGOTIATION',
        'CLOSED',
    ];

    private const MONITORING_STATUS_ROWS = [
        'TRAINING',
        'CONSULTANCY -ISO',
        'CONSULTANCY - IHOH',
        'MAN POWER',
        'EQUIPMENT SUPPLY',
        'ENGINEERING',
        'INFRASTRUCTURE',
    ];

    private const MONITORING_MANUAL_SERVICE_CATEGORIES = [
        'training' => 'TRAINING',
        'consultancy_iso' => 'CONSULTANCY -ISO',
        'consultancy_ihoh' => 'CONSULTANCY - IHOH',
        'man_power' => 'MAN POWER',
        'equipment_supply' => 'EQUIPMENT SUPPLY',
        'engineering' => 'ENGINEERING',
        'infrastructure' => 'INFRASTRUCTURE',
    ];

    public function createMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->manualPipelineEntryService()->create($request);
    }

    public function updateMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->manualPipelineEntryService()->update($request);
    }

    public function monitoringManualPipelineEntries(Request $request): JsonResponse
    {
        try {
            if (!$this->manualPipelineEntryService()->entriesTableReady()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Manual monitoring entries table is not available.',
                ], 409);
            }

            [$start, $end] = $this->parseDates($request);
            $staffFilter = $this->monitoringStaffFilter($request);
            if (!empty($staffFilter['forbidden'])) {
                return $this->monitoringStaffForbiddenResponse();
            }

            return response()->json([
                'status' => 'success',
                'entries' => $this->manualPipelineEntryService()->list($request, $start, $end, $staffFilter),
                'staffOptions' => $this->buildMonitoringStaffOptions($request),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function deleteMonitoringManualPipelineEntry(Request $request): JsonResponse
    {
        return $this->manualPipelineEntryService()->delete($request);
    }

    public function viewMonitoringManualPipelineEntryPhoto(Request $request)
    {
        return $this->manualPipelineEntryService()->viewPhoto($request);
    }

    private function manualPipelineEntryService(): ManualPipelineEntryService
    {
        return app(ManualPipelineEntryService::class);
    }

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

    private function parseDates(Request $request): array
    {
        $start = $this->normalizeStatsDate($request->input('start_date'));
        $end = $this->normalizeStatsDate($request->input('end_date'));
        if ($start && $end && $start > $end) {
            return [$end, $start];
        }

        return [$start ?: null, $end ?: null];
    }

    private function baseQuoteFactsQuery(): Builder
    {
        // The SQL view can return more than one row per logical quote if bad legacy
        // source rows exist. Normalize first so every downstream KPI aggregates on
        // one quote fact instead of raw joined rows.
        $base = DB::table('all_quotes')
            ->selectRaw("
                service_group,
                quote_id,
                MAX(created_at) AS created_at,
                MAX(award_date) AS award_date,
                MAX(staff_id) AS staff_id,
                MAX(staff_name) AS staff_name,
                MAX(staff_code) AS staff_code,
                MAX(client_id) AS client_id,
                MAX(client_name) AS client_name,
                MAX(quote_status) AS quote_status,
                MAX(value) AS value,
                MAX(inquiry_source) AS inquiry_source
            ")
            ->groupBy('service_group', 'quote_id');

        return DB::query()->fromSub($base, 'quote_facts');
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

    private function monitoringManualPipelineEntriesReady(): bool
    {
        if (!Schema::hasTable('monitoring_manual_pipeline_entries')) {
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
            if (!Schema::hasColumn('monitoring_manual_pipeline_entries', $column)) {
                return false;
            }
        }

        return true;
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

    private function normalizeStatsDate($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function monitoringSessionRoles(Request $request): array
    {
        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }

        return array_values(array_filter(array_map(
            static fn($role) => trim((string) $role),
            $roles
        )));
    }
}
