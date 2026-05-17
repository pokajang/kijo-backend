<?php

namespace App\Services\ProposalTemplates;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingProposalTemplatePdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function pdfTraining(Request $request, int $id)
    {
        $row = DB::table('proposal_template_training_main as t')
            ->where('t.id', $id)
            ->where('t.is_deleted', 0)
            ->select(['t.*'])
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Training proposal not found.'], 404);
        }

        $agenda = DB::table('proposal_template_training_agenda')
            ->where('template_id', $id)
            ->orderBy('day')
            ->orderBy('start_time')
            ->get();
        foreach ($agenda as $item) {
            $item->topic = $item->topic ?? ($item->activity ?? null);
        }

        $generatedAt = now();
        $html = $this->renderTrainingProposalPdf($row, $agenda, $request, $generatedAt);
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $this->auditLog->log($request, "Generated PDF for training proposal template #{$id}");

        $filename = 'training-proposal-' . $id . '.pdf';
        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
    private function renderTrainingProposalPdf(object $proposal, \Illuminate\Support\Collection $agendaRows, Request $request, \Carbon\CarbonInterface $generatedAt): string
    {
        $generatorId   = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $logoDataUri = $this->companyLogoDataUri();

        $sections = [
            ['title' => 'HRDC Training Programme No.', 'content' => $proposal->hrd_no ?? ''],
            ['title' => 'Introduction', 'content' => $proposal->introduction ?? ''],
            ['title' => 'Objectives', 'content' => $proposal->objectives ?? ''],
            ['title' => 'Modules', 'content' => $proposal->modules ?? ''],
            ['title' => 'Training Requirements', 'content' => $proposal->training_requirements ?? ''],
            ['title' => 'Additional Requirements', 'content' => (string) ($proposal->additional_requirements ?? ($proposal->additional_training_requirements ?? ''))],
            ['title' => 'Training Materials', 'content' => $proposal->training_materials ?? ''],
            ['title' => 'Lecture Medium', 'content' => $proposal->lecture_medium ?? ''],
            ['title' => 'Theory Method', 'content' => !empty($proposal->method_theory) ? (string) ($proposal->method_theory_desc ?? '') : ''],
            ['title' => 'Practical Method', 'content' => !empty($proposal->method_practical) ? (string) ($proposal->method_practical_desc ?? '') : ''],
            ['title' => 'Duration', 'content' => $this->formatTrainingDurationLabel($proposal->duration ?? null)],
        ];
        $sections = array_map(function (array $section): array {
            $section['contentHtml'] = $this->toRenderableRichText((string) ($section['content'] ?? ''));
            return $section;
        }, $sections);

        $agendaByDay = [];
        foreach ($agendaRows as $row) {
            $day = (int) ($row->day ?? 0);
            if ($day <= 0) {
                $day = 1;
            }
            $start = !empty($row->start_time) ? date('g:i A', strtotime((string) $row->start_time)) : '-';
            $end   = !empty($row->end_time) ? date('g:i A', strtotime((string) $row->end_time)) : '-';

            $agendaByDay[$day][] = [
                'timeRange' => "{$start} - {$end}",
                'topicHtml' => $this->toRenderableRichText((string) ($row->topic ?? $row->activity ?? '')),
            ];
        }
        if (!empty($agendaByDay)) {
            ksort($agendaByDay);
        }

        return view($this->pdfView('pdf.training-proposal', $proposal->proposal_language ?? 'en'), [
            'proposal'       => $proposal,
            'sections'       => $sections,
            'agendaByDay'    => $agendaByDay,
            'generatedDate'  => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode'=> $generatorCode,
            'generatedById'  => $generatorId,
            'logoDataUri'    => $logoDataUri,
        ])->render();
    }

    private function formatTrainingDurationLabel(mixed $durationRaw): string
    {
        $duration = strtolower(trim((string) $durationRaw));
        return match ($duration) {
            '1hour'      => '1 Hour',
            '2hour'      => '2 Hours',
            '3hour'      => '3 Hours',
            'halfday_am' => 'Half Day (4 hours)',
            'halfday_pm' => 'Half Day (4 hours)',
            '1day'       => '1 Full Day (8 hours)',
            '2day'       => '2 Days (16 hours)',
            '3day'       => '3 Days (24 hours)',
            default      => $duration !== '' ? ucfirst($duration) : '',
        };
    }
}
