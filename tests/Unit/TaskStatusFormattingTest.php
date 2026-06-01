<?php

namespace Tests\Unit;

use App\Services\Tasks\TaskBaseService;
use Tests\TestCase;

class TaskStatusFormattingTest extends TestCase
{
    public function test_active_task_duration_is_measured_from_creation_before_due_date(): void
    {
        $service = $this->makeTaskStatusService();

        $task = (object) [
            'status' => 'Ongoing',
            'created_at' => '2026-05-01',
            'due_date' => '2026-05-06',
            'completed_at' => null,
        ];

        $this->assertSame('Ongoing', $service->statusText($task, '2026-05-05'));
        $this->assertSame([
            'value' => 4,
            'display' => '4 days',
            'basis' => 'Open duration',
        ], $service->daysLapsedInfo($task, '2026-05-05'));
    }

    public function test_active_task_is_not_overdue_on_due_date(): void
    {
        $service = $this->makeTaskStatusService();

        $task = (object) [
            'status' => 'Ongoing',
            'created_at' => '2026-05-01',
            'due_date' => '2026-05-06',
            'completed_at' => null,
        ];

        $this->assertSame('Ongoing', $service->statusText($task, '2026-05-06'));
        $this->assertSame([
            'value' => 5,
            'display' => '5 days',
            'basis' => 'Open duration',
        ], $service->daysLapsedInfo($task, '2026-05-06'));
    }

    public function test_active_overdue_status_counts_from_due_date_only(): void
    {
        $service = $this->makeTaskStatusService();

        $task = (object) [
            'status' => 'Ongoing',
            'created_at' => '2026-05-01',
            'due_date' => '2026-05-06',
            'completed_at' => null,
        ];

        $this->assertSame('Overdue by 1 day', $service->statusText($task, '2026-05-07'));
        $this->assertSame([
            'value' => 6,
            'display' => '6 days',
            'basis' => 'Open duration',
        ], $service->daysLapsedInfo($task, '2026-05-07'));
    }

    public function test_completed_late_status_uses_due_to_completion_lateness(): void
    {
        $service = $this->makeTaskStatusService();

        $task = (object) [
            'status' => 'Completed',
            'created_at' => '2026-05-01',
            'due_date' => '2026-05-06',
            'completed_at' => '2026-05-10',
        ];

        $this->assertSame('Completed but late by 4 days', $service->statusText($task, '2026-05-25'));
        $this->assertSame([
            'value' => 9,
            'display' => '9 days',
            'basis' => 'Completion duration',
        ], $service->daysLapsedInfo($task, '2026-05-25'));
    }

    private function makeTaskStatusService(): object
    {
        return new class extends TaskBaseService
        {
            public function statusText(object $task, string $today): string
            {
                return $this->taskStatusText($task, $today);
            }

            public function daysLapsedInfo(object $task, string $today): array
            {
                return $this->taskDaysLapsedInfo($task, $today);
            }
        };
    }
}
