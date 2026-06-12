<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteFactsQuery
{
    public function __construct(private readonly DashboardDimensionNormalizer $normalizer) {}

    public function query(): Builder
    {
        // The SQL view can return more than one row per logical quote if bad legacy
        // source rows exist. Normalize first so every downstream KPI aggregates on
        // one quote fact instead of raw joined rows.
        $base = DB::table('all_quotes')
            ->selectRaw('
                service_group AS raw_service_group,
                quote_id,
                MAX(created_at) AS created_at,
                MAX(award_date) AS award_date,
                MAX(staff_id) AS staff_id,
                MAX(staff_name) AS staff_name,
                MAX(staff_code) AS staff_code,
                MAX(client_id) AS client_id,
                MAX(client_name) AS client_name,
                MAX(quote_status) AS quote_status,
                MAX(value) AS value,
                MAX(inquiry_source) AS raw_inquiry_source
            ')
            ->groupBy('service_group', 'quote_id');

        return DB::query()->fromSub($base, 'quote_facts');
    }

    public function facts(?string $start = null, ?string $end = null): Collection
    {
        $query = $this->query();
        if ($start && $end) {
            $query->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
        }

        $rows = $query->get();
        $latestSources = $this->latestInquirySourcesByQuoteService($rows);
        $staffNames = $this->staffNamesByCode($rows);

        return $rows
            ->map(function ($row) use ($latestSources, $staffNames) {
                $serviceGroup = $this->normalizer->serviceGroup($row->raw_service_group);
                $serviceKey = $this->normalizer->serviceKey($serviceGroup);
                $quoteKey = $this->quoteServiceKey($row->quote_id, $serviceGroup);
                $staffCode = $this->normalizer->staffCode($row->staff_code);

                return (object) [
                    'raw_service_group' => $this->normalizer->cleanText($row->raw_service_group),
                    'service_group' => $serviceGroup,
                    'service_key' => $serviceKey,
                    'quote_id' => $row->quote_id,
                    'created_at' => $row->created_at,
                    'award_date' => $row->award_date,
                    'staff_id' => $row->staff_id,
                    'staff_code' => $staffCode,
                    'staff_name' => $staffNames[$staffCode] ?? $this->normalizer->staffName($row->staff_name),
                    'client_id' => $row->client_id,
                    'client_name' => $row->client_name,
                    'quote_status' => $row->quote_status,
                    'value' => (float) ($row->value ?? 0),
                    'inquiry_source' => $this->normalizer->source(
                        $latestSources[$quoteKey] ?? $row->raw_inquiry_source ?? null
                    ),
                ];
            })
            ->groupBy(fn ($row) => $this->quoteServiceKey($row->quote_id, $row->service_group))
            ->map(function (Collection $duplicates) {
                $first = $duplicates->first();
                $first->value = (float) $duplicates->max('value');

                return $first;
            })
            ->values();
    }

    public function quoteServiceKey($quoteId, $serviceGroup): string
    {
        return $this->normalizer->serviceKey($serviceGroup).'|'.(string) $quoteId;
    }

    private function latestInquirySourcesByQuoteService(Collection $quoteFacts): array
    {
        if (! Schema::hasTable('quote_inquiry_sources') || $quoteFacts->isEmpty()) {
            return [];
        }

        $quoteIds = $quoteFacts
            ->pluck('quote_id')
            ->filter(fn ($quoteId) => $quoteId !== null && $quoteId !== '')
            ->unique()
            ->values()
            ->all();

        if ($quoteIds === []) {
            return [];
        }

        $sources = DB::table('quote_inquiry_sources')
            ->whereIn('quote_id', $quoteIds)
            ->orderBy('id')
            ->get();

        $latest = [];
        foreach ($sources as $source) {
            $serviceGroup = $this->normalizer->serviceGroup($source->service_type ?? '');
            $latest[$this->quoteServiceKey($source->quote_id, $serviceGroup)] = $source->source;
        }

        return $latest;
    }

    private function staffNamesByCode(Collection $quoteFacts): array
    {
        if (
            ! Schema::hasTable('staff_general') ||
            ! Schema::hasColumn('staff_general', 'name_code') ||
            ! Schema::hasColumn('staff_general', 'full_name')
        ) {
            return [];
        }

        $staffCodes = $quoteFacts
            ->map(fn ($row) => $this->normalizer->staffCode($row->staff_code ?? null))
            ->filter(fn ($staffCode) => $staffCode !== 'UNASSIGNED')
            ->unique()
            ->values()
            ->all();

        if ($staffCodes === []) {
            return [];
        }

        $staffNames = [];
        foreach (DB::table('staff_general')->whereIn('name_code', $staffCodes)->get() as $staff) {
            $staffCode = $this->normalizer->staffCode($staff->name_code ?? null);
            $staffName = $this->normalizer->staffName($staff->full_name ?? null);
            if ($staffCode !== 'UNASSIGNED' && $staffName !== 'Unassigned') {
                $staffNames[$staffCode] = $staffName;
            }
        }

        return $staffNames;
    }
}
