<?php

namespace App\Services\Catalog;

use App\Services\AuditLogService;
use App\Services\Word\CommercialWordDocumentBuilder;
use App\Services\Word\WordRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SupplierPurchaseOrderWordService extends WordRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private CommercialWordDocumentBuilder $builder,
    ) {}

    public function generate(Request $request, ?int $poId = null)
    {
        $id = $poId ?? (int) ($request->query('po_id') ?: $request->input('po_id', 0));
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing po_id'], 400);
        }

        $query = DB::table('supplier_po_main as pm')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'pm.created_by')
            ->leftJoin('vendor_main_details as vmd', 'vmd.vendor_id', '=', 'pm.supplier_id')
            ->where('pm.po_id', $id)
            ->select(['pm.*', 'sg.full_name', 'sg.name_code', 'vmd.email as supplier_email']);
        $query->selectRaw(Schema::hasColumn('staff_general', 'position') ? 'sg.position' : 'NULL as position');
        $query->selectRaw(Schema::hasColumn('staff_general', 'department') ? 'sg.department' : 'NULL as department');
        $po = $query->first();
        if (! $po) {
            return response()->json(['status' => 'error', 'message' => 'PO not found.'], 404);
        }

        $orderColumn = Schema::hasColumn('supplier_po_items', 'po_item_id') ? 'po_item_id' : 'id';
        $rows = DB::table('supplier_po_items')->where('po_id', $id)->orderBy($orderColumn)->get();
        $items = [];
        foreach ($rows as $index => $item) {
            $description = array_values(array_filter([
                (string) ($item->item_name ?? ''),
                trim((string) ($item->description ?? '')) !== '' ? 'Description: '.trim((string) $item->description) : '',
                trim((string) ($item->item_remarks ?? '')) !== '' ? 'Remarks: '.trim((string) $item->item_remarks) : '',
            ]));
            $items[] = [
                (string) ($index + 1), $description, (string) ($item->unit ?? '-'),
                number_format((float) ($item->quantity ?? 0), 0), number_format((float) ($item->unit_price ?? 0), 2),
                number_format((float) ($item->line_total ?? 0), 2),
            ];
        }
        $preparedBy = array_values(array_filter([
            (string) ($po->full_name ?? '-'),
            implode(', ', array_filter([(string) ($po->position ?? ''), (string) ($po->department ?? '')])),
            'AMIOSH RESOURCES SDN BHD',
        ]));
        $data = [
            'kind' => 'purchase-order', 'documentType' => 'PURCHASE ORDER',
            'reference' => (string) ($po->po_ref_no ?? '-'),
            'date' => date('d M Y', strtotime((string) ($po->created_at ?? now()))),
            'recipient' => array_values(array_filter([
                (string) ($po->supplier_name ?? '-'), ...preg_split('/\R/', (string) ($po->supplier_address ?? '-')),
                'Email: '.((string) ($po->supplier_email ?? '-') ?: '-'),
                'Phone: '.((string) ($po->supplier_contact_number ?? '-') ?: '-'),
            ])),
            'contactName' => (string) ($po->supplier_contact_name ?? '-') ?: '-', 'items' => $items,
            'totals' => [
                ['label' => 'Discount (RM)', 'value' => (float) ($po->discount ?? 0), 'show' => (float) ($po->discount ?? 0) > 0],
                ['label' => 'Delivery Charge (RM)', 'value' => (float) ($po->delivery_charge ?? 0), 'show' => (float) ($po->delivery_charge ?? 0) > 0],
                ['label' => 'SST ('.number_format((float) ($po->sst_percent ?? 0), 2).'%)', 'value' => (float) ($po->sst_amount ?? 0), 'show' => (float) ($po->sst_amount ?? 0) > 0],
                ['label' => 'Grand Total (RM)', 'value' => (float) ($po->grand_total ?? 0), 'bold' => true],
            ],
            'remarks' => trim((string) ($po->quotation_remarks ?? '')), 'preparedBy' => $preparedBy,
            'termSections' => $this->terms(),
        ];

        $this->auditLog->log($request, "Generated Supplier PO Word document for PO ID #{$id}");

        return parent::download($this->builder->build($data, $request), 'supplier-po-'.$data['reference'].'.docx');
    }

    private function terms(): array
    {
        return [
            ['heading' => 'A. Compliance Commitment', 'body' => 'AMIOSH Resources Sdn. Bhd. is committed to occupational health and safety compliance. All equipment supplied must meet applicable legal and safety requirements.'],
            ['heading' => 'B. Delivery and Acceptance', 'body' => 'Delivery must be made within the agreed timeline. Items are subject to inspection and testing upon receipt. Non-conforming goods may be rejected at supplier expense.'],
            ['heading' => 'C. E-Invoice and Documentation', 'body' => 'Supplier shall provide complete invoices and supporting documents required for tax, e-invoicing, and audit compliance.'],
            ['heading' => 'D. Warranty', 'body' => 'Supplier warrants goods are free from defects in materials and workmanship for the agreed warranty period, or at least twelve (12) months if not specified.'],
            ['heading' => 'E. General Commitments', 'items' => [
                'Supplier must update AMIOSH on delivery progress and clarify outstanding matters promptly.',
                'All supplied items must be new, in good condition, and accompanied by required supporting documents.',
                'Where applicable, products must comply with relevant certification standards.',
                'Serious misconduct or breach may result in immediate PO cancellation and legal action.',
                'This Purchase Order is governed by the laws of Malaysia.',
            ]],
        ];
    }
}
