<?php

namespace App\Services\Stats;

use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WorkloadDashboardPdfService extends PdfRenderer
{
    private const CURRENCY_PREFIX = 'RM ';

    private const OVERDUE_PRESSURE_MULTIPLIER = 0.5;

    private const DUE_SOON_PRESSURE_MULTIPLIER = 0.25;

    private const DEADLINE_PRESSURE_FIXED_CAP = 4.0;

    private const DEADLINE_PRESSURE_ACTIVE_BASE_CAP_RATIO = 0.35;

    private const COMPLETED_ON_TIME_MULTIPLIER = 0.5;

    private const COMPLETED_LATE_MULTIPLIER = 0.35;

    private const PROJECT_BASE_POINTS = 1.0;

    private const PROJECT_PROGRESS_POINTS_CAP = 2.0;

    private const PROJECT_VALUE_BAND_CAP = 2.0;

    private const SCORE_MATRIX_THRESHOLDS = [
        'moderate' => 10.0,
        'high' => 20.0,
        'extreme' => 35.0,
    ];

    private const TONE_THRESHOLDS = [
        'danger' => ['score' => 20, 'activeTasks' => 5, 'overdueTasks' => 3],
        'warning' => ['score' => 10, 'activeTasks' => 3, 'overdueTasks' => 1, 'dueSoonTasks' => 2],
    ];

    public function __construct(
        private WorkloadDashboardStatsService $workloadStats,
    ) {}

    public function export(Request $request)
    {
        $payload = $this->workloadStats->workloadPayload($request);
        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $completedWindow = is_array($payload['completedWindow'] ?? null) ? $payload['completedWindow'] : [];
        $startDate = (string) ($completedWindow['startDate'] ?? '');
        $endDate = (string) ($completedWindow['endDate'] ?? '');
        $asOfDate = (string) ($payload['asOfDate'] ?? now()->toDateString());
        $rows = is_array($payload['staff'] ?? null) ? $payload['staff'] : [];

        $html = view('pdf.workload-report', [
            'title' => 'Workload Tracking',
            'generatedDate' => $generatedAt->toDateString(),
            'periodLabel' => $this->periodLabel($startDate, $endDate),
            'asOfDate' => $asOfDate !== '' ? $asOfDate : '-',
            'completedWindowLabel' => $this->completedWindowLabel($startDate, $endDate, $asOfDate),
            'staffRows' => $this->buildStaffRows($rows, $asOfDate),
            'logoDataUri' => $this->companyLogoDataUri(),
            'fontFaceCss' => $this->arialFontFaceCss(),
        ])->render();

        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);
        $filenameDate = $endDate !== '' ? $endDate : $generatedAt->toDateString();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"workload-report-{$filenameDate}.pdf\"",
        ]);
    }

    private function buildStaffRows(array $rows, string $todayStr): array
    {
        return array_map(function (array $row) use ($todayStr): array {
            $evidenceDate = (string) ($row['asOfDate'] ?? $todayStr);

            return [
                'staffCode' => $this->staffCodeLabel($row),
                'staffName' => (string) ($row['staffName'] ?? ''),
                'score' => $this->formatCount($row['score'] ?? 0),
                'scoreLevelKey' => $this->workloadScoreLevelKey($row['score'] ?? 0),
                'tone' => $this->workloadTone($row),
                'chips' => [
                    $this->statChip('primary', $this->displayedProjectCount($row), 'Project'),
                    $this->statChip('info', $row['activeTasks'] ?? 0, 'Active Task'),
                    $this->statChip('danger', $row['overdueTasks'] ?? 0, 'Overdue Task'),
                ],
                'workTypeBreakdown' => $this->buildWorkTypeRows($row['workTypeBreakdown'] ?? []),
                'projects' => $this->buildProjectRows($row['projectGroups'] ?? [], $evidenceDate),
                'otherTasks' => $this->buildTaskRows($row['otherTasks'] ?? [], $evidenceDate, false, false),
                'completedTasks' => $this->buildTaskRows($row['completedTasks'] ?? [], $evidenceDate, true, true),
                'scoreRows' => $this->buildScoreTableRows($row),
            ];
        }, $rows);
    }

    private function displayedProjectCount(array $row): int
    {
        if (array_key_exists('projectGroupCount', $row)) {
            return max(0, (int) $row['projectGroupCount']);
        }

        return count(array_filter($row['projectGroups'] ?? [], 'is_array'));
    }

    private function buildProjectRows(array $groups, string $todayStr): array
    {
        $rows = [];
        foreach (array_values($groups) as $index => $group) {
            if (! is_array($group)) {
                continue;
            }

            $rows[] = [
                'title' => $this->projectTitle($group, $index),
                'value' => $this->formatCurrency($group['projectValue'] ?? 0),
                'activityRows' => $this->buildActivityRows($group, $todayStr),
                'completedTasks' => $this->buildTaskRows($group['completedTasks'] ?? [], $todayStr, false, true),
            ];
        }

        return $rows;
    }

    private function buildActivityRows(array $group, string $todayStr): array
    {
        $items = [];
        foreach (($group['progressUpdates'] ?? []) as $update) {
            if (is_array($update)) {
                $items[] = ['type' => 'progress', 'date' => (string) ($update['progressDate'] ?? ''), 'update' => $update];
            }
        }
        foreach (($group['activeTasks'] ?? []) as $task) {
            if (is_array($task)) {
                $items[] = ['type' => 'activeTask', 'date' => $this->taskActivityDate($task), 'task' => $task];
            }
        }

        usort($items, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return array_map(function (array $item) use ($todayStr): array {
            if (($item['type'] ?? '') === 'activeTask') {
                $task = $item['task'] ?? [];

                return [
                    'text' => $this->stripExactProjectMention((string) ($task['title'] ?? '-'), (string) ($task['projectName'] ?? '')) ?: '-',
                    'lapsed' => $this->formatDaysLapsed($this->taskActivityDate($task), $todayStr),
                    'badgeText' => 'Active 5MM Task',
                    'badgeTone' => 'info',
                ];
            }

            $update = $item['update'] ?? [];
            $isTaskProgress = $this->isTaskProgressUpdate($update);
            $text = (string) ($update['progressText'] ?? '-');
            if ($isTaskProgress) {
                $text = $this->stripExactProjectMention($text, (string) ($update['projectName'] ?? '')) ?: '-';
            }

            return [
                'text' => $text,
                'lapsed' => $this->formatDaysLapsed((string) ($update['progressDate'] ?? ''), $todayStr),
                'badgeText' => $isTaskProgress ? 'Done 5MM Task' : '',
                'badgeTone' => 'success',
            ];
        }, $items);
    }

    private function buildTaskRows(array $tasks, string $todayStr, bool $showProject, bool $showDateMeta): array
    {
        return array_map(function (array $task) use ($todayStr, $showProject, $showDateMeta): array {
            $projectName = (string) ($task['projectName'] ?? '');
            $meta = '';
            if ($showDateMeta) {
                $meta = ($showProject && $projectName !== '' ? "{$projectName} | " : '')
                    .'Due '.((string) ($task['dueDate'] ?? '') ?: '-')
                    .' | Latest '.($this->taskDisplayDate($task) ?: '-');
            }
            $statusText = $this->statusText($task, $todayStr);

            return [
                'title' => (string) ($task['title'] ?? '-') ?: '-',
                'meta' => $meta,
                'workTypeLabel' => (string) ($task['workTypeLabel'] ?? ''),
                'statusText' => $statusText,
                'statusTone' => $this->statusTone($statusText),
            ];
        }, $tasks);
    }

    private function buildWorkTypeRows(array $breakdown): array
    {
        return array_map(fn (array $line): array => [
            'label' => (string) ($line['workTypeLabel'] ?? 'Unclear'),
            'activeCount' => $this->formatCount($line['activeCount'] ?? 0),
            'completedCount' => $this->formatCount($line['completedCount'] ?? 0),
            'effortPoints' => $this->formatCount($line['effortPoints'] ?? 0),
        ], array_slice(array_values($breakdown), 0, 6));
    }

    private function buildScoreTableRows(array $row): array
    {
        $scoreLines = $this->backendScoreBreakdownLines($row);
        if (empty($scoreLines)) {
            $fallback = [
                ['label' => 'Active tasks', 'points' => (float) ($row['activeTasks'] ?? 0) * 2],
                ['label' => 'Overdue tasks', 'points' => (float) ($row['overdueTasks'] ?? 0) * 4],
                ['label' => 'Due soon tasks', 'points' => (float) ($row['dueSoonTasks'] ?? 0) * 2],
                ['label' => 'Project responsibility', 'points' => (float) ($row['projectTaggedActiveTasks'] ?? 0)],
            ];
            $total = array_sum(array_map(fn (array $line): float => (float) $line['points'], $fallback));

            return array_merge(
                array_map(fn (array $line): array => [
                    'type' => 'section',
                    'item' => $line['label'],
                    'points' => $this->formatCount($line['points']),
                ], $fallback),
                [['type' => 'total', 'item' => 'Total Score', 'points' => $this->formatCount($total)]]
            );
        }

        $deadlineLine = $this->findBreakdownLine($scoreLines, 'Deadline pressure');
        $sections = [
            ['label' => 'Non-project tasks', 'title' => 'Non Project Tasks Score', 'rows' => $this->nonProjectTaskRows($row), 'empty' => 'No active non-project tasks.'],
            ['label' => 'Project responsibility', 'title' => 'Project Task / Responsibility Score', 'rows' => $this->projectContributionRows($row), 'empty' => 'No weighted project activity for this staff member.'],
            ['label' => 'Deadline pressure', 'title' => 'Deadline Pressure Score', 'rows' => $this->deadlineRows($row, $deadlineLine['points'] ?? null), 'empty' => 'No overdue or due-soon tasks.'],
            ['label' => 'Completed work', 'title' => 'Completed Work Score', 'rows' => $this->completedWorkRows($row), 'empty' => 'No completed tasks in this period.'],
        ];

        $tableRows = [];
        foreach ($sections as $section) {
            $line = $this->findBreakdownLine($scoreLines, $section['label']);
            $tableRows[] = [
                'type' => 'section',
                'item' => $section['title'],
                'points' => $this->formatCount($line['points'] ?? 0),
            ];
            $tableRows = array_merge($tableRows, ! empty($section['rows']) ? $section['rows'] : [[
                'type' => 'empty',
                'item' => $section['empty'],
                'calculation' => '',
                'points' => '',
            ]]);
        }

        $total = array_sum(array_map(fn (array $line): float => (float) ($line['points'] ?? 0), $scoreLines));
        $tableRows[] = ['type' => 'total', 'item' => 'Total Score', 'points' => $this->formatCount($total)];

        return $tableRows;
    }

    private function nonProjectTaskRows(array $row): array
    {
        $tasks = $this->sortTasksByEffortDesc(array_values(array_filter(
            $row['otherTasks'] ?? [],
            fn (array $task): bool => $this->isActiveTask($task)
        )));

        return array_map(function (array $task, int $index): array {
            $effortScore = $this->effortScore($task);

            return [
                'type' => 'line',
                'item' => (string) ($task['title'] ?? ('Non-project task '.($index + 1))),
                'detail' => '',
                'calculation' => $this->formatCount($effortScore).' effort, active task '.$this->formatCount($index + 1),
                'points' => $this->formatCount($effortScore),
            ];
        }, $tasks, array_keys($tasks));
    }

    private function projectContributionRows(array $row): array
    {
        return array_map(function (array $group): array {
            $details = $this->projectResponsibilityDetails($group);

            return [
                'type' => 'line',
                'item' => (string) ($group['projectName'] ?? 'Tagged Project'),
                'detail' => $this->formatRole((string) ($group['projectRole'] ?? '')).' role, '
                    .$this->formatCurrency($group['projectValue'] ?? 0).', '
                    .$this->formatCount($details['activeTaskCount']).' active, '
                    .$this->formatCount($details['progressCount']).' scoreable progress'
                    .($details['totalProgressCount'] !== $details['progressCount']
                        ? ' ('.$this->formatCount($details['totalProgressCount']).' shown)'
                        : '')
                    .', '.$this->projectCapDetail($details),
                'calculation' => $this->formatCount($details['taskPoints']).' active task effort + '
                    .$this->formatCount($details['overheadPoints']).' overhead ('
                    .$this->formatCount($details['basePoints']).' base, '
                    .$this->formatCount($details['progressPoints']).' progress, '
                    .$this->formatCount($details['valuePoints']).' value, '
                    .number_format($details['roleWeight'], 2).' role weight)',
                'points' => $this->formatCount($group['scoreContribution'] ?? $details['roundedContribution']),
            ];
        }, $row['projectGroups'] ?? []);
    }

    private function projectCapDetail(array $details): string
    {
        $progressText = $details['progressCount'] > $details['progressPoints']
            ? 'progress '.$this->formatCount($details['progressCount']).' capped to '.$this->formatCount($details['progressPoints'])
            : $this->formatCount($details['progressPoints']).' progress';
        $valueText = $details['valueBand'] > $details['valuePoints']
            ? 'value band '.$this->formatCount($details['valueBand']).' capped to '.$this->formatCount($details['valuePoints'])
            : $this->formatCount($details['valuePoints']).' value';

        return $progressText.', '.$valueText;
    }

    private function deadlineRows(array $row, ?float $deadlineTotal = null): array
    {
        $rows = [];
        $activeNonProjectTasks = $this->sortTasksByEffortDesc(array_values(array_filter(
            $row['otherTasks'] ?? [],
            fn (array $task): bool => $this->isActiveTask($task)
        )));

        foreach ([['tasks' => array_values(array_filter($activeNonProjectTasks, fn ($task) => ! empty($task['isOverdue']))), 'kind' => 'overdue', 'multiplier' => self::OVERDUE_PRESSURE_MULTIPLIER],
            ['tasks' => array_values(array_filter($activeNonProjectTasks, fn ($task) => ! empty($task['isDueSoon']))), 'kind' => 'due soon', 'multiplier' => self::DUE_SOON_PRESSURE_MULTIPLIER]] as $group) {
            foreach ($group['tasks'] as $index => $task) {
                $effortScore = $this->effortScore($task);
                $rows[] = [
                    'item' => (string) ($task['title'] ?? ('Non-project '.$group['kind'].' task '.($index + 1))),
                    'detail' => '',
                    'calculation' => $this->formatCount($effortScore).' effort x '.$group['multiplier'].' '.$group['kind'].' weight',
                    'rawPoints' => $effortScore * $group['multiplier'],
                ];
            }
        }

        foreach (($row['projectGroups'] ?? []) as $groupIndex => $group) {
            $activeProjectTasks = $this->sortTasksByEffortDesc(array_values(array_filter(
                $group['activeTasks'] ?? [],
                fn (array $task): bool => $this->isActiveTask($task)
            )));
            $projectName = (string) ($group['projectName'] ?? ('Project '.($groupIndex + 1)));
            foreach ([['tasks' => array_values(array_filter($activeProjectTasks, fn ($task) => ! empty($task['isOverdue']))), 'kind' => 'overdue', 'multiplier' => self::OVERDUE_PRESSURE_MULTIPLIER],
                ['tasks' => array_values(array_filter($activeProjectTasks, fn ($task) => ! empty($task['isDueSoon']))), 'kind' => 'due-soon', 'multiplier' => self::DUE_SOON_PRESSURE_MULTIPLIER]] as $deadlineGroup) {
                foreach ($deadlineGroup['tasks'] as $index => $task) {
                    $effortScore = $this->effortScore($task);
                    $rows[] = [
                        'item' => $projectName.': '.((string) ($task['title'] ?? ucfirst($deadlineGroup['kind']).' task '.($index + 1))),
                        'detail' => '',
                        'calculation' => $this->formatCount($effortScore).' effort x '.$deadlineGroup['multiplier'].' '.$deadlineGroup['kind'].' weight',
                        'rawPoints' => $effortScore * $deadlineGroup['multiplier'],
                    ];
                }
            }
        }

        if (empty($rows)) {
            return [];
        }

        $rawTotal = array_sum(array_map(fn (array $line): float => (float) ($line['rawPoints'] ?? 0), $rows));
        $finalTotal = $deadlineTotal !== null ? max(0.0, (float) $deadlineTotal) : $this->cappedDeadlinePressure($rawTotal, $row);
        $isCapped = round($finalTotal, 2) < round($rawTotal, 2);
        $points = $this->allocateProportionalRoundedPoints($rows, $finalTotal);

        $deadlineRows = array_map(function (array $line, int $index) use ($points, $isCapped): array {
            $rawPoints = (float) ($line['rawPoints'] ?? 0);
            unset($line['rawPoints']);

            return [
                'type' => 'line',
                ...$line,
                'detail' => $isCapped ? 'Raw '.$this->formatCount($rawPoints).' before deadline cap' : '',
                'points' => $this->formatCount($points[$index] ?? 0),
            ];
        }, $rows, array_keys($rows));

        array_unshift($deadlineRows, [
            'type' => 'empty',
            'item' => 'Deadline pressure capped at lower of 4 or 35% of active workload base.',
            'calculation' => '',
            'points' => '',
        ]);

        return $deadlineRows;
    }

    private function completedWorkRows(array $row): array
    {
        $completedTasks = $this->sortTasksByEffortDesc($this->completedTasksForRow($row));
        $rows = [];
        $points = $this->allocateRoundedPoints($completedTasks, fn (array $task): float => $this->effortScore($task) * $this->completedWorkMultiplier($task));

        foreach ($completedTasks as $index => $task) {
            $effortScore = $this->effortScore($task);
            $isLate = $this->isLateCompletedTask($task);
            $rows[] = [
                'type' => 'line',
                'item' => (string) ($task['title'] ?? ('Completed task '.($index + 1))),
                'detail' => implode(', ', array_filter([
                    ! empty($task['projectName']) ? 'Project: '.(string) $task['projectName'] : '',
                    ! empty($task['completedAt']) ? 'Completed '.(string) $task['completedAt'] : '',
                ])),
                'calculation' => $this->formatCount($effortScore).' effort x '.($isLate ? '35% late completed credit' : '50% on-time completed credit'),
                'points' => $this->formatCount($points[$index] ?? 0),
            ];
        }

        return $rows;
    }

    private function backendScoreBreakdownLines(array $row): array
    {
        return array_values(array_filter(array_map(function ($line): ?array {
            if (! is_array($line) || trim((string) ($line['label'] ?? '')) === '') {
                return null;
            }

            return ['label' => (string) $line['label'], 'points' => (float) ($line['points'] ?? 0)];
        }, $row['scoreBreakdown'] ?? [])));
    }

    private function findBreakdownLine(array $lines, string $label): ?array
    {
        foreach ($lines as $line) {
            if (($line['label'] ?? '') === $label) {
                return $line;
            }
        }

        return null;
    }

    private function projectResponsibilityDetails(array $group): array
    {
        $activeTasks = array_values(array_filter($group['activeTasks'] ?? [], fn (array $task): bool => $this->isActiveTask($task)));
        $totalProgressCount = count($group['progressUpdates'] ?? []);
        $scoreableProgressCount = array_key_exists('scoreableProgressCount', $group)
            ? (int) $group['scoreableProgressCount']
            : count(array_values(array_filter(
                $group['progressUpdates'] ?? [],
                fn (array $update): bool => ! $this->isTaskProgressUpdate($update)
            )));
        $hasProjectResponsibilitySignal = count($activeTasks) > 0 || $scoreableProgressCount > 0;
        $basePoints = array_key_exists('projectBasePoints', $group)
            ? (float) $group['projectBasePoints']
            : ($hasProjectResponsibilitySignal ? self::PROJECT_BASE_POINTS : 0.0);
        $taskPoints = array_key_exists('projectTaskPoints', $group)
            ? (float) $group['projectTaskPoints']
            : $this->projectTaskPoints($activeTasks);
        $progressPoints = array_key_exists('projectProgressPoints', $group)
            ? (float) $group['projectProgressPoints']
            : ($hasProjectResponsibilitySignal ? min((float) $scoreableProgressCount, self::PROJECT_PROGRESS_POINTS_CAP) : 0.0);
        $valueBand = (float) ($group['valueBand'] ?? 0);
        $valuePoints = array_key_exists('projectValuePoints', $group)
            ? (float) $group['projectValuePoints']
            : ($hasProjectResponsibilitySignal ? min($valueBand, self::PROJECT_VALUE_BAND_CAP) : 0.0);
        $roleWeight = (float) ($group['roleWeight'] ?? 0);
        $overheadPoints = array_key_exists('projectOverheadPoints', $group)
            ? (float) $group['projectOverheadPoints']
            : round(($basePoints + $progressPoints + $valuePoints) * $roleWeight, 2);

        return [
            'activeTaskCount' => count($activeTasks),
            'progressCount' => $scoreableProgressCount,
            'totalProgressCount' => $totalProgressCount,
            'basePoints' => $basePoints,
            'taskPoints' => $taskPoints,
            'progressPoints' => $progressPoints,
            'valuePoints' => $valuePoints,
            'valueBand' => $valueBand,
            'roleWeight' => $roleWeight,
            'overheadPoints' => $overheadPoints,
            'roundedContribution' => round($taskPoints + $overheadPoints, 2),
        ];
    }

    private function projectTaskPoints(array $activeTasks): float
    {
        $points = 0.0;
        foreach ($this->sortTasksByEffortDesc($activeTasks) as $task) {
            $points += $this->effortScore($task);
        }

        return round($points, 2);
    }

    private function completedTasksForRow(array $row): array
    {
        $tasks = $row['completedTasks'] ?? [];
        foreach ($row['projectGroups'] ?? [] as $group) {
            foreach ($group['completedTasks'] ?? [] as $task) {
                if (is_array($task)) {
                    $task['projectName'] = $task['projectName'] ?? ($group['projectName'] ?? '');
                    $tasks[] = $task;
                }
            }
        }

        return $tasks;
    }

    private function sortTasksByEffortDesc(array $tasks): array
    {
        usort($tasks, function (array $a, array $b): int {
            $effortDelta = $this->effortScore($b) <=> $this->effortScore($a);
            if ($effortDelta !== 0) {
                return $effortDelta;
            }
            $dateDelta = strcmp($this->taskLatestDate($b), $this->taskLatestDate($a));
            if ($dateDelta !== 0) {
                return $dateDelta;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $tasks;
    }

    private function allocateRoundedPoints(array $items, callable $rawPointForItem): array
    {
        $rawTotal = 0.0;
        $roundedTotal = 0.0;
        $points = [];
        foreach ($items as $index => $item) {
            $rawTotal += $rawPointForItem($item, $index);
            $nextRoundedTotal = round($rawTotal, 2);
            $points[] = round($nextRoundedTotal - $roundedTotal, 2);
            $roundedTotal = $nextRoundedTotal;
        }

        return $points;
    }

    private function allocateProportionalRoundedPoints(array $rows, float $finalTotal): array
    {
        $rawTotal = array_sum(array_map(fn (array $line): float => (float) ($line['rawPoints'] ?? 0), $rows));
        if ($rawTotal <= 0 || $finalTotal <= 0) {
            return array_fill(0, count($rows), 0.0);
        }

        $scale = $finalTotal / $rawTotal;

        return $this->allocateRoundedPoints($rows, fn (array $line): float => (float) ($line['rawPoints'] ?? 0) * $scale);
    }

    private function cappedDeadlinePressure(float $rawDeadlinePressure, array $row): float
    {
        if ($rawDeadlinePressure <= 0) {
            return 0.0;
        }

        $activeWorkloadBase = $this->breakdownPoints($row, 'Non-project tasks')
            + $this->breakdownPoints($row, 'Project responsibility');
        if ($activeWorkloadBase <= 0) {
            return 0.0;
        }

        return round(min(
            $rawDeadlinePressure,
            self::DEADLINE_PRESSURE_FIXED_CAP,
            $activeWorkloadBase * self::DEADLINE_PRESSURE_ACTIVE_BASE_CAP_RATIO
        ), 2);
    }

    private function breakdownPoints(array $row, string $label): float
    {
        $line = $this->findBreakdownLine($this->backendScoreBreakdownLines($row), $label);

        return (float) ($line['points'] ?? 0);
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

    private function periodLabel(string $startDate, string $endDate): string
    {
        if ($startDate !== '' && $endDate !== '') {
            return "{$startDate} to {$endDate}";
        }
        if ($startDate !== '') {
            return "From {$startDate}";
        }
        if ($endDate !== '') {
            return "Until {$endDate}";
        }

        return 'All available records';
    }

    private function completedWindowLabel(string $startDate, string $endDate, string $asOfDate): string
    {
        $windowEnd = $endDate !== '' ? $endDate : $asOfDate;
        if ($startDate !== '' && $windowEnd !== '') {
            return "{$startDate} to {$windowEnd}";
        }
        if ($windowEnd !== '') {
            return "Until {$windowEnd}";
        }

        return 'All available completed work';
    }

    private function staffCodeLabel(array $row): string
    {
        return (string) (($row['staffCode'] ?? '') ?: ($row['staffLabel'] ?? 'Unknown'));
    }

    private function statChip(string $tone, mixed $value, string $singular): array
    {
        $count = (float) $value;
        $label = $this->formatCount($count).' '.($count === 1.0 ? $singular : "{$singular}s");

        return ['tone' => $count > 0 ? $tone : 'muted', 'label' => $label];
    }

    private function projectTitle(array $group, int $index): string
    {
        $projectName = (string) ($group['projectName'] ?? 'Tagged Project');
        $clientName = trim((string) ($group['clientName'] ?? ''));

        return 'Project '.($index + 1).' - '.$projectName.($clientName !== '' ? " for {$clientName}" : '');
    }

    private function workloadTone(array $row): string
    {
        $score = (float) ($row['score'] ?? 0);
        $activeTasks = (int) ($row['activeTasks'] ?? 0);
        $overdueTasks = (int) ($row['overdueTasks'] ?? 0);
        $dueSoonTasks = (int) ($row['dueSoonTasks'] ?? 0);
        if ($score >= self::TONE_THRESHOLDS['danger']['score']
            || $activeTasks >= self::TONE_THRESHOLDS['danger']['activeTasks']
            || $overdueTasks >= self::TONE_THRESHOLDS['danger']['overdueTasks']) {
            return 'danger';
        }
        if ($score >= self::TONE_THRESHOLDS['warning']['score']
            || $activeTasks >= self::TONE_THRESHOLDS['warning']['activeTasks']
            || $overdueTasks >= self::TONE_THRESHOLDS['warning']['overdueTasks']
            || $dueSoonTasks >= self::TONE_THRESHOLDS['warning']['dueSoonTasks']) {
            return 'warning';
        }

        return 'success';
    }

    private function workloadScoreLevelKey(mixed $score): string
    {
        $value = (float) $score;
        if ($value >= self::SCORE_MATRIX_THRESHOLDS['extreme']) {
            return 'extreme';
        }

        if ($value >= self::SCORE_MATRIX_THRESHOLDS['high']) {
            return 'high';
        }

        if ($value >= self::SCORE_MATRIX_THRESHOLDS['moderate']) {
            return 'moderate';
        }

        return 'low';
    }

    private function formatCount(mixed $value): string
    {
        $number = (float) $value;
        if (abs($number - round($number)) < 0.00001) {
            return number_format($number, 0);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
    }

    private function formatCurrency(mixed $value): string
    {
        return self::CURRENCY_PREFIX.number_format((float) $value, 2);
    }

    private function formatDaysLapsed(string $dateValue, string $todayStr): string
    {
        if (! $this->validDate($dateValue) || ! $this->validDate($todayStr)) {
            return '-';
        }
        $days = max(0, Carbon::parse($dateValue)->startOfDay()->diffInDays(Carbon::parse($todayStr)->startOfDay(), false));

        return $this->formatCount($days).' '.((int) $days === 1 ? 'day' : 'days');
    }

    private function statusText(array $task, string $todayStr): string
    {
        if ((string) ($task['status'] ?? '') === 'Completed') {
            $completedAt = (string) ($task['completedAt'] ?? '');
            if ($completedAt === '') {
                return 'Completed';
            }
            $lateDays = $this->daysBetween((string) ($task['dueDate'] ?? ''), $completedAt);
            if ($lateDays === null) {
                return 'Completed';
            }

            return $lateDays > 0 ? 'Completed but late by '.$this->dayCount($lateDays) : 'Completed (On time)';
        }

        if ($this->isOverdue($task, $todayStr)) {
            $overdueDays = $this->daysBetween((string) ($task['dueDate'] ?? ''), $todayStr);

            return $overdueDays !== null && $overdueDays > 0 ? 'Overdue by '.$this->dayCount($overdueDays) : 'Overdue';
        }

        return 'Ongoing';
    }

    private function statusTone(string $statusText): string
    {
        if (str_starts_with($statusText, 'Completed')) {
            return 'success';
        }
        if (str_starts_with($statusText, 'Overdue')) {
            return 'danger';
        }

        return 'info';
    }

    private function isOverdue(array $task, string $todayStr): bool
    {
        $dueDate = (string) ($task['dueDate'] ?? '');

        return (string) ($task['status'] ?? '') !== 'Completed'
            && $this->validDate($dueDate)
            && $this->validDate($todayStr)
            && Carbon::parse($todayStr)->startOfDay()->gt(Carbon::parse($dueDate)->startOfDay());
    }

    private function daysBetween(string $startDate, string $endDate): ?int
    {
        if (! $this->validDate($startDate) || ! $this->validDate($endDate)) {
            return null;
        }

        return Carbon::parse($startDate)->startOfDay()->diffInDays(Carbon::parse($endDate)->startOfDay(), false);
    }

    private function dayCount(int $days): string
    {
        return $this->formatCount($days).' '.($days === 1 ? 'day' : 'days');
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    private function isTaskProgressUpdate(array $update): bool
    {
        return (string) ($update['sourceType'] ?? '') === 'task' || ($update['sourceTaskId'] ?? null) !== null;
    }

    private function stripExactProjectMention(string $value, string $projectName): string
    {
        $mention = trim($projectName) !== '' ? '@'.trim($projectName) : '';
        if ($mention === '') {
            return trim($value);
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', str_replace($mention, '', $value)));
    }

    private function formatRole(string $role): string
    {
        $text = trim($role);

        return $text !== '' ? ucfirst($text) : 'Role not set';
    }

    private function isActiveTask(array $task): bool
    {
        return strtolower((string) ($task['status'] ?? '')) !== 'completed';
    }

    private function effortScore(array $task): float
    {
        if (! array_key_exists('effortScore', $task) || $task['effortScore'] === null || $task['effortScore'] === '') {
            return 1.0;
        }

        return max(0.0, (float) $task['effortScore']);
    }

    private function taskActivityDate(array $task): string
    {
        return (string) (($task['createdAt'] ?? '') ?: ($task['dueDate'] ?? '') ?: '-');
    }

    private function taskDisplayDate(array $task): string
    {
        return (string) (($task['completedAt'] ?? '') ?: ($task['createdAt'] ?? '') ?: ($task['dueDate'] ?? '') ?: '-');
    }

    private function taskLatestDate(array $task): string
    {
        return (string) (($task['completedAt'] ?? '') ?: ($task['createdAt'] ?? '') ?: ($task['dueDate'] ?? '') ?: '');
    }

    private function arialFontFaceCss(): string
    {
        $fonts = $this->arialFontFiles();
        if (($fonts['normal'] ?? '') === '' || ($fonts['bold'] ?? '') === '') {
            return '';
        }

        $faces = [
            ['path' => $fonts['normal'], 'weight' => 'normal', 'style' => 'normal'],
            ['path' => $fonts['bold'], 'weight' => 'bold', 'style' => 'normal'],
        ];
        if (($fonts['italic'] ?? '') !== '') {
            $faces[] = ['path' => $fonts['italic'], 'weight' => 'normal', 'style' => 'italic'];
        }
        if (($fonts['boldItalic'] ?? '') !== '') {
            $faces[] = ['path' => $fonts['boldItalic'], 'weight' => 'bold', 'style' => 'italic'];
        }

        return implode("\n", array_map(function (array $face): string {
            return "@font-face { font-family: WorkloadArial; font-style: {$face['style']}; font-weight: {$face['weight']}; src: url('{$this->fontDataUri($face['path'])}') format('truetype'); }";
        }, $faces));
    }

    private function arialFontFiles(): array
    {
        return [
            'normal' => $this->firstReadableFontPath([
                storage_path('fonts/arial.ttf'),
                resource_path('fonts/arial.ttf'),
                'C:\\Windows\\Fonts\\arial.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/arial.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf',
            ]),
            'bold' => $this->firstReadableFontPath([
                storage_path('fonts/arialbd.ttf'),
                resource_path('fonts/arialbd.ttf'),
                'C:\\Windows\\Fonts\\arialbd.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/arialbd.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
            ]),
            'italic' => $this->firstReadableFontPath([
                storage_path('fonts/ariali.ttf'),
                resource_path('fonts/ariali.ttf'),
                'C:\\Windows\\Fonts\\ariali.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/ariali.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/Arial_Italic.ttf',
            ]),
            'boldItalic' => $this->firstReadableFontPath([
                storage_path('fonts/arialbi.ttf'),
                resource_path('fonts/arialbi.ttf'),
                'C:\\Windows\\Fonts\\arialbi.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/arialbi.ttf',
                '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold_Italic.ttf',
            ]),
        ];
    }

    private function firstReadableFontPath(array $paths): string
    {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return '';
    }

    private function fontDataUri(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return '';
        }

        return 'data:font/truetype;base64,'.base64_encode($bytes);
    }
}
