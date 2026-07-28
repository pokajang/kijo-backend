<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CommercialCycleDatabaseGuard
{
    public static function assertSqliteMemory(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Commercial-cycle tests require SQLite :memory:; refusing to alter {$driver} database '{$database}'.",
            );
        }
    }
}
