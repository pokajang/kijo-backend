<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;

class EquipmentQuoteRecordPdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private EquipmentQuoteDocumentData $documentData,
    ) {}

    public function pdfEquipment(Request $request, int $id = 0): mixed
    {
        $quoteId = $id > 0 ? $id : (int) $request->query('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'quote_id is required'], 400);
        }

        $data = $this->documentData->find($quoteId);
        if ($data === null) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $html = view('pdf.equipment-quote', [
            ...$data,
            'clientAddressBlock' => $data['clientAddress'],
            'logoDataUri' => $this->companyLogoDataUri(),
        ])->render();

        $dompdf = $this->renderPortraitWithFooter(
            $html,
            $generatedAt,
            $generatorCode,
            $generatorId,
            $request->boolean('approval_preview'),
        );

        $this->auditLog->log($request, "Generated Equipment quotation PDF for quote ID #{$quoteId}");

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->filename($data, 'pdf').'"',
        ]);
    }

    private function filename(array $data, string $extension): string
    {
        $safeRef = preg_replace('/[^A-Za-z0-9._-]+/', '_', $data['quoteRefNo'] ?: 'quote-'.$data['quoteId']);
        $safeClient = preg_replace('/[^A-Za-z0-9._-]+/', '_', $data['clientName'] ?: 'client');

        return "{$safeRef}_{$safeClient}.{$extension}";
    }
}
