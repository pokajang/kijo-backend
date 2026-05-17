<?php

namespace App\Services\Quotes\Pdf;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IhQuotePdfService
{
    public function __construct(
        private AuditLogService $auditLog,
        private QuotePdfRenderer $renderer
    ) {}

    public function generate(Request $request, int $quoteId)
    {
        $quote = DB::table('quotes_ih')->where('id', $quoteId)->first();
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

        $sampleCount = (int) ($quote->sample_counts ?? 0);
        $sampleUnit = trim((string) ($quote->sample_unit ?? ''));
        $rawWorkUnits = (int) ($quote->num_work_units ?? 0);
        $workUnitsForCalc = max(1, $rawWorkUnits);
        $workUnitsDisplay = $rawWorkUnits > 0 ? (string) $rawWorkUnits : 'N/A';

        $remarksRaw = trim((string) ($quote->inquiry_remarks ?? ''));
        $remarksHtml = $remarksRaw !== '' ? nl2br(e($remarksRaw)) : '-';

        $unitPrice = (float) ($quote->unit_price ?? 0);
        $travelCharge = (float) ($quote->travel_charge ?? 0);
        $discountAmount = (float) ($quote->discount ?? 0);
        $sstPercent = (float) ($quote->sst_percent ?? 0);
        $sstAmount = (float) ($quote->sst_amount ?? 0);
        $grandTotal = (float) ($quote->grand_total ?? 0);
        $sstPercentLabel = ((float) (int) $sstPercent === $sstPercent)
            ? number_format($sstPercent, 0)
            : number_format($sstPercent, 2);

        $serviceTotal = $sampleCount * $workUnitsForCalc * $unitPrice;
        $grossSubtotal = $serviceTotal + $travelCharge;
        $subTotalNet = $grossSubtotal - $discountAmount;
        $showNetSubtotal = $discountAmount > 0 && $sstAmount > 0;

        $serviceCostBasis = $sampleCount . ' ' . $sampleUnit . ' x ' . $workUnitsForCalc . ' work unit(s) x RM ' . number_format($unitPrice, 2) . '/unit';
        $subtotalDetail = $serviceCostBasis;
        if ($travelCharge > 0) {
            $subtotalDetail .= ' + RM ' . number_format($travelCharge, 2) . ' travel';
        }

        $appendProposal = (int) ($quote->attach_proposal ?? 0) === 1 && (int) ($quote->service_id ?? 0) > 0;
        $proposalTitle = '';
        $proposalSections = [];
        $additionalInfoHtml = '';
        if ($appendProposal) {
            $proposal = DB::table('proposal_template_ih')
                ->where('id', (int) $quote->service_id)
                ->first();

            if ($proposal) {
                $proposalTitle = trim((string) ($proposal->service_title ?? '')) . ' Service Proposal';

                $sections = [
                    'Introduction' => (string) ($proposal->introduction ?? ''),
                    'Objectives' => (string) ($proposal->objectives ?? ''),
                    'Work Scope' => (string) ($proposal->work_scope ?? ''),
                    'Schedule' => (string) ($proposal->schedule ?? ''),
                    'References' => (string) ($proposal->reference ?? ''),
                ];

                foreach ($sections as $title => $content) {
                    $plain = trim(str_replace("\xc2\xa0", ' ', strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                    if ($plain === '') {
                        continue;
                    }
                    $proposalSections[] = [
                        'title' => $title,
                        'icon' => '*',
                        'contentHtml' => $this->renderer->toRenderableRichText($content),
                    ];
                }

                $additionalRaw = (string) ($proposal->other_fields ?? '');
                $additionalPlain = trim(str_replace("\xc2\xa0", ' ', strip_tags(html_entity_decode($additionalRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                if ($additionalPlain !== '') {
                    $additionalInfoHtml = $this->renderer->toRenderableRichText($additionalRaw);
                }
            } else {
                $appendProposal = false;
            }
        }

        $createdAtRaw = (string) ($quote->created_at ?? '');
        $updatedAtRaw = (string) ($quote->updated_at ?? '');
        $createdDateLegacy = '';
        if ($createdAtRaw !== '') {
            $timestamp = strtotime($createdAtRaw);
            if ($timestamp !== false) {
                $createdDateLegacy = date('d M Y', $timestamp);
            }
        }

        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $logoDataUri = $this->renderer->companyLogoDataUri();

        $html = view($this->renderer->pdfView('pdf.ih-quote', $quote->proposal_language ?? 'en'), [
            'quoteRefNo' => (string) ($quote->quote_ref_no ?? ''),
            'revisionNo' => (int) ($quote->revision_no ?? 0),
            'createdDateLegacy' => $createdDateLegacy,
            'createdDateIso' => $createdAtRaw !== '' ? substr($createdAtRaw, 0, 10) : '',
            'updatedDateIso' => $updatedAtRaw !== '' ? substr($updatedAtRaw, 0, 10) : '',
            'picName' => (string) ($quote->pic_name ?? '-'),
            'clientName' => (string) ($quote->client_name ?? '-'),
            'clientAddressBlock' => trim((string) ($quote->client_address ?? '-') . ",\n" . (string) ($quote->client_city ?? '-') . ', ' . (string) ($quote->client_state ?? '-') . ' ' . (string) ($quote->client_zip ?? '-')),
            'picEmail' => (string) ($quote->pic_email ?? '-'),
            'picPhone' => (string) ($quote->pic_phone ?? '-'),
            'serviceTitle' => (string) ($quote->service_title ?? ''),
            'serviceCode' => (string) ($quote->service_code ?? ''),
            'siteAddress' => (string) ($quote->site_address ?? ''),
            'sampleCount' => $sampleCount,
            'sampleUnit' => $sampleUnit,
            'workUnitsDisplay' => $workUnitsDisplay,
            'remarksHtml' => $remarksHtml,
            'unitPrice' => $unitPrice,
            'grossSubtotal' => $grossSubtotal,
            'subtotalDetail' => $subtotalDetail,
            'discountAmount' => $discountAmount,
            'showNetSubtotal' => $showNetSubtotal,
            'subTotalNet' => $subTotalNet,
            'sstAmount' => $sstAmount,
            'sstPercentLabel' => $sstPercentLabel,
            'grandTotal' => $grandTotal,
            'preparedByName' => (string) ($quote->created_by_name ?? ''),
            'signOffTitle' => $signOffTitle,
            'appendProposal' => $appendProposal,
            'proposalTitle' => trim($proposalTitle) !== '' ? trim($proposalTitle) : 'Service Proposal',
            'proposalSections' => $proposalSections,
            'additionalInfoHtml' => $additionalInfoHtml,
            'logoDataUri' => $logoDataUri,
        ])->render();

        $dompdf = $this->renderer->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $this->auditLog->log($request, "Generated IH quotation PDF for quote ID #{$quoteId}");

        $safeClient = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($quote->client_name ?? 'client'));
        $filename = ((string) ($quote->quote_ref_no ?? "quote-{$quoteId}")) . '_' . trim($safeClient, '_') . '.pdf';

        return response($dompdf->output(), 200, [
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
