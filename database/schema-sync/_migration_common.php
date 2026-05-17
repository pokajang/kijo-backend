<?php
declare(strict_types=1);

throw new RuntimeException('Legacy schema-sync scripts are disabled. Use Laravel migrations via php artisan migrate.');

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function ensureSchemaSyncTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_sync_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            requested_by VARCHAR(255) NOT NULL,
            status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            total_files INT UNSIGNED NOT NULL DEFAULT 0,
            changed_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_sync_file_state (
            file_name VARCHAR(255) NOT NULL PRIMARY KEY,
            file_hash CHAR(64) NOT NULL,
            last_status ENUM('success', 'failed') NOT NULL DEFAULT 'success',
            last_message TEXT NULL,
            last_changed TINYINT(1) NOT NULL DEFAULT 0,
            last_run_id BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ssfs_status (last_status),
            KEY idx_ssfs_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function ensureMigrationTables(PDO $pdo): void
{
    ensureSchemaSyncTables($pdo);
}

function getCurrentSessionUser(PDO $pdo): array
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        respondJson(403, [
            'status' => 'error',
            'message' => 'Unauthorized.',
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT email
        FROM system_users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $email = (string)$stmt->fetchColumn();

    $roles = [];
    if (isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
        $roles = $_SESSION['roles'];
    } elseif (isset($_SESSION['role'])) {
        $roles = [$_SESSION['role']];
    }

    return [
        'id' => $userId,
        'email' => $email,
        'roles' => $roles,
    ];
}

function requireSystemAdmin(array $user): void
{
    $roles = $user['roles'] ?? [];
    if (!in_array('System Admin', $roles, true)) {
        respondJson(403, [
            'status' => 'error',
            'message' => 'Unauthorized: System Admin only.',
        ]);
    }
}

function canRunMigrations(array $user): bool
{
    $email = strtolower(trim((string)($user['email'] ?? '')));
    return $email === 'azam@amiosh.com';
}

function getSchemaScriptDir(): string
{
    return __DIR__ . '/scripts';
}

function getMigrationDir(): string
{
    return getSchemaScriptDir();
}

function getSchemaScriptFiles(): array
{
    $dir = getSchemaScriptDir();
    if (!is_dir($dir)) {
        return [];
    }

    $paths = glob($dir . DIRECTORY_SEPARATOR . '*.php');
    if ($paths === false) {
        return [];
    }

    $files = array_map(
        static fn(string $path): string => basename($path),
        $paths
    );
    $files = array_values(array_filter(
        $files,
        static fn(string $name): bool => (bool)preg_match('/^\d{8}_[0-9]{3}_.+\.php$/', $name)
    ));
    sort($files, SORT_STRING);
    return $files;
}

function getMigrationFiles(): array
{
    return getSchemaScriptFiles();
}

function getSchemaScriptHash(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Failed to hash schema script: ' . $path);
    }
    return $hash;
}

function getSchemaFileStateLookup(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT file_name, file_hash, last_status, last_message, last_changed, last_run_id, updated_at
        FROM schema_sync_file_state
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[(string)$row['file_name']] = $row;
    }
    return $lookup;
}

