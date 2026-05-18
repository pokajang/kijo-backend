<?php

namespace App\Services\Quotes\Crud;

use App\Http\Requests\Quote\StoreSpecialQuoteRequest;
use App\Http\Requests\Quote\UpdateSpecialQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialQuoteService
{
    use SharedQuoteCrudHelpers;

    public function __construct(private AuditLogService $auditLog) {}

    public function showSpecial(Request $request, int $id): JsonResponse
    {
        $quote = DB::table('quotes_special')
            ->where('id', $id)
            ->selectRaw('
                id,
                client_id as clientId,
                client_name as clientName,
                client_ssm as clientSsm,
                client_address as clientAddress,
                client_city as clientCity,
                client_state as clientState,
                client_zip as clientZip,
                pic_name as picName,
                pic_email as picEmail,
                pic_phone as picPhone,
                pic_position as picPosition,
                sp_id as spId,
                service_title as serviceTitle,
                service_code as serviceCode,
                general_remarks as generalRemarks,
                sst_percent as sstPercent,
                sst_amount as sstAmount,
                discount,
                sub_total as subTotal,
                grand_total as grandTotal,
                price_exception_request_id as priceExceptionRequestId,
                attach_proposal as attachProposal,
                proposal_language as proposalLanguage
            ')
            ->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $lineItems = DB::table('quotes_special_items')
            ->where('quote_id', $id)
            ->selectRaw('
                id as itemId,
                service_id as spId,
                line_item_title as title,
                description,
                unit,
                unit_price as unitPrice,
                quantity,
                line_total as amount,
                created_by as createdBy,
                created_at as createdAt,
                updated_at as updatedAt
            ')
            ->orderBy('id')
            ->get();

        $quote->lineItems = $lineItems;

        return response()->json(['status' => 'success', 'data' => $quote]);
    }


    public function storeSpecial(StoreSpecialQuoteRequest $request): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data      = $request->validated();
        $lineItems = $this->normalizeSpecialItems($data['line_items'] ?? []);
        $itemsSubtotal = array_sum(array_map(fn ($item) => (float) ($item['total_price'] ?? 0), $lineItems));
        $discount = (float) ($data['discount'] ?? 0);
        $sstPercent = (float) ($data['sst_percent'] ?? 0);
        $subtotal = max(0, $itemsSubtotal - $discount);
        $sstAmount = round($subtotal * $sstPercent / 100, 2);
        $grandTotal = round($subtotal + $sstAmount, 2);
        $table     = 'quotes_special';
        $type      = 'special';
        $lockName  = "quotes_{$type}_" . date('Y');
        $prefix    = 'QSS' . date('y') . '-%';

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
            $refNo = 'QSS' . date('y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT) . $nameCode;

            $quoteId = DB::table($table)->insertGetId([
                'service_group'    => 'special',
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
                'sp_id'           => $data['sp_id'] ?? null,
                'service_title'   => $data['service_title'],
                'service_code'    => $data['service_code'],
                'general_remarks' => $data['general_remarks'] ?? null,
                ...(Schema::hasColumn('quotes_special', 'discount') ? ['discount' => $discount] : []),
                'sst_percent'     => $sstPercent,
                'sst_amount'      => $sstAmount,
                'sub_total'       => round($subtotal, 2),
                'grand_total'     => $grandTotal,
                'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'proposal_language' => $this->normalizeProposalLanguage($data['proposal_language'] ?? 'en'),
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
            foreach ($lineItems as $item) {
                $lineInserts[] = [
                    'quote_id'        => $quoteId,
                    'service_id'      => $data['sp_id'] ?? null,
                    'line_item_title' => $item['item_name'],
                    'description'     => $item['description'] ?? null,
                    'unit'            => $item['unit'] ?? null,
                    'unit_price'      => (float) $item['unit_price'],
                    'quantity'        => (int) $item['quantity'],
                    'line_total'      => (float) $item['total_price'],
                    'created_by'      => $staffId,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
            DB::table('quotes_special_items')->insert($lineInserts);

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

        $this->auditLog->log($request, "Created special quote {$refNo} (ID #{$quoteId})");

        return response()->json([
            'status'  => 'success',
            'message' => 'Special quote created successfully.',
            'quote_id'     => $quoteId,
            'quote_ref_no' => $refNo,
            'data'    => [
                'quote_id'     => $quoteId,
                'quote_ref_no' => $refNo,
            ],
        ]);
    }


    public function updateSpecial(UpdateSpecialQuoteRequest $request, int $id): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $quote = DB::table('quotes_special')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $data       = $request->validated();
        $lineItems  = $this->normalizeSpecialItems($data['line_items'] ?? []);
        $isRevision = $request->boolean('isRevision');
        $itemsSubtotal = array_sum(array_map(fn ($item) => (float) ($item['total_price'] ?? 0), $lineItems));
        $discount = (float) ($data['discount'] ?? 0);
        $sstPercent = (float) ($data['sst_percent'] ?? 0);
        $subtotal = max(0, $itemsSubtotal - $discount);
        $sstAmount = round($subtotal * $sstPercent / 100, 2);
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
            'sp_id'           => $data['sp_id'] ?? null,
            'service_title'   => $data['service_title'],
            'service_code'    => $data['service_code'],
            'general_remarks' => $data['general_remarks'] ?? null,
            ...(Schema::hasColumn('quotes_special', 'discount') ? ['discount' => $discount] : []),
            'sst_percent'     => $sstPercent,
            'sst_amount'      => $sstAmount,
            'sub_total'       => round($subtotal, 2),
            'grand_total'     => $grandTotal,
            'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
            'proposal_language' => $this->normalizeProposalLanguage($data['proposal_language'] ?? ($quote->proposal_language ?? 'en')),
            'updated_at'      => now(),
        ];

        try {
            DB::beginTransaction();
            $priceException = $this->approvedPriceException($request, 'special', $id);
            if ($priceException) {
                $updates['discount'] = (float) $priceException->approved_discount_amount;
                $revisedSubtotal = max(0, $itemsSubtotal - $updates['discount']);
                $updates['sst_amount'] = round($revisedSubtotal * $sstPercent / 100, 2);
                $updates['sub_total'] = round($revisedSubtotal, 2);
                $updates['grand_total'] = round($revisedSubtotal + $updates['sst_amount'], 2);
                if (Schema::hasColumn('quotes_special', 'price_exception_request_id')) {
                    $updates['price_exception_request_id'] = $priceException->id;
                }
            }

            if ($isRevision) {
                $updates['revision_no'] = DB::table('quotes_special')->where('id', $id)->lockForUpdate()->value('revision_no') + 1;
            }

            DB::table('quotes_special')->where('id', $id)->update($updates);
            $this->markPriceExceptionUsed($priceException, $id);
            DB::table('quotes_special_items')->where('quote_id', $id)->delete();

            $lineInserts = [];
            foreach ($lineItems as $item) {
                $lineInserts[] = [
                    'quote_id'        => $id,
                    'service_id'      => $data['sp_id'] ?? null,
                    'line_item_title' => $item['item_name'],
                    'description'     => $item['description'] ?? null,
                    'unit'            => $item['unit'] ?? null,
                    'unit_price'      => (float) $item['unit_price'],
                    'quantity'        => (int) $item['quantity'],
                    'line_total'      => (float) $item['total_price'],
                    'created_by'      => $staffId,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
            DB::table('quotes_special_items')->insert($lineInserts);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated special quote ID #{$id} by {$nameCode}" . ($isRevision ? ' (revision)' : ''));

        return response()->json([
            'status'  => 'success',
            'message' => 'Special quote updated successfully.',
            'data'    => [
                'revision_no' => $updates['revision_no'] ?? $quote->revision_no,
            ],
        ]);
    }


    private function normalizeSpecialItems(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $title = trim((string) ($item['item_name'] ?? $item['title'] ?? ''));
            $qty = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineTotal = $item['line_total'] ?? $item['total_price'] ?? ($qty * $unitPrice);
            return [
                'item_name' => $title,
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => (float) $lineTotal,
            ];
        }, $items));
    }

}
