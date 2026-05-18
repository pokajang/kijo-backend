<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManpowerQuoteRecordPdfService extends PdfRenderer
{
    public function __construct(private AuditLogService $auditLog) {}

    public function pdfManpower(Request $request, int $id = 0): mixed
    {
        $quoteId = $id > 0 ? $id : (int) $request->query('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'quote_id is required'], 400);
        }

        $quote = DB::table('quotes_manpower as qm')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'qm.created_by_id')
            ->where('qm.id', $quoteId)
            ->select([
                'qm.*',
                'sg.position as staff_position',
                'sg.crm_position as crm_position',
                'sg.department as staff_department',
            ])
            ->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $signOffTitle = !empty($quote->crm_position)
            ? (string) $quote->crm_position
            : trim(((string) ($quote->staff_position ?? '')) . ' (' . ((string) ($quote->staff_department ?? '')) . ')');
        if ($signOffTitle === '()' || $signOffTitle === '') {
            $signOffTitle = 'Staff';
        }

        $durationMonths = (int) ($quote->duration_months ?? 0);
        $durationHours  = (float) ($quote->duration_hours ?? 0);
        $noOfPax        = (int) ($quote->no_of_pax ?? 0);
        $unitCost       = (float) ($quote->unit_cost ?? 0);
        $serviceRateText = strtolower((string) ($quote->service_title ?? '') . ' ' . (string) ($quote->service_code ?? ''));
        $billingUnit    = strtolower(trim((string) ($quote->billing_unit ?? '')));
        $isHourly       = $billingUnit === 'hour' || str_contains($serviceRateText, 'aesp') || abs($unitCost - 48.0) < 0.01;
        $durationQuantity = $isHourly ? ($durationHours > 0 ? $durationHours : $durationMonths) : $durationMonths;
        $durationDisplay = rtrim(rtrim(number_format($durationQuantity, 2, '.', ''), '0'), '.');
        $durationUnitLabel = $isHourly ? 'hour(s)' : 'month(s)';
        $unitCostLabel = $isHourly ? 'per pax per hour' : 'per pax per month';
        $discountAmount = (float) ($quote->discount ?? 0);
        $subTotalNet    = (float) ($quote->sub_total ?? 0);
        $sstPercent     = (float) ($quote->sst_percent ?? 0);
        $sstAmount      = (float) ($quote->sst_amount ?? 0);
        $grandTotal     = (float) ($quote->grand_total ?? 0);
        $sstPercentLabel = ((float) (int) $sstPercent === $sstPercent)
            ? number_format($sstPercent, 0)
            : number_format($sstPercent, 2);
        $grossAmount    = $subTotalNet + $discountAmount;
        $showSubtotal   = $discountAmount > 0 && $sstAmount > 0;
        $amountDetail   = $noOfPax . ' pax x ' . $durationDisplay . ' ' . $durationUnitLabel . ' x RM ' . number_format($unitCost, 2) . '/' . ($isHourly ? 'pax/hour' : 'pax/month');

        $createdAtRaw      = (string) ($quote->created_at ?? '');
        $updatedAtRaw      = (string) ($quote->updated_at ?? '');
        $createdDateLegacy = '';
        if ($createdAtRaw !== '') {
            $ts = strtotime($createdAtRaw);
            if ($ts !== false) {
                $createdDateLegacy = date('d M Y', $ts);
            }
        }

        $appendProposal  = (int) ($quote->attach_proposal ?? 0) === 1 && (int) ($quote->mp_id ?? 0) > 0;
        $proposalTitle   = '';
        $proposalSections = [];
        if ($appendProposal) {
            $proposal = DB::table('proposal_template_manpower')->where('id', (int) $quote->mp_id)->first();
            if ($proposal) {
                $proposalTitle = trim((string) ($proposal->service_title ?? '')) . ' Manpower Supply Service Proposal';
                foreach ([
                    'Introduction'                  => (string) ($proposal->introduction ?? ''),
                    'Service Deliverables'          => (string) ($proposal->service_deliverables ?? ''),
                    'Supplied Manpower Deliverables' => (string) ($proposal->supplied_manpower_deliverables ?? ''),
                    'Additional Information'        => (string) ($proposal->custom_section ?? ''),
                ] as $title => $content) {
                    $plain = trim(str_replace("\xc2\xa0", ' ', strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                    if ($plain !== '') {
                        $proposalSections[] = ['title' => $title, 'contentHtml' => $content];
                    }
                }
            } else {
                $appendProposal = false;
            }
        }

        $generatedAt   = now();
        $generatorId   = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $logoDataUri   = $this->companyLogoDataUri();

        $clientAddressBlock = $this->formatAddressBlock(
            $quote->client_address ?? null,
            $quote->client_city ?? null,
            $quote->client_state ?? null,
            $quote->client_zip ?? null
        );

        $html = view($this->pdfView('pdf.manpower-quote', $quote->proposal_language ?? 'en'), [
            'quoteRefNo'         => (string) ($quote->quote_ref_no ?? ''),
            'revisionNo'         => (int) ($quote->revision_no ?? 0),
            'createdDateLegacy'  => $createdDateLegacy,
            'createdDateIso'     => $createdAtRaw !== '' ? substr($createdAtRaw, 0, 10) : '',
            'updatedDateIso'     => $updatedAtRaw !== '' ? substr($updatedAtRaw, 0, 10) : '',
            'picName'            => (string) ($quote->pic_name ?? '-'),
            'clientName'         => (string) ($quote->client_name ?? '-'),
            'clientAddressBlock' => $clientAddressBlock,
            'picEmail'           => (string) ($quote->pic_email ?? '-'),
            'picPhone'           => (string) ($quote->pic_phone ?? '-'),
            'serviceTitle'       => (string) ($quote->service_title ?? ''),
            'serviceCode'        => (string) ($quote->service_code ?? ''),
            'natureOfWork'       => (string) ($quote->nature_of_work ?? ''),
            'siteLocation'       => (string) ($quote->site_location ?? ''),
            'durationMonths'     => $durationMonths,
            'durationDisplay'    => $durationDisplay,
            'durationUnitLabel'  => $durationUnitLabel,
            'noOfPax'            => $noOfPax,
            'unitCost'           => $unitCost,
            'unitCostLabel'      => $unitCostLabel,
            'grossAmount'        => $grossAmount,
            'amountDetail'       => $amountDetail,
            'discountAmount'     => $discountAmount,
            'showSubtotal'       => $showSubtotal,
            'subTotalNet'        => $subTotalNet,
            'sstAmount'          => $sstAmount,
            'sstPercentLabel'    => $sstPercentLabel,
            'grandTotal'         => $grandTotal,
            'inquiryRemarks'     => (string) ($quote->inquiry_remarks ?? ''),
            'preparedByName'     => (string) ($quote->created_by_name ?? ''),
            'signOffTitle'       => $signOffTitle,
            'appendProposal'     => $appendProposal,
            'proposalTitle'      => $proposalTitle ?: 'Service Proposal',
            'proposalSections'   => $proposalSections,
            'logoDataUri'        => $logoDataUri,
        ])->render();

        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $this->auditLog->log($request, "Generated Manpower quotation PDF for quote ID #{$quoteId}");

        $safeClient = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($quote->client_name ?? 'client'));
        $filename   = ((string) ($quote->quote_ref_no ?? "quote-{$quoteId}")) . '_' . trim($safeClient, '_') . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

}
