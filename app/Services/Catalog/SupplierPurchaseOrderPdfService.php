<?php

namespace App\Services\Catalog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierPurchaseOrderPdfService extends CatalogBaseService
{

    public function purchaseOrderPdf(Request $request, ?int $poId = null)
    {
        $resolvedPoId = $poId
            ?? (int) $request->query('po_id', 0)
            ?: (int) $request->input('po_id', 0);

        if ($resolvedPoId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing po_id'], 400);
        }

        $po = DB::table('supplier_po_main as pm')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'pm.created_by')
            ->leftJoin('vendor_main_details as vmd', 'vmd.vendor_id', '=', 'pm.supplier_id')
            ->where('pm.po_id', $resolvedPoId)
            ->select([
                'pm.*',
                'sg.full_name',
                'sg.name_code',
                'sg.position',
                'sg.department',
                'vmd.email as supplier_email',
            ])
            ->first();

        if (!$po) {
            return response()->json(['status' => 'error', 'message' => 'PO not found.'], 404);
        }

        $items = DB::table('supplier_po_items')
            ->where('po_id', $resolvedPoId)
            ->orderBy('po_item_id')
            ->get();

        $generatedAt   = now();
        $generatorId   = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $logoDataUri = $this->companyLogoDataUri();

        $html = view('pdf.catalog-supplier-po', [
            'po'             => $po,
            'items'          => $items,
            'documentType'   => 'PURCHASE ORDER',
            'createdDate'    => date('d M Y', strtotime((string) ($po->created_at ?? now()->toDateTimeString()))),
            'generatedDate'  => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode'=> $generatorCode,
            'generatedById'  => $generatorId,
            'logoDataUri'    => $logoDataUri,
        ])->render();

        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $safeRef = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($po->po_ref_no ?? "po-{$resolvedPoId}"));
        $this->auditLog->log($request, "Generated Supplier PO PDF for PO ID #{$resolvedPoId}");

        return response($dompdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"supplier-po-{$safeRef}.pdf\"",
        ]);
    }
}
