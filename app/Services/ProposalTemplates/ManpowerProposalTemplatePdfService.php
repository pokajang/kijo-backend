<?php

namespace App\Services\ProposalTemplates;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManpowerProposalTemplatePdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function pdfManpower(Request $request, int $id)
    {
        $row = DB::table('proposal_template_manpower as m')
            ->where('m.id', $id)
            ->where('m.is_deleted', 0)
            ->select(['m.*'])
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Manpower proposal not found.'], 404);
        }

        $generatedAt = now();
        $html = $this->renderManpowerProposalPdf($row, $request, $generatedAt);
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $this->auditLog->log($request, "Generated PDF for manpower proposal template #{$id}");

        $filename = 'manpower-proposal-' . $id . '.pdf';
        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
    private function renderManpowerProposalPdf(object $proposal, Request $request, \Carbon\CarbonInterface $generatedAt): string
    {
        $generatorId   = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $logoDataUri = $this->companyLogoDataUri();

        $sections = [
            ['title' => 'Introduction', 'content' => (string) ($proposal->introduction ?? '')],
            ['title' => 'Service Deliverables', 'content' => (string) ($proposal->service_deliverables ?? '')],
            ['title' => 'Supplied Manpower Deliverables', 'content' => (string) ($proposal->supplied_manpower_deliverables ?? '')],
            ['title' => 'Additional Information', 'content' => (string) ($proposal->custom_section ?? '')],
        ];
        $sections = array_map(function (array $section): array {
            $section['contentHtml'] = $this->toRenderableRichText((string) ($section['content'] ?? ''));
            return $section;
        }, $sections);

        return view($this->pdfView('pdf.manpower-proposal', $proposal->proposal_language ?? 'en'), [
            'proposal'       => $proposal,
            'sections'       => $sections,
            'generatedDate'  => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode'=> $generatorCode,
            'generatedById'  => $generatorId,
            'logoDataUri'    => $logoDataUri,
        ])->render();
    }
}
