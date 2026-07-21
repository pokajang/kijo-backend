<?php

namespace App\Console\Commands;

use App\Services\Salary\SalaryPaymentNotificationService;
use App\Services\Salary\SalaryWorkflowNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReconcileSalaryWorkflowNotifications extends Command
{
    protected $signature = 'salary:reconcile-workflow-notifications
        {--dry-run : Report eligible records without changing notifications}
        {--limit= : Maximum number of workflow records to inspect}';

    protected $description = 'Reconcile actionable Salary/Other Claim workflow and payment notifications';

    public function handle(
        SalaryWorkflowNotificationService $workflowNotifications,
        SalaryPaymentNotificationService $paymentNotifications,
    ): int {
        foreach (['workflow_instances', 'workflow_actions', 'hr_salary_applications', 'hr_other_claim_applications', 'in_app_notifications'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Salary notification reconciliation skipped: {$table} table is missing.");

                return self::SUCCESS;
            }
        }

        $limit = max(0, (int) ($this->option('limit') ?: 0));
        $query = DB::table('workflow_instances as instance')
            ->leftJoin('hr_salary_applications as salary', function ($join): void {
                $join->on('salary.id', '=', 'instance.subject_id')
                    ->where('instance.subject_type', 'salary_application');
            })
            ->leftJoin('hr_other_claim_applications as other_claim', function ($join): void {
                $join->on('other_claim.id', '=', 'instance.subject_id')
                    ->where('instance.subject_type', 'other_claim_application');
            })
            ->whereIn('instance.subject_type', ['salary_application', 'other_claim_application'])
            ->select('instance.*', 'salary.status as salary_status', 'other_claim.status as other_claim_status')
            ->orderBy('instance.id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();
        $dryRun = (bool) $this->option('dry-run');
        $pending = 0;
        $paymentReady = 0;
        $resolved = 0;

        foreach ($rows as $row) {
            $subjectType = (string) $row->subject_type;
            $subjectId = (int) $row->subject_id;
            $status = $subjectType === 'other_claim_application'
                ? (string) ($row->other_claim_status ?: $row->status)
                : (string) ($row->salary_status ?: $row->status);

            if (in_array($status, ['Submitted', 'Prepared', 'Checked'], true)) {
                $pending++;
                if (! $dryRun) {
                    $workflowNotifications->reconcilePending($subjectType, $subjectId);
                }

                continue;
            }

            if ($status === 'Approved') {
                $paymentReady++;
                if (! $dryRun) {
                    $action = DB::table('workflow_actions')
                        ->where('instance_id', $row->id)
                        ->orderByDesc('id')
                        ->first(['id', 'actor_staff_id']);
                    $workflowNotifications->resolvePending($subjectType, $subjectId);
                    $paymentNotifications->notifyReady(
                        $subjectType,
                        $subjectId,
                        (int) ($action->actor_staff_id ?? 0),
                        'reconcile-'.(int) ($action->id ?? $row->id),
                    );
                }

                continue;
            }

            if (in_array($status, ['Rejected', 'Cancelled', 'Paid'], true)) {
                $resolved++;
                if (! $dryRun) {
                    $workflowNotifications->resolvePending($subjectType, $subjectId);
                    $paymentNotifications->resolveReady($subjectType, $subjectId);
                }
            }
        }

        $mode = $dryRun ? 'dry-run' : 'applied';
        $this->info("Salary notification reconciliation finished. Mode={$mode}, Pending={$pending}, PaymentReady={$paymentReady}, Terminal={$resolved}");

        return self::SUCCESS;
    }
}
