<?php

namespace App\Console\Commands;

use App\Services\AppNotificationService;
use App\Services\Leaves\LeaveWorkflowRecipientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D2: heal the stored leave-notification table so it can become the badge
 * source of truth (D3). Using the same actionable-stage predicates as the live
 * recompute ({@see AppNotificationService::leaveAttentionCount()}) as the oracle:
 *
 *  - CREATE missing active rows for currently-pending leaves whose stage
 *    recipients have no active notification.
 *  - RESOLVE active needs_* rows whose leave has left that pending stage.
 *
 * Idempotent and re-runnable; behaviour-neutral (badge still recompute-driven
 * until D3). Mirrors the creation payloads in LeaveRequestService.
 */
class ReconcileLeaveNotifications extends Command
{
    protected $signature = 'notifications:reconcile-leaves {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill/heal stored leave-application notifications to match actionable workflow stages.';

    private const MODULE = 'staff.leaves';

    private const ENTITY = 'leave_application';

    private const TYPE_RECOMMEND = 'leave.needs_recommendation';

    private const TYPE_APPROVE = 'leave.needs_approval';

    public function handle(
        LeaveWorkflowRecipientService $recipients,
        AppNotificationService $notifications,
    ): int {
        if (! Schema::hasTable('hr_leaves_application') || ! Schema::hasTable('in_app_notifications')) {
            $this->warn('Required tables missing; nothing to reconcile.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $submittedIds = $this->submittedPendingLeaveIds();
        $recommendedIds = $this->recommendedPendingLeaveIds();

        $recommenderIds = $recipients->stageStaffIds(
            LeaveWorkflowRecipientService::STAGE_SUBMITTED_RECOMMENDERS,
            ['Manager', 'System Admin'],
        );
        $approverIds = $recipients->stageStaffIds(
            LeaveWorkflowRecipientService::STAGE_RECOMMENDED_APPROVERS,
            ['HR', 'System Admin'],
        );

        $summary = ['created' => 0, 'resolved' => 0, 'dryRun' => $dryRun];

        // --- CREATE missing active rows for in-stage leaves -------------------
        $summary['created'] += $this->ensureRows(
            $submittedIds,
            $recommenderIds,
            self::TYPE_RECOMMEND,
            'Leave request needs recommendation',
            fn (object $leave): string => trim(($leave->applicant_name ?? 'A staff member').' submitted '.($leave->type ?? '').' leave.'),
            $dryRun,
            $notifications,
        );
        $summary['created'] += $this->ensureRows(
            $recommendedIds,
            $approverIds,
            self::TYPE_APPROVE,
            'Leave request needs approval',
            fn (object $leave): string => trim(($leave->applicant_name ?? "A staff member")."'s leave request has been recommended."),
            $dryRun,
            $notifications,
        );

        // --- RESOLVE stale rows whose leave left the stage -------------------
        $summary['resolved'] += $this->resolveStale(self::TYPE_RECOMMEND, $submittedIds, $dryRun, $notifications);
        $summary['resolved'] += $this->resolveStale(self::TYPE_APPROVE, $recommendedIds, $dryRun, $notifications);

        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Ensure each recipient has an active row for each in-stage leave; create the
     * gaps. Returns the number of rows created (or that would be created).
     */
    private function ensureRows(
        array $leaveIds,
        array $recipientIds,
        string $type,
        string $title,
        callable $message,
        bool $dryRun,
        AppNotificationService $notifications,
    ): int {
        if (empty($leaveIds) || empty($recipientIds)) {
            return 0;
        }

        $created = 0;

        $leaves = DB::table('hr_leaves_application as hla')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'hla.staff_id')
            ->whereIn('hla.id', $leaveIds)
            ->select(['hla.id', 'hla.type', 'sg.full_name as applicant_name'])
            ->get();

        foreach ($leaves as $leave) {
            $existing = DB::table('in_app_notifications')
                ->where('module_key', self::MODULE)
                ->where('entity_type', self::ENTITY)
                ->where('entity_id', (int) $leave->id)
                ->where('type', $type)
                ->whereNull('consumed_at')
                ->whereNull('resolved_at')
                ->pluck('recipient_staff_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missing = array_values(array_diff(
                array_map('intval', $recipientIds),
                $existing,
            ));

            if (empty($missing)) {
                continue;
            }

            $created += count($missing);

            if (! $dryRun) {
                $notifications->createForStaff($missing, [
                    'module_key' => self::MODULE,
                    'entity_type' => self::ENTITY,
                    'entity_id' => (int) $leave->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message($leave),
                    'route' => '/staff/leaves/records/'.(int) $leave->id,
                    'severity' => 'warning',
                ]);
            }
        }

        return $created;
    }

    /**
     * Resolve active rows of $type whose leave is no longer in the given
     * in-stage id set. Returns the number of (entity) groups resolved.
     */
    private function resolveStale(
        string $type,
        array $inStageIds,
        bool $dryRun,
        AppNotificationService $notifications,
    ): int {
        $staleEntityIds = DB::table('in_app_notifications')
            ->where('module_key', self::MODULE)
            ->where('entity_type', self::ENTITY)
            ->where('type', $type)
            ->whereNull('consumed_at')
            ->whereNull('resolved_at')
            ->when(! empty($inStageIds), fn ($q) => $q->whereNotIn('entity_id', array_map('intval', $inStageIds)))
            ->distinct()
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($staleEntityIds)) {
            return 0;
        }

        if (! $dryRun) {
            foreach ($staleEntityIds as $entityId) {
                $notifications->resolveActive(self::MODULE, self::ENTITY, $entityId, [$type]);
            }
        }

        return count($staleEntityIds);
    }

    private function submittedPendingLeaveIds(): array
    {
        return DB::table('hr_leaves_application')
            ->whereRaw("LOWER(COALESCE(status, '')) = ?", ['pending'])
            ->where(function ($query): void {
                $query->whereNull('reviewed_by')->orWhere('reviewed_by', 0);
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('reviewed_status')
                    ->orWhere('reviewed_status', '')
                    ->orWhereRaw("LOWER(COALESCE(reviewed_status, '')) = ?", ['pending']);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function recommendedPendingLeaveIds(): array
    {
        return DB::table('hr_leaves_application')
            ->whereRaw("LOWER(COALESCE(status, '')) = ?", ['pending'])
            ->whereRaw("LOWER(COALESCE(reviewed_status, '')) = ?", ['recommended'])
            ->where(function ($query): void {
                $query->whereNull('approved_by')->orWhere('approved_by', 0);
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('approved_status')
                    ->orWhere('approved_status', '')
                    ->orWhereRaw("LOWER(COALESCE(approved_status, '')) = ?", ['pending']);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
