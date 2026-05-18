<?php

namespace App\Services\Projects;

use App\Http\Requests\Project\AddCollaboratorRequest;
use App\Http\Requests\Project\AddExpenseRequest;
use App\Http\Requests\Project\AddProgressRequest;
use App\Http\Requests\Project\AssignVendorRequest;
use App\Http\Requests\Project\CloseProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProgressRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectFinanceService
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function __construct(private AuditLogService $auditLog) {}

    public function addExpense(AddExpenseRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data      = $request->validated();
        $projectId = (int) $data['project_id'];
        $filePath  = null;

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $year     = now()->format('Y');
            $month    = now()->format('m');
            $file     = $request->file('file');
            $original = $file->getClientOriginalName();
            $safeName = uniqid('proof_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
            $folder   = "project_expenses/{$year}/{$month}/{$projectId}";

            $filePath = AppFilePaths::storeFileAs($folder, $file, $safeName);
        }

        DB::table('project_expenses')->insert([
            'project_id' => $projectId,
            'date'       => $data['date'],
            'amount'     => $data['amount'],
            'remarks'    => $data['remarks'] ?? null,
            'file_path'  => $filePath,
            'created_by' => $staffId,
        ]);

        $this->auditLog->log($request, "Added expense to project ID #{$projectId}, amount: {$data['amount']}");

        return response()->json(['status' => 'success', 'message' => 'Expense recorded successfully.']);
    }

    public function deleteExpense(Request $request): JsonResponse
    {
        $expenseId = (int) $request->input('expense_id', 0);
        $projectId = (int) $request->input('project_id', 0);
        if (!$expenseId || !$projectId) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid project_id/expense_id.']);
        }

        $filePath = DB::table('project_expenses')
            ->where('id', $expenseId)
            ->where('project_id', $projectId)
            ->value('file_path');

        $deleted = DB::table('project_expenses')
            ->where('id', $expenseId)
            ->where('project_id', $projectId)
            ->delete();

        if ($deleted < 1) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found.']);
        }

        AppFilePaths::deleteStoredPath((string) $filePath);

        $this->auditLog->log($request, "Deleted project expense ID #{$expenseId} for project ID #{$projectId}");

        return response()->json(['status' => 'success', 'message' => 'Expense deleted successfully.']);
    }

    public function financeData(Request $request): JsonResponse
    {
        $projectId = (int) $request->query('project_id', 0);
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id.']);
        }

        $payments = DB::select("
            SELECT
                vp.id,
                vp.vendor_id,
                vmd.vendor_name,
                vp.project_id,
                vp.payment_context,
                vp.remarks,
                vp.amount,
                vp.method,
                vp.status,
                vp.created_at,
                vp.date_approved,
                vp.payment_type,
                vp.receipt_path,
                vp.created_by,
                sg.full_name AS created_by_full_name,
                sg.name_code AS created_by_name_code
            FROM vendor_payments vp
            LEFT JOIN vendor_main_details vmd ON vp.vendor_id = vmd.vendor_id
            LEFT JOIN staff_general sg ON vp.created_by = sg.staff_id
            WHERE vp.project_id = ? AND vp.deleted_at IS NULL
            ORDER BY vp.created_at ASC
        ", [$projectId]);

        $outstanding = 0;
        foreach ($payments as $payment) {
            $payment->receipt_path = AppFilePaths::publicUrlForStoredPath($payment->receipt_path ?? '');
            $payment->receipt_url = $payment->receipt_path;
            if (strtolower($payment->status) === 'approved') {
                $outstanding += (float) $payment->amount;
            }
        }

        $expenses = DB::select("
            SELECT
                pe.id,
                pe.date,
                pe.amount,
                pe.remarks,
                pe.file_path,
                pe.created_at,
                sg.full_name AS created_by_full_name,
                sg.name_code AS created_by_name_code
            FROM project_expenses pe
            LEFT JOIN staff_general sg ON pe.created_by = sg.staff_id
            WHERE pe.project_id = ?
            ORDER BY pe.created_at DESC
        ", [$projectId]);

        foreach ($expenses as $expense) {
            $expense->file_path = AppFilePaths::publicUrlForStoredPath($expense->file_path ?? '');
            $expense->file_url = $expense->file_path;
        }

        return response()->json([
            'status'      => 'success',
            'outstanding' => $outstanding,
            'history'     => $payments,
            'expenses'    => $expenses,
        ]);
    }
}
