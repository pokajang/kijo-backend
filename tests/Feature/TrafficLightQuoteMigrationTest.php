<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrafficLightQuoteMigrationTest extends TestCase
{
    private const TABLES = [
        'quotes_training',
        'quotes_ih',
        'quotes_equipment',
    ];

    public function test_it_adds_and_removes_the_advisory_fields_for_all_supported_quote_types(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::dropIfExists($tableName);
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->decimal('grand_total', 15, 2)->default(0);
            });
        }

        $migration = require database_path('migrations/2026_07_16_010000_add_traffic_light_fields_to_quote_tables.php');
        $migration->up();

        foreach (self::TABLES as $tableName) {
            $this->assertTrue(Schema::hasColumn($tableName, 'estimated_total_cost'));
            $this->assertTrue(Schema::hasColumn($tableName, 'traffic_light_rule_version'));
        }

        $migration->down();

        foreach (self::TABLES as $tableName) {
            $this->assertFalse(Schema::hasColumn($tableName, 'estimated_total_cost'));
            $this->assertFalse(Schema::hasColumn($tableName, 'traffic_light_rule_version'));
            Schema::drop($tableName);
        }
    }
}
