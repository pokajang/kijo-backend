<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Jd14Service
{
    private const TRAINING_PROJECT_TYPE = 'Training';

    public function __construct(private AuditLogService $auditLog)
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
            // JD14 must keep the legacy TCPDF form layout, not the shared CRM PDF template.
            require_once AppFilePaths::tcpdfTemplatePath('HrdJd14.php');

            $safe = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeMultiline = static fn ($value): string => nl2br($safe($value), false);

            $employerName      = $safe($row->employer_name ?? '');
            $employerAddress   = $safeMultiline($row->employer_address ?? '');
            $approvalNo        = $safe($row->approval_no ?? '');
            $employerCode      = $safe($row->employer_code ?? '');
            $groupApproved     = $safe($row->group_approved ?? '');
            $groupClaimed      = $safe($row->group_claimed ?? '');
            $courseTitle       = $safe($row->course_title ?? '');
            $trainingVenue     = $safe($row->training_venue ?? '');
            $commencedDate     = $safe($row->commenced_date ?? '');
            $endDate           = $safe($row->end_date ?? '');
            $noOfPax           = $safe($row->no_of_pax ?? '');
            $totalFeeApproved  = $safe($row->total_fee_approved ?? '');
            $totalFeeClaimed   = $safe($row->total_fee_claimed ?? '');
            $todayDate         = now()->format('d F Y');

            $pdf = new \HrdJd14();
            $pdf->SetTitle('JD14 Declaration Form');
            $pdf->AddPage();
            $pdf->addJD14Header();

            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, "PART 1 - EMPLOYER'S PARTICULAR", 0, 1, 'C');

            $pdf->SetFont('helvetica', 'R', 9);

            $html = <<<EOD
<style>
  table { font-size: 10pt; }
</style>

<table border="0.5" cellpadding="4" cellspacing="0" width="100%">
  <tr>
    <td rowspan="4" width="50%"><span></span>
      Registered Name and Address of Employer:<br />
      {$employerName}<br />
      {$employerAddress}
    </td>
    <td width="20%">Employer Code</td>
    <td width="30%">{$employerCode}</td>
  </tr>
  <tr>
    <td>Approval No</td>
    <td>{$approvalNo}</td>
  </tr>
  <tr>
    <td>Group Approved</td>
    <td>{$groupApproved}</td>
  </tr>
  <tr>
    <td>Group Claimed</td>
    <td>{$groupClaimed}</td>
  </tr>
  <tr>
    <td width="20%">Course Title</td>
    <td width="80%" colspan="3">{$courseTitle}</td>
  </tr>
  <tr>
    <td>Training Dates</td>
    <td colspan="3">Commenced: {$commencedDate}&nbsp;&nbsp;&nbsp;&nbsp;Ended: {$endDate}</td>
  </tr>
  <tr>
    <td>Training Venue</td>
    <td colspan="3">{$trainingVenue}</td>
  </tr>
</table>
EOD;

            $pdf->writeHTML($html, true, false, false, false, '');

            $signaturePath = AppFilePaths::tcpdfTemplatePath('assets/sign.png');
            if (is_file($signaturePath)) {
                $pdf->Image($signaturePath, 46.5, 184, 28, 0, 'PNG');
            }

            $stampPath = AppFilePaths::tcpdfTemplatePath('assets/stamp.png');
            if (is_file($stampPath)) {
                $pdf->Image($stampPath, 149, 190, 40, 0, 'PNG');
            }

            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, "PART 2 - CLAIM FOR COURSE FEE", 0, 1, 'C');

            $pdf->SetFont('helvetica', '', 11);

            $html = <<<EOD
<table border="0.5" cellpadding="4" cellspacing="0" width="100%">
  <tr>
    <td width="33%" align="center"><strong>Number of Trainee(s)*</strong></td>
    <td width="33%" align="center"><strong>Total Fee Approved (RM)</strong></td>
    <td width="34%" align="center"><strong>Total Fee Claimed (RM)</strong></td>
  </tr>
  <tr>
    <td height="20" align="center"><strong>{$noOfPax}</strong></td>
    <td align="center"><strong>{$totalFeeApproved}</strong></td>
    <td align="center"><strong>{$totalFeeClaimed}</strong></td>
  </tr>
