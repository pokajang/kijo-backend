<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoicePdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private ReceiptNumberService $receiptNumbers,
        private InvoiceDocumentAssetService $assets,
    ) {}

    public function invoicePdf(Request $request, int $id = 0)
    {
        $invoiceId = $id > 0 ? $id : (int) ($request->query('invoice_id') ?? $request->query('id', 0));
        if ($invoiceId < 1) {
            return response()->json(['status' => 'error', 'message' => 'invoice_id is required'], 422);
        }

        try {
            $inv = DB::table('invoices')->where('id', $invoiceId)->first();
            if (! $inv) {
                return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
            }

            $allItems = DB::table('invoice_breakdown')
                ->where('invoice_id', $invoiceId)
                ->orderBy('sort_order')
                ->get();

            $preTax = [];
            $taxItems = [];
            $isTrainingInvoice = strcasecmp((string) ($inv->service_type ?? ''), 'Training') === 0;
            $isHrdLine = static fn (object $itm): bool => (bool) preg_match(
                '/^\s*(\d+(\.\d+)?\s*%\s*)?hrd\s*charge\b/i',
                (string) ($itm->item_description ?? '')
            );

            foreach ($allItems as $itm) {
                $sub = (float) $itm->subtotal;
                $desc = strtolower((string) ($itm->item_description ?? ''));
                if ($sub === 0.0) {
                    continue;
                }
                if (str_contains($desc, 'sst') || (! $isTrainingInvoice && $isHrdLine($itm))) {
                    $taxItems[] = $itm;
                } else {
                    $preTax[] = $itm;
                }
            }

            $creator = DB::table('staff_general')
                ->where('staff_id', $inv->created_by)
                ->first(['full_name', 'name_code', 'position', 'crm_position', 'department']);

            if ($creator) {
                $creator->signOffTitle = ! empty($creator->crm_position)
                    ? $creator->crm_position
                    : ($creator->position.' ('.$creator->department.')');
            }

            $project = DB::table('projects_main as p')
                ->leftJoin('client_company as c', 'p.client_id', '=', 'c.company_id')
                ->where('p.id', $inv->project_id)
                ->first(['p.client_id', 'p.project_name', 'p.description', 'p.service_start_date', 'p.service_end_date', 'c.company_name', 'c.ssm_number']);

            $generatedAt = now();
            $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
            $generatorCode = (string) $request->session()->get('name_code', '');
            $logoDataUri = $this->companyLogoDataUri();
            [$signDataUri, $stampDataUri] = $this->assets->dataUris($request, $inv, $creator);

            $isTraining = strcasecmp((string) ($inv->service_type ?? ''), 'Training') === 0;
            $template = $isTraining ? 'pdf.invoice-training' : 'pdf.invoice';
            $template = $this->pdfView($template, $inv->document_language ?? 'en');

            $html = view($template, [
                'inv' => $inv,
                'preTax' => $preTax,
                'taxItems' => $taxItems,
                'creator' => $creator,
                'project' => $project,
                'logoDataUri' => $logoDataUri,
                'signDataUri' => $signDataUri,
                'stampDataUri' => $stampDataUri,
            ])->render();

            $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

            $refNo = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($inv->invoice_ref_no ?? "inv-{$invoiceId}"));
            $this->auditLog->log($request, "Generated invoice PDF for {$inv->invoice_ref_no}");

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$refNo}.pdf\"",
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function receiptPdf(Request $request, int $id = 0)
    {
        $invoiceId = $id > 0 ? $id : (int) ($request->query('invoice_id') ?? $request->query('id', 0));
        if ($invoiceId < 1) {
            return response()->json(['status' => 'error', 'message' => 'invoice_id is required'], 422);
        }

        try {
            try {
                $inv = $this->receiptNumbers->resolvePaidInvoice($invoiceId);
            } catch (\OutOfBoundsException $exception) {
                return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 404);
            } catch (\DomainException $exception) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only paid invoices with payment date and amount can generate receipt PDF.',
                ], 422);
            }

            $items = DB::table('invoice_breakdown')
                ->where('invoice_id', $invoiceId)
                ->orderBy('sort_order')
                ->get(['item_description', 'description', 'item_remarks', 'unit', 'quantity', 'unit_price', 'subtotal']);

            $generatedAt = now();
            $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
            $generatorCode = (string) $request->session()->get('name_code', '');
            $logoDataUri = $this->companyLogoDataUri();

            $html = view($this->pdfView('pdf.receipt', $inv->document_language ?? 'en'), [
                'inv' => $inv,
                'items' => $items,
                'logoDataUri' => $logoDataUri,
            ])->render();

            $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

            $receiptRef = (string) ($inv->receipt_no ?? $inv->invoice_ref_no ?? 'receipt');
            $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $receiptRef);
            $this->auditLog->log($request, "Generated receipt PDF for {$receiptRef}");

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$safeName}.pdf\"",
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function normalizeDocumentLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));

        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    public function pdfView(string $baseView, mixed $language): string
    {
        $bmView = $baseView.'-bm';

        return $this->normalizeDocumentLanguage($language) === 'ms-MY' && view()->exists($bmView)
            ? $bmView
            : $baseView;
    }
}
