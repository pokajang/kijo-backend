<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RealizedSalesProjectQuery
{
    public function projectFacts(): Builder
    {
        $sourceSql = "
            COALESCE(
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = p.quote_id
                      AND qis.service_type = 'Training'
                      AND p.project_type = 'Training'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ),
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = p.quote_id
                      AND qis.service_type = 'Industrial Hygiene'
                      AND p.project_type = 'Industrial Hygiene'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ),
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = p.quote_id
                      AND qis.service_type = 'Manpower Supply'
                      AND p.project_type = 'Manpower Supply'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ),
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = p.quote_id
                      AND qis.service_type = 'Equipment Supply'
                      AND p.project_type = 'Equipment Supply'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ),
                (
                    SELECT qis.source
                    FROM quote_inquiry_sources qis
                    WHERE qis.quote_id = p.quote_id
                      AND qis.service_type = 'Special Service'
                      AND p.project_type = 'Special Service'
                    ORDER BY qis.id DESC
                    LIMIT 1
                ),
                'Unattributed'
            ) AS inquiry_source
        ";

        $base = DB::table('projects_main as p')
            ->leftJoin('quotes_training as qt', function ($join) {
                $join->on('qt.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Training');
            })
            ->leftJoin('quotes_ih as qh', function ($join) {
                $join->on('qh.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Industrial Hygiene');
            })
            ->leftJoin('quotes_manpower as qm', function ($join) {
                $join->on('qm.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Manpower Supply');
            })
            ->leftJoin('quotes_equipment as qe', function ($join) {
                $join->on('qe.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Equipment Supply');
            })
            ->leftJoin('quotes_special as qs', function ($join) {
                $join->on('qs.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Special Service');
            })
            ->selectRaw("
                p.id,
                p.quote_id,
                p.project_type AS service_group,
                p.quote_value AS value,
                p.award_date,
                p.status AS project_status,
                COALESCE(qt.created_by_code, qh.created_by_code, qm.created_by_code, qe.created_by_code, qs.created_by_code, 'UNASSIGNED') AS staff_code,
                COALESCE(qt.created_by_name, qh.created_by_name, qm.created_by_name, qe.created_by_name, qs.created_by_name, 'Unassigned') AS staff_name,
                {$sourceSql}
            ");

        return DB::query()->fromSub($base, 'project_facts');
    }

    public function realizedStatusPredicate(string $column = 'project_status'): string
    {
        return "LOWER({$column}) IN ('active', 'completed')";
    }

    public function quoteHasRealizedProjectPredicate(string $quoteAlias = 'quote_facts'): string
    {
        return "
            EXISTS (
                SELECT 1
                FROM projects_main pm
                WHERE pm.quote_id = {$quoteAlias}.quote_id
                  AND LOWER(pm.status) IN ('active', 'completed')
                  AND (
                    (LOWER({$quoteAlias}.service_group) LIKE '%training%' AND LOWER(pm.project_type) LIKE '%training%')
                    OR ((LOWER({$quoteAlias}.service_group) LIKE '%industrial%' OR LOWER({$quoteAlias}.service_group) = 'ih') AND (LOWER(pm.project_type) LIKE '%industrial%' OR LOWER(pm.project_type) LIKE '%ih%'))
                    OR (LOWER({$quoteAlias}.service_group) LIKE '%manpower%' AND LOWER(pm.project_type) LIKE '%manpower%')
                    OR (LOWER({$quoteAlias}.service_group) LIKE '%equipment%' AND LOWER(pm.project_type) LIKE '%equipment%')
                    OR (LOWER({$quoteAlias}.service_group) LIKE '%special%' AND LOWER(pm.project_type) LIKE '%special%')
                  )
            )
        ";
    }
}
