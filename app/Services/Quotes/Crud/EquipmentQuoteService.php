<?php

namespace App\Services\Quotes\Crud;

use App\Http\Requests\Quote\StoreEquipmentQuoteRequest;
use App\Http\Requests\Quote\UpdateEquipmentQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EquipmentQuoteService
{
    use SharedQuoteCrudHelpers;

    public function __construct(private AuditLogService $auditLog) {}

    public function showEquipment(Request $request, int $id): JsonResponse
    {
        $quote = DB::table('quotes_equipment')->where('id', $id)->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $items = DB::table('quotes_equipment_items as qei')
            ->leftJoin('catalog_items as ci', 'ci.id', '=', 'qei.item_id')
            ->where('qei.quote_id', $id)
            ->select([
                'qei.item_id',
                'qei.unit_price',
                'qei.quantity',
                'qei.marked_up_price',
                'qei.line_total',
                'ci.item_name',
                'ci.description',
                'ci.unit',
                'ci.supplier_name',
                'ci.supplier_price',
            ])
            ->orderBy('qei.id')
            ->get();

        $quote->items = $items;
        $quote->subtotal = $quote->sub_total ?? null;

        return response()->json(['status' => 'success', 'data' => $quote]);
    }


    public function storeEquipment(StoreEquipmentQuoteRequest $request): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data  = $request->validated();
        $items = $this->normalizeEquipmentItems($data['items'] ?? []);

        // Calculate totals server-side
        $itemsSubtotal  = array_sum(array_map(fn ($item) => (float) ($item['line_total'] ?? 0), $items));
        $deliveryCharge = (float) ($data['delivery_charge'] ?? 0);
        $miscCharge     = (float) ($data['misc_charge'] ?? 0);
        $discount       = (float) ($data['discount'] ?? 0);
        $sstPercent     = (float) ($data['sst_percent'] ?? 0);

        $subtotal   = $itemsSubtotal + $deliveryCharge + $miscCharge - $discount;
        $sstAmount  = round($subtotal * $sstPercent / 100, 2);
        $grandTotal = round($subtotal + $sstAmount, 2);

        $table      = 'quotes_equipment';
        $prefixCode = 'ES';
        $type       = 'equipment';
        $lockName   = "quotes_{$type}_" . date('Y');
        $prefix     = 'QES' . date('y') . '-%';

        $quoteId = null;
        $refNo   = null;

        DB::beginTransaction();
        try {
            $lockResult = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (!$lockResult || !$lockResult->acquired) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Could not acquire lock. Please retry.'], 503);
            }

            $row  = DB::selectOne(
                "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(quote_ref_no, '-', -1), ?, 1) AS UNSIGNED)) AS max_run FROM {$table} WHERE quote_ref_no LIKE ?",
                [$nameCode, $prefix]
            );
            $next  = (($row->max_run ?? 0) ?: 0) + 1;
            $refNo = 'Q' . $prefixCode . date('y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT) . $nameCode;

            $quoteId = DB::table($table)->insertGetId([
                'service_group'    => 'equipment',
                'quote_running_no' => $next,
                'client_id'       => $data['client_id'],
                'client_name'     => $data['client_name'],
                'client_ssm'      => $data['client_ssm'] ?? null,
                'client_address'  => $data['client_address'],
                'client_city'     => $data['client_city'] ?? null,
                'client_state'    => $data['client_state'] ?? null,
                'client_zip'      => $data['client_zip'] ?? null,
                'pic_name'        => $data['pic_name'],
                'pic_email'       => $data['pic_email'],
                'pic_phone'       => $data['pic_phone'],
                'pic_position'    => $data['pic_position'],
                'delivery_charge' => $deliveryCharge,
                'misc_charge'     => $miscCharge,
                'discount'        => $discount,
                'sst_percent'     => $sstPercent,
                'sst_amount'      => $sstAmount,
                'sub_total'       => round($subtotal, 2),
                'grand_total'     => $grandTotal,
                'status'          => 'Open',
                'revision_no'     => 0,
                'created_by_id'   => $staffId,
                'created_by_name' => (string) $request->session()->get('full_name', ''),
                'created_by_code' => $nameCode,
                'quote_ref_no'    => $refNo,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $lineInserts = [];
            foreach ($items as $item) {
                $lineInserts[] = [
                    'quote_id'       => $quoteId,
                    'item_id'        => (int) $item['item_id'],
                    'quantity'       => (int) $item['quantity'],
                    'unit_price'     => (float) $item['unit_price'],
                    'marked_up_price'=> (float) $item['marked_up_price'],
                    'line_total'     => (float) $item['line_total'],
                    'created_by'     => $staffId,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }
            DB::table('quotes_equipment_items')->insert($lineInserts);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        } finally {
            DB::select('DO RELEASE_LOCK(?)', [$lockName]);
        }

        $this->auditLog->log($request, "Created equipment quote {$refNo} (ID #{$quoteId})");

        return response()->json([
            'status'        => 'success',
            'message'       => 'Equipment quote created successfully.',
            'quote_id'      => $quoteId,
            'quote_ref_no'  => $refNo,
            'data'          => [
                'quote_id'     => $quoteId,
                'quote_ref_no' => $refNo,
            ],
        ]);
    }


    public function updateEquipment(UpdateEquipmentQuoteRequest $request, int $id): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $quote = DB::table('quotes_equipment')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $data  = $request->validated();
        $items = $this->normalizeEquipmentItems($data['items'] ?? []);

        $isRevision     = $request->boolean('isRevision');
        $itemsSubtotal  = array_sum(array_map(fn ($item) => (float) ($item['line_total'] ?? 0), $items));
        $deliveryCharge = (float) ($data['delivery_charge'] ?? 0);
        $miscCharge     = (float) ($data['misc_charge'] ?? 0);
        $discount       = (float) ($data['discount'] ?? 0);
        $sstPercent     = (float) ($data['sst_percent'] ?? 0);

        $subtotal   = $itemsSubtotal + $deliveryCharge + $miscCharge - $discount;
        $sstAmount  = round($subtotal * $sstPercent / 100, 2);
        $grandTotal = round($subtotal + $sstAmount, 2);

        $updates = [
            'client_id'       => $data['client_id'],
            'client_name'     => $data['client_name'],
            'client_ssm'      => $data['client_ssm'] ?? null,
            'client_address'  => $data['client_address'],
            'client_city'     => $data['client_city'] ?? null,
            'client_state'    => $data['client_state'] ?? null,
            'client_zip'      => $data['client_zip'] ?? null,
            'pic_name'        => $data['pic_name'],
            'pic_email'       => $data['pic_email'],
            'pic_phone'       => $data['pic_phone'],
            'pic_position'    => $data['pic_position'],
            'delivery_charge' => $deliveryCharge,
            'misc_charge'     => $miscCharge,
            'discount'        => $discount,
            'sst_percent'     => $sstPercent,
            'sst_amount'      => $sstAmount,
            'sub_total'       => round($subtotal, 2),
            'grand_total'     => $grandTotal,
            'updated_at'      => now(),
        ];

        try {
            DB::beginTransaction();
            $priceException = $this->approvedPriceException($request, 'equipment', $id);
            if ($priceException) {
                $updates['discount'] = (float) $priceException->approved_discount_amount;
                $revisedSubtotal = $itemsSubtotal + $deliveryCharge + $miscCharge - $updates['discount'];
                $updates['sst_amount'] = round($revisedSubtotal * $sstPercent / 100, 2);
                $updates['sub_total'] = round($revisedSubtotal, 2);
                $updates['grand_total'] = round($revisedSubtotal + $updates['sst_amount'], 2);
                if (Schema::hasColumn('quotes_equipment', 'price_exception_request_id')) {
                    $updates['price_exception_request_id'] = $priceException->id;
                }
            }

            if ($isRevision) {
                $updates['revision_no'] = DB::table('quotes_equipment')->where('id', $id)->lockForUpdate()->value('revision_no') + 1;
            }

            DB::table('quotes_equipment')->where('id', $id)->update($updates);
            $this->markPriceExceptionUsed($priceException, $id);
            DB::table('quotes_equipment_items')->where('quote_id', $id)->delete();

            $lineInserts = [];
            foreach ($items as $item) {
                $lineInserts[] = [
                    'quote_id'       => $id,
                    'item_id'        => (int) $item['item_id'],
                    'quantity'       => (int) $item['quantity'],
                    'unit_price'     => (float) $item['unit_price'],
                    'marked_up_price'=> (float) $item['marked_up_price'],
                    'line_total'     => (float) $item['line_total'],
                    'created_by'     => $staffId,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ];
            }
            DB::table('quotes_equipment_items')->insert($lineInserts);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated equipment quote ID #{$id} by {$nameCode}" . ($isRevision ? ' (revision)' : ''));

        return response()->json([
            'status'  => 'success',
            'message' => 'Equipment quote updated successfully.',
            'quote_ref_no' => $quote->quote_ref_no ?? null,
            'data'    => [
                'revision_no' => $updates['revision_no'] ?? $quote->revision_no,
            ],
        ]);
    }


    private function normalizeEquipmentItems(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $itemId = (int) ($item['item_id'] ?? $item['catalog_item_id'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $markedUp = (float) ($item['marked_up_price'] ?? $unitPrice);
            $lineTotal = $item['line_total'] ?? $item['total_price'] ?? ($qty * $markedUp);
            return [
                'item_id' => $itemId,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'marked_up_price' => $markedUp,
                'line_total' => (float) $lineTotal,
            ];
        }, $items));
    }

}
