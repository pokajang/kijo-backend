<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_response_feedback')) {
            Schema::create('assistant_response_feedback', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('message_id')->index();
                $table->unsignedBigInteger('thread_id')->index();
                $table->unsignedBigInteger('staff_id')->index();
                $table->string('rating', 20)->index();
                $table->json('reasons_json')->nullable();
                $table->text('note')->nullable();
                $table->text('question')->nullable();
                $table->text('answer_excerpt')->nullable();
                $table->json('sources_json')->nullable();
                $table->string('confidence', 20)->nullable();
                $table->string('answer_mode', 20)->nullable();
                $table->string('current_route', 255)->nullable();
                $table->string('answer_signature', 64)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assistant_provider_feedback_memory')) {
            Schema::create('assistant_provider_feedback_memory', function (Blueprint $table): void {
                $table->id();
                $table->string('memory_key', 64)->unique();
                $table->string('question_hash', 64)->index();
                $table->string('normalized_question', 500);
                $table->string('provider_key', 191)->index();
                $table->string('source_type', 80)->index();
                $table->string('source_slug', 255)->nullable();
                $table->string('route_hash', 64)->nullable()->index();
                $table->string('scope_hash', 64)->index();
                $table->unsignedInteger('positive_count')->default(0);
                $table->unsignedInteger('negative_count')->default(0);
                $table->timestamp('last_feedback_at')->nullable()->index();
                $table->timestamps();
            });
        }

        $this->ensureProviderFeedbackMemoryIndexes();

        if (Schema::hasTable('assistant_answer_cache') && ! Schema::hasColumn('assistant_answer_cache', 'answer_signature')) {
            Schema::table('assistant_answer_cache', function (Blueprint $table): void {
                $table->string('answer_signature', 64)->nullable()->index()->after('answer_json');
            });
        }

        if (Schema::hasTable('assistant_live_result_cache') && ! Schema::hasColumn('assistant_live_result_cache', 'answer_signature')) {
            Schema::table('assistant_live_result_cache', function (Blueprint $table): void {
                $table->string('answer_signature', 64)->nullable()->index()->after('answer_json');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assistant_live_result_cache') && Schema::hasColumn('assistant_live_result_cache', 'answer_signature')) {
            Schema::table('assistant_live_result_cache', function (Blueprint $table): void {
                $table->dropColumn('answer_signature');
            });
        }

        if (Schema::hasTable('assistant_answer_cache') && Schema::hasColumn('assistant_answer_cache', 'answer_signature')) {
            Schema::table('assistant_answer_cache', function (Blueprint $table): void {
                $table->dropColumn('answer_signature');
            });
        }

        Schema::dropIfExists('assistant_provider_feedback_memory');
        Schema::dropIfExists('assistant_response_feedback');
    }

    private function ensureProviderFeedbackMemoryIndexes(): void
    {
        $table = 'assistant_provider_feedback_memory';

        if (! Schema::hasTable($table)) {
            return;
        }

        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_memory_key_unique', ['memory_key'], unique: true);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_question_hash_index', ['question_hash']);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_provider_key_index', ['provider_key']);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_source_type_index', ['source_type']);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_source_slug_index', ['source_slug'], mysqlPrefixLength: 191);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_route_hash_index', ['route_hash']);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_scope_hash_index', ['scope_hash']);
        $this->addIndexIfMissing($table, 'assistant_provider_feedback_memory_last_feedback_at_index', ['last_feedback_at']);
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
