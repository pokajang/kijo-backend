<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillEquipmentQuoteItemSnapshots extends Command
{
    protected $signature = 'quotes:backfill-equipment-item-snapshots
        {--commit : Copy current catalogue wording into empty quotation snapshots.}';

    protected $description = 'Audit and backfill immutable equipment quotation item wording.';

    public function handle(): int
    {
        if (! $this->schemaIsReady()) {
            $this->error('Equipment quotation snapshot columns are unavailable. Run migrations first.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $totals = ['candidates' => 0, 'matched' => 0, 'updated' => 0, 'unmatched' => 0];

        DB::table('quotes_equipment_items as qi')
            ->leftJoin('catalog_items as ci', 'ci.id', '=', 'qi.item_id')
            ->where(function ($query): void {
                $query->whereNull('qi.item_name')
                    ->orWhereNull('qi.description')
                    ->orWhereNull('qi.unit');
            })
            ->select([
                'qi.id',
                'qi.item_id',
                'qi.item_name as snapshot_item_name',
                'qi.description as snapshot_description',
                'qi.unit as snapshot_unit',
                'ci.item_name as catalog_item_name',
                'ci.description as catalog_description',
                'ci.unit as catalog_unit',
            ])
            ->orderBy('qi.id')
            ->chunkById(250, function ($rows) use ($commit, &$totals): void {
                foreach ($rows as $row) {
                    $totals['candidates']++;
                    if ($row->catalog_item_name === null) {
                        $totals['unmatched']++;
                        $this->warn("Quotation item #{$row->id}: catalogue item #{$row->item_id} is unavailable.");

                        continue;
                    }

                    $totals['matched']++;
                    if (! $commit) {
                        continue;
                    }

                    $updates = [
                        'item_name' => $row->snapshot_item_name ?? $row->catalog_item_name,
                        'description' => $row->snapshot_description ?? $row->catalog_description ?? '',
                        'unit' => $row->snapshot_unit ?? $row->catalog_unit ?? '',
                    ];
                    $totals['updated'] += DB::table('quotes_equipment_items')
                        ->where('id', $row->id)
                        ->where(function ($query): void {
                            $query->whereNull('item_name')
                                ->orWhereNull('description')
                                ->orWhereNull('unit');
                        })
                        ->update($updates);
                }
            }, 'qi.id', 'id');

        $this->table(['Metric', 'Count'], collect($totals)->map(
            fn (int $count, string $label): array => [$label, $count],
        )->values()->all());
        $this->info($commit
            ? 'Equipment quotation snapshot backfill completed.'
            : 'Dry run only. Re-run with --commit after reviewing unmatched rows.');

        return $totals['unmatched'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasTable('catalog_items')
            && Schema::hasTable('quotes_equipment_items')
            && Schema::hasColumns('quotes_equipment_items', ['item_name', 'description', 'unit']);
    }
}
