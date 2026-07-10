<?php

namespace App\Services\Invoices;

use App\Services\Pdf\PdfRenderer;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoicePdfService extends PdfRenderer
{
    public function __construct(private AuditLogService $auditLog)
    {
    }

    public function invoicePdf(Request $request, int $id = 0)
    {
        $invoiceId = $id > 0 ? $id : (int) ($request->query('invoice_id') ?? $request->query('id', 0));
        if ($invoiceId < 1) {
            return response()->json(['status' => 'error', 'message' => 'invoice_id is required'], 422);
        }

        try {
            $inv = DB::table('invoices')->where('id', $invoiceId)->first();
            if (!$inv) {
                return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
            }

            $allItems = DB::table('invoice_breakdown')
                ->where('invoice_id', $invoiceId)
                ->orderBy('sort_order')
                ->get();

            $preTax = [];
            $taxItems = [];
            $isTrainingInvoice = strcasecmp((string) ($inv->service_type ?? ''), 'Training') === 0;
            $isHrdLine = static fn(object $itm): bool => (bool) preg_match(
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
                $creator->signOffTitle = !empty($creator->crm_position)
                    ? $creator->crm_position
                    : ($creator->position . ' (' . $creator->department . ')');
            }

            $project = DB::table('projects_main as p')
                ->leftJoin('client_company as c', 'p.client_id', '=', 'c.company_id')
                ->where('p.id', $inv->project_id)
                ->first(['p.client_id', 'p.project_name', 'p.description', 'p.service_start_date', 'p.service_end_date', 'c.company_name', 'c.ssm_number']);

            $generatedAt = now();
            $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
            $generatorCode = (string) $request->session()->get('name_code', '');
            $logoDataUri = $this->companyLogoDataUri();
            [$signDataUri, $stampDataUri] = $this->invoiceSignatureAndStampDataUris($request, $inv, $creator);

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
            DB::beginTransaction();
            $inv = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();

            if (!$inv) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
            }

            $status = strtolower(trim((string) ($inv->status ?? '')));
            $paidDate = trim((string) ($inv->paid_date ?? ''));
            $paidAmount = $inv->paid_amount;
            $isPaidValid = $paidAmount !== null && is_numeric($paidAmount) && (float) $paidAmount > 0;

            if ($status !== 'paid' || $paidDate === '' || !$isPaidValid) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only paid invoices with payment date and amount can generate receipt PDF.',
                ], 422);
            }

            if (empty($inv->receipt_no)) {
                $currentYear = date('Y');
                $maxReceipt = DB::table('invoices')
                    ->where('receipt_no', 'LIKE', "RCPT{$currentYear}-%")
                    ->max('receipt_no');
                $nextNum = $maxReceipt ? ((int) substr($maxReceipt, -4)) + 1 : 1;
                $receiptNo = sprintf('RCPT%s-%04d', $currentYear, $nextNum);

                DB::table('invoices')->where('id', $invoiceId)->update(['receipt_no' => $receiptNo]);
                $inv = DB::table('invoices')->where('id', $invoiceId)->first();
            }

            DB::commit();

            $items = DB::table('invoice_breakdown')
                ->where('invoice_id', $invoiceId)
                ->orderBy('sort_order')
                ->get(['item_description', 'description', 'unit', 'quantity', 'unit_price', 'subtotal']);

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
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
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
        $bmView = $baseView . '-bm';
        return $this->normalizeDocumentLanguage($language) === 'ms-MY' && view()->exists($bmView)
            ? $bmView
            : $baseView;
    }

    private function fileDataUri(string $path, string $ext): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $mime = match (strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };
        return "data:{$mime};base64," . base64_encode($bytes);
    }
    private function invoiceSignatureAndStampDataUris(Request $request, object $inv, ?object $creator): array
    {
        $candidates = [];
        if (! empty($inv->created_by) && ! empty($creator?->name_code)) {
            $candidates[] = [(string) $inv->created_by, (string) $creator->name_code];
        }

        $sessionId = (string) $request->session()->get('staff_id', '');
        $sessionCode = (string) $request->session()->get('name_code', '');
        if ($sessionId !== '' && $sessionCode !== '') {
            $candidates[] = [$sessionId, $sessionCode];
        }

        $stampPaths = [];
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $stampPaths[$ext] = "invoice-assets/stamp.{$ext}";
        }
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $stampPaths[$ext . '-signature'] = "signatures/stamp.{$ext}";
        }

        return [
            $this->invoiceSignatureDataUriForCandidates($candidates),
            $this->publicDiskImageDataUri($stampPaths),
        ];
    }

    private function invoiceSignatureDataUriForCandidates(array $candidates): ?string
    {
        foreach ($candidates as [$sid, $code]) {
            $sid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $sid);
            $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $code);
            if ($sid === '' || $code === '') {
                continue;
            }

            $paths = [];
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $paths[$ext] = "signatures/{$sid}-{$code}.{$ext}";
            }
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $paths[$ext . '-invoice'] = "invoice-assets/{$sid}-{$code}.{$ext}";
            }

            $dataUri = $this->publicDiskImageDataUri($paths);
            if ($dataUri !== null) {
                return $dataUri;
            }
        }

        return null;
    }

    private function publicDiskImageDataUri(array $pathsByExt): ?string
    {
        foreach ($pathsByExt as $key => $relativePath) {
            $relativePath = AppFilePaths::publicStorageRelativePath((string) $relativePath);
            if ($relativePath === null) {
                continue;
            }

            $path = AppFilePaths::storedPathLocalPath($relativePath);
            if ($path === null) {
                continue;
            }

            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $ext = strtolower((string) pathinfo((string) $relativePath, PATHINFO_EXTENSION));
            if ($ext === '') {
                $ext = strtolower(preg_replace('/-.+$/', '', (string) $key));
            }

            return $this->fileDataUri($path, $ext);
        }

        return null;
    }
}
