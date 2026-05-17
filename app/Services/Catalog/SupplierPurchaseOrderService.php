<?php

namespace App\Services\Catalog;

use App\Http\Requests\Catalog\MarkSupplierPoPaidRequest;
use App\Http\Requests\Catalog\StoreCatalogItemRequest;
use App\Http\Requests\Catalog\StoreSupplierPoRequest;
use App\Http\Requests\Catalog\UpdateCatalogItemRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SupplierPurchaseOrderService extends CatalogBaseService
{

    public function listPurchaseOrders(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $q       = trim((string) $request->query('q', ''));

        $query = DB::table('supplier_po_main as pm')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'pm.created_by')
            ->select([
                'pm.po_id',
                'pm.project_id',
                'pm.supplier_id',
                'pm.supplier_name',
                'pm.supplier_address',
                'pm.supplier_contact_name',
                'pm.supplier_contact_number',
                'pm.discount',
                'pm.delivery_charge',
                'pm.sst_percent',
                'pm.sst_amount',
                'pm.grand_total',
                'pm.po_running_no',
                'pm.po_ref_no',
                'pm.status',
                'pm.status_remarks',
                'pm.created_by',
                'pm.created_at',
                'pm.updated_at',
                'sg.full_name as created_by_name',
                'sg.name_code as created_by_code',
            ])
            ->orderByDesc('pm.created_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pm.po_ref_no', 'like', "%{$q}%")
                    ->orWhere('pm.supplier_name', 'like', "%{$q}%")
                    ->orWhere('pm.supplier_contact_name', 'like', "%{$q}%")
                    ->orWhere('pm.supplier_contact_number', 'like', "%{$q}%");
            });
        }
        $year = (int) $request->query('year', 0);
        if ($year >= 2000 && $year <= 2100) {
            $query->whereYear('pm.created_at', $year);
        }

        $paginator = $query->paginate($perPage);
        $pos       = $paginator->items();

        if (!empty($pos)) {
            $poIds = array_map(fn ($po) => (int) $po->po_id, $pos);
            $rows  = DB::table('supplier_po_items')
                ->select([
                    'po_id',
                    'item_id',
                    'item_name',
                    'description',
                    'unit',
                    'quantity',
                    'unit_price',
                    'line_total',
                ])
                ->whereIn('po_id', $poIds)
                ->orderBy('po_item_id')
                ->get();

            $itemsByPo = [];
            foreach ($rows as $row) {
                $itemsByPo[$row->po_id][] = $row;
            }

            foreach ($pos as $po) {
                $po->items = $itemsByPo[$po->po_id] ?? [];
            }
        }

        return response()->json([
            'status'     => 'success',
            'data'       => $pos,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function storePurchaseOrder(StoreSupplierPoRequest $request)
    {
        $staffId    = (int) $request->session()->get('staff_id', 0);
        $creatorCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $creatorCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data      = $request->validated();
        $projectId = $data['project_id'] ?? null;
        $supplier  = $data['supplier'];
        $items     = $data['items'];

        $lockName     = 'supplier_po_' . date('Y');
        $lockAcquired = false;

        try {
            DB::beginTransaction();

            $lockRow = DB::selectOne('SELECT GET_LOCK(?, 10) AS lock_status', [$lockName]);
            $lockAcquired = isset($lockRow->lock_status) && (int) $lockRow->lock_status === 1;
            if (!$lockAcquired) {
                throw new \RuntimeException('Unable to acquire supplier PO lock.');
            }

            $yearFull = (int) date('Y');
            $yearTwo  = date('y');

            $maxNo = DB::table('supplier_po_main')
                ->whereYear('created_at', $yearFull)
                ->lockForUpdate()
                ->max('po_running_no');

            $runningNo = ((int) $maxNo) + 1;
            $padded    = str_pad((string) $runningNo, 4, '0', STR_PAD_LEFT);
            $refNo     = "POES{$yearTwo}-{$padded}{$creatorCode}";

            $poId = DB::table('supplier_po_main')->insertGetId([
                'project_id'              => $projectId,
                'supplier_id'             => $supplier['id'] ?? null,
                'supplier_name'           => $supplier['company_name'] ?? '',
                'supplier_address'        => $supplier['full_address'] ?? '',
                'supplier_contact_name'   => $supplier['contact_name'] ?? '',
                'supplier_contact_number' => $supplier['contact_number'] ?? '',
                'discount'                => $data['discount'] ?? 0,
                'delivery_charge'         => $data['delivery_charge'] ?? 0,
                'sst_percent'             => $data['sst_percent'] ?? 0,
                'sst_amount'              => $data['sst_amount'] ?? 0,
                'grand_total'             => $data['grand_total'] ?? 0,
                'po_running_no'           => $runningNo,
                'po_ref_no'               => $refNo,
                'created_by'              => $staffId,
                'created_at'              => now(),
            ]);

            DB::table('supplier_po_items')->insert(array_map(
                fn (array $item) => [
                    'po_id'       => $poId,
                    'item_id'     => $item['item_id'] ?? null,
                    'item_name'   => $item['item_name'],
                    'description' => $item['description'] ?? '',
                    'unit'        => $item['unit'] ?? '',
                    'quantity'    => $item['quantity'] ?? 0,
                    'unit_price'  => $item['unit_price'] ?? 0,
                    'line_total'  => $item['line_total'] ?? 0,
                    'created_at'  => now(),
                ],
                $items
            ));

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($lockAcquired) {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to create supplier PO.'], 500);
        }

        if ($lockAcquired) {
            DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
        }

        $this->auditLog->log($request, "Created supplier PO {$refNo} for project {$projectId}");
        return response()->json([
            'status'    => 'success',
            'po_id'     => $poId,
            'po_ref_no' => $refNo,
        ]);
    }

    public function markPurchaseOrderPaid(MarkSupplierPoPaidRequest $request)
    {
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $data        = $request->validated();
        $poId        = (int) $data['po_id'];
        $paymentDate = $data['payment_date'];
        $remarks     = trim((string) ($data['remarks'] ?? ''));

        $statusRemarks = "Paid on {$paymentDate}";
        if ($remarks !== '') {
            $statusRemarks .= " | {$remarks}";
        }

        try {
            $affected = DB::table('supplier_po_main')
                ->where('po_id', $poId)
                ->where('status', '<>', 'Paid')
                ->update([
                    'status'         => 'Paid',
                    'status_remarks' => $statusRemarks,
                    'updated_at'     => now(),
                ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        if ($affected < 1) {
            return response()->json([
                'status'  => 'error',
                'message' => 'PO not found or already marked as paid.',
            ], 404);
        }

        $this->auditLog->log($request, "Marked supplier PO #{$poId} as paid by {$staffCode}");
        return response()->json(['status' => 'success', 'message' => 'Payment marked.']);
    }

    public function destroyPurchaseOrder(Request $request, ?int $poId = null)
    {
        $actorCode = trim((string) $request->session()->get('name_code', ''));
        if ($actorCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $resolvedPoId = $poId ?? (int) $request->input('po_id', 0);
        if ($resolvedPoId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing PO ID.'], 400);
        }

        try {
            DB::beginTransaction();
            DB::table('supplier_po_items')->where('po_id', $resolvedPoId)->delete();
            $deletedMain = DB::table('supplier_po_main')->where('po_id', $resolvedPoId)->delete();

            if ($deletedMain < 1) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'PO not found.'], 404);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        $this->auditLog->log($request, "Supplier PO #{$resolvedPoId} deleted by {$actorCode}");
        return response()->json(['status' => 'success', 'message' => 'PO deleted successfully.']);
    }
}
