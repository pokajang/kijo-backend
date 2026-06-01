<?php

namespace App\Services\Stats;

use App\Services\Tasks\TaskAiClassificationService;
use App\Services\Tasks\TaskClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkloadDashboardStatsService
{
    private const DUE_SOON_DAYS = 7;

    private const OVERDUE_PRESSURE_MULTIPLIER = 0.5;

    private const DUE_SOON_PRESSURE_MULTIPLIER = 0.25;

    private const DEADLINE_PRESSURE_FIXED_CAP = 4.0;

    private const DEADLINE_PRESSURE_ACTIVE_BASE_CAP_RATIO = 0.35;

    private const COMPLETED_ON_TIME_MULTIPLIER = 0.5;

    private const COMPLETED_LATE_MULTIPLIER = 0.35;

    private const PROJECT_BASE_POINTS = 1.0;

    private const PROJECT_PROGRESS_POINTS_CAP = 2.0;

    private const PROJECT_VALUE_BAND_CAP = 2.0;

    private function taskAiClassificationService(): TaskAiClassificationService
    {
        return app(TaskAiClassificationService::class);
    }

    private const ROLE_WEIGHTS = [
        'leader' => 1.0,
        'pic' => 1.0,
        'owner' => 1.0,
        'assistant' => 0.65,
        'collaborator' => 0.45,
    ];

    private const PROJECT_ROLE_SQL_LIST = "'leader', 'pic', 'owner', 'assistant', 'collaborator'";

    public function workload(Request $request): JsonResponse
    {
        return response()->json($this->workloadPayload($request));
    }

    public function workloadPayload(Request $request): array
    {
        $startDate = $this->dateParam($request, ['start_date', 'start']);
        $endDate = $this->dateParam($request, ['end_date', 'end']);
        $asOfDate = $endDate !== '' ? $endDate : Carbon::today()->toDateString();
        $completedWindow = [
            'startDate' => $startDate,
            'endDate' => $endDate !== '' ? $endDate : $asOfDate,
        ];
        $asOf = Carbon::parse($asOfDate)->startOfDay();

        $staff = [];

        foreach ($this->taskRows($completedWindow['startDate'], $completedWindow['endDate'], $asOfDate) as $row) {
            $staffKey = $this->staffKey($row->staff_id ?? null, $row->name_code ?? null, $row->full_name ?? null);
            if ($staffKey === '') {
                continue;
            }

            $staffRow = &$this->ensureStaffRow($staff, $staffKey, $row->staff_id ?? null, $row->name_code ?? null, $row->full_name ?? null);
            $task = $this->normalizeTask($row, $asOf);

            if ($task['isActive']) {
                $staffRow['activeTasks']++;
                $staffRow['avgDaysLapsedTotal'] += $task['daysLapsed'];
                if ($task['isOverdue']) {
                    $staffRow['overdueTasks']++;
                }
                if ($task['isDueSoon']) {
                    $staffRow['dueSoonTasks']++;
                }
                if ($task['projectId'] !== null) {
                    $staffRow['projectTaggedActiveTasks']++;
                }
            } else {
                $staffRow['completedInPeriod']++;
                if ($task['isLateCompleted']) {
                    $staffRow['lateCompletedInPeriod']++;
                }
            }

            if ($task['projectId'] !== null) {
                $projectGroup = &$this->ensureProjectGroup(
                    $staffRow,
                    $task['projectId'],
                    $task['projectName'],
                    $task['clientName'],
                    $task['projectValue'],
                    $task['projectRole']
                );
                if ($task['isActive']) {
                    $projectGroup['activeTasks'][] = $this->taskPayload($task);
                } else {
                    $projectGroup['completedTasks'][] = $this->taskPayload($task);
                }
                unset($projectGroup);
            } else {
                $staffRow['otherTasks'][] = $this->taskPayload($task);
                if (! $task['isActive']) {
                    $staffRow['completedTasks'][] = $this->taskPayload($task);
                }
            }

            unset($staffRow);
        }

        foreach ($this->progressRows($startDate, $endDate) as $row) {
            $staffKey = $this->staffKey($row->staff_id ?? null, $row->name_code ?? null, $row->full_name ?? null);
            if ($staffKey === '') {
                $staffKey = 'unknown-progress-updater';
            }

            $staffRow = &$this->ensureStaffRow($staff, $staffKey, $row->staff_id ?? null, $row->name_code ?? null, $row->full_name ?? null);
            $projectId = isset($row->project_id) ? (int) $row->project_id : 0;
            if ($projectId > 0) {
                $sourceTaskId = isset($row->source_task_id) && $row->source_task_id !== null
                    ? (int) $row->source_task_id
                    : null;
                if ($this->hasMatchingActiveProjectTask($staffRow, $projectId, $sourceTaskId)) {
                    unset($staffRow);

                    continue;
                }

                $projectGroup = &$this->ensureProjectGroup(
                    $staffRow,
                    $projectId,
                    (string) ($row->project_name ?? 'Tagged Project'),
                    (string) ($row->client_name ?? ''),
                    (float) ($row->project_value ?? 0),
                    (string) ($row->project_role ?? '')
                );
                $projectGroup['progressUpdates'][] = [
                    'id' => isset($row->id) ? (int) $row->id : null,
                    'projectId' => $projectId,
                    'projectName' => (string) ($row->project_name ?? 'Tagged Project'),
                    'clientName' => (string) ($row->client_name ?? ''),
                    'progressDate' => $this->dateOnly($row->progress_date ?? ''),
                    'progressText' => (string) ($row->progress_text ?? ''),
                    'updatedBy' => (int) ($row->staff_id ?? 0) ?: null,
                    'updatedByCode' => (string) ($row->name_code ?? ''),
                    'updatedByName' => (string) ($row->full_name ?? ''),
                    'updatedOn' => (string) ($row->updated_on ?? ''),
                    'sourceType' => (string) ($row->source_type ?? ''),
                    'sourceTaskId' => $sourceTaskId,
                ];
                unset($projectGroup);
            }
            unset($staffRow);
        }

        $rows = array_values(array_map(function (array $row): array {
            $activeTasks = (int) $row['activeTasks'];
            $row['avgDaysLapsed'] = $activeTasks > 0
                ? (int) round(((int) $row['avgDaysLapsedTotal']) / $activeTasks)
                : 0;
            unset($row['avgDaysLapsedTotal']);

            $row = $this->applyWeightedScore($row);

            $row['projectGroups'] = array_values(array_map(function (array $group): array {
                usort($group['activeTasks'], fn ($a, $b) => strcmp((string) $this->taskLatestDate($b), (string) $this->taskLatestDate($a)));
                usort($group['completedTasks'], fn ($a, $b) => strcmp((string) $this->taskLatestDate($b), (string) $this->taskLatestDate($a)));
                usort($group['progressUpdates'], fn ($a, $b) => strcmp((string) ($b['progressDate'] ?? ''), (string) ($a['progressDate'] ?? '')));

                return $group;
            }, $row['projectGroups']));

            usort($row['projectGroups'], function (array $a, array $b): int {
                $activityDelta = (count($b['activeTasks']) + count($b['progressUpdates']) + count($b['completedTasks']))
                    <=> (count($a['activeTasks']) + count($a['progressUpdates']) + count($a['completedTasks']));
                if ($activityDelta !== 0) {
                    return $activityDelta;
                }

                return strcmp((string) $a['projectName'], (string) $b['projectName']);
            });
            $row['projectGroupCount'] = count($row['projectGroups']);

            usort($row['otherTasks'], fn ($a, $b) => strcmp((string) $this->taskLatestDate($b), (string) $this->taskLatestDate($a)));
            usort($row['completedTasks'], fn ($a, $b) => strcmp((string) $this->taskLatestDate($b), (string) $this->taskLatestDate($a)));
            $row['workTypeBreakdown'] = $this->workTypeBreakdownForRow($row);

            return $row;
        }, $staff));

        usort($rows, function (array $a, array $b): int {
            if ((float) $b['score'] !== (float) $a['score']) {
                return (float) $b['score'] <=> (float) $a['score'];
            }
            if ((int) $b['overdueTasks'] !== (int) $a['overdueTasks']) {
                return (int) $b['overdueTasks'] <=> (int) $a['overdueTasks'];
            }
            if ((int) $b['activeTasks'] !== (int) $a['activeTasks']) {
                return (int) $b['activeTasks'] <=> (int) $a['activeTasks'];
            }

            return strcmp((string) $a['staffLabel'], (string) $b['staffLabel']);
        });

        return [
            'status' => 'success',
            'asOfDate' => $asOfDate,
            'completedWindow' => $completedWindow,
            'staff' => $rows,
        ];
    }

    private function taskRows(string $completedStartDate, string $completedEndDate, string $asOfDate)
    {
        if (! Schema::hasTable('tasks')) {
            return collect();
        }

        $hasProjectId = Schema::hasColumn('tasks', 'project_id');
        $hasProjectProgressId = Schema::hasColumn('tasks', 'project_progress_id');
        $hasProjects = Schema::hasTable('projects_main');
        $hasProjectValue = $hasProjects && Schema::hasColumn('projects_main', 'quote_value');
        $hasProjectCollaborators = Schema::hasTable('project_collaborators');
        $hasProjectAccess = $hasProjectId && $hasProjects && $hasProjectCollaborators;
        $hasTaskCategory = Schema::hasColumn('tasks', 'task_category');
        $hasEffortScore = Schema::hasColumn('tasks', 'effort_score');
        $hasClassificationConfidence = Schema::hasColumn('tasks', 'classification_confidence');
        $hasClassificationSource = Schema::hasColumn('tasks', 'classification_source');
        $hasUserOverride = Schema::hasColumn('tasks', 'user_override');
        $hasMatchedPattern = Schema::hasColumn('tasks', 'matched_pattern');
        $hasWorkType = Schema::hasColumn('tasks', 'work_type');
        $hasWorkTypeConfidence = Schema::hasColumn('tasks', 'work_type_confidence');
        $hasWorkTypeMatchedPattern = Schema::hasColumn('tasks', 'work_type_matched_pattern');
        $projectRoleSelect = $hasProjectAccess
            ? DB::raw("(SELECT pc.project_role FROM project_collaborators pc WHERE pc.project_id = t.project_id AND pc.staff_id = t.staff_id AND LOWER(TRIM(COALESCE(pc.project_role, ''))) IN (".self::PROJECT_ROLE_SQL_LIST.') LIMIT 1) as project_role')
            : DB::raw('NULL as project_role');

        $query = DB::table('tasks as t')
            ->leftJoin('staff_general as s', 't.staff_id', '=', 's.staff_id');

        $this->applyActiveStaffFilter($query, 's');

        if ($hasProjectId && $hasProjects) {
            $query->leftJoin('projects_main as p', 'p.id', '=', 't.project_id');
            $this->leftJoinProjectClientSources($query);
        }

        $query->select([
            't.id',
            't.staff_id',
            's.full_name',
            's.name_code',
            't.title',
            't.status',
            't.created_at',
            't.due_date',
            't.completed_at',
            $hasProjectId ? 't.project_id' : DB::raw('NULL as project_id'),
            $hasProjectProgressId ? 't.project_progress_id' : DB::raw('NULL as project_progress_id'),
            $hasProjectId && $hasProjects ? 'p.project_name' : DB::raw('NULL as project_name'),
            $hasProjectId && $hasProjects ? $this->projectClientNameSelect() : DB::raw('NULL as client_name'),
            $hasProjectId && $hasProjects && $hasProjectValue ? 'p.quote_value as project_value' : DB::raw('0 as project_value'),
            $hasProjectId && $hasProjects ? 'p.status as project_status' : DB::raw('NULL as project_status'),
            $hasTaskCategory ? 't.task_category' : DB::raw("'uncategorised' as task_category"),
            $hasEffortScore ? 't.effort_score' : DB::raw('1 as effort_score'),
            $hasClassificationConfidence ? 't.classification_confidence' : DB::raw("'low' as classification_confidence"),
            $hasClassificationSource ? 't.classification_source' : DB::raw("'system' as classification_source"),
            $hasUserOverride ? 't.user_override' : DB::raw('0 as user_override'),
            $hasMatchedPattern ? 't.matched_pattern' : DB::raw('NULL as matched_pattern'),
            $hasWorkType ? 't.work_type' : DB::raw("'unclear' as work_type"),
            $hasWorkTypeConfidence ? 't.work_type_confidence' : DB::raw("'low' as work_type_confidence"),
            $hasWorkTypeMatchedPattern ? 't.work_type_matched_pattern' : DB::raw('NULL as work_type_matched_pattern'),
            $projectRoleSelect,
            $hasProjectAccess
                ? DB::raw("CASE WHEN LOWER(TRIM(COALESCE(p.status, ''))) = 'active' AND EXISTS (SELECT 1 FROM project_collaborators pc WHERE pc.project_id = t.project_id AND pc.staff_id = t.staff_id AND LOWER(TRIM(COALESCE(pc.project_role, ''))) IN (".self::PROJECT_ROLE_SQL_LIST.')) THEN 1 ELSE 0 END as is_project_collaborator')
                : DB::raw('0 as is_project_collaborator'),
        ]);

        $query->where(function ($snapshotQuery) use ($completedStartDate, $completedEndDate, $asOfDate): void {
            $snapshotQuery
                ->where(function ($activeQuery) use ($asOfDate): void {
                    $activeQuery
                        ->whereDate('t.created_at', '<=', $asOfDate)
                        ->where(function ($statusQuery) use ($asOfDate): void {
                            $statusQuery
                                ->whereRaw('LOWER(COALESCE(t.status, \'\')) <> ?', ['completed'])
                                ->orWhereNull('t.completed_at')
                                ->orWhereDate('t.completed_at', '>', $asOfDate);
                        });
                })
                ->orWhere(function ($completedQuery) use ($completedStartDate, $completedEndDate): void {
                    $completedQuery
                        ->whereRaw('LOWER(COALESCE(t.status, \'\')) = ?', ['completed'])
                        ->whereNotNull('t.completed_at')
                        ->whereDate('t.completed_at', '<=', $completedEndDate);

                    if ($completedStartDate !== '') {
                        $completedQuery->whereDate('t.completed_at', '>=', $completedStartDate);
                    }
                });
        });

        return $query->get();
    }

    private function progressRows(string $startDate, string $endDate)
    {
        if (
            ! Schema::hasTable('project_progress')
            || ! Schema::hasTable('projects_main')
            || ! Schema::hasTable('project_collaborators')
        ) {
            return collect();
        }

        $sourceTypeSelect = Schema::hasColumn('project_progress', 'source_type')
            ? 'pp.source_type'
            : DB::raw('NULL as source_type');
        $sourceTaskIdSelect = Schema::hasColumn('project_progress', 'source_task_id')
            ? 'pp.source_task_id'
            : DB::raw('NULL as source_task_id');
        $allowedRoles = $this->projectRoles();
        $projectRoleSelect = DB::raw("(SELECT pc.project_role FROM project_collaborators pc WHERE pc.project_id = pp.project_id AND pc.staff_id = pp.updated_by AND LOWER(TRIM(COALESCE(pc.project_role, ''))) IN (".self::PROJECT_ROLE_SQL_LIST.') LIMIT 1) as project_role');

        $query = DB::table('project_progress as pp')
            ->leftJoin('projects_main as p', 'p.id', '=', 'pp.project_id')
            ->leftJoin('staff_general as s', 'pp.updated_by', '=', 's.staff_id');

        $this->applyActiveStaffFilter($query, 's');

        $this->leftJoinProjectClientSources($query);

        $query
            ->select([
                'pp.id',
                'pp.project_id',
                'p.project_name',
                $this->projectClientNameSelect(),
                Schema::hasColumn('projects_main', 'quote_value') ? 'p.quote_value as project_value' : DB::raw('0 as project_value'),
                'pp.progress_date',
                'pp.progress_text',
                'pp.updated_by as staff_id',
                's.name_code',
                's.full_name',
                'pp.updated_on',
                $sourceTypeSelect,
                $sourceTaskIdSelect,
                $projectRoleSelect,
            ]);

        $query
            ->whereRaw("LOWER(TRIM(COALESCE(p.status, ''))) = ?", ['active'])
            ->whereExists(function ($exists) use ($allowedRoles): void {
                $exists
                    ->select(DB::raw(1))
                    ->from('project_collaborators as pc')
                    ->whereColumn('pc.project_id', 'pp.project_id')
                    ->whereColumn('pc.staff_id', 'pp.updated_by')
                    ->whereIn(DB::raw("LOWER(TRIM(COALESCE(pc.project_role, '')))"), $allowedRoles);
            });

        $this->applyDateRange($query, 'pp.progress_date', $startDate, $endDate);

        return $query->get();
    }

    private function &ensureStaffRow(array &$staff, string $key, mixed $staffId, mixed $staffCode, mixed $staffName): array
    {
        if (! isset($staff[$key])) {
            $code = trim((string) $staffCode);
            $name = trim((string) $staffName);
            $label = $code !== '' && $name !== '' && $code !== $name ? "{$code} - {$name}" : ($name ?: ($code ?: 'Unknown'));

            $staff[$key] = [
                'staffId' => $staffId !== null ? (int) $staffId : null,
                'staffCode' => $code,
                'staffName' => $name,
                'staffLabel' => $label,
                'staffKey' => $key,
                'score' => 0,
                'activeTasks' => 0,
                'overdueTasks' => 0,
                'dueSoonTasks' => 0,
                'projectTaggedActiveTasks' => 0,
                'projectGroupCount' => 0,
                'completedInPeriod' => 0,
                'lateCompletedInPeriod' => 0,
                'avgDaysLapsed' => 0,
                'avgDaysLapsedTotal' => 0,
                'scoreBreakdown' => [],
                'workTypeBreakdown' => [],
                'projectGroups' => [],
                'otherTasks' => [],
                'completedTasks' => [],
            ];
        }

        return $staff[$key];
    }

    private function &ensureProjectGroup(
        array &$staffRow,
        int $projectId,
        string $projectName,
        string $clientName = '',
        float $projectValue = 0,
        string $projectRole = ''
    ): array {
        $key = (string) $projectId;
        $normalizedRole = $this->normalizeProjectRole($projectRole);
        if (! isset($staffRow['projectGroups'][$key])) {
            $staffRow['projectGroups'][$key] = [
                'projectId' => $projectId,
                'projectName' => $projectName !== '' ? $projectName : 'Tagged Project',
                'clientName' => trim($clientName),
                'projectValue' => $projectValue,
                'projectRole' => $normalizedRole,
                'roleWeight' => $this->roleWeight($normalizedRole),
                'valueBand' => $this->valueBand($projectValue),
                'scoreContribution' => 0,
                'scoreableProgressCount' => 0,
                'projectTaskPoints' => 0,
                'projectBasePoints' => 0,
                'projectProgressPoints' => 0,
                'projectValuePoints' => 0,
                'projectOverheadPoints' => 0,
                'activeTasks' => [],
                'completedTasks' => [],
                'progressUpdates' => [],
            ];
        } else {
            if ($staffRow['projectGroups'][$key]['clientName'] === '' && trim($clientName) !== '') {
                $staffRow['projectGroups'][$key]['clientName'] = trim($clientName);
            }
            if ((float) ($staffRow['projectGroups'][$key]['projectValue'] ?? 0) <= 0 && $projectValue > 0) {
                $staffRow['projectGroups'][$key]['projectValue'] = $projectValue;
                $staffRow['projectGroups'][$key]['valueBand'] = $this->valueBand($projectValue);
            }
            if (($staffRow['projectGroups'][$key]['projectRole'] ?? '') === '' && $normalizedRole !== '') {
                $staffRow['projectGroups'][$key]['projectRole'] = $normalizedRole;
                $staffRow['projectGroups'][$key]['roleWeight'] = $this->roleWeight($normalizedRole);
            }
        }

        return $staffRow['projectGroups'][$key];
    }

    private function leftJoinProjectClientSources($query): void
    {
        if (Schema::hasTable('quotes_training')) {
            $query->leftJoin('quotes_training as qt', function ($join): void {
                $join->on('qt.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Training');
            });
        }
        if (Schema::hasTable('quotes_ih')) {
            $query->leftJoin('quotes_ih as qh', function ($join): void {
                $join->on('qh.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Industrial Hygiene');
            });
        }
        if (Schema::hasTable('quotes_manpower')) {
            $query->leftJoin('quotes_manpower as qm', function ($join): void {
                $join->on('qm.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Manpower Supply');
            });
        }
        if (Schema::hasTable('quotes_special')) {
            $query->leftJoin('quotes_special as qs', function ($join): void {
                $join->on('qs.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Special Service');
            });
        }
        if (Schema::hasTable('quotes_equipment')) {
            $query->leftJoin('quotes_equipment as qe', function ($join): void {
                $join->on('qe.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Equipment Supply');
            });
        }
        if (Schema::hasTable('client_company') && Schema::hasColumn('projects_main', 'client_id')) {
            $query->leftJoin('client_company as cc', 'cc.company_id', '=', 'p.client_id');
        }
    }

    private function projectClientNameSelect()
    {
        $parts = [];
        foreach ([
            ['quotes_training', 'qt.client_name'],
            ['quotes_ih', 'qh.client_name'],
            ['quotes_manpower', 'qm.client_name'],
            ['quotes_special', 'qs.client_name'],
            ['quotes_equipment', 'qe.client_name'],
        ] as [$table, $column]) {
            if (Schema::hasTable($table)) {
                $parts[] = "NULLIF(TRIM({$column}), '')";
            }
        }
        if (Schema::hasTable('client_company') && Schema::hasColumn('projects_main', 'client_id')) {
            $parts[] = "NULLIF(TRIM(cc.company_name), '')";
        }

        if (count($parts) === 1) {
            return DB::raw($parts[0].' as client_name');
        }

        return count($parts) > 1
            ? DB::raw('COALESCE('.implode(', ', $parts).') as client_name')
            : DB::raw('NULL as client_name');
    }

    private function hasMatchingActiveProjectTask(array $staffRow, int $projectId, ?int $sourceTaskId): bool
    {
        if ($sourceTaskId === null || $sourceTaskId <= 0) {
            return false;
        }

        $projectGroup = $staffRow['projectGroups'][(string) $projectId] ?? null;
        if (! is_array($projectGroup)) {
            return false;
        }

        foreach ($projectGroup['activeTasks'] ?? [] as $task) {
            if ((int) ($task['id'] ?? 0) === $sourceTaskId) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTask(object $row, Carbon $asOf): array
    {
        $status = (string) ($row->status ?? '');
        $isCompletedStatus = strtolower($status) === 'completed';
        $createdAt = $this->dateOnly($row->created_at ?? '');
        $dueDate = $this->dateOnly($row->due_date ?? '');
        $completedAt = $this->dateOnly($row->completed_at ?? '');
        $isProjectCollaborator = (int) ($row->is_project_collaborator ?? 0) === 1;
        $projectId = $isProjectCollaborator && $row->project_id !== null ? (int) $row->project_id : null;
        $isCompletedAsOf = $isCompletedStatus
            && $completedAt !== ''
            && ! Carbon::parse($completedAt)->startOfDay()->gt($asOf);
        $snapshotStatus = $isCompletedAsOf ? $status : 'Ongoing';
        $snapshotCompletedAt = $isCompletedAsOf ? $completedAt : '';

        $daysLapsed = $this->daysBetween($createdAt, $isCompletedAsOf ? $completedAt : $asOf->toDateString());
        $overdueBy = ! $isCompletedAsOf && $dueDate !== '' && $asOf->gt(Carbon::parse($dueDate)->startOfDay())
            ? $this->daysBetween($dueDate, $asOf->toDateString())
            : 0;
        $daysUntilDue = ! $isCompletedAsOf && $dueDate !== ''
            ? $this->daysBetween($asOf->toDateString(), $dueDate)
            : null;
        $lateBy = $isCompletedAsOf && $dueDate !== '' && $completedAt !== '' && Carbon::parse($completedAt)->gt(Carbon::parse($dueDate))
            ? $this->daysBetween($dueDate, $completedAt)
            : 0;

        $task = [
            'id' => (int) $row->id,
            'staffId' => (int) $row->staff_id,
            'staffName' => (string) ($row->full_name ?? ''),
            'staffCode' => (string) ($row->name_code ?? ''),
            'projectId' => $projectId,
            'projectName' => $projectId !== null ? (string) ($row->project_name ?? '') : '',
            'clientName' => $projectId !== null ? (string) ($row->client_name ?? '') : '',
            'projectValue' => $projectId !== null ? (float) ($row->project_value ?? 0) : 0,
            'projectStatus' => $projectId !== null ? (string) ($row->project_status ?? '') : '',
            'projectRole' => $projectId !== null ? $this->normalizeProjectRole((string) ($row->project_role ?? '')) : '',
            'projectProgressId' => $row->project_progress_id !== null ? (int) $row->project_progress_id : null,
            'title' => (string) ($row->title ?? ''),
            'taskCategory' => (string) ($row->task_category ?? 'uncategorised'),
            'effortScore' => (float) ($row->effort_score ?? 1),
            'classificationConfidence' => (string) ($row->classification_confidence ?? 'low'),
            'classificationSource' => (string) ($row->classification_source ?? 'system'),
            'userOverride' => (bool) ($row->user_override ?? false),
            'matchedPattern' => $row->matched_pattern !== null ? (string) $row->matched_pattern : null,
            'workType' => TaskClassificationService::normalizeWorkType((string) ($row->work_type ?? 'unclear')),
            'workTypeLabel' => TaskClassificationService::workTypeLabel((string) ($row->work_type ?? 'unclear')),
            'workTypeConfidence' => (string) ($row->work_type_confidence ?? 'low'),
            'workTypeMatchedPattern' => $row->work_type_matched_pattern !== null ? (string) $row->work_type_matched_pattern : null,
            'status' => $snapshotStatus,
            'createdAt' => $createdAt,
            'dueDate' => $dueDate,
            'completedAt' => $snapshotCompletedAt,
            'daysLapsed' => max(0, (int) $daysLapsed),
            'overdueBy' => max(0, (int) $overdueBy),
            'lateBy' => max(0, (int) $lateBy),
            'isActive' => ! $isCompletedAsOf,
            'isOverdue' => $overdueBy > 0,
            'isDueSoon' => ! $isCompletedAsOf && $overdueBy <= 0 && $daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= self::DUE_SOON_DAYS,
            'isLateCompleted' => $lateBy > 0,
        ];

        $task['aiClassificationStatus'] = $this->supportsAiClassificationStatus()
            ? $this->taskAiClassificationService()->statusForClassification([
                'task_category' => $task['taskCategory'],
                'effort_score' => $task['effortScore'],
                'classification_confidence' => $task['classificationConfidence'],
                'classification_source' => $task['classificationSource'],
                'work_type' => $task['workType'],
            ])
            : 'not_applicable';

        return $task;
    }

    private function supportsAiClassificationStatus(): bool
    {
        return Schema::hasColumn('tasks', 'task_category')
            && Schema::hasColumn('tasks', 'effort_score')
            && Schema::hasColumn('tasks', 'classification_confidence')
            && Schema::hasColumn('tasks', 'classification_source')
            && Schema::hasColumn('tasks', 'user_override')
            && Schema::hasColumn('tasks', 'matched_pattern')
            && Schema::hasColumn('tasks', 'work_type')
            && Schema::hasColumn('tasks', 'work_type_confidence')
            && Schema::hasColumn('tasks', 'work_type_matched_pattern');
    }

    private function taskPayload(array $task): array
    {
        return [
            'id' => $task['id'],
            'staffId' => $task['staffId'],
            'staffName' => $task['staffName'],
            'staffCode' => $task['staffCode'],
            'projectId' => $task['projectId'],
            'projectName' => $task['projectName'],
            'clientName' => $task['clientName'],
            'projectStatus' => $task['projectStatus'],
            'projectProgressId' => $task['projectProgressId'],
            'title' => $task['title'],
            'taskCategory' => $task['taskCategory'],
            'effortScore' => $task['effortScore'],
            'classificationConfidence' => $task['classificationConfidence'],
            'classificationSource' => $task['classificationSource'],
            'userOverride' => (bool) $task['userOverride'],
            'matchedPattern' => $task['matchedPattern'],
            'workType' => $task['workType'],
            'workTypeLabel' => $task['workTypeLabel'],
            'workTypeConfidence' => $task['workTypeConfidence'],
            'workTypeMatchedPattern' => $task['workTypeMatchedPattern'],
            'aiClassificationStatus' => $task['aiClassificationStatus'] ?? 'not_applicable',
            'status' => $task['status'],
            'createdAt' => $task['createdAt'],
            'dueDate' => $task['dueDate'],
            'completedAt' => $task['completedAt'],
            'daysLapsed' => $task['daysLapsed'],
            'isOverdue' => (bool) $task['isOverdue'],
            'isDueSoon' => (bool) $task['isDueSoon'],
            'isActive' => (bool) $task['isActive'],
        ];
    }

    private function workTypeBreakdownForRow(array $row): array
    {
        $breakdown = [];
        foreach ($this->allTasksForWorkTypeBreakdown($row) as $task) {
            $workType = TaskClassificationService::normalizeWorkType((string) ($task['workType'] ?? 'unclear'));

            if (! isset($breakdown[$workType])) {
                $breakdown[$workType] = [
                    'workType' => $workType,
                    'workTypeLabel' => TaskClassificationService::workTypeLabel($workType),
                    'activeCount' => 0,
                    'completedCount' => 0,
                    'taskCount' => 0,
                    'effortPoints' => 0.0,
                ];
            }

            $isActive = array_key_exists('isActive', $task)
                ? (bool) $task['isActive']
                : strtolower((string) ($task['status'] ?? '')) !== 'completed';

            $breakdown[$workType]['taskCount']++;
            if ($isActive) {
                $breakdown[$workType]['activeCount']++;
            } else {
                $breakdown[$workType]['completedCount']++;
            }
            $breakdown[$workType]['effortPoints'] = $this->roundPoints(
                (float) $breakdown[$workType]['effortPoints'] + $this->taskEffortScore($task)
            );
        }

        $rows = array_values($breakdown);
        usort($rows, function (array $a, array $b): int {
            if ((float) $b['effortPoints'] !== (float) $a['effortPoints']) {
                return (float) $b['effortPoints'] <=> (float) $a['effortPoints'];
            }
            if ((int) $b['taskCount'] !== (int) $a['taskCount']) {
                return (int) $b['taskCount'] <=> (int) $a['taskCount'];
            }

            return strcmp((string) $a['workTypeLabel'], (string) $b['workTypeLabel']);
        });

        return $rows;
    }

    private function allTasksForWorkTypeBreakdown(array $row): array
    {
        $tasks = $row['otherTasks'] ?? [];
        foreach ($row['projectGroups'] ?? [] as $group) {
            array_push($tasks, ...($group['activeTasks'] ?? []), ...($group['completedTasks'] ?? []));
        }

        return array_values(array_filter($tasks, fn ($task): bool => is_array($task)));
    }

    private function applyWeightedScore(array $row): array
    {
        $activeNonProjectTasks = $this->sortTasksByEffortDesc(array_values(array_filter(
            $row['otherTasks'],
            fn (array $task): bool => $this->isActiveTask($task)
        )));
        $nonProjectOverdueTasks = array_values(array_filter(
            $activeNonProjectTasks,
            fn (array $task): bool => ! empty($task['isOverdue'])
        ));
        $nonProjectDueSoonTasks = array_values(array_filter(
            $activeNonProjectTasks,
            fn (array $task): bool => ! empty($task['isDueSoon'])
        ));

        $nonProjectTaskPressure = $this->taskEffortPoints($activeNonProjectTasks, 1.0);
        $rawDeadlinePressure = $this->taskEffortPoints($nonProjectOverdueTasks, self::OVERDUE_PRESSURE_MULTIPLIER)
            + $this->taskEffortPoints($nonProjectDueSoonTasks, self::DUE_SOON_PRESSURE_MULTIPLIER);
        $projectResponsibilityPressure = 0;

        foreach ($row['projectGroups'] as $key => $group) {
            $activeTasks = $group['activeTasks'] ?? [];
            $progressUpdates = $group['progressUpdates'] ?? [];
            $scoreableProgressUpdates = array_values(array_filter(
                $progressUpdates,
                fn (array $update): bool => ! $this->isTaskLinkedProgressUpdate($update)
            ));
            $projectOverdueTasks = array_values(array_filter(
                $activeTasks,
                fn (array $task): bool => ! empty($task['isOverdue'])
            ));
            $projectDueSoonTasks = array_values(array_filter(
                $activeTasks,
                fn (array $task): bool => ! empty($task['isDueSoon'])
            ));

            $rawDeadlinePressure += $this->taskEffortPoints($projectOverdueTasks, self::OVERDUE_PRESSURE_MULTIPLIER);
            $rawDeadlinePressure += $this->taskEffortPoints($projectDueSoonTasks, self::DUE_SOON_PRESSURE_MULTIPLIER);

            $roleWeight = (float) ($group['roleWeight'] ?? $this->roleWeight($group['projectRole'] ?? ''));
            $valueBand = (int) ($group['valueBand'] ?? $this->valueBand((float) ($group['projectValue'] ?? 0)));
            $scoreableProgressCount = count($scoreableProgressUpdates);
            $hasProjectResponsibilitySignal = count($activeTasks) > 0 || $scoreableProgressCount > 0;
            $projectBase = $hasProjectResponsibilitySignal ? self::PROJECT_BASE_POINTS : 0.0;
            $projectTaskPoints = $this->roundPoints($this->taskEffortPoints($activeTasks, 1.0));
            $progressPoints = $hasProjectResponsibilitySignal
                ? min((float) $scoreableProgressCount, self::PROJECT_PROGRESS_POINTS_CAP)
                : 0.0;
            $valuePoints = $hasProjectResponsibilitySignal
                ? min((float) $valueBand, self::PROJECT_VALUE_BAND_CAP)
                : 0.0;
            $projectOverheadPoints = $this->roundPoints(($projectBase + $progressPoints + $valuePoints) * $roleWeight);
            $scoreContribution = $this->roundPoints($projectTaskPoints + $projectOverheadPoints);

            $row['projectGroups'][$key]['roleWeight'] = $roleWeight;
            $row['projectGroups'][$key]['valueBand'] = $valueBand;
            $row['projectGroups'][$key]['scoreableProgressCount'] = $scoreableProgressCount;
            $row['projectGroups'][$key]['projectTaskPoints'] = $projectTaskPoints;
            $row['projectGroups'][$key]['projectBasePoints'] = $projectBase;
            $row['projectGroups'][$key]['projectProgressPoints'] = $progressPoints;
            $row['projectGroups'][$key]['projectValuePoints'] = $valuePoints;
            $row['projectGroups'][$key]['projectOverheadPoints'] = $projectOverheadPoints;
            $row['projectGroups'][$key]['scoreContribution'] = $scoreContribution;
            $projectResponsibilityPressure += $scoreContribution;
        }

        $nonProjectTaskPressure = $this->roundPoints($nonProjectTaskPressure);
        $projectResponsibilityPressure = $this->roundPoints($projectResponsibilityPressure);
        $activeWorkloadBase = $nonProjectTaskPressure + $projectResponsibilityPressure;
        $completedWorkPressure = $this->completedWorkPoints($this->completedTasksForRow($row));
        $deadlinePressure = $this->cappedDeadlinePressure($rawDeadlinePressure, $activeWorkloadBase);
        $row['scoreBreakdown'] = [
            ['label' => 'Non-project tasks', 'points' => $nonProjectTaskPressure],
            ['label' => 'Project responsibility', 'points' => $projectResponsibilityPressure],
            ['label' => 'Deadline pressure', 'points' => $deadlinePressure],
            ['label' => 'Completed work', 'points' => $completedWorkPressure],
        ];
        $row['score'] = $this->roundPoints(array_sum(array_map(fn (array $line): float => (float) $line['points'], $row['scoreBreakdown'])));

        return $row;
    }

    private function taskEffortPoints(array $tasks, float $multiplier): float
    {
        $points = 0.0;
        foreach ($this->sortTasksByEffortDesc($tasks) as $task) {
            $points += $this->taskEffortScore($task) * $multiplier;
        }

        return $points;
    }

    private function isTaskLinkedProgressUpdate(array $update): bool
    {
        return (string) ($update['sourceType'] ?? '') === 'task'
            || ($update['sourceTaskId'] ?? null) !== null;
    }

    private function completedWorkPoints(array $tasks): float
    {
        $points = 0.0;
        foreach ($this->sortTasksByEffortDesc($tasks) as $task) {
            $points += $this->taskEffortScore($task) * $this->completedWorkMultiplier($task);
        }

        return $this->roundPoints($points);
    }

    private function cappedDeadlinePressure(float $rawDeadlinePressure, float $activeWorkloadBase): float
    {
        if ($rawDeadlinePressure <= 0 || $activeWorkloadBase <= 0) {
            return 0.0;
        }

        $activeBaseCap = $activeWorkloadBase * self::DEADLINE_PRESSURE_ACTIVE_BASE_CAP_RATIO;

        return $this->roundPoints(min(
            $rawDeadlinePressure,
            self::DEADLINE_PRESSURE_FIXED_CAP,
            $activeBaseCap
        ));
    }

    private function completedWorkMultiplier(array $task): float
    {
        return $this->isLateCompletedTask($task)
            ? self::COMPLETED_LATE_MULTIPLIER
            : self::COMPLETED_ON_TIME_MULTIPLIER;
    }

    private function isLateCompletedTask(array $task): bool
    {
        $dueDate = (string) ($task['dueDate'] ?? '');
        $completedAt = (string) ($task['completedAt'] ?? '');
        if ($dueDate === '' || $completedAt === '') {
            return false;
        }

        return Carbon::parse($completedAt)->startOfDay()->gt(Carbon::parse($dueDate)->startOfDay());
    }

    private function completedTasksForRow(array $row): array
    {
        $tasks = $row['completedTasks'] ?? [];
        foreach ($row['projectGroups'] ?? [] as $group) {
            array_push($tasks, ...($group['completedTasks'] ?? []));
        }

        return $tasks;
    }

    private function sortTasksByEffortDesc(array $tasks): array
    {
        usort($tasks, function (array $a, array $b): int {
            $effortDelta = $this->taskEffortScore($b) <=> $this->taskEffortScore($a);
            if ($effortDelta !== 0) {
                return $effortDelta;
            }

            $dateDelta = strcmp((string) $this->taskLatestDate($b), (string) $this->taskLatestDate($a));
            if ($dateDelta !== 0) {
                return $dateDelta;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $tasks;
    }

    private function taskEffortScore(array $task): float
    {
        if (! array_key_exists('effortScore', $task) || $task['effortScore'] === null || $task['effortScore'] === '') {
            return 1.0;
        }

        return max(0.0, (float) $task['effortScore']);
    }

    private function roundPoints(float $points): float
    {
        return round($points, 2);
    }

    private function valueBand(float $projectValue): int
    {
        if ($projectValue <= 0) {
            return 0;
        }
        if ($projectValue <= 10000) {
            return 1;
        }
        if ($projectValue <= 50000) {
            return 2;
        }
        if ($projectValue <= 150000) {
            return 3;
        }
        if ($projectValue <= 500000) {
            return 4;
        }

        return 5;
    }

    private function normalizeProjectRole(string $role): string
    {
        return strtolower(trim($role));
    }

    private function roleWeight(string $role): float
    {
        $normalizedRole = $this->normalizeProjectRole($role);

        return self::ROLE_WEIGHTS[$normalizedRole] ?? 0.35;
    }

    private function projectRoles(): array
    {
        return array_keys(self::ROLE_WEIGHTS);
    }

    private function isActiveTask(array $task): bool
    {
        return strtolower((string) ($task['status'] ?? '')) !== 'completed';
    }

    private function applyDateRange($query, string $column, string $startDate, string $endDate): void
    {
        if ($startDate !== '') {
            $query->whereDate($column, '>=', $startDate);
        }
        if ($endDate !== '') {
            $query->whereDate($column, '<=', $endDate);
        }
    }

    private function applyActiveStaffFilter($query, string $alias): void
    {
        if (! Schema::hasTable('staff_general')) {
            return;
        }

        if (Schema::hasColumn('staff_general', 'status')) {
            $query->whereRaw("LOWER(TRIM(COALESCE({$alias}.status, ''))) = ?", ['active']);
        }

        if (Schema::hasColumn('staff_general', 'terminated_at')) {
            $query->whereNull("{$alias}.terminated_at");
        }

        if (Schema::hasColumn('staff_general', 'deleted_at')) {
            $query->whereNull("{$alias}.deleted_at");
        }
    }

    private function dateParam(Request $request, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) $request->input($key, ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $value;
            }
        }

        return '';
    }

    private function dateOnly(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) ? substr($text, 0, 10) : '';
    }

    private function daysBetween(string $startDate, string $endDate): ?int
    {
        if ($startDate === '' || $endDate === '') {
            return null;
        }

        return Carbon::parse($startDate)->startOfDay()->diffInDays(Carbon::parse($endDate)->startOfDay(), false);
    }

    private function staffKey(mixed $staffId, mixed $staffCode, mixed $staffName): string
    {
        if ($staffId !== null && (int) $staffId > 0) {
            return (string) (int) $staffId;
        }

        return trim((string) ($staffCode ?: $staffName));
    }

    private function taskLatestDate(array $task): string
    {
        return (string) ($task['completedAt'] ?: $task['createdAt'] ?: $task['dueDate'] ?: '');
    }
}
