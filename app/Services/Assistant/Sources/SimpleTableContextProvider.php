<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class SimpleTableContextProvider extends ModuleContextProvider
{
    public function __construct(
        AssistantText $text,
        protected readonly ModuleEntityResolver $resolver,
        protected readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    abstract protected function tokens(): array;

    abstract protected function routeHints(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    abstract protected function tableSpecs(): array;

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        if (! $this->hasAnyAvailableTable()) {
            return false;
        }

        $route = strtolower($currentRoute);
        foreach ($this->routeHints() as $hint) {
            if ($hint !== '' && str_contains($route, strtolower($hint))) {
                return true;
            }
        }

        return $this->hasToken($question, $this->tokens());
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $rows = $this->rows($request);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            '_assistant_id',
            '_assistant_title',
            ['_assistant_title', '_assistant_search'],
            $this->routePatterns(),
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }
        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->rowSource((array) $resolved['row']));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, '_assistant_title', [
            '_assistant_title',
            '_assistant_search',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        if ($matches === [] && ! $this->hasListIntent($question) && ! $this->routeMatches($currentRoute)) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->listSource($matches ?: array_slice($rows, 0, 8)));
    }

    public function auditMetadata(): array
    {
        $specs = $this->tableSpecs();
        $routes = array_values(array_unique(array_filter(array_merge(
            $this->routeHints(),
            array_map(static fn (array $spec): string => (string) ($spec['route'] ?? ''), $specs),
        ))));
        $hasSelfScope = collect($specs)->contains(
            static fn (array $spec): bool => ! empty($spec['self_staff_column']) || ! empty($spec['admin_roles']),
        );

        return [
            'provider_key' => $this->key(),
            'supported_routes' => $routes,
            'exact_ref_support' => collect($specs)->contains(static fn (array $spec): bool => ! empty($spec['route_pattern'])),
            'detail_route_support' => false,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'source_status_metadata' => 'partial',
            'permission_scope' => $hasSelfScope ? 'self-or-privileged-role' : 'session-role',
            'smoke_sample' => $this->tokens()[0] ?? $this->key(),
            'tests_present' => 'unknown',
            'classification' => 'summary-only',
        ];
    }

    protected function hasAnyAvailableTable(): bool
    {
        foreach ($this->tableSpecs() as $spec) {
            if (Schema::hasTable((string) $spec['table'])) {
                return true;
            }
        }

        return false;
    }

    protected function rows(Request $request): array
    {
        $rows = [];
        foreach ($this->tableSpecs() as $spec) {
            $table = (string) $spec['table'];
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $selected = $this->existingColumns($table, array_values(array_unique(array_merge(
                [(string) $spec['id'], (string) $spec['title']],
                $spec['fields'] ?? [],
                $spec['search'] ?? [],
                [(string) ($spec['self_staff_column'] ?? '')],
            ))));
            if ($selected === []) {
                continue;
            }

            $query = DB::table($table)->select($selected);
            if (in_array('deleted_at', $columns, true)) {
                $query->whereNull('deleted_at');
            }
            if (in_array('is_deleted', $columns, true)) {
                $query->where('is_deleted', 0);
            }
            if (($spec['published_column'] ?? null) && in_array((string) $spec['published_column'], $columns, true)) {
                $query->where((string) $spec['published_column'], (bool) ($spec['published_value'] ?? true));
            }
            if (
                ($spec['self_staff_column'] ?? null)
                && in_array((string) $spec['self_staff_column'], $columns, true)
                && ! $this->hasAnyRole($request, $spec['admin_roles'] ?? ['System Admin', 'Manager'])
            ) {
                $query->where((string) $spec['self_staff_column'], (int) $request->session()->get('staff_id', 0));
            }
            if (($spec['order_by'] ?? null) && in_array((string) $spec['order_by'], $columns, true)) {
                $query->orderByDesc((string) $spec['order_by']);
            }

            foreach ($query->limit((int) ($spec['limit'] ?? 80))->get() as $row) {
                $item = (array) $row;
                $item['_assistant_id'] = (int) ($item[$spec['id']] ?? 0);
                $item['_assistant_title'] = trim((string) ($item[$spec['title']] ?? ($spec['label'] ?? $this->key())));
                $item['_assistant_search'] = implode(' ', array_map(
                    fn (string $field): string => (string) ($item[$field] ?? ''),
                    $spec['search'] ?? [],
                ));
                $item['_assistant_source_type'] = (string) $spec['source_type'];
                $item['_assistant_category'] = (string) $spec['category'];
                $item['_assistant_route'] = $this->routeFor($spec, $item);
                $item['_assistant_fields'] = $spec['fields'] ?? [];
                $item['_assistant_score'] = (int) ($spec['score'] ?? 300);
                $rows[] = $item;
            }
        }

        return $rows;
    }

    protected function rowSource(array $row): ?array
    {
        $id = (int) ($row['_assistant_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $sourceType = (string) ($row['_assistant_source_type'] ?? $this->key());

        return $this->source(
            "{$sourceType}:{$id}",
            $sourceType,
            (string) ($row['_assistant_title'] ?? ucfirst($sourceType)." #{$id}"),
            (string) ($row['_assistant_route'] ?? ''),
            ['record' => $this->sanitizer->keep($row, $row['_assistant_fields'] ?? [])],
            (int) ($row['_assistant_score'] ?? 360),
            (string) ($row['_assistant_category'] ?? ucfirst($sourceType)),
        );
    }

    protected function listSource(array $rows): ?array
    {
        $sourceType = (string) ($rows[0]['_assistant_source_type'] ?? $this->key());
        $payloadRows = $this->sanitizer->rows($rows, array_values(array_unique(array_merge(
            ['_assistant_id', '_assistant_title'],
            $rows[0]['_assistant_fields'] ?? [],
        ))), 8);

        return $this->source(
            "{$sourceType}:list:".substr(sha1(json_encode($payloadRows)), 0, 12),
            $sourceType,
            ucfirst(str_replace('_', ' ', $sourceType)).' matches',
            (string) ($rows[0]['_assistant_route'] ?? ''),
            [
                'note' => 'Multiple records may be relevant. Ask with an exact name, reference, or ID for a narrower answer.',
                'matches' => $payloadRows,
            ],
            300,
            (string) ($rows[0]['_assistant_category'] ?? ucfirst($sourceType)),
        );
    }

    protected function ambiguousSource(array $rows): ?array
    {
        $payloadRows = $this->sanitizer->rows($rows, ['_assistant_id', '_assistant_title'], 5);

        return $this->source(
            $this->key().':ambiguous:'.substr(sha1(json_encode($payloadRows)), 0, 12),
            'live_entity',
            'Ambiguous '.ucfirst(str_replace('_', ' ', $this->key())).' matches',
            (string) ($rows[0]['_assistant_route'] ?? ''),
            [
                'note' => 'The question matched multiple records. Ask again with the exact name, reference, or ID.',
                'matches' => $payloadRows,
            ],
            360,
            ucfirst(str_replace('_', ' ', $this->key())),
        );
    }

    private function resultFromSource(?array $source): AssistantContextResult
    {
        return new AssistantContextResult(
            $source ? [$source] : [],
            $source ? 'live' : 'static',
            $source ? $this->freshnessLabel() : null,
            [$this->key()],
        );
    }

    private function existingColumns(string $table, array $columns): array
    {
        $available = array_flip(Schema::getColumnListing($table));

        return array_values(array_filter(
            array_unique($columns),
            static fn (string $column): bool => $column !== '' && isset($available[$column]),
        ));
    }

    private function routeFor(array $spec, array $row): string
    {
        $template = (string) ($spec['route'] ?? '');
        if ($template === '') {
            return '';
        }

        return str_replace('{id}', (string) ($row[$spec['id']] ?? ''), $template);
    }

    private function routeMatches(string $currentRoute): bool
    {
        foreach ($this->routeHints() as $hint) {
            if ($hint !== '' && str_contains(strtolower($currentRoute), strtolower($hint))) {
                return true;
            }
        }

        return false;
    }

    private function routePatterns(): array
    {
        $patterns = [];
        foreach ($this->tableSpecs() as $spec) {
            if (! empty($spec['route_pattern'])) {
                $patterns[] = (string) $spec['route_pattern'];
            }
        }

        return $patterns;
    }
}