</table>
EOD;

            $pdf->writeHTML($html, true, false, false, false, '');

            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, "PART 3 - JOINT DECLARATION OF THE TRAINING PROVIDER AND THE EMPLOYER", 0, 1, 'C');

            $pdf->SetFont('helvetica', 'R', 9);

            $html = <<<EOD
<style>
  table.part3 { font-size: 10pt; border: 0.5px solid #000; border-collapse: collapse; }
  table.part3 td { border: none; padding: 6px 4px; vertical-align: top; }
  .label { font-weight: bold; width: 25%; }
  .colon { width: 3%; text-align: center; }
  .value { width: 72%; }
</style>

<table class="part3" width="100%" cellpadding="4" cellspacing="0">
  <tr>
    <td colspan="2">
      (a) I certify that all information declared above is true and correct and the training program claimed above has been conducted with all terms and condition under this scheme has been complied. I also declared that apart from this claim, there is no other claim has been made for these expenses. All relevant documents pertaining to this claim are with us and can be inspected by the Secretariat of the Pembangunan Sumber Manusia Berhad. <strong>(Training Provider)</strong>
    </td>
  </tr>

  <tr>
    <td width="40%">
      <table width="100%">
        <tr>
        <td width="35%" class="label">SIGNATURE</td>
        <td class="colon">: </td>
        <td class="value" height="45"><br /><br /></td>
        </tr>

        <tr>
          <td width="35%" class="label">NAME</td>
          <td class="colon">: </td>
          <td class="value">MUHAMMAD AMIN ROZAK</td>
        </tr>
        <tr>
          <td width="35%" class="label">MYKAD NO</td>
          <td class="colon">: </td>
          <td class="value">760628-03-5981</td>
        </tr>
      </table>
    </td>

    <td width="60%">
      <table width="100%">
        <tr>
          <td class="label">DESIGNATION</td>
          <td class="colon">: </td>
          <td class="value">MANAGING DIRECTOR</td>
        </tr>
        <tr>
          <td class="label">COMPANY STAMP</td>
          <td class="colon">: </td>
          <td class="value" height="55"><br /></td>
        </tr>
        <tr>
          <td class="label">DATE</td>
          <td class="colon">: </td>
            <td class="value">{$todayDate}</td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      (b)  I certify that the training had been completed and agreed with the fees charged above.  I am responsible to the claimed above and certify all information provided here is true and correct. <strong>(Employer)</strong>
    </td>
  </tr>

  <tr>
    <td width="40%">
      <table width="100%">
        <tr>
          <td width="35%" class="label">SIGNATURE</td>
          <td class="colon">: </td>
          <td class="value" height="45"><br /><br /></td>
        </tr>
        <tr>
          <td width="35%" class="label">NAME</td>
          <td class="colon">: </td>
          <td class="value"></td>
        </tr>
        <tr>
          <td width="35%" class="label">MYKAD NO</td>
          <td class="colon">: </td>
          <td class="value"></td>
        </tr>
      </table>
    </td>

    <td width="60%">
      <table width="100%">
        <tr>
          <td class="label">DESIGNATION</td>
          <td class="colon">: </td>
          <td class="value"></td>
        </tr>
        <tr>
          <td class="label">COMPANY STAMP</td>
          <td class="colon">: </td>
        <td height="40" class="value" style="color:rgb(182, 182, 182); font-size: 8pt"><span></span>
        (Shall only be certified by either<br /> Managing Director/General Manager/<br />Financial Controller/Finance<br /> Director of Employer)<br />
        </td>
        </tr>
        <tr>
          <td class="label">DATE</td>
          <td class="colon">: </td>
          <td class="value"></td>
        </tr>
      </table>
    </td>
  </tr>
</table>
EOD;

            $pdf->writeHTML($html, true, false, false, false, '');

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
