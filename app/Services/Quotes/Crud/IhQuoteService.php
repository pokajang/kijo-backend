<?php

namespace App\Services\Quotes\Crud;

use App\Http\Requests\Quote\StoreIhQuoteRequest;
use App\Http\Requests\Quote\UpdateIhQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IhQuoteService
{
    use SharedQuoteCrudHelpers;

    public function __construct(private AuditLogService $auditLog) {}

    public function showIh(Request $request, int $id): JsonResponse
    {
        $quote = DB::table('quotes_ih')->where('id', $id)->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $quote]);
    }


    public function storeIh(StoreIhQuoteRequest $request): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data     = $request->validated();
        $table    = 'quotes_ih';
        $type     = 'ih';
        $lockName = "quotes_{$type}_" . date('Y');
        $prefix   = 'QIH' . date('y') . '-%';

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
            $refNo = 'QIH' . date('y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT) . $nameCode;

            $quoteId = DB::table($table)->insertGetId([
                'service_group'      => 'ih',
                'quote_running_no'   => $next,
                'client_id'         => $data['client_id'],
                'client_name'       => $data['client_name'],
                'client_ssm'        => $data['client_ssm'] ?? null,
                'client_address'    => $data['client_address'],
                'client_city'       => $data['client_city'] ?? null,
                'client_state'      => $data['client_state'] ?? null,
                'client_zip'        => $data['client_zip'] ?? null,
                'pic_name'          => $data['pic_name'],
                'pic_email'         => $data['pic_email'],
                'pic_phone'         => $data['pic_phone'],
                'pic_position'      => $data['pic_position'],
                'service_id'        => $data['service_id'] ?? null,
                'service_title'     => $data['service_title'],
                'service_code'      => $data['service_code'],
                'site_address'      => $data['site_address'] ?? null,
                'travel_charge'     => $this->nd($data['travel_charge'] ?? null),
                'sample_counts'     => $this->nd($data['sample_counts'] ?? null),
                'sample_unit'       => $data['sample_unit'] ?? null,
                'num_work_units'    => $this->nd($data['num_work_units'] ?? null),
                'unit_price'        => $this->nd($data['unit_price'] ?? null),
                'discount'          => $this->nd($data['discount'] ?? null),
                'sst_percent'       => $this->nd($data['sst_percent'] ?? null),
                'sst_amount'        => $this->nd($data['sst_amount'] ?? null),
                'sub_total'         => $this->nd($data['sub_total'] ?? null),
                'grand_total'       => $this->nd($data['grand_total'] ?? null),
                'inquiry_remarks'   => $data['inquiry_remarks'] ?? null,
                'attach_proposal'   => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'proposal_language' => $this->normalizeProposalLanguage($data['proposal_language'] ?? 'en'),
                'status'            => 'Open',
                'revision_no'       => 0,
                'created_by_id'     => $staffId,
                'created_by_name'   => (string) $request->session()->get('full_name', ''),
                'created_by_code'   => $nameCode,
                'quote_ref_no'      => $refNo,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

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

        $this->auditLog->log($request, "Created IH quote {$refNo} (ID #{$quoteId})");

        return response()->json([
            'status'  => 'success',
            'message' => 'IH quote created successfully.',
            'quote_id'     => $quoteId,
            'quote_ref_no' => $refNo,
            'data'    => [
                'quote_id'     => $quoteId,
                'quote_ref_no' => $refNo,
            ],
        ]);
    }


    public function updateIh(UpdateIhQuoteRequest $request, int $id): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $quote = DB::table('quotes_ih')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $data       = $request->validated();
        $isRevision = $request->boolean('isRevision');

        $updates = [
            'client_id'         => $data['client_id'],
            'client_name'       => $data['client_name'],
            'client_ssm'        => $data['client_ssm'] ?? null,
            'client_address'    => $data['client_address'],
            'client_city'       => $data['client_city'] ?? null,
            'client_state'      => $data['client_state'] ?? null,
            'client_zip'        => $data['client_zip'] ?? null,
            'pic_name'          => $data['pic_name'],
            'pic_email'         => $data['pic_email'],
            'pic_phone'         => $data['pic_phone'],
            'pic_position'      => $data['pic_position'],
            'service_id'        => $data['service_id'] ?? null,
            'service_title'     => $data['service_title'],
            'service_code'      => $data['service_code'],
            'site_address'      => $data['site_address'] ?? null,
            'travel_charge'     => $this->nd($data['travel_charge'] ?? null),
            'sample_counts'     => $this->nd($data['sample_counts'] ?? null),
            'sample_unit'       => $data['sample_unit'] ?? null,
            'num_work_units'    => $this->nd($data['num_work_units'] ?? null),
            'unit_price'        => $this->nd($data['unit_price'] ?? null),
            'discount'          => $this->nd($data['discount'] ?? null),
            'sst_percent'       => $this->nd($data['sst_percent'] ?? null),
            'sst_amount'        => $this->nd($data['sst_amount'] ?? null),
            'sub_total'         => $this->nd($data['sub_total'] ?? null),
            'grand_total'       => $this->nd($data['grand_total'] ?? null),
            'inquiry_remarks'   => $data['inquiry_remarks'] ?? null,
            'attach_proposal'   => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
            'proposal_language' => $this->normalizeProposalLanguage($data['proposal_language'] ?? ($quote->proposal_language ?? 'en')),
            'updated_at'        => now(),
        ];

        try {
            DB::beginTransaction();
            $priceException = $this->approvedPriceException($request, 'ih', $id);
            if ($priceException) {
                $discount = (float) $priceException->approved_discount_amount;
                $grossSubtotal = (float) ($data['sub_total'] ?? 0);
                $taxableTotal = max(0, $grossSubtotal - $discount);
                $sstAmount = round($taxableTotal * (float) ($data['sst_percent'] ?? 0) / 100, 2);
                $updates['discount'] = $discount;
                $updates['sst_amount'] = $sstAmount;
                $updates['grand_total'] = round($taxableTotal + $sstAmount, 2);
                if (Schema::hasColumn('quotes_ih', 'price_exception_request_id')) {
                    $updates['price_exception_request_id'] = $priceException->id;
                }
            }

            if ($isRevision) {
                $updates['revision_no'] = DB::table('quotes_ih')->where('id', $id)->lockForUpdate()->value('revision_no') + 1;
            }

            DB::table('quotes_ih')->where('id', $id)->update($updates);
            $this->markPriceExceptionUsed($priceException, $id);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated IH quote ID #{$id} by {$nameCode}" . ($isRevision ? ' (revision)' : ''));

        return response()->json([
            'status'  => 'success',
            'message' => 'IH quote updated successfully.',
            'data'    => [
                'revision_no' => $updates['revision_no'] ?? $quote->revision_no,
            ],
        ]);
    }

}
