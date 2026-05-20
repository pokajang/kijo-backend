<?php

namespace App\Services\Quotes\Crud;

use App\Http\Requests\Quote\StoreManpowerQuoteRequest;
use App\Http\Requests\Quote\UpdateManpowerQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManpowerQuoteService
{
    use SharedQuoteCrudHelpers;

    public function __construct(private AuditLogService $auditLog) {}

    public function showManpower(Request $request, int $id): JsonResponse
    {
        $quote = DB::table('quotes_manpower')
            ->where('id', $id)
            ->selectRaw('
                *,
                nature_of_work as scope,
                no_of_pax as quantity
            ')
            ->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $quote]);
    }


    public function storeManpower(StoreManpowerQuoteRequest $request): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data     = $request->validated();
        $table    = 'quotes_manpower';
        $type     = 'manpower';
        $lockName = "quotes_{$type}_" . date('Y');
        $prefix   = 'QMS' . date('y') . '-%';

        $quoteId = null;
        $refNo   = null;

        DB::beginTransaction();
        try {
            $priceException = $this->approvedPriceException($request, 'manpower', 0);
            $manpowerTotals = $this->manpowerTotals($data, $priceException);

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
            $refNo = 'QMS' . date('y') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT) . $nameCode;

            $insert = [
                'service_group'    => 'manpower',
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
                'mp_id'           => $data['mp_id'] ?? null,
                'service_title'   => $data['service_title'],
                'service_code'    => $data['service_code'],
                'manpower_rate_type' => $data['manpower_rate_type'] ?? null,
                'billing_unit'    => $data['billing_unit'] ?? 'month',
                'duration_hours'  => $this->nd($data['duration_hours'] ?? null),
                'requires_management_approval' => !empty($data['requires_management_approval']) ? 1 : 0,
                'nature_of_work'  => $data['nature_of_work'] ?? null,
                'site_location'   => $data['site_location'] ?? null,
                'duration_months' => $this->nd($data['duration_months'] ?? null),
                'no_of_pax'       => $data['no_of_pax'] ?? null,
                'unit_cost'       => $this->nd($data['unit_cost'] ?? null),
                'discount'        => $manpowerTotals['discount'],
                'sst_percent'     => $this->nd($data['sst_percent'] ?? null),
                'sst_amount'      => $manpowerTotals['sst_amount'],
                'sub_total'       => $manpowerTotals['sub_total'],
                'grand_total'     => $manpowerTotals['grand_total'],
                'inquiry_remarks' => $data['inquiry_remarks'] ?? null,
                'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'status'          => 'Open',
                'revision_no'     => 0,
                'created_by_id'   => $staffId,
                'created_by_name' => (string) $request->session()->get('full_name', ''),
                'created_by_code' => $nameCode,
                'quote_ref_no'    => $refNo,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            if (Schema::hasColumn($table, 'price_exception_request_id')) {
                $insert['price_exception_request_id'] = $priceException?->id;
            }
            if (Schema::hasColumn($table, 'proposal_language')) {
                $insert['proposal_language'] = $this->normalizeProposalLanguage($data['proposal_language'] ?? 'en');
            }

            $quoteId = DB::table($table)->insertGetId($insert);
            $this->markPriceExceptionUsed($priceException, $quoteId);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        } finally {
            DB::select('DO RELEASE_LOCK(?)', [$lockName]);
        }

        $this->auditLog->log($request, "Created manpower quote {$refNo} (ID #{$quoteId})");

        return response()->json([
            'status'  => 'success',
            'message' => 'Manpower quote created successfully.',
            'quote_id'      => $quoteId,
            'quote_ref_no'  => $refNo,
            'data'    => [
                'quote_id'     => $quoteId,
                'quote_ref_no' => $refNo,
            ],
        ]);
    }


    public function updateManpower(UpdateManpowerQuoteRequest $request, int $id): JsonResponse
    {
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $quote = DB::table('quotes_manpower')->where('id', $id)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $data       = $request->validated();
        $isRevision = $request->boolean('isRevision');

        try {
            DB::beginTransaction();
            $priceException = $this->approvedPriceException($request, 'manpower', $id);
            $manpowerTotals = $this->manpowerTotals($data, $priceException);

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
                'mp_id'           => $data['mp_id'] ?? null,
                'service_title'   => $data['service_title'],
                'service_code'    => $data['service_code'],
                'manpower_rate_type' => $data['manpower_rate_type'] ?? null,
                'billing_unit'    => $data['billing_unit'] ?? 'month',
                'duration_hours'  => $this->nd($data['duration_hours'] ?? null),
                'requires_management_approval' => !empty($data['requires_management_approval']) ? 1 : 0,
                'nature_of_work'  => $data['nature_of_work'] ?? null,
                'site_location'   => $data['site_location'] ?? null,
                'duration_months' => $this->nd($data['duration_months'] ?? null),
                'no_of_pax'       => $data['no_of_pax'] ?? null,
                'unit_cost'       => $this->nd($data['unit_cost'] ?? null),
                'discount'        => $manpowerTotals['discount'],
                'sst_percent'     => $this->nd($data['sst_percent'] ?? null),
                'sst_amount'      => $manpowerTotals['sst_amount'],
                'sub_total'       => $manpowerTotals['sub_total'],
                'grand_total'     => $manpowerTotals['grand_total'],
                'inquiry_remarks' => $data['inquiry_remarks'] ?? null,
                'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'updated_at'      => now(),
            ];

            if (Schema::hasColumn('quotes_manpower', 'price_exception_request_id')) {
                $updates['price_exception_request_id'] = $priceException?->id ?? $quote->price_exception_request_id ?? null;
            }
            if (Schema::hasColumn('quotes_manpower', 'proposal_language')) {
                $updates['proposal_language'] = $this->normalizeProposalLanguage($data['proposal_language'] ?? ($quote->proposal_language ?? 'en'));
            }

            if ($isRevision) {
                $updates['revision_no'] = DB::table('quotes_manpower')->where('id', $id)->lockForUpdate()->value('revision_no') + 1;
            }

            DB::table('quotes_manpower')->where('id', $id)->update($updates);
            $this->markPriceExceptionUsed($priceException, $id);

            DB::commit();
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated manpower quote ID #{$id} by {$nameCode}" . ($isRevision ? ' (revision)' : ''));

        return response()->json([
            'status'  => 'success',
            'message' => 'Manpower quote updated successfully.',
            'data'    => [
                'revision_no' => $updates['revision_no'] ?? $quote->revision_no,
            ],
        ]);
    }

    private function manpowerTotals(array $data, ?object $priceException): array
    {
        $quantity = ($data['billing_unit'] ?? 'month') === 'hour'
            ? (float) ($data['duration_hours'] ?? 0)
            : (float) ($data['duration_months'] ?? 0);
        $gross = (float) ($data['unit_cost'] ?? 0) * (float) ($data['no_of_pax'] ?? 0) * $quantity;
        $discount = $priceException ? (float) $priceException->approved_discount_amount : (float) ($data['discount'] ?? 0);
        $subtotal = max(0, $gross - $discount);
        $sstAmount = round($subtotal * (float) ($data['sst_percent'] ?? 0) / 100, 2);

        return [
            'discount' => round($discount, 2),
            'sst_amount' => $sstAmount,
            'sub_total' => round($subtotal, 2),
            'grand_total' => round($subtotal + $sstAmount, 2),
        ];
    }

}
