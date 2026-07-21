<?php

namespace App\Services\Quotes\Pdf;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrainingQuotePdfService
{
    public function __construct(
        private AuditLogService $auditLog,
        private QuotePdfRenderer $renderer,
        private PdfMergeService $pdfMerge
    ) {}

    public function generate(Request $request, int $quoteId)
    {
        $quote = DB::table('quotes_training')->where('id', $quoteId)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found'], 404);
        }

        $staff = null;
        if (!empty($quote->created_by_id) && $this->hasTable('staff_general')) {
            $staff = DB::table('staff_general')
                ->where('staff_id', (int) $quote->created_by_id)
                ->select(['position', 'crm_position', 'department'])
                ->first();
        }
        $signOffTitle = !empty($staff?->crm_position)
            ? (string) $staff->crm_position
            : trim(((string) ($staff?->position ?? '')) . ' (' . ((string) ($staff?->department ?? '')) . ')');
        if ($signOffTitle === '()' || $signOffTitle === '') {
            $signOffTitle = 'Staff';
        }

        $sessionCount = (int) ($quote->session_count ?? 0);
        $durationPerSession = (int) ($quote->duration_per_session ?? 0);
        $paxCount = (int) ($quote->pax ?? 0);
        $unitPrice = (float) ($quote->unit_price ?? 0);
        $isPerPaxPricing = $sessionCount <= 0 || $durationPerSession <= 0;

        $durationUnitRaw = trim((string) ($quote->duration_unit ?? 'day(s)'));
        $durationUnitShort = trim(str_replace('(s)', '', strtolower($durationUnitRaw)));
        $trainingTypeText = (string) ($quote->training_type ?? '');

        $trainingTotalAmount = (float) ($quote->training_total ?? 0);
        $mealTotalAmount = (float) ($quote->meal_total ?? 0);
        $mobilizationAmount = (float) ($quote->mobilization_cost ?? 0);
        $discountAmount = (float) ($quote->discount_amount ?? 0);
        $grossSubtotal = $trainingTotalAmount + $mealTotalAmount + $mobilizationAmount;
        $netSubtotal = (float) ($quote->subtotal ?? ($grossSubtotal - $discountAmount));

        $hrdAmount = (float) ($quote->hrd_amount ?? 0);
        $sstAmount = (float) ($quote->sst_amount ?? 0);
        $hasHrdCharge = $hrdAmount > 0;
        $hasSstCharge = $sstAmount > 0;
        $hrdRateValue = (float) ($quote->hrd_charge ?? 0);
        $sstRateValue = (float) ($quote->sst_rate ?? 0);
        $hrdRateLabel = ((float) (int) $hrdRateValue === $hrdRateValue) ? number_format($hrdRateValue, 0) : number_format($hrdRateValue, 2);
        $sstRateLabel = ((float) (int) $sstRateValue === $sstRateValue) ? number_format($sstRateValue, 0) : number_format($sstRateValue, 2);
        $showNetSubtotal = $discountAmount > 0 && ($hasHrdCharge || $hasSstCharge);

        $subtotalBasis = $isPerPaxPricing
            ? "{$paxCount} pax x " . number_format($unitPrice, 2) . "/pax"
            : "{$sessionCount} session(s) x {$durationPerSession} {$durationUnitShort} x " . number_format($unitPrice, 2) . "/{$durationUnitShort}";
        $subtotalExtras = [];
        if ($mealTotalAmount > 0) {
            $subtotalExtras[] = 'meals';
        }
        if ($mobilizationAmount > 0) {
            $subtotalExtras[] = 'travel';
        }
        if (!empty($subtotalExtras)) {
            $subtotalBasis .= ' + ' . implode(' + ', $subtotalExtras);
        }

