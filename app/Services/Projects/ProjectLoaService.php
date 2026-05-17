<?php

namespace App\Services\Projects;

use App\Services\Pdf\PdfRenderer;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectLoaService extends PdfRenderer
{
    public function __construct(private AuditLogService $auditLog) {}

    public function generateLoa(Request $request): mixed
    {
        $assignmentId = (int) $request->query('assignment_id', 0);
        $projectId    = (int) $request->query('project_id', 0);
        $vendorId     = (int) $request->query('vendor_id', 0);

        if ($assignmentId <= 0 && ($projectId <= 0 || $vendorId <= 0)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing assignment_id or project_id/vendor_id.',
            ], 400);
        }

        $query  = DB::table('project_vendors as pv')
            ->join('vendor_main_details as v', 'v.vendor_id', '=', 'pv.vendor_id')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'pv.awarded_by')
            ->select([
                'pv.id as assignment_id',
                'pv.project_id',
                'pv.vendor_id',
                'v.vendor_name',
                'v.contact_person_name',
                'v.mobile_number',
                'v.email',
                'v.address',
                'v.city',
                'v.state',
                'v.zip',
                'pv.award_value',
                'pv.position',
                'pv.remarks',
                'pv.awarded_by',
                'pv.services_description',
                'pv.venue_details',
                'pv.fee_breakdown',
                'pv.payment_terms',
                'pv.loa_ref_no',
                'pv.loa_running_no',
                'sg.name_code',
            ])
            ->orderByDesc('pv.award_date')
            ->orderByDesc('pv.id')
            ->limit(1);

        if ($assignmentId > 0) {
            $query->where('pv.id', $assignmentId);
            if ($projectId > 0) {
                $query->where('pv.project_id', $projectId);
            }
            if ($vendorId > 0) {
                $query->where('pv.vendor_id', $vendorId);
            }
        } else {
            $query->where('pv.project_id', $projectId)->where('pv.vendor_id', $vendorId);
        }

        $data = $query->first();

        if (!$data) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No matching vendor or project found.',
            ], 404);
        }

        $cleanMultiline = static function ($input): string {
            $text  = $input ?? '';
            $text  = str_replace(["\r\n", "\r"], "\n", $text);
            $lines = array_filter(array_map('trim', explode("\n", $text)));
            return htmlspecialchars(implode('; ', $lines), ENT_QUOTES, 'UTF-8');
        };

        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $html = view('pdf.loa', [
            'data'           => $data,
            'services'       => $cleanMultiline($data->services_description ?? null),
            'venue'          => $cleanMultiline($data->venue_details ?? null),
            'breakdown'      => $cleanMultiline($data->fee_breakdown ?? null),
            'remarks'        => $cleanMultiline($data->remarks ?? null),
            'formattedAward' => 'RM ' . number_format((float) ($data->award_value ?? 0), 2),
            'refNo'          => $data->loa_ref_no ?? 'LOA-UNKNOWN',
            'printDate'      => $generatedAt->format('d M Y'),
            'logoDataUri'    => $this->companyLogoDataUri(),
        ])->render();

        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $this->auditLog->log(
            $request,
            "Generated LOA PDF for assignment ID #{$data->assignment_id} (vendor ID #{$data->vendor_id}) under project ID #{$data->project_id}"
        );

        $cleanVendor = preg_replace('/[^a-zA-Z0-9]/', '_', (string) ($data->vendor_name ?? 'vendor'));
        $filename    = ($data->loa_ref_no ?? 'LOA') . "_{$cleanVendor}.pdf";

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

}
