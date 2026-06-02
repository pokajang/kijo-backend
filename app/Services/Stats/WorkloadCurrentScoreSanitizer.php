<?php

namespace App\Services\Stats;

use App\Services\Tasks\TaskClassificationService;

class WorkloadCurrentScoreSanitizer
{
    private const COMPLETED_WORK_LABEL = 'Completed work';

    public function sanitizePayload(array $payload): array
    {
        if (isset($payload['staff']) && is_array($payload['staff'])) {
            $payload['staff'] = array_values(array_map(
                fn (array $row): array => $this->sanitizeStaffRow($row),
                array_filter($payload['staff'], 'is_array')
            ));
        }

        return $payload;
    }

    public function sanitizeStaffRow(array $row): array
    {
        $row['completedInPeriod'] = 0;
        $row['lateCompletedInPeriod'] = 0;
        $row['completedTasks'] = [];

        $row['scoreBreakdown'] = $this->currentScoreBreakdown($row['scoreBreakdown'] ?? []);
        if (! empty($row['scoreBreakdown'])) {
            $row['score'] = $this->roundScore(array_sum(array_map(
                fn (array $line): float => (float) ($line['points'] ?? 0),
                $row['scoreBreakdown']
            )));
        }

        $projectGroups = [];
        foreach ($row['projectGroups'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            $group['completedTasks'] = [];
            $group['progressUpdates'] = array_values(array_filter(
                $group['progressUpdates'] ?? [],
                fn ($update): bool => is_array($update) && ! $this->isTaskLinkedProgressUpdate($update)
            ));
            $hasActiveTasks = count(array_filter($group['activeTasks'] ?? [], 'is_array')) > 0;
            $scoreableProgressCount = array_key_exists('scoreableProgressCount', $group)
                ? (int) $group['scoreableProgressCount']
                : count($group['progressUpdates']);
            $group['scoreableProgressCount'] = $scoreableProgressCount;
            $hasScoreableProgress = $scoreableProgressCount > 0;
            if (! $hasActiveTasks && ! $hasScoreableProgress) {
                continue;
            }

            $projectGroups[] = $group;
        }
        $row['projectGroups'] = $projectGroups;
        $row['projectGroupCount'] = count($projectGroups);
        $row['workTypeBreakdown'] = $this->workTypeBreakdownForRow($row);

        return $row;
    }

    public function currentScoreBreakdown(mixed $breakdown): array
    {
        if (! is_array($breakdown)) {
            return [];
        }

        return array_values(array_map(
            fn (array $line): array => [
                'label' => trim((string) ($line['label'] ?? '')),
                'points' => $this->roundScore((float) ($line['points'] ?? 0)),
            ],
            array_filter($breakdown, function ($line): bool {
                return is_array($line)
                    && trim((string) ($line['label'] ?? '')) !== ''
                    && ! $this->isCompletedWorkLabel($line['label'] ?? '');
            })
        ));
    }

    public function hasCompletedWorkLine(mixed $breakdown): bool
    {
        if (! is_array($breakdown)) {
            return false;
        }

        foreach ($breakdown as $line) {
            if (is_array($line) && $this->isCompletedWorkLabel($line['label'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    public function scoreFromBreakdown(array $breakdown): float
    {
        return $this->roundScore(array_sum(array_map(
            fn (array $line): float => (float) ($line['points'] ?? 0),
            $breakdown
        )));
    }

    private function workTypeBreakdownForRow(array $row): array
    {
        $breakdown = [];
        foreach ($this->activeTasksForWorkTypeBreakdown($row) as $task) {
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

            $breakdown[$workType]['activeCount']++;
            $breakdown[$workType]['taskCount']++;
            $breakdown[$workType]['effortPoints'] = $this->roundScore(
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

    private function activeTasksForWorkTypeBreakdown(array $row): array
    {
        $tasks = array_values(array_filter($row['otherTasks'] ?? [], 'is_array'));
        foreach ($row['projectGroups'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            array_push($tasks, ...array_values(array_filter($group['activeTasks'] ?? [], 'is_array')));
        }

        return $tasks;
    }

    private function taskEffortScore(array $task): float
    {
        if (! array_key_exists('effortScore', $task) || $task['effortScore'] === null || $task['effortScore'] === '') {
            return 1.0;
        }

        return max(0.0, (float) $task['effortScore']);
    }

    private function roundScore(float $score): float
    {
        return round(max(0.0, $score), 2);
    }

    private function isTaskLinkedProgressUpdate(array $update): bool
    {
        return strtolower(trim((string) ($update['sourceType'] ?? ''))) === 'task'
            || ($update['sourceTaskId'] ?? null) !== null;
    }

    private function isCompletedWorkLabel(mixed $label): bool
    {
        return strtolower(trim((string) $label)) === strtolower(self::COMPLETED_WORK_LABEL);
    }
}
