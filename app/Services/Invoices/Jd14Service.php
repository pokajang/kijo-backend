<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use App\Services\Invoices\Pdf\Jd14PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Jd14Service
{
    private const TRAINING_PROJECT_TYPE = 'Training';

    public function __construct(
        private AuditLogService $auditLog,
        private Jd14PdfRenderer $pdfRenderer,
    )
    {
    }
    public function listJd14(Request $request): JsonResponse
    {
        try {
            $query = DB::table('invoices_jd14form as j')
                ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'j.created_by')
                ->select('j.*', 'sg.full_name as created_by_name', 'sg.name_code as created_by_code');

            $year = (int) $request->query('year', 0);
            if ($year >= 2000 && $year <= 2100) {
                $query->whereYear('j.commenced_date', $year);
            }

            $forms = $query->orderByDesc('j.id')->get();

            return response()->json(['status' => 'success', 'forms' => $forms]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function storeJd14(Request $request): JsonResponse
    {
        $request->validate([
            'project_id'       => 'required|numeric',
            'employer_name'    => 'required|string',
            'employer_address' => 'required|string',
            'approval_no'      => 'required|string',
            'course_title'     => 'required|string',
            'training_venue'   => 'required|string',
            'commenced_date'   => 'required',
            'end_date'         => 'required',
        ]);

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $projectValidation = $this->validateTrainingProject((int) $request->input('project_id'));
        if ($projectValidation !== null) {
            return $projectValidation;
        }

        try {
            DB::beginTransaction();

            $formId = DB::table('invoices_jd14form')->insertGetId([
                'project_id'         => $request->input('project_id'),
                'created_by'         => $staffId,
                'employer_name'      => trim((string) $request->input('employer_name')),
                'employer_address'   => trim((string) $request->input('employer_address')),
                'approval_no'        => trim((string) $request->input('approval_no')),
                'employer_code'      => trim((string) $request->input('employer_code', '')),
                'group_approved'     => trim((string) $request->input('group_approved', '')),
                'group_claimed'      => trim((string) $request->input('group_claimed', '')),
                'course_title'       => trim((string) $request->input('course_title')),
                'training_venue'     => trim((string) $request->input('training_venue')),
                'commenced_date'     => $request->input('commenced_date'),
                'end_date'           => $request->input('end_date'),
                'no_of_pax'          => $request->input('no_of_pax'),
                'total_fee_approved' => $request->input('total_fee_approved'),
                'total_fee_claimed'  => $request->input('total_fee_claimed'),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $this->insertProjectProgress(
                (int) $request->input('project_id'),
                "JD14 form data created.",
                $request
            );

            $this->auditLog->log(
                $request,
                "Created JD14 form for project ID {$request->input('project_id')} with approval no. {$request->input('approval_no')}"
            );

            DB::commit();
            return response()->json(['status' => 'success', 'form_number' => $formId]);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            if (($e->errorInfo[1] ?? 0) == 1062) {
                return response()->json(['status' => 'error', 'message' => 'A JD14 form with this approval number already exists.'], 409);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function updateJd14(Request $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0 || $id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing session or form ID.'], 422);
        }

        $jd14 = DB::table('invoices_jd14form')
            ->where('id', $id)
            ->first(['id', 'project_id']);

        if (!$jd14) {
            return response()->json(['status' => 'error', 'message' => 'JD14 form not found'], 404);
        }

        if ($request->has('project_id') && (int) $request->input('project_id') !== (int) $jd14->project_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'JD14 project cannot be changed.',
                'errors' => [
                    'project_id' => ['JD14 project cannot be changed.'],
                ],
            ], 422);
        }

        $projectValidation = $this->validateTrainingProject((int) $jd14->project_id);
        if ($projectValidation !== null) {
            return $projectValidation;
        }

        try {
            DB::beginTransaction();

            DB::table('invoices_jd14form')->where('id', $id)->update([
                'employer_name'      => $request->input('employer_name', ''),
                'employer_address'   => $request->input('employer_address', ''),
                'approval_no'        => $request->input('approval_no', ''),
                'employer_code'      => $request->input('employer_code', ''),
                'group_approved'     => $request->input('group_approved', ''),
                'group_claimed'      => $request->input('group_claimed', ''),
                'course_title'       => $request->input('course_title', ''),
                'training_venue'     => $request->input('training_venue', ''),
                'commenced_date'     => $request->input('commenced_date'),
                'end_date'           => $request->input('end_date'),
                'no_of_pax'          => $request->input('no_of_pax'),
                'total_fee_approved' => $request->input('total_fee_approved'),
                'total_fee_claimed'  => $request->input('total_fee_claimed'),
                'updated_at'         => now(),
            ]);

            $projectId = DB::table('invoices_jd14form')->where('id', $id)->value('project_id');
            if ($projectId) {
                $this->insertProjectProgress((int) $projectId, "JD14 form (ID {$id}) was updated.", $request);
            }

            $this->auditLog->log($request, "Updated JD14 form ID {$id}");
            DB::commit();

            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function destroyJd14(Request $request, int $id = 0): JsonResponse
    {
        if ($id <= 0) {
            $id = (int) $request->input('id', 0);
        }
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing JD14 form ID'], 422);
        }

        $jd14 = DB::table('invoices_jd14form')
            ->where('id', $id)
            ->first(['id', 'approval_no', 'project_id']);

        if (!$jd14) {
            return response()->json(['status' => 'error', 'message' => 'JD14 form not found'], 404);
        }

        try {
            DB::beginTransaction();
            DB::table('invoices_jd14form')->where('id', $id)->delete();

            if ($jd14->project_id) {
                $this->insertProjectProgress(
                    (int) $jd14->project_id,
                    "JD14 form with approval no. {$jd14->approval_no} was deleted.",
                    $request
                );
            }

            $this->auditLog->log($request, "Deleted JD14 form: {$jd14->approval_no}");
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'JD14 form deleted successfully.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function jd14ByProject(Request $request): JsonResponse
    {
        $projectId = (int) $request->query('project_id', 0);
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing project ID.'], 422);
        }

        $approvalNo = DB::table('invoices_jd14form')
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->value('approval_no');

        if (!$approvalNo) {
            return response()->json(['status' => 'error', 'message' => 'No JD14 form found for this project.'], 404);
        }

        return response()->json(['approval_no' => $approvalNo]);
    }

    public function jd14Pdf(Request $request, int $id = 0)
    {
        if ($id <= 0) {
            $id = (int) $request->query('id', 0);
        }
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing ID'], 422);
        }

        $row = DB::table('invoices_jd14form')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        try {
            $pdf = $this->pdfRenderer->render($row);

            $this->auditLog->log($request, "Generated JD14 PDF for approval number {$row->approval_no}");

            $pdfBytes = $pdf->Output("JD14-{$row->approval_no}.pdf", 'S');

            return response($pdfBytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"JD14-{$row->approval_no}.pdf\"",
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function validateTrainingProject(int $projectId): ?JsonResponse
    {
        if ($projectId <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or missing project ID.',
                'errors' => [
                    'project_id' => ['Invalid or missing project ID.'],
                ],
            ], 422);
        }

        $project = DB::table('projects_main')
            ->where('id', $projectId)
            ->first(['id', 'project_type']);

        if (!$project) {
            return response()->json([
                'status' => 'error',
                'message' => 'Project not found.',
                'errors' => [
                    'project_id' => ['Project not found.'],
                ],
            ], 422);
        }

        if ((string) $project->project_type !== self::TRAINING_PROJECT_TYPE) {
            return response()->json([
                'status' => 'error',
                'message' => 'JD14 forms can only be generated for Training projects.',
                'errors' => [
                    'project_id' => ['JD14 forms can only be generated for Training projects.'],
                ],
            ], 422);
        }

        return null;
    }

    private function insertProjectProgress(int $projectId, string $text, Request $request): void
    {
        if ($projectId <= 0 || $text === '') {
            return;
        }
        try {
            DB::table('project_progress')->insert([
                'project_id'    => $projectId,
                'progress_date' => now()->format('Y-m-d'),
                'progress_text' => $text,
                'updated_by'    => (int) $request->session()->get('staff_id', 0) ?: null,
                'updated_on'    => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
