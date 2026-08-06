<?php

namespace App\Services\Equipment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EquipmentCommercialSnapshotService
{
    /**
     * Resolve the exact equipment wording captured on a quotation. Commercial
     * documents copy this snapshot when they are created and remain independent
     * from later quotation edits.
     *
     * @return array{quote_id: int|null, quotation_remarks: string|null, items: array<int, array<string, mixed>>}
     */
    public function forQuote(?int $quoteId): array
    {
        if (! $quoteId || ! $this->hasEquipmentTables()) {
            return $this->emptySnapshot();
        }

        return DB::transaction(function () use ($quoteId): array {
            $quoteColumns = ['id'];
            if (Schema::hasColumn('quotes_equipment', 'quotation_remarks')) {
                $quoteColumns[] = 'quotation_remarks';
            }

            $quote = DB::table('quotes_equipment')->where('id', $quoteId)->first($quoteColumns);
            if (! $quote) {
                return $this->emptySnapshot();
            }

            $itemColumns = [
                'qi.item_id',
                'qi.quantity',
                'qi.unit_price',
                'c.item_name',
                'c.description',
                'c.unit',
            ];
            if (Schema::hasColumn('quotes_equipment_items', 'item_remarks')) {
                $itemColumns[] = 'qi.item_remarks';
            }

            $items = DB::table('quotes_equipment_items as qi')
                ->leftJoin('catalog_items as c', 'c.id', '=', 'qi.item_id')
                ->where('qi.quote_id', $quoteId)
                ->orderBy('qi.id')
                ->get($itemColumns)
                ->map(static fn ($item): array => (array) $item)
                ->all();

            return [
                'quote_id' => (int) $quote->id,
                'quotation_remarks' => $this->nullableText($quote->quotation_remarks ?? null),
                'items' => $items,
            ];
        });
    }

    /** @return array{quote_id: int|null, quotation_remarks: string|null, items: array<int, array<string, mixed>>} */
    public function forProject(?int $projectId): array
    {
        if (
            ! $projectId
            || ! Schema::hasTable('projects_main')
            || ! Schema::hasColumn('projects_main', 'quote_id')
            || ! Schema::hasColumn('projects_main', 'project_type')
        ) {
            return $this->emptySnapshot();
        }

        $project = DB::table('projects_main')->where('id', $projectId)->first(['quote_id', 'project_type']);
        if (! $project || strcasecmp(trim((string) $project->project_type), 'Equipment Supply') !== 0) {
            return $this->emptySnapshot();
        }

        return $this->forQuote((int) $project->quote_id);
    }

    /**
     * Fill snapshot wording only when a caller did not explicitly provide the
     * field. An explicit empty value is intentional and is never overwritten.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array{items?: array<int, array<string, mixed>>}  $snapshot
     * @return array<int, array<string, mixed>>
     */
    public function enrichItems(array $items, array $snapshot): array
    {
        $byId = [];
        $byName = [];
        foreach ($snapshot['items'] ?? [] as $source) {
            $itemId = (int) ($source['item_id'] ?? 0);
            if ($itemId > 0) {
                $byId[$itemId] = $source;
            }
            $itemName = $this->normalizedName($source['item_name'] ?? null);
            if ($itemName !== '') {
                $byName[$itemName] = $source;
            }
        }

        return array_map(function (array $item) use ($byId, $byName): array {
            $itemId = (int) ($item['item_id'] ?? $item['catalog_item_id'] ?? 0);
            $itemName = $this->normalizedName($item['item_name'] ?? $item['item_description'] ?? null);
            $source = ($itemId > 0 ? ($byId[$itemId] ?? null) : null) ?? ($byName[$itemName] ?? null);
            if (! $source) {
                return $item;
            }

            if (! array_key_exists('item_remarks', $item)) {
                $item['item_remarks'] = $this->nullableText($source['item_remarks'] ?? null);
            }
            if (! array_key_exists('description', $item)) {
                $item['description'] = $this->nullableText($source['description'] ?? null);
            }

            return $item;
        }, $items);
    }

    /**
     * Preserve remarks from an existing commercial document when an older
     * caller omits the field. Match by item identity before falling back to
     * row order so a reordered payload cannot move remarks to another item.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  iterable<int, array<string, mixed>|object>  $existingItems
     * @return array<int, array<string, mixed>>
     */
    public function preserveMissingItemRemarks(array $items, iterable $existingItems): array
    {
        $existingByIndex = [];
        $remarksByName = [];
        foreach ($existingItems as $index => $existingItem) {
            $existing = (array) $existingItem;
            $existingByIndex[$index] = $existing['item_remarks'] ?? null;
            $name = $this->normalizedName(
                $existing['item_name'] ?? $existing['item_description'] ?? null
            );
            if ($name !== '') {
                $remarksByName[$name][] = $existing['item_remarks'] ?? null;
            }
        }

        foreach ($items as $index => &$item) {
            if (array_key_exists('item_remarks', $item)) {
                continue;
            }

            $name = $this->normalizedName(
                $item['item_name'] ?? $item['item_description'] ?? null
            );
            if ($name !== '' && ! empty($remarksByName[$name])) {
                $item['item_remarks'] = array_shift($remarksByName[$name]);

                continue;
            }

            $item['item_remarks'] = $existingByIndex[$index] ?? null;
        }
        unset($item);

        return $items;
    }

    /** @param array{items?: array<int, array<string, mixed>>} $snapshot */
    public function servicesDescription(array $snapshot): ?string
    {
        $blocks = [];
        foreach ($snapshot['items'] ?? [] as $item) {
            $lines = array_values(array_filter([
                $this->nullableText($item['item_name'] ?? null),
                $this->nullableText($item['description'] ?? null),
                ($remarks = $this->nullableText($item['item_remarks'] ?? null)) !== null
                    ? "Specifications / remarks:\n{$remarks}"
                    : null,
            ], static fn ($value): bool => $value !== null));

            if ($lines !== []) {
                $blocks[] = implode("\n", $lines);
            }
        }

        return $blocks === [] ? null : implode("\n\n", $blocks);
    }

    private function hasEquipmentTables(): bool
    {
        return Schema::hasTable('quotes_equipment')
            && Schema::hasTable('quotes_equipment_items')
            && Schema::hasTable('catalog_items');
    }

    /** @return array{quote_id: null, quotation_remarks: null, items: array<int, array<string, mixed>>} */
    private function emptySnapshot(): array
    {
        return ['quote_id' => null, 'quotation_remarks' => null, 'items' => []];
    }

    private function nullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizedName($value): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return mb_strtolower($name, 'UTF-8');
    }
}
