<?php

namespace App\Services\ProposalTemplates;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProposalTemplateCrudSupport
{
    private array $columnCache = [];

    public function normalizeProposalLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    public function filterExistingColumns(string $table, array $payload): array
    {
        $columns = $this->columns($table);
        if (empty($columns)) {
            return [];
        }

        return array_filter(
            array_intersect_key($payload, array_flip($columns)),
            fn ($value, string $column): bool => !$this->isGeneratedTemplateColumn($column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    public function isLegacyRequest(Request $request): bool
    {
        return str_ends_with(strtolower((string) $request->path()), '.php');
    }

    public function errorResponse(Request $request, string $message, int $statusCode = 200): JsonResponse
    {
        if ($this->isLegacyRequest($request)) {
            return response()->json(['success' => false, 'message' => $message], $statusCode);
        }

        return response()->json(['status' => 'error', 'message' => $message], $statusCode);
    }

    public function writeHistory(string $historyTable, array $payload): void
    {
        if (!Schema::hasTable($historyTable)) {
            return;
        }

        $filtered = $this->filterExistingColumns($historyTable, $payload);
        if (!empty($filtered)) {
            DB::table($historyTable)->insert($filtered);
        }
    }

    private function columns(string $table): array
    {
        if (array_key_exists($table, $this->columnCache)) {
            return $this->columnCache[$table];
        }

        try {
            $this->columnCache[$table] = Schema::hasTable($table)
                ? Schema::getColumnListing($table)
                : [];
        } catch (\Throwable) {
            $this->columnCache[$table] = [];
        }

        return $this->columnCache[$table];
    }

    private function isGeneratedTemplateColumn(string $column): bool
    {
        return in_array($column, [
            'active_bm_source_template_id',
        ], true);
    }
}
