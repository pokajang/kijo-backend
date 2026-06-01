<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_source_gaps')) {
            Schema::create('assistant_source_gaps', function (Blueprint $table): void {
                $table->id();
                $table->string('gap_key', 64)->unique();
                $table->string('normalized_intent', 500);
                $table->text('sample_question')->nullable();
                $table->string('current_route', 255)->nullable();
                $table->json('source_types_json')->nullable();
                $table->json('provider_keys_json')->nullable();
                $table->string('confidence', 20)->nullable()->index();
                $table->string('answer_mode', 20)->nullable()->index();
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->string('status', 20)->default('open')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureAssistantSourceGapIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_source_gaps');
    }

    private function ensureAssistantSourceGapIndexes(): void
    {
        $table = 'assistant_source_gaps';

        if (! Schema::hasTable($table)) {
            return;
        }

        $this->addIndexIfMissing($table, 'assistant_source_gaps_gap_key_unique', ['gap_key'], unique: true);
        $this->addIndexIfMissing($table, 'assistant_source_gaps_normalized_intent_index', ['normalized_intent'], mysqlPrefixLength: 191);
        $this->addIndexIfMissing($table, 'assistant_source_gaps_current_route_index', ['current_route'], mysqlPrefixLength: 191);
        $this->addIndexIfMissing($table, 'assistant_source_gaps_confidence_index', ['confidence']);
        $this->addIndexIfMissing($table, 'assistant_source_gaps_answer_mode_index', ['answer_mode']);
        $this->addIndexIfMissing($table, 'assistant_source_gaps_last_seen_at_index', ['last_seen_at']);
        $this->addIndexIfMissing($table, 'assistant_source_gaps_status_index', ['status']);
    }

    private function addIndexIfMissing(
        string $table,
        string $index,
        array $columns,
        bool $unique = false,
        ?int $mysqlPrefixLength = null,
    ): void {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        if ($this->indexExists($table, $index)) {
            return;
        }

        if (DB::getDriverName() === 'mysql' && $mysqlPrefixLength !== null && count($columns) === 1) {
            $quotedTable = $this->quoteMysqlIdentifier($table);
            $quotedIndex = $this->quoteMysqlIdentifier($index);
            $quotedColumn = $this->quoteMysqlIdentifier($columns[0]);

            DB::statement("ALTER TABLE {$quotedTable} ADD INDEX {$quotedIndex} ({$quotedColumn}({$mysqlPrefixLength}))");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $index, $unique): void {
            if ($unique) {
                $table->unique($columns, $index);

                return;
            }

            $table->index($columns, $index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'mysql') {
            $quotedTable = $this->quoteMysqlIdentifier($table);

            return count(DB::select("SHOW INDEX FROM {$quotedTable} WHERE Key_name = ?", [$index])) > 0;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('".$this->quoteSqliteLiteral($table)."')") as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function quoteMysqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteSqliteLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
};
