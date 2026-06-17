<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_request_diagnostics')) {
            Schema::create('assistant_request_diagnostics', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('message_id');
                $table->unsignedBigInteger('thread_id')->nullable();
                $table->string('question_hash', 64);
                $table->text('question')->nullable();
                $table->string('current_route', 191)->nullable();
                $table->json('diagnostics_json');
                $table->timestamps();
            });
        }

        $this->ensureIndex('message_id', 'assistant_request_diagnostics_message_id_unique', true);
        $this->ensureIndex('thread_id', 'assistant_request_diagnostics_thread_id_index');
        $this->ensureIndex('question_hash', 'assistant_request_diagnostics_question_hash_index');
        $this->ensureCurrentRouteIndex();
        $this->ensureMessageForeignKey();
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_request_diagnostics');
    }

    private function ensureIndex(string $column, string $indexName, bool $unique = false): void
    {
        if ($this->indexExists($indexName)) {
            return;
        }

        Schema::table('assistant_request_diagnostics', function (Blueprint $table) use ($column, $indexName, $unique): void {
            if ($unique) {
                $table->unique($column, $indexName);

                return;
            }

            $table->index($column, $indexName);
        });
    }

    private function ensureCurrentRouteIndex(): void
    {
        $indexName = 'assistant_request_diagnostics_current_route_index';
        if ($this->indexExists($indexName)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` (`current_route`(191))',
            'assistant_request_diagnostics',
            $indexName,
        ));
    }

    private function ensureMessageForeignKey(): void
    {
        $constraintName = 'assistant_request_diagnostics_message_fk';
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'assistant_request_diagnostics')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table('assistant_request_diagnostics', function (Blueprint $table) use ($constraintName): void {
            $table->foreign('message_id', $constraintName)
                ->references('id')
                ->on('knowledge_assistant_messages')
                ->cascadeOnDelete();
        });
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'assistant_request_diagnostics')
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
