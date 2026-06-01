<?php

namespace App\Services\Stats;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RealizedSalesProjectQuery
{
    public function projectFacts(): Builder
    {
        $hasProjectQuoteId = Schema::hasColumn('projects_main', 'quote_id');
        $hasProjectType = Schema::hasColumn('projects_main', 'project_type');
        $projectNameColumn = Schema::hasColumn('projects_main', 'project_name') ? 'p.project_name' : "''";
        $projectQuoteIdColumn = $hasProjectQuoteId ? 'p.quote_id' : 'NULL';
        $projectTypeColumn = $hasProjectType ? 'p.project_type' : "''";
        $projectValueColumn = Schema::hasColumn('projects_main', 'quote_value') ? 'p.quote_value' : '0';
        $projectAwardDateColumn = Schema::hasColumn('projects_main', 'award_date') ? 'p.award_date' : 'NULL';
        $projectStatusColumn = Schema::hasColumn('projects_main', 'status') ? 'p.status' : "''";
        $hasTrainingQuotes = Schema::hasTable('quotes_training');
        $hasIndustrialHygieneQuotes = Schema::hasTable('quotes_ih');
        $hasManpowerQuotes = Schema::hasTable('quotes_manpower');
        $hasEquipmentQuotes = Schema::hasTable('quotes_equipment');
        $hasSpecialQuotes = Schema::hasTable('quotes_special');
        $canJoinQuoteTables = $hasProjectQuoteId && $hasProjectType;

        $quoteRefColumns = [];
        $serviceTitleColumns = [$projectNameColumn, "''"];
        $staffCodeColumns = ["'UNASSIGNED'"];
        $staffNameColumns = ["'Unassigned'"];
        $clientNameColumns = [$projectNameColumn, "''"];

        if ($hasTrainingQuotes && $canJoinQuoteTables) {
            $quoteRefColumns[] = 'qt.quote_ref_no';
            $serviceTitleColumns[] = 'qt.training_title';
            $staffCodeColumns[] = 'qt.created_by_code';
            $staffNameColumns[] = 'qt.created_by_name';
            $clientNameColumns[] = 'qt.client_name';
        }
        if ($hasIndustrialHygieneQuotes && $canJoinQuoteTables) {
            $quoteRefColumns[] = 'qh.quote_ref_no';
            $serviceTitleColumns[] = 'qh.service_title';
            $staffCodeColumns[] = 'qh.created_by_code';
            $staffNameColumns[] = 'qh.created_by_name';
            $clientNameColumns[] = 'qh.client_name';
        }
        if ($hasManpowerQuotes && $canJoinQuoteTables) {
            $quoteRefColumns[] = 'qm.quote_ref_no';
            $serviceTitleColumns[] = 'qm.service_title';
            $staffCodeColumns[] = 'qm.created_by_code';
            $staffNameColumns[] = 'qm.created_by_name';
            $clientNameColumns[] = 'qm.client_name';
        }
        if ($hasEquipmentQuotes && $canJoinQuoteTables) {
            $quoteRefColumns[] = 'qe.quote_ref_no';
            $serviceTitleColumns[] = "CASE WHEN qe.id IS NOT NULL THEN 'Equipment Supply' ELSE NULL END";
            $staffCodeColumns[] = 'qe.created_by_code';
            $staffNameColumns[] = 'qe.created_by_name';
            $clientNameColumns[] = 'qe.client_name';
        }
        if ($hasSpecialQuotes && $canJoinQuoteTables) {
            $quoteRefColumns[] = 'qs.quote_ref_no';
            $serviceTitleColumns[] = 'qs.service_title';
            $staffCodeColumns[] = 'qs.created_by_code';
            $staffNameColumns[] = 'qs.created_by_name';
            $clientNameColumns[] = 'qs.client_name';
        }

        $quoteRefColumns[] = "''";
        $sourceSql = Schema::hasTable('quote_inquiry_sources') && $canJoinQuoteTables
            ? "
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
            "
            : "'Unattributed' AS inquiry_source";

        $base = DB::table('projects_main as p');

        if ($hasTrainingQuotes && $canJoinQuoteTables) {
            $base->leftJoin('quotes_training as qt', function ($join) {
                $join->on('qt.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Training');
            });
        }
        if ($hasIndustrialHygieneQuotes && $canJoinQuoteTables) {
            $base->leftJoin('quotes_ih as qh', function ($join) {
                $join->on('qh.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Industrial Hygiene');
            });
        }
        if ($hasManpowerQuotes && $canJoinQuoteTables) {
            $base->leftJoin('quotes_manpower as qm', function ($join) {
                $join->on('qm.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Manpower Supply');
            });
        }
        if ($hasEquipmentQuotes && $canJoinQuoteTables) {
            $base->leftJoin('quotes_equipment as qe', function ($join) {
                $join->on('qe.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Equipment Supply');
            });
        }
        if ($hasSpecialQuotes && $canJoinQuoteTables) {
            $base->leftJoin('quotes_special as qs', function ($join) {
                $join->on('qs.id', '=', 'p.quote_id')->where('p.project_type', '=', 'Special Service');
            });
        }

        $base->selectRaw("
                p.id,
                p.id AS project_id,
                {$projectNameColumn} AS project_name,
                {$projectQuoteIdColumn} AS quote_id,
                {$projectTypeColumn} AS service_group,
                ".$this->coalesceSql($quoteRefColumns).' AS quote_ref_no,
                '.$this->coalesceSql($serviceTitleColumns)." AS service_title,
                {$projectValueColumn} AS value,
                {$projectAwardDateColumn} AS award_date,
                {$projectStatusColumn} AS project_status,
                ".$this->coalesceSql($staffCodeColumns).' AS staff_code,
                '.$this->coalesceSql($staffNameColumns).' AS staff_name,
                '.$this->coalesceSql($clientNameColumns)." AS client_name,
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

    /**
     * SQLite treats COALESCE(single_argument) as invalid, so collapse to the
     * lone expression when optional quote-table columns are absent.
     */
    private function coalesceSql(array $expressions): string
    {
        $expressions = array_values(array_filter($expressions, static fn ($expression) => trim((string) $expression) !== ''));

        if (count($expressions) <= 1) {
            return $expressions[0] ?? 'NULL';
        }

        return 'COALESCE('.implode(', ', $expressions).')';
    }
}
