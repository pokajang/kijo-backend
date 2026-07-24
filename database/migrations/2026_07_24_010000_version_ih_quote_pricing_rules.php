<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_RULE = 'ih_complexity_v1';

    private const STANDARD_RULE = 'ih_standard_v2';

    public function up(): void
    {
        if (! Schema::hasTable('quotes_ih')) {
            return;
        }

        if (! Schema::hasColumn('quotes_ih', 'pricing_rule_version')) {
            $afterColumn = Schema::hasColumn('quotes_ih', 'traffic_light_rule_version')
                ? 'traffic_light_rule_version'
                : 'grand_total';

            Schema::table('quotes_ih', function (Blueprint $table) use ($afterColumn): void {
                $table->string('pricing_rule_version', 40)
                    ->nullable()
                    ->after($afterColumn);
            });
        }

        $hasComplexityRating = Schema::hasColumn('quotes_ih', 'complexity_rating');
        $hasComplexityMarkup = Schema::hasColumn('quotes_ih', 'complexity_markup');

        if ($hasComplexityRating || $hasComplexityMarkup) {
            DB::table('quotes_ih')
                ->whereNull('pricing_rule_version')
                ->where(function ($query) use ($hasComplexityMarkup, $hasComplexityRating): void {
                    if ($hasComplexityRating) {
                        $query->where('complexity_rating', '>', 1);
                    }
                    if ($hasComplexityMarkup) {
                        $method = $hasComplexityRating ? 'orWhere' : 'where';
                        $query->{$method}('complexity_markup', '<>', 0);
                    }
                })
                ->update(['pricing_rule_version' => self::LEGACY_RULE]);
        }

        $standardIds = collect();
        if (Schema::hasColumn('quotes_ih', 'estimated_total_cost')) {
            $standardIds = DB::table('quotes_ih')
                ->whereNotNull('estimated_total_cost')
                ->pluck('id');
        }

        if (Schema::hasTable('quotes_ih_items')) {
            $standardIds = $standardIds
                ->merge(DB::table('quotes_ih_items')->distinct()->pluck('quote_id'))
                ->unique()
                ->values();
        }

        if ($standardIds->isNotEmpty()) {
            DB::table('quotes_ih')
                ->whereNull('pricing_rule_version')
                ->whereIn('id', $standardIds->all())
                ->update(['pricing_rule_version' => self::STANDARD_RULE]);
        }

        $hasLegacyColumns = $hasComplexityRating || $hasComplexityMarkup;

        DB::table('quotes_ih')
            ->whereNull('pricing_rule_version')
            ->update([
                'pricing_rule_version' => $hasLegacyColumns
                    ? self::LEGACY_RULE
                    : self::STANDARD_RULE,
            ]);

        Schema::table('quotes_ih', function (Blueprint $table): void {
            $table->string('pricing_rule_version', 40)
                ->default(self::STANDARD_RULE)
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('quotes_ih')
            && Schema::hasColumn('quotes_ih', 'pricing_rule_version')
        ) {
            Schema::table('quotes_ih', function (Blueprint $table): void {
                $table->dropColumn('pricing_rule_version');
            });
        }
    }
};