function upsertSchemaFileState(
    PDO $pdo,
    string $fileName,
    string $fileHash,
    string $status,
    ?string $message,
    bool $changed,
    int $runId
): void {
    $stmt = $pdo->prepare("
        INSERT INTO schema_sync_file_state (file_name, file_hash, last_status, last_message, last_changed, last_run_id)
        VALUES (:file_name, :file_hash, :last_status, :last_message, :last_changed, :last_run_id)
        ON DUPLICATE KEY UPDATE
            file_hash = VALUES(file_hash),
            last_status = VALUES(last_status),
            last_message = VALUES(last_message),
            last_changed = VALUES(last_changed),
            last_run_id = VALUES(last_run_id),
            updated_at = NOW()
    ");
    $stmt->execute([
        ':file_name' => $fileName,
        ':file_hash' => $fileHash,
        ':last_status' => $status,
        ':last_message' => $message,
        ':last_changed' => $changed ? 1 : 0,
        ':last_run_id' => $runId,
    ]);
}

function normalizeSchemaScriptResult(mixed $result): array
{
    if ($result === null) {
        return ['changed' => false, 'message' => null];
    }
    if (is_bool($result)) {
        return ['changed' => $result, 'message' => null];
    }
    if (is_string($result)) {
        $message = trim($result);
        return [
            'changed' => $message !== '',
            'message' => $message !== '' ? $message : null,
        ];
    }
    if (!is_array($result)) {
        throw new RuntimeException('Schema script must return null, bool, string, or array.');
    }

    $changed = false;
    if (array_key_exists('changed', $result)) {
        $changed = (bool)$result['changed'];
    } elseif (isset($result['changes']) && is_array($result['changes'])) {
        $changed = count($result['changes']) > 0;
    }

    $message = null;
    if (isset($result['message'])) {
        $message = trim((string)$result['message']);
        if ($message === '') {
            $message = null;
        }
    } elseif (isset($result['changes']) && is_array($result['changes']) && !empty($result['changes'])) {
        $parts = [];
        foreach ($result['changes'] as $change) {
            $changeText = trim((string)$change);
            if ($changeText !== '') {
                $parts[] = $changeText;
            }
        }
        if (!empty($parts)) {
            $message = implode('; ', $parts);
        }
    }

    return ['changed' => $changed, 'message' => $message];
}

function loadSchemaScriptRunner(string $path): callable
{
    $runner = require $path;
    if (!is_callable($runner)) {
        throw new RuntimeException('Schema script must return a callable: ' . basename($path));
    }
    return $runner;
}

function schemaQuoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid schema identifier: ' . $identifier);
    }
    return '`' . $identifier . '`';
}

function schemaTableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
        LIMIT 1
    ");
    $stmt->execute([':table_name' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

function schemaColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);
    return (bool)$stmt->fetchColumn();
}

function schemaIndexExists(PDO $pdo, string $tableName, string $indexName): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);
    return (bool)$stmt->fetchColumn();
}

function schemaPrimaryKeyExists(PDO $pdo, string $tableName): bool
{
    return schemaIndexExists($pdo, $tableName, 'PRIMARY');
}

function schemaAddColumnIfMissing(
    PDO $pdo,
    string $tableName,
    string $columnName,
    string $definitionSql,
    array &$changes
): bool {
    if (schemaColumnExists($pdo, $tableName, $columnName)) {
        return false;
    }

    $table = schemaQuoteIdentifier($tableName);
    $column = schemaQuoteIdentifier($columnName);
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definitionSql}");
    $changes[] = "Added column {$tableName}.{$columnName}";
    return true;
}

function schemaAddPrimaryKeyIfMissing(PDO $pdo, string $tableName, array $columns, array &$changes): bool
{
    if (schemaPrimaryKeyExists($pdo, $tableName)) {
        return false;
    }

    if (empty($columns)) {
        throw new InvalidArgumentException('Primary key column list cannot be empty.');
    }

    $quotedColumns = [];
    foreach ($columns as $column) {
        $quotedColumns[] = schemaQuoteIdentifier((string)$column);
    }

    $table = schemaQuoteIdentifier($tableName);
    $pdo->exec("ALTER TABLE {$table} ADD PRIMARY KEY (" . implode(', ', $quotedColumns) . ")");
    $changes[] = 'Added primary key on ' . $tableName;
    return true;
}

function schemaAddIndexIfMissing(
    PDO $pdo,
    string $tableName,
    string $indexName,
    array $columns,
    array &$changes,
    bool $unique = false
): bool {
    if (schemaIndexExists($pdo, $tableName, $indexName)) {
        return false;
    }
    if (empty($columns)) {
        throw new InvalidArgumentException('Index column list cannot be empty.');
    }

    $quotedColumns = [];
    foreach ($columns as $column) {
        $quotedColumns[] = schemaQuoteIdentifier((string)$column);
    }

    $table = schemaQuoteIdentifier($tableName);
    $index = schemaQuoteIdentifier($indexName);
    $uniqueSql = $unique ? 'UNIQUE ' : '';
    $pdo->exec(
        "ALTER TABLE {$table} ADD {$uniqueSql}INDEX {$index} (" . implode(', ', $quotedColumns) . ")"
    );

    $changes[] = 'Added index ' . $tableName . '.' . $indexName;
    return true;
}

function getAppliedMigrationRows(PDO $pdo): array
{
    return [];
}

function getAppliedMigrationLookup(PDO $pdo): array
{
    return [];
}
