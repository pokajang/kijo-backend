<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentQuoteRecordPdfService extends PdfRenderer
{
    public function __construct(private AuditLogService $auditLog) {}

    public function pdfEquipment(Request $request, int $id = 0): mixed
    {
        $quoteId = $id > 0 ? $id : (int) $request->query('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'quote_id is required'], 400);
        }

        $quote = DB::table('quotes_equipment as qe')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'qe.created_by_id')
            ->where('qe.id', $quoteId)
            ->select([
                'qe.*',
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

        $items = DB::table('quotes_equipment_items as qei')
            ->join('catalog_items as ci', 'ci.id', '=', 'qei.item_id')
            ->where('qei.quote_id', $quoteId)
            ->orderBy('qei.id')
            ->select(['ci.item_name as title', 'ci.description', 'qei.marked_up_price', 'qei.quantity', 'qei.line_total'])
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        $lineItemsTotal = array_sum(array_column($items, 'line_total'));
        $deliveryCharge = (float) ($quote->delivery_charge ?? 0);
        $miscCharge     = (float) ($quote->misc_charge ?? 0);
        $discountAmount = (float) ($quote->discount ?? 0);
        $subTotalNet    = (float) ($quote->sub_total ?? 0);
        $sstPercent     = (float) ($quote->sst_percent ?? 0);
        $sstAmount      = (float) ($quote->sst_amount ?? 0);
        $grandTotal     = (float) ($quote->grand_total ?? 0);
        $sstPercentLabel = ((float) (int) $sstPercent === $sstPercent)
            ? number_format($sstPercent, 0)
            : number_format($sstPercent, 2);

        $createdDateLegacy = '';
        if (!empty($quote->created_at)) {
            $ts = strtotime((string) $quote->created_at);
            if ($ts !== false) {
                $createdDateLegacy = date('d M Y', $ts);
            }
        }

        $clientAddressBlock = $this->formatAddressBlock(
            $quote->client_address ?? null,
            $quote->client_city ?? null,
            $quote->client_state ?? null,
            $quote->client_zip ?? null
        );

        $generatedAt   = now();
        $generatorId   = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $logoDataUri   = $this->companyLogoDataUri();

        $html = view('pdf.equipment-quote', [
            'quoteRefNo'        => (string) ($quote->quote_ref_no ?? ''),
            'revisionNo'        => (int) ($quote->revision_no ?? 0),
            'createdDateLegacy' => $createdDateLegacy,
            'createdDateIso'    => !empty($quote->created_at) ? substr((string) $quote->created_at, 0, 10) : '',
            'updatedDateIso'    => !empty($quote->updated_at) ? substr((string) $quote->updated_at, 0, 10) : '',
            'clientName'        => (string) ($quote->client_name ?? '-'),
            'clientAddressBlock'=> $clientAddressBlock,
            'picName'           => (string) ($quote->pic_name ?? '-'),
            'picEmail'          => (string) ($quote->pic_email ?? '-'),
            'picPhone'          => (string) ($quote->pic_phone ?? '-'),
            'items'             => $items,
            'lineItemsTotal'    => $lineItemsTotal,
            'deliveryCharge'    => $deliveryCharge,
            'miscCharge'        => $miscCharge,
            'discountAmount'    => $discountAmount,
            'subTotalNet'       => $subTotalNet,
            'sstAmount'         => $sstAmount,
            'sstPercentLabel'   => $sstPercentLabel,
            'grandTotal'        => $grandTotal,
            'preparedByName'    => (string) ($quote->created_by_name ?? ''),
            'signOffTitle'      => $signOffTitle,
            'logoDataUri'       => $logoDataUri,
        ])->render();

        $dompdf = $this->renderPortraitWithFooter(
            $html,
            $generatedAt,
            $generatorCode,
            $generatorId,
            $request->boolean('approval_preview'),
        );

        $this->auditLog->log($request, "Generated Equipment quotation PDF for quote ID #{$quoteId}");

        $safeRef    = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($quote->quote_ref_no ?? "quote-{$quoteId}"));
        $safeClient = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($quote->client_name ?? 'client'));
        $filename   = "{$safeRef}_{$safeClient}.pdf";

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

}
