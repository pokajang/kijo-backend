<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_other_claim_items')) {
            Schema::table('hr_other_claim_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('hr_other_claim_items', 'travel_category')) {
                    $table->string('travel_category', 32)->nullable()->after('expense_category');
                }
                if (! Schema::hasColumn('hr_other_claim_items', 'distance_method')) {
                    $table->string('distance_method', 32)->nullable()->after('travel_category');
                }
                if (! Schema::hasColumn('hr_other_claim_items', 'mileage_rate')) {
                    $table->decimal('mileage_rate', 10, 4)->nullable()->after('distance_method');
                }
                if (! Schema::hasColumn('hr_other_claim_items', 'charge_to_project_id')) {
                    $table->unsignedBigInteger('charge_to_project_id')->nullable()->after('mileage_rate');
                }
                if (! Schema::hasColumn('hr_other_claim_items', 'location_detail')) {
                    $table->string('location_detail')->nullable()->after('charge_to_project_id');
                }
                if (! Schema::hasColumn('hr_other_claim_items', 'expense_type')) {
                    $table->string('expense_type', 120)->nullable()->after('location_detail');
                }
            });

            Schema::table('hr_other_claim_items', function (Blueprint $table): void {
                $table->index(['application_id', 'travel_category'], 'hr_other_claim_travel_category_index');
                $table->index('charge_to_project_id', 'hr_other_claim_charge_project_index');
            });

            if (Schema::hasColumn('hr_other_claim_items', 'trip_mode') && Schema::hasColumn('hr_other_claim_items', 'expense_category')) {
                DB::table('hr_other_claim_items')
                    ->orderBy('id')
                    ->chunkById(100, function ($items): void {
                        foreach ($items as $item) {
                            $updates = [];

                            if ((string) $item->type === 'Mileage') {
                                $updates['travel_category'] = 'mileage';
                                $updates['distance_method'] = (string) $item->trip_mode === 'one_way'
                                    ? 'one_way'
                                    : 'return_same_route';

                                $km = (float) ($item->km ?? 0);
                                $multiplier = (string) $item->trip_mode === 'one_way' ? 1 : 2;
                                if ($km > 0 && (float) $item->amount > 0) {
                                    $updates['mileage_rate'] = round((float) $item->amount / ($km * $multiplier), 4);
                                }
                            } elseif ((string) $item->type === 'Expense' && ! empty($item->expense_category)) {
                                $updates['travel_category'] = (string) $item->expense_category === 'combined'
                                    ? 'legacy_combined'
                                    : (string) $item->expense_category;
                            }

                            if ($updates !== []) {
                                $updates['updated_at'] = now();
                                DB::table('hr_other_claim_items')->where('id', $item->id)->update($updates);
                            }
                        }
                    });
            }
        }

        if (Schema::hasTable('hr_other_claim_attachments') && ! Schema::hasColumn('hr_other_claim_attachments', 'purpose')) {
            Schema::table('hr_other_claim_attachments', function (Blueprint $table): void {
                $table->string('purpose', 32)->nullable()->after('size');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_other_claim_attachments') && Schema::hasColumn('hr_other_claim_attachments', 'purpose')) {
            Schema::table('hr_other_claim_attachments', function (Blueprint $table): void {
                $table->dropColumn('purpose');
            });
        }

        if (! Schema::hasTable('hr_other_claim_items')) {
            return;
        }

        Schema::table('hr_other_claim_items', function (Blueprint $table): void {
            $table->dropIndex('hr_other_claim_travel_category_index');
            $table->dropIndex('hr_other_claim_charge_project_index');
            $table->dropColumn([
                'travel_category',
                'distance_method',
                'mileage_rate',
                'charge_to_project_id',
                'location_detail',
                'expense_type',
            ]);
        });
    }
};
