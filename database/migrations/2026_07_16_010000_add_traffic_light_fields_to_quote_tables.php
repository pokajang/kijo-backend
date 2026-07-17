<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'quotes_training',
        'quotes_ih',
        'quotes_equipment',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'estimated_total_cost')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->decimal('estimated_total_cost', 15, 2)->nullable()->after('grand_total');
                });
            }

            if (! Schema::hasColumn($tableName, 'traffic_light_rule_version')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('traffic_light_rule_version', 50)->nullable()->after('estimated_total_cost');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'traffic_light_rule_version')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('traffic_light_rule_version');
                });
            }

            if (Schema::hasColumn($tableName, 'estimated_total_cost')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('estimated_total_cost');
                });
            }
        }
    }
};
