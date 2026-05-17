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

class CatalogItemService extends CatalogBaseService
{

    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $q       = trim((string) $request->query('q', ''));

        $query = DB::table('catalog_items')
            ->select([
                'id',
                'item_name',
                'category_id',
                'description',
                'unit',
                'supplier_name',
                'supplier_price',
                'price_date',
                'remarks',
                'brochure_filename',
                'created_by_code',
                'created_at',
            ])
            ->orderByDesc('created_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('item_name', 'like', "%{$q}%")
                    ->orWhere('category_id', 'like', "%{$q}%")
                    ->orWhere('supplier_name', 'like', "%{$q}%");
            });
        }
        $year = (int) $request->query('year', 0);
        if ($year >= 2000 && $year <= 2100) {
            $query->whereRaw('YEAR(COALESCE(price_date, created_at)) = ?', [$year]);
        }

        $paginator = $query->paginate($perPage);

        $items = array_map(fn ($row) => $this->normalizeCatalogRow($row), $paginator->items());

        return response()->json([
            'status'     => 'success',
            'data'       => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(int $id)
    {
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid item ID.'], 400);
        }

        $item = DB::table('catalog_items')
            ->select([
                'id',
                'item_name',
                'category_id',
                'description',
                'unit',
                'supplier_name',
                'supplier_price',
                'price_date',
                'remarks',
                'brochure_filename',
                'created_by_code',
                'created_at',
            ])
            ->where('id', $id)
            ->first();

        if (!$item) {
            return response()->json(['status' => 'error', 'message' => 'Catalog item not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $this->normalizeCatalogRow($item),
        ]);
    }

    public function store(StoreCatalogItemRequest $request)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $data     = $request->validated();
        $remarks  = $data['entry_remarks'] ?? ($data['remarks'] ?? null);
        $filename = null;

        if ($request->hasFile('image')) {
            $filename = $this->storeCatalogFile($request->file('image'));
        }

        try {
            $id = DB::table('catalog_items')->insertGetId([
                'item_name'       => $data['item_name'],
                'category_id'     => $data['category_id'],
                'description'     => $data['description'] ?? null,
                'unit'            => $data['unit'] ?? '',
                'supplier_name'   => $data['supplier_name'] ?? null,
                'supplier_price'  => $data['supplier_price'] ?? 0,
                'price_date'      => $data['price_date'] ?? null,
                'remarks'         => $remarks,
                'brochure_filename' => $filename,
                'created_by_id'   => $staffId,
                'created_by_code' => $staffCode,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            if ($filename !== null) {
                $this->deleteCatalogFile($filename);
            }
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        $this->auditLog->log($request, "New catalog item \"{$data['item_name']}\" created with ID #{$id}");
        return response()->json([
            'status'  => 'success',
            'message' => 'Catalog item created successfully.',
            'id'      => $id,
        ]);
    }

    public function update(UpdateCatalogItemRequest $request, ?int $id = null)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        if ($staffId <= 0 || $staffCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $resolvedId = $id ?? (int) $request->query('id', 0);
        if ($resolvedId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing item ID.'], 400);
        }

        $item = DB::table('catalog_items')->where('id', $resolvedId)->first();
        if (!$item) {
            return response()->json(['status' => 'error', 'message' => 'Catalog item not found.'], 404);
        }

        $data     = $request->validated();
        $remarks  = $data['remarks'] ?? ($data['entry_remarks'] ?? null);

        $removeBrochure = in_array((string) $request->input('remove_brochure', '0'), ['1', 'true'], true);

        $upload = $request->file('new_brochure') ?: $request->file('image');
        $newFilename = null;

        if ($upload instanceof UploadedFile) {
            $newFilename = $this->storeCatalogFile($upload);
        }

        $updates = [
            'item_name'       => $data['item_name'],
            'category_id'     => $data['category_id'],
            'description'     => $data['description'] ?? null,
            'unit'            => $data['unit'] ?? '',
            'supplier_name'   => $data['supplier_name'] ?? null,
            'supplier_price'  => $data['supplier_price'] ?? 0,
            'price_date'      => $data['price_date'] ?? null,
            'remarks'         => $remarks,
            'updated_by_id'   => $staffId,
            'updated_by_code' => $staffCode,
            'updated_at'      => now(),
        ];

        if ($newFilename !== null) {
            $updates['brochure_filename'] = $newFilename;
        } elseif ($removeBrochure) {
            $updates['brochure_filename'] = null;
        }

        try {
            DB::table('catalog_items')->where('id', $resolvedId)->update($updates);
        } catch (\Throwable $e) {
            report($e);
            if ($newFilename !== null) {
                $this->deleteCatalogFile($newFilename);
            }
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        $oldFilename = $item->brochure_filename ?: null;
        if ($oldFilename !== null) {
            if ($newFilename !== null || ($removeBrochure && $newFilename === null)) {
                $this->deleteCatalogFile($oldFilename);
            }
        }

        $updatedItem = DB::table('catalog_items')
            ->select([
                'id',
                'item_name',
                'category_id',
                'description',
                'unit',
                'supplier_name',
                'supplier_price',
                'price_date',
                'remarks',
                'brochure_filename',
                'created_by_code',
                'created_at',
            ])
            ->where('id', $resolvedId)
            ->first();

        $this->auditLog->log($request, "Catalog item ID #{$resolvedId} updated by {$staffCode}");
        return response()->json([
            'status'  => 'success',
            'message' => 'Item updated successfully.',
            'data'    => $updatedItem ? $this->normalizeCatalogRow($updatedItem) : null,
        ]);
    }

    public function destroy(Request $request, ?int $id = null)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $resolvedId = $id ?? (int) $request->query('id', 0);
        if ($resolvedId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid ID'], 400);
        }

        $item = DB::table('catalog_items')->where('id', $resolvedId)->first();
        if (!$item) {
            return response()->json(['status' => 'error', 'message' => 'Catalog item not found.'], 404);
        }

        try {
            DB::table('catalog_items')->where('id', $resolvedId)->delete();
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        if (!empty($item->brochure_filename)) {
            $this->deleteCatalogFile((string) $item->brochure_filename);
        }

        $this->auditLog->log($request, "Catalog item ID #{$resolvedId} deleted by staff #{$staffId}");
        return response()->json(['status' => 'success', 'message' => 'Item deleted successfully.']);
    }
}
