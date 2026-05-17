<?php

namespace App\Services\ProposalTemplates;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IhProposalTemplatePdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function pdfIh(Request $request, int $id)
    {
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid proposal_id.'], 422);
        }

        $row = DB::table('proposal_template_ih as ih')
            ->where('ih.id', $id)
            ->where('ih.is_deleted', 0)
            ->select(['ih.*'])
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'IH proposal not found.'], 404);
        }

        $generatedAt = now();
        $html = $this->renderIhProposalPdf($row, $request, $generatedAt);
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $this->auditLog->log($request, "Generated PDF for IH proposal template #{$id}");

        $filename = 'ih-proposal-' . $id . '.pdf';
        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
    private function renderIhProposalPdf(object $proposal, Request $request, \Carbon\CarbonInterface $generatedAt): string
    {
        $generatorId   = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $logoDataUri = $this->companyLogoDataUri();

        $sections = [
            ['title' => 'Introduction', 'content' => $proposal->introduction ?? ''],
            ['title' => 'Objectives', 'content' => $proposal->objectives ?? ''],
            ['title' => 'Work Scope', 'content' => $proposal->work_scope ?? ''],
            ['title' => 'Schedule', 'content' => $proposal->schedule ?? ''],
            ['title' => 'References', 'content' => $proposal->reference ?? ''],
            ['title' => 'Additional Information', 'content' => $proposal->other_fields ?? ''],
        ];

        $sections = array_map(function (array $section): array {
            $section['contentHtml'] = $this->toRenderableRichText((string) ($section['content'] ?? ''));
            return $section;
        }, $sections);

        $mainSections = [];
        $additionalInfoHtml = '';
        foreach ($sections as $section) {
            $title = (string) ($section['title'] ?? '');
            $contentHtml = (string) ($section['contentHtml'] ?? '');
            $hasContent = trim(strip_tags($contentHtml)) !== '';

            if ($title === 'Additional Information') {
                if ($hasContent) {
                    $additionalInfoHtml = $contentHtml;
                }
                continue;
            }

            if ($hasContent) {
                $mainSections[] = $section;
            }
        }

        return view($this->pdfView('pdf.ih-proposal', $proposal->proposal_language ?? 'en'), [
            'proposal'            => $proposal,
            'sections'            => $mainSections,
            'additionalInfoHtml'  => $additionalInfoHtml,
            'hasAdditionalInfo'   => trim(strip_tags($additionalInfoHtml)) !== '',
            'generatedDate'       => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode'     => $generatorCode,
            'generatedById'       => $generatorId,
            'logoDataUri'         => $logoDataUri,
        ])->render();
    }
}
