<?php

namespace App\Services\Quotes\Records;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteRecordListingService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function listQuoteRecords(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (!$cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        try {
            $quotes = DB::table($cfg['table'])
                ->orderByRaw("FIELD(status, 'Open', 'Failed', 'Awarded')")
                ->orderByDesc('created_at')
                ->get();

            $quoteIds = $quotes->pluck('id')->map(fn ($v) => (int) $v)->all();

            $itemsByQuote = [];
            if ($service === 'equipment' && !empty($quoteIds)) {
                $rows = DB::table('quotes_equipment_items as qi')
                    ->leftJoin('catalog_items as ci', 'ci.id', '=', 'qi.item_id')
                    ->whereIn('qi.quote_id', $quoteIds)
                    ->select([
                        'qi.id',
                        'qi.quote_id',
                        'qi.item_id',
                        'qi.quantity',
                        'qi.unit_price',
                        'qi.marked_up_price',
                        'qi.line_total',
                        'qi.created_by',
                        'qi.created_at',
                        'qi.updated_at',
                        'ci.item_name',
                        'ci.category_id',
                        'ci.description',
                        'ci.unit',
                    ])
                    ->orderBy('qi.quote_id')
                    ->orderBy('qi.id')
                    ->get();

                foreach ($rows as $row) {
                    $itemsByQuote[(int) $row->quote_id][] = $row;
                }
            }

            if ($service === 'special' && !empty($quoteIds)) {
                $rows = DB::table('quotes_special_items')
                    ->whereIn('quote_id', $quoteIds)
                    ->select([
                        'id',
                        'quote_id',
                        'service_id',
                        'line_item_title',
                        'description',
                        'unit',
                        'quantity',
                        'unit_price',
                        'line_total',
                        'created_by',
                        'created_at',
                        'updated_at',
                    ])
                    ->orderBy('quote_id')
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $row) {
                    $itemsByQuote[(int) $row->quote_id][] = $row;
                }
            }

            $followups = [];
            if ($this->config->hasTable('quote_followups') && !empty($quoteIds)) {
                $followups = DB::table('quote_followups')
                    ->where('quote_type', $service)
                    ->whereIn('quote_id', $quoteIds)
                    ->orderBy('quote_id')
                    ->orderByDesc('follow_up_date')
                    ->orderByDesc('id')
                    ->get();
            }

            $awardHistory = [];
            if (!empty($quoteIds) && $this->config->hasTable('projects_main')) {
                $awardRows = $this->config->linkedProjectsBase($service)
                    ->whereIn('quote_id', $quoteIds)
                    ->select(['id', 'quote_id', 'award_date', 'created_at'])
                    ->orderBy('quote_id')
                    ->orderBy('award_date')
                    ->orderBy('id')
                    ->get();
                $awardHistory = $awardRows;
            }

            $data = $quotes->map(function ($quote) use ($service, $itemsByQuote) {
                if ($service === 'equipment') {
                    $quote->line_items = $itemsByQuote[(int) $quote->id] ?? [];
                }
                if ($service === 'special') {
                    $quote->line_items = $itemsByQuote[(int) $quote->id] ?? [];
                }
                return $quote;
            });

            return response()->json([
                'status' => 'success',
                'data' => $data,
                'followups' => $followups,
                'award_history' => $awardHistory,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }
    }

    public function listSpecialLineItemsByService(Request $request): JsonResponse
    {
        $serviceId = (int) $request->query('service_id', 0);
        if ($serviceId <= 0) {
            return response()->json([]);
        }

        try {
            $items = DB::select(
                "
                SELECT
                    qi.id,
                    qi.line_item_title AS title,
                    qi.description,
                    qi.unit,
                    qi.unit_price,
                    qi.created_at
                FROM quotes_special_items AS qi
                INNER JOIN (
                    SELECT
                        line_item_title,
                        MAX(created_at) AS max_created
                    FROM quotes_special_items
                    WHERE service_id = ?
                    GROUP BY line_item_title
                ) AS latest
                  ON qi.line_item_title = latest.line_item_title
                 AND qi.created_at = latest.max_created
                WHERE qi.service_id = ?
                ORDER BY qi.id ASC
                ",
                [$serviceId, $serviceId]
            );
            return response()->json($items);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }
    }
}