        $trainingDetailsLine = $isPerPaxPricing
            ? "Mode: {$trainingTypeText} - Pricing Basis: {$paxCount} pax x RM " . number_format($unitPrice, 2) . ' per pax'
            : "Duration: {$durationPerSession} {$durationUnitRaw} x {$sessionCount} session(s) - Mode: {$trainingTypeText}";
        $unitPriceLine = $isPerPaxPricing
            ? number_format($unitPrice, 2) . ' per pax'
            : number_format($unitPrice, 2) . " per {$durationUnitRaw}";

        $trainingDateDisplayHtml = 'To be Confirmed<br/>Confirmed Date: ______________________________<br/><span style="font-size:10pt; color: grey;">(annotate above on confirmed date when applying HRDC grant)</span>';
        if (!empty($quote->proposed_date) && (string) $quote->proposed_date !== '0000-00-00' && (string) ($quote->to_be_confirmed ?? '0') !== '1') {
            $trainingDateDisplayHtml = e(date('d M Y', strtotime((string) $quote->proposed_date)));
            if (!empty($quote->proposed_end_date) && (string) $quote->proposed_end_date !== '0000-00-00' && (string) $quote->proposed_end_date !== (string) $quote->proposed_date) {
                $trainingDateDisplayHtml .= ' - ' . e(date('d M Y', strtotime((string) $quote->proposed_end_date)));
            }
        }

        $remarksText = trim((string) ($quote->remarks ?? ''));
        $remarksLineHtml = $remarksText !== '' ? 'Remarks: ' . nl2br(e($remarksText)) : '';

        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $logoDataUri = $this->renderer->companyLogoDataUri();

        $appendProposal = (int) ($quote->attach_proposal ?? 0) === 1 && (int) ($quote->proposal_id ?? 0) > 0;
        $proposalTitle = '';
        $proposalSections = [];
        $proposalAgendaByDay = [];
        if ($appendProposal) {
            $proposal = DB::table('proposal_template_training_main')
                ->where('id', (int) $quote->proposal_id)
                ->where('is_deleted', 0)
                ->first();
            if ($proposal) {
                $proposalTitle = (string) ($proposal->training_title ?? '');
                $proposalSections = [
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
                    ['title' => 'Duration', 'content' => $this->renderer->formatProposalDurationLabel($proposal->duration ?? null)],
                ];
                $proposalSections = array_map(function (array $section): array {
                    $section['contentHtml'] = $this->renderer->toRenderableRichText((string) ($section['content'] ?? ''));
                    return $section;
                }, $proposalSections);

                $agendaRows = DB::table('proposal_template_training_agenda')
                    ->where('template_id', (int) $proposal->id)
                    ->orderBy('day')
                    ->orderBy('start_time')
                    ->get();
                foreach ($agendaRows as $ar) {
                    $day = (int) ($ar->day ?? 0);
                    if ($day <= 0) {
                        $day = 1;
                    }
                    $start = !empty($ar->start_time) ? date('g:i A', strtotime((string) $ar->start_time)) : '-';
                    $end   = !empty($ar->end_time) ? date('g:i A', strtotime((string) $ar->end_time)) : '-';
                    $proposalAgendaByDay[$day][] = [
                        'timeRange' => "{$start} - {$end}",
                        'topicHtml' => $this->renderer->toRenderableRichText((string) ($ar->topic ?? $ar->activity ?? '')),
                    ];
                }
                if (!empty($proposalAgendaByDay)) {
                    ksort($proposalAgendaByDay);
                }
            } else {
                $appendProposal = false;
            }
        }

