<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use App\Services\Word\CommercialWordDocumentBuilder;
use App\Services\Word\WordRenderer;
use App\Support\InvoicePdfDescription;
use App\Support\PdfLabels;
use App\Support\PdfLegalTerms;
use App\Support\PdfText;
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
        if (! $this->supportsInvoiceWord($invoice)) {
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
        $hasDiscountItems = false;
        $isTraining = strcasecmp((string) ($invoice->service_type ?? ''), 'Training') === 0;
        $isEquipmentSupply = strtolower(trim((string) ($invoice->service_type ?? ''))) === 'equipment supply';
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
                $hasDiscountItems = true;
            }
            $runningSubtotal += $subtotal;
            $items[] = [
                'number' => (string) (count($items) + 1),
                'sortOrder' => (int) ($item->sort_order ?? $index),
                'name' => $itemLabel,
                'segments' => PdfText::itemCellSegments(
                    $isEquipmentSupply ? ($item->description ?? '') : InvoicePdfDescription::clientVisible($item->description ?? ''),
                    $item->item_remarks ?? '',
                ),
                'unitPrice' => number_format((float) ($item->unit_price ?? 0), 2),
                'quantity' => number_format((float) ($item->quantity ?? 0), 2),
                'unit' => (string) ($item->unit ?? ''),
                'subtotal' => number_format($subtotal, 2),
            ];
        }
        usort($items, static function (array $left, array $right): int {
            $leftDiscount = str_contains(strtolower($left['name']), 'discount') || str_contains(strtolower($left['name']), 'less');
            $rightDiscount = str_contains(strtolower($right['name']), 'discount') || str_contains(strtolower($right['name']), 'less');

            return $leftDiscount === $rightDiscount
                ? $left['sortOrder'] <=> $right['sortOrder']
                : $leftDiscount <=> $rightDiscount;
        });
        foreach ($items as $index => &$item) {
            $item['number'] = (string) ($index + 1);
        }
        unset($item);
        $recipient = array_values(array_filter([
            (string) ($invoice->invoice_pic_name ?? ''),
            (string) ($invoice->invoice_client_name ?? ''),
            'SSM No. : '.((string) ($invoice->invoice_client_ssm ?? '') ?: 'N/A'),
            'Tax Identification Number (TIN) : '.((string) ($invoice->invoice_client_tin ?? '') ?: 'N/A'),
            (string) ($invoice->invoice_client_address ?? ''),
            implode(', ', array_filter([(string) ($invoice->invoice_client_city ?? ''), (string) ($invoice->invoice_client_state ?? ''), (string) ($invoice->invoice_client_zip ?? '')])),
        ]));
        if (trim((string) ($invoice->invoice_pic_email ?? '')) !== '' || trim((string) ($invoice->invoice_pic_phone ?? '')) !== '') {
            $recipient[] = PdfLabels::get($language, 'email', 'Email').': '.((string) ($invoice->invoice_pic_email ?? '') ?: 'N/A')
                .'    '.PdfLabels::get($language, 'phone', 'Phone').': '.((string) ($invoice->invoice_pic_phone ?? '') ?: 'N/A');
        }
        $purposeDisplay = $this->purposeDisplay($invoice);
        $service = trim((string) ($invoice->service_type ?? '-').' - '.$purposeDisplay);
        $serviceLines = [$service];
        if (trim((string) ($invoice->invoice_loa_no ?? '')) !== '') {
            $serviceLines[] = 'LOA/PO Number: '.trim((string) $invoice->invoice_loa_no);
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

        if ($isTraining) {
            return $this->trainingDocumentData($request, $invoice, $items, $runningSubtotal, $recipient, $creator, $title, $assetPaths, $terms, $language);
        }

        if ($this->isManpowerInvoice($invoice)) {
            $this->applyManpowerPresentation($items, $invoice, $purposeDisplay, $language);
        }

        return [
            'kind' => 'invoice', 'documentType' => PdfLabels::documentType($language, 'TAX INVOICE'), 'language' => $language,
            'reference' => (string) ($invoice->invoice_ref_no ?? '-'), 'date' => $this->date((string) ($invoice->invoice_date ?? $invoice->created_at ?? '')),
            'recipient' => $recipient,
            'attentionLabel' => PdfLabels::get($language, 'attention_to', 'Attention To'),
            'greetingPrefix' => $language === 'ms-MY' ? 'Kepada' : 'Dear',
            'greetingName' => PdfLabels::get($language, 'dear_valued_customer', 'Valued Customer'),
            'intro' => PdfLabels::get($language, strcasecmp((string) ($invoice->service_type ?? ''), 'Training') === 0 ? 'invoice_training_intro' : 'invoice_intro', 'We appreciate your business. Please review the Tax Invoice below for your kind action.'),
            'service' => $service, 'serviceLines' => $serviceLines, 'items' => $items,
            'totals' => [
                ['label' => PdfLabels::get($language, $hasDiscountItems ? 'subtotal_after_discount' : 'subtotal_rm', $hasDiscountItems ? 'Subtotal after Discount' : 'Subtotal (RM)'), 'value' => $runningSubtotal, 'shade' => true],
                ['label' => $this->sstLabel($invoice, $runningSubtotal), 'value' => (float) ($invoice->sst_amount ?? 0), 'show' => (float) ($invoice->sst_amount ?? 0) > 0],
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
            'paymentDetails' => [
                ['label' => PdfLabels::get($language, 'bank_name', 'Bank Name'), 'value' => 'CIMB BANK BERHAD', 'suffix' => '    '.PdfLabels::get($language, 'branch', 'Branch').': UNIKEB Bandar Baru Bangi'],
                ['label' => PdfLabels::get($language, 'account_name', 'Account Name'), 'value' => 'AMIOSH RESOURCES SDN BHD', 'suffix' => '    '.PdfLabels::get($language, 'account_number', 'Account Number').': 8002246023'],
            ],
            'termsHeading' => PdfLabels::get($language, 'terms_and_conditions', 'Terms and Conditions'),
            'terms' => $terms,
        ];
    }

    private function isEquipmentInvoice(object $invoice): bool
    {
        return in_array(strtolower(trim((string) ($invoice->service_type ?? ''))), ['equipment', 'equipment supply'], true);
    }

    private function supportsInvoiceWord(object $invoice): bool
    {
        return in_array(strtolower(trim((string) ($invoice->service_type ?? ''))), [
            'equipment', 'equipment supply', 'training', 'industrial hygiene', 'ih', 'manpower supply', 'special service', 'special',
        ], true);
    }

    private function unsupportedServiceResponse()
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Word export is not available for this invoice service type.',
        ], 422);
    }

    private function purposeDisplay(object $invoice): string
    {
        $purpose = (string) ($invoice->invoice_purpose ?? '-');
        if (! $this->isManpowerInvoice($invoice)) {
            return $purpose;
        }

        return trim((string) preg_replace('/\s*-\s*For (Month|Months):\s*.+$/i', '', $purpose));
    }

    private function isManpowerInvoice(object $invoice): bool
    {
        return strtolower(trim((string) ($invoice->service_type ?? ''))) === 'manpower supply';
    }

    private function applyManpowerPresentation(array &$items, object $invoice, string $purposeDisplay, string $language): void
    {
        $purpose = (string) ($invoice->invoice_purpose ?? '');
        $claimLabel = 'Claim Period';
        if (preg_match('/-\s*For (Month|Months):\s*(.+)$/i', $purpose, $match)) {
            $value = trim($match[2]);
            if (strcasecmp($match[1], 'Month') === 0 && preg_match('/^\d{4}-\d{2}$/', $value)) {
                $date = \DateTime::createFromFormat('Y-m', $value);
                $value = $date instanceof \DateTime ? $date->format('F Y') : $value;
            }
            $claimLabel = 'For '.$match[1].': '.$value;
        }
        $purposeNorm = strtolower(trim($purpose));
        $displayNorm = strtolower(trim($purposeDisplay));
        foreach ($items as &$item) {
            $name = strtolower(trim((string) $item['name']));
            $discount = str_contains($name, 'discount') || str_contains($name, 'less');
            if (! $discount && (($purposeNorm !== '' && $name === $purposeNorm) || ($displayNorm !== '' && $name === $displayNorm))) {
                $item['manpowerBase'] = true;
                $item['claimLabel'] = $claimLabel;
                $item['invoiceRemarks'] = trim((string) ($invoice->remarks ?? '')) ?: 'N/A';
                $item['segments'] = [[
                    'description' => '', 'remarks' => '', 'show_description_label' => false, 'show_remarks_label' => false,
                ]];
            }
        }
        unset($item);
    }

    private function trainingDocumentData(Request $request, object $invoice, array $items, float $subtotal, array $recipient, ?object $creator, string $title, array $assetPaths, array $terms, string $language): array
    {
        $isHrdGrant = strcasecmp((string) ($invoice->payment_method ?? ''), 'HRD Grant') === 0;
        if ($isHrdGrant) {
            $recipient = [
                'Human Resource Development Corporation',
                'SSM No. : '.((string) ($invoice->invoice_client_ssm ?? '') ?: 'N/A'),
                'Tax Identification Number (TIN) : '.((string) ($invoice->invoice_client_tin ?? '') ?: 'N/A'),
                'Wisma HRD Corp',
                'Jalan Beringin, Bukit Damansara,',
                '50490 Kuala Lumpur.',
            ];
        }
        usort($items, static function (array $left, array $right): int {
            $rank = static function (string $name): int {
                $name = strtolower($name);
                return match (true) {
                    str_contains($name, 'training fee') => 0,
                    str_contains($name, 'meal total') => 1,
                    str_contains($name, 'mobilization') => 2,
                    str_contains($name, 'discount'), str_contains($name, 'less') => 4,
                    default => 3,
                };
            };
            $leftRank = $rank($left['name']);
            $rightRank = $rank($right['name']);

            return $leftRank === $rightRank ? $left['sortOrder'] <=> $right['sortOrder'] : $leftRank <=> $rightRank;
        });
        foreach ($items as $index => &$item) {
            $item['number'] = (string) ($index + 1);
        }
        unset($item);

        $project = DB::table('projects_main as p')
            ->leftJoin('client_company as c', 'p.client_id', '=', 'c.company_id')
            ->where('p.id', $invoice->project_id)
            ->first(['p.project_name', 'p.service_start_date', 'p.service_end_date', 'c.company_name', 'c.ssm_number']);
        $start = ! empty($project?->service_start_date) ? $this->date((string) $project->service_start_date) : '-';
        $end = ! empty($project?->service_end_date) ? $this->date((string) $project->service_end_date) : '-';
        $details = [
            ['label' => $language === 'ms-MY' ? 'Nama Penyedia' : 'Provider Name', 'value' => 'AMIOSH RESOURCES SDN. BHD.'],
            ['label' => $language === 'ms-MY' ? 'Nama Majikan' : 'Employer Name', 'value' => trim((string) ($project?->company_name ?? '')).(! empty($project?->ssm_number) ? ' ('.$project->ssm_number.')' : '')],
        ];
        if ($isHrdGrant && trim((string) ($invoice->grant_approval_no ?? '')) !== '') {
            $details[] = ['label' => 'Grant ID', 'value' => (string) $invoice->grant_approval_no];
        }
        if (trim((string) ($invoice->invoice_loa_no ?? '')) !== '') {
            $details[] = ['label' => 'LOA/PO Number', 'value' => (string) $invoice->invoice_loa_no];
        }
        $details[] = ['label' => $language === 'ms-MY' ? 'Tajuk Latihan' : 'Training Title', 'value' => (string) ($project?->project_name ?? '')];
        $details[] = ['label' => $language === 'ms-MY' ? 'Tarikh Latihan' : 'Training Date', 'value' => 'Start - '.$start.' ; End - '.$end];
        $details[] = ['label' => $language === 'ms-MY' ? 'Catatan Invois' : 'Invoice Remarks', 'value' => trim((string) ($invoice->remarks ?? '')) ?: 'N/A'];

        return [
            'kind' => 'invoice', 'layout' => 'training', 'documentType' => PdfLabels::documentType($language, 'TAX INVOICE'), 'language' => $language,
            'reference' => (string) ($invoice->invoice_ref_no ?? '-'), 'date' => $this->date((string) ($invoice->invoice_date ?? $invoice->created_at ?? '')),
            'recipient' => $recipient, 'attentionLabel' => PdfLabels::get($language, 'attention_to', 'Attention To'),
            'greetingPrefix' => $language === 'ms-MY' ? 'Kepada' : 'Dear',
            'greetingName' => $isHrdGrant ? PdfLabels::get($language, 'dear_hrd_officer', 'Respected HRD Officer') : PdfLabels::get($language, 'dear_valued_customer', 'Valued Customer'),
            'intro' => $isHrdGrant ? PdfLabels::get($language, 'invoice_training_intro', 'Kindly find the tax invoice for the training program we conducted as detailed below.') : PdfLabels::get($language, 'invoice_intro', 'We appreciate your business. Please review the Tax Invoice below for your kind action.'),
            'items' => $items, 'trainingDetails' => $details,
            'totals' => [
                ['label' => PdfLabels::get($language, 'subtotal_rm', 'Subtotal (RM)'), 'value' => $subtotal, 'shade' => true],
                ['label' => 'SST 8% (RM)', 'value' => (float) ($invoice->sst_amount ?? 0), 'show' => (float) ($invoice->sst_amount ?? 0) > 0],
                ['label' => PdfLabels::get($language, 'grand_total_rm', 'Grand Total (RM)'), 'value' => (float) ($invoice->grand_total ?? 0), 'bold' => true],
            ],
            'remarks' => '', 'preparedByLabel' => PdfLabels::get($language, 'prepared_by', 'Prepared by'),
            'preparedBy' => array_values(array_filter([(string) ($creator->full_name ?? '-'), $title, 'AMIOSH RESOURCES SDN BHD'])),
            'signaturePath' => $assetPaths['signature'], 'stampPath' => $assetPaths['stamp'],
            'noSignatureText' => PdfLabels::get($language, 'no_signature_or_stamp', '[No signature or stamp on file]'),
            'paymentLines' => [PdfLabels::get($language, 'payment_instruction', 'Please remit payment to the following account:')],
            'paymentDetails' => [
                ['label' => PdfLabels::get($language, 'bank_name', 'Bank Name'), 'value' => 'CIMB BANK BERHAD', 'suffix' => '    '.PdfLabels::get($language, 'branch', 'Branch').': UNIKEB Bandar Baru Bangi'],
                ['label' => PdfLabels::get($language, 'account_name', 'Account Name'), 'value' => 'AMIOSH RESOURCES SDN BHD', 'suffix' => '    '.PdfLabels::get($language, 'account_number', 'Account Number').': 8002246023'],
            ],
            'termsHeading' => PdfLabels::get($language, 'terms_and_conditions', 'Terms and Conditions'), 'terms' => $terms,
        ];
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

    private function sstLabel(object $invoice, float $netSubtotal): string
    {
        $base = $netSubtotal;
        if ($base <= 0) {
            $base = (float) ($invoice->amount ?? 0);
        }
        if ($base <= 0) {
            return 'SST (RM)';
        }

        $rate = rtrim(rtrim(number_format(((float) $invoice->sst_amount / $base) * 100, 2, '.', ''), '0'), '.');

        return $rate.'% SST (RM)';
    }
}
