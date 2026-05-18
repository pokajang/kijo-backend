<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $quoteTables = [
        'quotes_training',
        'quotes_manpower',
        'quotes_ih',
        'quotes_equipment',
        'quotes_special',
    ];

    public function up(): void
    {
        $mappings = [];

        foreach ($this->quoteTables as $quoteTable) {
            if (! Schema::hasTable($quoteTable) || ! Schema::hasColumn($quoteTable, 'quote_ref_no')) {
                continue;
            }

            $rows = DB::table($quoteTable)
                ->whereRaw("quote_ref_no REGEXP '^[Q][A-Z]{2}[0-9]{2}-[0-9]{3}[A-Z]+$'")
                ->get(['id', 'quote_ref_no']);

            foreach ($rows as $row) {
                $oldRef = (string) $row->quote_ref_no;
                $newRef = $this->padQuoteRef($oldRef);

                if ($newRef === $oldRef) {
                    continue;
                }

                DB::table($quoteTable)
                    ->where('id', $row->id)
                    ->update(['quote_ref_no' => $newRef]);

                $mappings[$oldRef] = $newRef;
            }
        }

        foreach ($mappings as $oldRef => $newRef) {
            foreach ($this->tablesWithQuoteRefColumn() as $referenceTable) {
                DB::table($referenceTable)
                    ->where('quote_ref_no', $oldRef)
                    ->update(['quote_ref_no' => $newRef]);
            }
        }
    }

    public function down(): void
    {
        // No-op: shrinking four-digit quotation refs could damage valid legacy-format records.
    }

    private function padQuoteRef(string $ref): string
    {
        return preg_replace_callback(
            '/^(Q[A-Z]{2}\d{2}-)(\d{3})([A-Z]+)$/',
            static fn (array $matches): string => $matches[1]
                . str_pad($matches[2], 4, '0', STR_PAD_LEFT)
                . $matches[3],
            $ref
        ) ?? $ref;
    }

    /**
     * @return list<string>
     */
    private function tablesWithQuoteRefColumn(): array
    {
        return collect(DB::select("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = 'quote_ref_no'
        "))
            ->pluck('TABLE_NAME')
            ->map(static fn ($table): string => (string) $table)
            ->filter(fn (string $table): bool => ! in_array($table, $this->quoteTables, true))
            ->values()
            ->all();
    }
};
