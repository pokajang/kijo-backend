<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createAllQuotesView(true);
    }

    public function down(): void
    {
        $this->createAllQuotesView(false);
    }

    private function createAllQuotesView(bool $withExplicitCollation): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! $this->requiredTablesExist()) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS all_quotes');

        $text = fn (string $expression): string => $withExplicitCollation
            ? "CONVERT({$expression} USING utf8mb4) COLLATE utf8mb4_unicode_ci"
            : $expression;

        $literal = fn (string $value): string => $withExplicitCollation
            ? "_utf8mb4'{$value}' COLLATE utf8mb4_unicode_ci"
            : "'{$value}'";

        DB::statement(<<<SQL
CREATE VIEW all_quotes AS
SELECT
    q.id AS quote_id,
    {$literal('Training')} AS service_group,
    {$text('q.training_title')} AS service_title,
    q.created_at AS created_at,
    q.award_date AS award_date,
    q.created_by_id AS staff_id,
    {$text('q.created_by_name')} AS staff_name,
    {$text('q.created_by_code')} AS staff_code,
    q.client_id AS client_id,
    {$text('q.client_name')} AS client_name,
    {$text('q.status')} AS quote_status,
    q.grand_total AS value,
    {$text('qis.source')} AS inquiry_source,
    {$text('qis.remarks')} AS inquiry_remarks
FROM quotes_training q
LEFT JOIN quote_inquiry_sources qis
    ON qis.quote_id = q.id AND qis.service_type = {$literal('Training')}
UNION ALL
SELECT
    q.id AS quote_id,
    {$literal('Industrial Hygiene')} AS service_group,
    {$text('q.service_title')} AS service_title,
    q.created_at AS created_at,
    q.award_date AS award_date,
    q.created_by_id AS staff_id,
    {$text('q.created_by_name')} AS staff_name,
    {$text('q.created_by_code')} AS staff_code,
    q.client_id AS client_id,
    {$text('q.client_name')} AS client_name,
    {$text('q.status')} AS quote_status,
    q.grand_total AS value,
    {$text('qis.source')} AS inquiry_source,
    {$text('qis.remarks')} AS inquiry_remarks
FROM quotes_ih q
LEFT JOIN quote_inquiry_sources qis
    ON qis.quote_id = q.id AND qis.service_type = {$literal('Industrial Hygiene')}
UNION ALL
SELECT
    q.id AS quote_id,
    {$literal('Equipment Supply')} AS service_group,
    {$literal('Equipment Supply')} AS service_title,
    q.created_at AS created_at,
    q.award_date AS award_date,
    q.created_by_id AS staff_id,
    {$text('q.created_by_name')} AS staff_name,
    {$text('q.created_by_code')} AS staff_code,
    q.client_id AS client_id,
    {$text('q.client_name')} AS client_name,
    {$text('q.status')} AS quote_status,
    q.grand_total AS value,
    {$text('qis.source')} AS inquiry_source,
    {$text('qis.remarks')} AS inquiry_remarks
FROM quotes_equipment q
LEFT JOIN quote_inquiry_sources qis
    ON qis.quote_id = q.id AND qis.service_type = {$literal('Equipment Supply')}
UNION ALL
SELECT
    q.id AS quote_id,
    {$literal('Manpower Supply')} AS service_group,
    {$text('q.service_title')} AS service_title,
    q.created_at AS created_at,
    q.award_date AS award_date,
    q.created_by_id AS staff_id,
    {$text('q.created_by_name')} AS staff_name,
    {$text('q.created_by_code')} AS staff_code,
    q.client_id AS client_id,
    {$text('q.client_name')} AS client_name,
    {$text('q.status')} AS quote_status,
    q.grand_total AS value,
    {$text('qis.source')} AS inquiry_source,
    {$text('qis.remarks')} AS inquiry_remarks
FROM quotes_manpower q
LEFT JOIN quote_inquiry_sources qis
    ON qis.quote_id = q.id AND qis.service_type = {$literal('Manpower Supply')}
UNION ALL
SELECT
    q.id AS quote_id,
    {$literal('Special Service')} AS service_group,
    {$text('q.service_title')} AS service_title,
    q.created_at AS created_at,
    q.award_date AS award_date,
    q.created_by_id AS staff_id,
    {$text('q.created_by_name')} AS staff_name,
    {$text('q.created_by_code')} AS staff_code,
    q.client_id AS client_id,
    {$text('q.client_name')} AS client_name,
    {$text('q.status')} AS quote_status,
    q.grand_total AS value,
    {$text('qis.source')} AS inquiry_source,
    {$text('qis.remarks')} AS inquiry_remarks
FROM quotes_special q
LEFT JOIN quote_inquiry_sources qis
    ON qis.quote_id = q.id AND qis.service_type = {$literal('Special Service')}
SQL);
    }

    private function requiredTablesExist(): bool
    {
        foreach ([
            'quotes_training',
            'quotes_ih',
            'quotes_equipment',
            'quotes_manpower',
            'quotes_special',
            'quote_inquiry_sources',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
};
