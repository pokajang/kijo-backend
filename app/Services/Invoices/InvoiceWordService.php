<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use App\Services\Word\CommercialWordDocumentBuilder;
use App\Services\Word\WordRenderer;
use App\Support\PdfLabels;
use App\Support\PdfLegalTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InvoiceWordService extends WordRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private CommercialWordDocumentBuilder $builder,
        private ReceiptNumberService $receiptNumbers,
        private InvoiceDocumentAssetService $assets,
    ) {}

    public function invoice(Request $request, int $id = 0)
    {
        $invoiceId = $this->resolveId($request, $id);
        if ($invoiceId < 1) {
            return response()->json(['status' => 'error', 'message' => 'invoice_id is required'], 422);
        }
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }
        if (! $this->isEquipmentInvoice($invoice)) {
            return $this->unsupportedServiceResponse();
        }
        $data = $this->documentData($request, $invoice, false);
        $this->auditLog->log($request, "Generated invoice Word document for {$data['reference']}");

        return parent::download($this->builder->build($data, $request), $data['reference'].'.docx');
    }

    public function receipt(Request $request, int $id = 0)
    {
        $invoiceId = $this->resolveId($request, $id);
        if ($invoiceId < 1) {
            return response()->json(['status' => 'error', 'message' => 'invoice_id is required'], 422);
        }
        $existingInvoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $existingInvoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }
        if (! $this->isEquipmentInvoice($existingInvoice)) {
            return $this->unsupportedServiceResponse();
        }
        try {
            $invoice = $this->receiptNumbers->resolvePaidInvoice($invoiceId);
        } catch (\OutOfBoundsException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 404);
        } catch (\DomainException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }
        $data = $this->documentData($request, $invoice, true);
        $this->auditLog->log($request, "Generated receipt Word document for {$data['reference']}");

        return parent::download($this->builder->build($data, $request), $data['reference'].'.docx');
    }

    private function documentData(Request $request, object $invoice, bool $receipt): array
    {
        $language = PdfLabels::normalize($invoice->document_language ?? 'en');
        $rows = DB::table('invoice_breakdown')->where('invoice_id', $invoice->id)->orderBy('sort_order')->get();
        $items = [];
        $runningSubtotal = 0.0;
        $isTraining = strcasecmp((string) ($invoice->service_type ?? ''), 'Training') === 0;
        foreach ($rows as $index => $item) {
            if ($item->subtotal === null || (float) $item->subtotal === 0.0) {
                continue;
            }
            $itemLabel = (string) ($item->item_description ?? '');
            $normalizedLabel = strtolower($itemLabel);
            $isHrdCharge = (bool) preg_match('/^\s*(\d+(\.\d+)?\s*%\s*)?hrd\s*charge\b/i', $itemLabel);
            if (! $receipt && (str_contains($normalizedLabel, 'sst') || (! $isTraining && $isHrdCharge))) {
                continue;
            }
            $subtotal = (float) $item->subtotal;
            if (str_contains($normalizedLabel, 'discount') || str_contains($normalizedLabel, 'less')) {
                $subtotal = -abs($subtotal);
            }
            $runningSubtotal += $subtotal;
            $description = array_values(array_filter([
                $itemLabel,
                trim((string) ($item->description ?? '')) !== '' ? PdfLabels::get($language, 'description', 'Description').': '.trim((string) $item->description) : '',
                trim((string) ($item->item_remarks ?? '')) !== '' ? PdfLabels::get($language, 'remarks', 'Remarks').': '.trim((string) $item->item_remarks) : '',
            ]));
            $items[] = [
                (string) (count($items) + 1), $description, number_format((float) ($item->unit_price ?? 0), 2),
                number_format((float) ($item->quantity ?? 0), 2), (string) ($item->unit ?? ''), number_format($subtotal, 2),
            ];
        }
        $recipient = array_values(array_filter([
            (string) ($invoice->invoice_client_name ?? '-'),
            'SSM No.: '.((string) ($invoice->invoice_client_ssm ?? '') ?: 'N/A'),
            'Tax Identification Number (TIN): '.((string) ($invoice->invoice_client_tin ?? '') ?: 'N/A'),
            (string) ($invoice->invoice_client_address ?? ''),
            implode(', ', array_filter([(string) ($invoice->invoice_client_city ?? ''), (string) ($invoice->invoice_client_state ?? ''), (string) ($invoice->invoice_client_zip ?? '')])),
            PdfLabels::get($language, 'email', 'Email').': '.((string) ($invoice->invoice_pic_email ?? '') ?: 'N/A').'    '.PdfLabels::get($language, 'phone', 'Phone').': '.((string) ($invoice->invoice_pic_phone ?? '') ?: 'N/A'),
        ]));
        $service = trim((string) ($invoice->service_type ?? '-').' - '.(string) ($invoice->invoice_purpose ?? '-'));
        if (trim((string) ($invoice->invoice_loa_no ?? '')) !== '') {
            $service .= ' | LOA/PO Number: '.trim((string) $invoice->invoice_loa_no);
        }
        if ($receipt) {
            return [
                'kind' => 'receipt', 'documentType' => PdfLabels::documentType($language, 'OFFICIAL RECEIPT'), 'language' => $language,
                'reference' => (string) ($invoice->receipt_no ?? $invoice->invoice_ref_no ?? 'receipt'),
                'invoiceReference' => (string) ($invoice->invoice_ref_no ?? '-'),
                'date' => $this->date((string) ($invoice->paid_date ?? '')), 'recipient' => $recipient, 'service' => $service,
                'items' => $items, 'totals' => [
                    ['label' => 'SST (RM)', 'value' => (float) ($invoice->sst_amount ?? 0), 'show' => (float) ($invoice->sst_amount ?? 0) > 0],
                    ['label' => PdfLabels::get($language, 'total_paid_rm', 'Total Paid (RM)'), 'value' => (float) ($invoice->paid_amount ?? 0), 'bold' => true],
                ], 'remarks' => trim((string) ($invoice->quotation_remarks ?? '')),
            ];
        }
        $creator = DB::table('staff_general')->where('staff_id', $invoice->created_by ?? 0)->first(['full_name', 'name_code', 'position', 'crm_position', 'department']);
        $title = trim((string) ($creator->crm_position ?? '')) ?: trim((string) ($creator->position ?? '').' ('.(string) ($creator->department ?? '').')', ' ()');
        $assetPaths = $this->assets->paths($request, $invoice, $creator);
        $terms = PdfLegalTerms::get($language, 'invoice');
        if ($terms !== []) {
            $days = (int) ($invoice->payment_terms_days ?? 30);
            $terms[0] = $language === 'ms-MY'
                ? "Bayaran perlu dijelaskan dalam tempoh {$days} hari dari tarikh invois ini."
                : "Payment is due within {$days} days from the date of this invoice.";
        }

        return [
            'kind' => 'invoice', 'documentType' => PdfLabels::documentType($language, 'TAX INVOICE'), 'language' => $language,
            'reference' => (string) ($invoice->invoice_ref_no ?? '-'), 'date' => $this->date((string) ($invoice->invoice_date ?? $invoice->created_at ?? '')),
            'recipient' => $recipient, 'intro' => PdfLabels::get($language, strcasecmp((string) ($invoice->service_type ?? ''), 'Training') === 0 ? 'invoice_training_intro' : 'invoice_intro', 'We appreciate your business. Kindly review the Tax Invoice below for your action.'),
            'service' => $service, 'items' => $items,
            'totals' => [
                ['label' => PdfLabels::get($language, 'subtotal_rm', 'Subtotal (RM)'), 'value' => $runningSubtotal],
                ['label' => PdfLabels::get($language, 'sst_charge_rm', 'SST Charge (RM)'), 'value' => (float) ($invoice->sst_amount ?? 0), 'show' => (float) ($invoice->sst_amount ?? 0) > 0],
                ['label' => PdfLabels::get($language, 'grand_total_rm', 'Grand Total (RM)'), 'value' => (float) ($invoice->grand_total ?? 0), 'bold' => true],
            ],
            'remarks' => trim((string) ($invoice->quotation_remarks ?? '')),
            'preparedByLabel' => PdfLabels::get($language, 'prepared_by', 'Prepared by'),
            'preparedBy' => array_values(array_filter([(string) ($creator->full_name ?? '-'), $title, 'AMIOSH RESOURCES SDN BHD'])),
            'signaturePath' => $assetPaths['signature'],
            'stampPath' => $assetPaths['stamp'],
            'noSignatureText' => PdfLabels::get($language, 'no_signature_or_stamp', '[No signature or stamp on file]'),
            'paymentLines' => [
                PdfLabels::get($language, 'payment_instruction', 'Please remit payment to the following account:'),
                PdfLabels::get($language, 'bank_name', 'Bank Name').': CIMB BANK BERHAD    '.PdfLabels::get($language, 'branch', 'Branch').': UNIKEB Bandar Baru Bangi',
                PdfLabels::get($language, 'account_name', 'Account Name').': AMIOSH RESOURCES SDN BHD    '.PdfLabels::get($language, 'account_number', 'Account Number').': 8002246023',
            ],
            'termsHeading' => PdfLabels::get($language, 'terms_and_conditions', 'Terms and Conditions'),
            'terms' => $terms,
        ];
    }

    private function isEquipmentInvoice(object $invoice): bool
    {
        return in_array(strtolower(trim((string) ($invoice->service_type ?? ''))), ['equipment', 'equipment supply'], true);
    }

    private function unsupportedServiceResponse()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Word export is currently available for Equipment invoices only.',
        ], 422);
    }

    private function resolveId(Request $request, int $id): int
    {
        return $id > 0 ? $id : (int) ($request->query('invoice_id') ?? $request->query('id', 0));
    }

    private function date(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp === false ? '-' : date('d M Y', $timestamp);
    }
}
