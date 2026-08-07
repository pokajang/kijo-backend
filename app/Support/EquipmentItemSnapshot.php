<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class EquipmentItemSnapshot
{
    public static function expression(
        string $column,
        string $quoteAlias = 'qi',
        string $catalogAlias = 'ci',
    ): string {
        $snapshot = Schema::hasColumn('quotes_equipment_items', $column)
            ? "{$quoteAlias}.{$column}"
            : 'NULL';

        return "COALESCE({$snapshot}, {$catalogAlias}.{$column})";
    }

    /** @param array<string, mixed> $item */
    public static function writableValues(array $item): array
    {
        $values = [];
        foreach (['item_name', 'description', 'unit'] as $column) {
            if (Schema::hasColumn('quotes_equipment_items', $column)) {
                $values[$column] = $item[$column] ?? null;
            }
        }

        return $values;
    }
}
