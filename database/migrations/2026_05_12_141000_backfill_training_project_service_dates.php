<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects_main') || !Schema::hasTable('quotes_training')) {
            return;
        }

        DB::statement("
            UPDATE projects_main p
            JOIN quotes_training qt ON qt.id = p.quote_id
            SET
                p.service_start_date = COALESCE(p.service_start_date, qt.proposed_date),
                p.service_end_date = COALESCE(
                    p.service_end_date,
                    NULLIF(CAST(qt.proposed_end_date AS CHAR), '0000-00-00'),
                    qt.proposed_date
                )
            WHERE p.project_type = 'Training'
              AND COALESCE(qt.to_be_confirmed, 0) <> 1
              AND qt.proposed_date IS NOT NULL
              AND CAST(qt.proposed_date AS CHAR) <> '0000-00-00'
              AND (p.service_start_date IS NULL OR p.service_end_date IS NULL)
        ");
    }

    public function down(): void
    {
        // Data backfill only. Do not clear project service dates on rollback.
    }
};
