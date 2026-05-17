<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'proposal_template_training_main' => 'uq_train_active_bm_source',
        'proposal_template_ih' => 'uq_ih_active_bm_source',
        'proposal_template_manpower' => 'uq_mp_active_bm_source',
        'proposal_template_special' => 'uq_sp_active_bm_source',
    ];

    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->tables as $table => $indexName) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'active_bm_source_template_id')) {
                continue;
            }

            $this->assertNoDuplicateActiveBmCopies($table);

            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN `active_bm_source_template_id` BIGINT UNSIGNED GENERATED ALWAYS AS " .
                "(CASE WHEN `is_deleted` = 0 AND `proposal_language` = 'ms-MY' AND `source_template_id` IS NOT NULL " .
                "THEN `source_template_id` ELSE NULL END) STORED"
            );

            DB::statement("CREATE UNIQUE INDEX `{$indexName}` ON `{$table}` (`active_bm_source_template_id`)");
        }
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->tables as $table => $indexName) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'active_bm_source_template_id')) {
                continue;
            }

            if ($this->indexExists($table, $indexName)) {
                DB::statement("DROP INDEX `{$indexName}` ON `{$table}`");
            }

            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `active_bm_source_template_id`");
        }
    }

    private function assertNoDuplicateActiveBmCopies(string $table): void
    {
        $duplicates = DB::table($table)
            ->select('source_template_id', DB::raw('COUNT(*) as copies'))
            ->where('is_deleted', 0)
            ->where('proposal_language', 'ms-MY')
            ->whereNotNull('source_template_id')
            ->groupBy('source_template_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($duplicates) {
            throw new RuntimeException(
                "Cannot add active BM copy uniqueness guard for {$table}; source template " .
                "{$duplicates->source_template_id} has {$duplicates->copies} active BM copies."
            );
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($rows) > 0;
    }
};
