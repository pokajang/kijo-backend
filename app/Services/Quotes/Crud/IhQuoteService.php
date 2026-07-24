<?php

namespace App\Services\Quotes\Crud;

use App\Http\Requests\Quote\StoreIhQuoteRequest;
use App\Http\Requests\Quote\UpdateIhQuoteRequest;
use App\Services\AuditLogService;
use App\Services\Quotes\Pricing\IhPricingCalculator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IhQuoteService
{
    use SharedQuoteCrudHelpers;

    public function __construct(
        private AuditLogService $auditLog,
        private IhPricingCalculator $pricingCalculator,
    ) {}

    public function showIh(Request $request, int $id): JsonResponse
    {
        $quote = DB::table('quotes_ih')->where('id', $id)->first();

        if (! $quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $quote->hygiene_items = $this->ihItems($id);
        $quote->pricing_rule_version = $this->quotePricingRule($quote);
        $quote->complexity_rating = $this->quoteComplexityRating($quote);
        $quote->complexity_multiplier = $this->pricingCalculator->multiplierFor(
            $quote->complexity_rating,
        );

        return response()->json(['status' => 'success', 'data' => $quote]);
    }

    public function storeIh(StoreIhQuoteRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $lineItems = $this->normalizeIhItems($data['hygiene_items'] ?? []);
        $pricingRule = IhPricingCalculator::STANDARD_RULE;
        $totals = $this->pricingCalculator->calculate($data, $lineItems, $pricingRule);
        $table = 'quotes_ih';
        $type = 'ih';
        $lockName = "quotes_{$type}_".date('Y');
        $prefix = 'QIH'.date('y').'-%';

        $quoteId = null;
        $refNo = null;

        DB::beginTransaction();
        try {
            $lockResult = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (! $lockResult || ! $lockResult->acquired) {
                DB::rollBack();

                return response()->json(['status' => 'error', 'message' => 'Could not acquire lock. Please retry.'], 503);
            }

            $row = DB::selectOne(
                "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(quote_ref_no, '-', -1), ?, 1) AS UNSIGNED)) AS max_run FROM {$table} WHERE quote_ref_no LIKE ?",
                [$nameCode, $prefix]
            );
            $next = (($row->max_run ?? 0) ?: 0) + 1;
            $refNo = 'QIH'.date('y').'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT).$nameCode;

            $insert = [
                'service_group' => 'ih',
                'quote_running_no' => $next,
                'client_id' => $data['client_id'],
                'client_name' => $data['client_name'],
                'client_ssm' => $data['client_ssm'] ?? '',
                'client_address' => $data['client_address'],
                'client_city' => $data['client_city'] ?? '',
                'client_state' => $data['client_state'] ?? '',
                'client_zip' => $data['client_zip'] ?? '',
                'pic_name' => $data['pic_name'],
                'pic_email' => $data['pic_email'],
                'pic_phone' => $data['pic_phone'],
                'pic_position' => $data['pic_position'],
                'service_id' => $data['service_id'] ?? null,
                'service_title' => $data['service_title'],
                'service_code' => $data['service_code'],
                'site_address' => $data['site_address'] ?? '',
                'travel_charge' => $this->nd($data['travel_charge'] ?? null),
                'sample_counts' => $this->nd($data['sample_counts'] ?? null),
                'sample_unit' => $data['sample_unit'] ?? 'sample(s)',
                'num_work_units' => $this->nd($data['num_work_units'] ?? null),
                'unit_price' => $this->nd($data['unit_price'] ?? null),
                'discount' => $totals['discount'],
                'sst_percent' => $totals['sst_percent'],
                'sst_amount' => $totals['sst_amount'],
                'sub_total' => $totals['sub_total'],
                'grand_total' => $totals['grand_total'],
                ...(Schema::hasColumn($table, 'pricing_rule_version')
                    ? ['pricing_rule_version' => $pricingRule]
                    : []),
                ...(Schema::hasColumn($table, 'estimated_total_cost')
                    ? ['estimated_total_cost' => $this->nd($data['estimated_total_cost'] ?? null)]
                    : []),
                ...(Schema::hasColumn($table, 'traffic_light_rule_version')
                    ? ['traffic_light_rule_version' => $data['traffic_light_rule_version'] ?? 'v1']
                    : []),
                'inquiry_remarks' => $data['inquiry_remarks'] ?? null,
                'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
                'status' => 'Open',
                'revision_no' => 0,
                'created_by_id' => $staffId,
                'created_by_name' => (string) $request->session()->get('full_name', ''),
                'created_by_code' => $nameCode,
                'quote_ref_no' => $refNo,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn($table, 'proposal_language')) {
                $insert['proposal_language'] = $this->normalizeProposalLanguage($data['proposal_language'] ?? 'en');
            }

            $quoteId = DB::table($table)->insertGetId($insert);
            $this->replaceIhItems($quoteId, $lineItems);

            DB::commit();
        } catch (HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
        }

        $this->auditLog->log($request, "Created IH quote {$refNo} (ID #{$quoteId})");

        return response()->json([
            'status' => 'success',
            'message' => 'IH quote created successfully.',
            'quote_id' => $quoteId,
            'quote_ref_no' => $refNo,
            'data' => [
                'quote_id' => $quoteId,
                'quote_ref_no' => $refNo,
            ],
        ]);
    }

    public function updateIh(UpdateIhQuoteRequest $request, int $id): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = trim((string) $request->session()->get('name_code', ''));

        if ($staffId <= 0 || $nameCode === '') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $quote = DB::table('quotes_ih')->where('id', $id)->first();
        if (! $quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $data = $request->validated();
        $pricingRule = $this->quotePricingRule($quote);
        $hasLineItemsPayload = $pricingRule === IhPricingCalculator::STANDARD_RULE
            && $request->exists('hygiene_items');
        $lineItems = $hasLineItemsPayload
            ? $this->normalizeIhItems($data['hygiene_items'] ?? [])
            : $this->existingIhItemsForTotals($id);
        $complexityRating = $this->quoteComplexityRating($quote);
        $pricingChanged = $this->ihPricingInputsChanged($quote, $data);
        $totals = $pricingRule === IhPricingCalculator::LEGACY_RULE && ! $pricingChanged
            ? $this->preservedIhTotals($quote, $pricingRule, $complexityRating)
            : $this->pricingCalculator->calculate(
                $data,
                $lineItems,
                $pricingRule,
                $complexityRating,
            );
        $isRevision = $request->boolean('isRevision');

        $updates = [
            'client_id' => $data['client_id'],
            'client_name' => $data['client_name'],
            'client_ssm' => $data['client_ssm'] ?? '',
            'client_address' => $data['client_address'],
            'client_city' => $data['client_city'] ?? '',
            'client_state' => $data['client_state'] ?? '',
            'client_zip' => $data['client_zip'] ?? '',
            'pic_name' => $data['pic_name'],
            'pic_email' => $data['pic_email'],
            'pic_phone' => $data['pic_phone'],
            'pic_position' => $data['pic_position'],
            'service_id' => $data['service_id'] ?? null,
            'service_title' => $data['service_title'],
            'service_code' => $data['service_code'],
            'site_address' => $data['site_address'] ?? '',
            'travel_charge' => $this->nd($data['travel_charge'] ?? null),
            'sample_counts' => $this->nd($data['sample_counts'] ?? null),
            'sample_unit' => $data['sample_unit'] ?? 'sample(s)',
            'num_work_units' => $this->nd($data['num_work_units'] ?? null),
            'unit_price' => $this->nd($data['unit_price'] ?? null),
            'discount' => $totals['discount'],
            'sst_percent' => $totals['sst_percent'],
            'sst_amount' => $totals['sst_amount'],
            'sub_total' => $totals['sub_total'],
            'grand_total' => $totals['grand_total'],
            ...(Schema::hasColumn('quotes_ih', 'pricing_rule_version')
                ? ['pricing_rule_version' => $pricingRule]
                : []),
            ...(Schema::hasColumn('quotes_ih', 'estimated_total_cost')
                ? ['estimated_total_cost' => $this->nd($data['estimated_total_cost'] ?? null)]
                : []),
            ...(Schema::hasColumn('quotes_ih', 'traffic_light_rule_version')
                ? ['traffic_light_rule_version' => $data['traffic_light_rule_version'] ?? $quote->traffic_light_rule_version ?? 'v1']
                : []),
            'inquiry_remarks' => $data['inquiry_remarks'] ?? null,
            'attach_proposal' => isset($data['attach_proposal']) ? (int) $data['attach_proposal'] : 0,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('quotes_ih', 'proposal_language')) {
            $updates['proposal_language'] = $this->normalizeProposalLanguage($data['proposal_language'] ?? ($quote->proposal_language ?? 'en'));
        }

        try {
            DB::beginTransaction();
            $priceException = $this->approvedPriceException($request, 'ih', $id);
            if ($priceException) {
                $discount = (float) $priceException->approved_discount_amount;
                $grossSubtotal = (float) $totals['gross_subtotal'];
                $taxableTotal = max(0, $grossSubtotal - $discount);
                $sstAmount = round($taxableTotal * (float) $totals['sst_percent'] / 100, 2);
                $updates['discount'] = $discount;
                $updates['sst_amount'] = $sstAmount;
                $updates['sub_total'] = $pricingRule === IhPricingCalculator::LEGACY_RULE
                    ? round($taxableTotal, 2)
                    : round($grossSubtotal, 2);
                $updates['grand_total'] = round($taxableTotal + $sstAmount, 2);
                if (Schema::hasColumn('quotes_ih', 'price_exception_request_id')) {
                    $updates['price_exception_request_id'] = $priceException->id;
                }
            }

            if ($isRevision) {
                $updates['revision_no'] = DB::table('quotes_ih')->where('id', $id)->lockForUpdate()->value('revision_no') + 1;
            }

            if ($decisionResponse = $this->projectValueDecisionResponse($request, 'ih', $id, $quote, (float) $updates['grand_total'])) {
                DB::rollBack();

                return $decisionResponse;
            }

            DB::table('quotes_ih')->where('id', $id)->update($updates);
            $this->markPriceExceptionUsed($priceException, $id);
            if ($hasLineItemsPayload) {
                $this->replaceIhItems($id, $lineItems);
            }

            DB::commit();
        } catch (HttpResponseException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Updated IH quote ID #{$id} by {$nameCode}".($isRevision ? ' (revision)' : ''));

        return response()->json([
            'status' => 'success',
            'message' => 'IH quote updated successfully.',
            'data' => [
                'revision_no' => $updates['revision_no'] ?? $quote->revision_no,
            ],
        ]);
    }

    private function normalizeIhItems(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item, mixed $index): ?array {
            $title = trim((string) ($item['item_description'] ?? $item['item_name'] ?? $item['title'] ?? ''));
            $qty = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? $item['unitPrice'] ?? 0);

            if ($title === '' || $qty <= 0 || $unitPrice <= 0) {
                return null;
            }

            return [
                'item_description' => $title,
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'sort_order' => (int) ($item['sort_order'] ?? $index),
            ];
        }, $items, array_keys($items))));
    }

    private function quotePricingRule(object $quote): string
    {
        if (isset($quote->pricing_rule_version)) {
            return $this->pricingCalculator->normalizeRule($quote->pricing_rule_version);
        }

        return property_exists($quote, 'complexity_rating')
            ? IhPricingCalculator::LEGACY_RULE
            : IhPricingCalculator::STANDARD_RULE;
    }

    private function quoteComplexityRating(object $quote): int
    {
        return max(1, min(5, (int) ($quote->complexity_rating ?? 1)));
    }

    private function ihPricingInputsChanged(object $quote, array $data): bool
    {
        foreach ([
            'sample_counts',
            'num_work_units',
            'unit_price',
            'travel_charge',
            'discount',
            'sst_percent',
        ] as $field) {
            if (abs((float) ($data[$field] ?? 0) - (float) ($quote->{$field} ?? 0)) > 0.00001) {
                return true;
            }
        }

        return false;
    }

    private function preservedIhTotals(
        object $quote,
        string $pricingRule,
        int $complexityRating,
    ): array {
        $discount = max(0, (float) ($quote->discount ?? 0));
        $subTotal = max(0, (float) ($quote->sub_total ?? 0));
        $grossSubtotal = $pricingRule === IhPricingCalculator::LEGACY_RULE
            ? $subTotal + $discount
            : $subTotal;

        return [
            'pricing_rule_version' => $pricingRule,
            'complexity_rating' => $complexityRating,
            'complexity_multiplier' => $this->pricingCalculator->multiplierFor($complexityRating),
            'gross_subtotal' => round($grossSubtotal, 2),
            'discount' => round($discount, 2),
            'sst_percent' => max(0, (float) ($quote->sst_percent ?? 0)),
            'sst_amount' => round(max(0, (float) ($quote->sst_amount ?? 0)), 2),
            'sub_total' => round($subTotal, 2),
            'grand_total' => round(max(0, (float) ($quote->grand_total ?? 0)), 2),
        ];
    }

    private function replaceIhItems(int $quoteId, array $lineItems): void
    {
        if (! Schema::hasTable('quotes_ih_items')) {
            if (! empty($lineItems)) {
                throw new \RuntimeException('Industrial Hygiene additional fee storage is unavailable.');
            }

            return;
        }

        DB::table('quotes_ih_items')->where('quote_id', $quoteId)->delete();

        if (empty($lineItems)) {
            return;
        }

        $now = now();
        DB::table('quotes_ih_items')->insert(array_map(fn (array $item): array => [
            'quote_id' => $quoteId,
            'item_description' => $item['item_description'],
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'],
            'unit_price' => $item['unit_price'],
            'line_total' => $item['line_total'],
            'sort_order' => $item['sort_order'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $lineItems));
    }

    private function ihItems(int $quoteId)
    {
        if (! Schema::hasTable('quotes_ih_items')) {
            return collect();
        }

        return DB::table('quotes_ih_items')
            ->where('quote_id', $quoteId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'quote_id',
                'item_description',
                'description',
                'quantity',
                'unit',
                'unit_price',
                'line_total',
                'sort_order',
            ]);
    }

    private function existingIhItemsForTotals(int $quoteId): array
    {
        $items = $this->ihItems($quoteId);

        if ($items instanceof Collection) {
            return $this->normalizeIhItems($items->map(fn ($item): array => (array) $item)->all());
        }

        return [];
    }
}