        $html = view($this->renderer->pdfView('pdf.training-quote', $quote->proposal_language ?? 'en'), [
            'quoteRefNo' => (string) ($quote->quote_ref_no ?? ''),
            'revisionNo' => (int) ($quote->revision_no ?? 0),
            'createdDate' => !empty($quote->created_at) ? substr((string) $quote->created_at, 0, 10) : '',
            'updatedDate' => !empty($quote->updated_at) ? substr((string) $quote->updated_at, 0, 10) : '',
            'clientName' => (string) ($quote->client_name ?? '-'),
            'clientAddressBlock' => $this->renderer->formatAddressBlock(
                $quote->client_address ?? null,
                $quote->client_city ?? null,
                $quote->client_state ?? null,
                $quote->client_zip ?? null
            ),
            'picName' => (string) ($quote->pic_name ?? '-'),
            'picEmail' => (string) ($quote->pic_email ?? '-'),
            'picPhone' => (string) ($quote->pic_phone ?? '-'),
            'trainingTitle' => (string) ($quote->training_title ?? ''),
            'trainingType' => $trainingTypeText,
            'trainingDetailsLine' => $trainingDetailsLine,
            'targetGroups' => (string) ($quote->target_groups ?? ''),
            'venue' => (string) ($quote->venue ?? ''),
            'trainingDateDisplayHtml' => $trainingDateDisplayHtml,
            'remarksLineHtml' => $remarksLineHtml,
            'unitPriceLine' => $unitPriceLine,
            'travelCharge' => (float) ($quote->travel_charge ?? 0),
            'showMealsRow' => in_array(strtolower(trim((string) ($quote->meals_provided ?? ''))), ['1', 'yes', 'true'], true) && (float) ($quote->meal_price ?? 0) > 0,
            'mealPrice' => (float) ($quote->meal_price ?? 0),
            'grossSubtotal' => $grossSubtotal,
            'subtotalBasis' => $subtotalBasis,
            'discountAmount' => $discountAmount,
            'discountType' => (string) ($quote->discount_type ?? ''),
            'showNetSubtotal' => $showNetSubtotal,
            'netSubtotal' => $netSubtotal,
            'hasHrdCharge' => $hasHrdCharge,
            'hasSstCharge' => $hasSstCharge,
            'hrdRateLabel' => $hrdRateLabel,
            'sstRateLabel' => $sstRateLabel,
            'hrdAmount' => $hrdAmount,
            'sstAmount' => $sstAmount,
            'grandTotal' => (float) ($quote->grand_total ?? 0),
            'preparedByName' => (string) ($quote->created_by_name ?? ''),
            'signOffTitle' => $signOffTitle,
            'paxCount' => $paxCount,
            'generatedDate' => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode' => $generatorCode,
            'generatedById' => $generatorId,
            'logoDataUri' => $logoDataUri,
            'appendProposal' => false,
            'proposalTitle' => $proposalTitle,
            'proposalSections' => $proposalSections,
            'proposalAgendaByDay' => $proposalAgendaByDay,
        ])->render();

        $draftWatermark = $request->boolean('approval_preview');
        $dompdf = $this->renderer->renderPortraitWithFooter(
            $html,
            $generatedAt,
            $generatorCode,
            $generatorId,
            $draftWatermark,
        );
        $pdfBytes = $dompdf->output();

        if ($appendProposal && !empty($proposalSections)) {
            $proposalHtml = view($this->renderer->pdfView('pdf.training-proposal', $quote->proposal_language ?? 'en'), [
                'proposal' => (object) [
                    'training_title' => $proposalTitle,
                    'proposal_language' => $quote->proposal_language ?? 'en',
                ],
                'proposalTitle' => $proposalTitle,
                'sections' => $proposalSections,
                'agendaByDay' => $proposalAgendaByDay,
                'logoDataUri' => $logoDataUri,
            ])->render();
            $proposalPdf = $this->renderer->renderPortraitWithFooter(
                $proposalHtml,
                $generatedAt,
                $generatorCode,
                $generatorId,
                $draftWatermark,
            )->output();
            $mergedBytes = $this->pdfMerge->mergeSequence([$pdfBytes, $proposalPdf]);
            if ($mergedBytes !== null) {
                $pdfBytes = $mergedBytes;
            }
        }

        $this->auditLog->log($request, "Generated training quotation PDF for quote ID #{$quoteId}");

        $safeClient = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($quote->client_name ?? 'client'));
        $filename = ((string) ($quote->quote_ref_no ?? "quote-{$quoteId}")) . '_' . trim($safeClient, '_') . '.pdf';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
