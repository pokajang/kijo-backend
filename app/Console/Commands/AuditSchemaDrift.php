<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AuditSchemaDrift extends Command
{
    protected $signature = 'app:audit-schema-drift
        {--path=* : File or directory paths to scan. Defaults to backend service/controller/request code.}
        {--format=table : Output format: table or json.}
        {--include-ok : Include non-problem code references in JSON output.}
        {--fail-on= : Exit with failure when this severity or higher is present: warning, likely_runtime_error, blocker.}';

    protected $description = 'Report likely schema drift between live database columns and backend DB query assumptions.';

    private const SEVERITY_RANKS = [
        'ok' => 0,
        'warning' => 1,
        'likely_runtime_error' => 2,
        'blocker' => 3,
    ];

    private const DEFAULT_SCAN_PATHS = [
        'app/Services',
        'app/Http/Controllers',
        'app/Http/Requests',
    ];

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error('Invalid --format. Use table or json.');
            return self::FAILURE;
        }

        $schema = $this->loadSchema();
        $files = $this->phpFilesForScanPaths($this->scanPaths());
        $references = [];
        $findings = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $references = array_merge($references, $this->extractDbReferences($file, $contents));
            $findings = array_merge($findings, $this->extractSilentDatabaseErrorFindings($file, $contents));
        }

        foreach ($references as $reference) {
            $findings = array_merge($findings, $this->compareReferenceToSchema($reference, $schema));
        }

        $findings = $this->sortFindings($findings);
        $summary = $this->summary($findings, count($files), count($schema));

        if ($format === 'json') {
            $payload = [
                'summary' => $summary,
                'findings' => $findings,
            ];
            if ((bool) $this->option('include-ok')) {
                $payload['references'] = $references;
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTableOutput($summary, $findings);
        }

        return $this->shouldFail($findings) ? self::FAILURE : self::SUCCESS;
    }

    private function loadSchema(): array
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => $this->loadMySqlSchema(),
            'sqlite' => $this->loadSqliteSchema(),
            default => $this->loadPortableSchema(),
        };
    }

    private function loadMySqlSchema(): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION",
        );

        $schema = [];
        foreach ($rows as $row) {
            $table = (string) $row->TABLE_NAME;
            $column = (string) $row->COLUMN_NAME;
            $schema[$table]['columns'][$column] = [
                'nullable' => strtoupper((string) $row->IS_NULLABLE) === 'YES',
                'default' => $row->COLUMN_DEFAULT,
                'type' => (string) $row->COLUMN_TYPE,
                'auto_increment' => str_contains(strtolower((string) $row->EXTRA), 'auto_increment'),
            ];
        }

        return $schema;
    }

    private function loadSqliteSchema(): array
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $schema = [];

        foreach ($tables as $tableRow) {
            $table = (string) $tableRow->name;
            $columns = DB::select("PRAGMA table_info('{$table}')");
            foreach ($columns as $columnRow) {
                $schema[$table]['columns'][(string) $columnRow->name] = [
                    'nullable' => (int) $columnRow->notnull === 0,
                    'default' => $columnRow->dflt_value,
                    'type' => (string) $columnRow->type,
                    'auto_increment' => (int) $columnRow->pk === 1,
                ];
            }
        }

        return $schema;
    }

    private function loadPortableSchema(): array
    {
        $schema = [];
        foreach (Schema::getTables() as $tableInfo) {
            $table = (string) ($tableInfo['name'] ?? $tableInfo->name ?? '');
            if ($table === '') {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                $schema[$table]['columns'][$column] = [
                    'nullable' => true,
                    'default' => null,
                    'type' => '',
                    'auto_increment' => false,
                ];
            }
        }

        return $schema;
    }

    private function scanPaths(): array
    {
        $paths = $this->option('path');
        if (! is_array($paths) || empty($paths)) {
            $paths = self::DEFAULT_SCAN_PATHS;
        }

        return array_values(array_unique(array_map(fn ($path): string => $this->absolutePath((string) $path), $paths)));
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return base_path();
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function phpFilesForScanPaths(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path) && str_ends_with($path, '.php')) {
                $files[] = $path;
                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    private function extractDbReferences(string $file, string $contents): array
    {
        $references = [];
        preg_match_all('/DB::table\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as $match) {
            [$tableExpression, $offset] = $match;
            if (str_contains((string) $tableExpression, '$') || str_contains((string) $tableExpression, '{')) {
                continue;
            }

            $statement = $this->statementFromOffset($contents, $offset);
            $line = $this->lineForOffset($contents, $offset);
            [$table, $alias] = $this->normalizeTableExpression((string) $tableExpression);
            if ($this->isExternalSchemaTable($table)) {
                continue;
            }

            $references = array_merge($references, $this->columnReferencesFromStatement($file, $line, $table, $alias, $statement));
        }

        return $references;
    }

    private function columnReferencesFromStatement(string $file, int $line, string $table, ?string $alias, string $statement): array
    {
        $references = [];

        foreach ($this->readColumns($statement) as $operation => $columns) {
            foreach ($columns as $column) {
                $column = $this->normalizeColumnName($column, $table, $alias);
                if ($column === null) {
                    continue;
                }

                $references[] = $this->reference($file, $line, $table, $column, $operation);
            }
        }

        foreach (['insertGetId', 'insert', 'update', 'upsert'] as $operation) {
            $write = $this->writeColumns($statement, $operation);
            if ($write === null) {
                continue;
            }

            $columns = $write['columns'];
            $references[] = $this->reference(
                $file,
                $line,
                $table,
                null,
                $operation,
                $columns,
                (bool) $write['partially_dynamic'],
            );
            foreach ($columns as $column) {
                $references[] = $this->reference($file, $line, $table, $column, $operation);
            }
        }

        return $references;
    }

    private function readColumns(string $statement): array
    {
        $columns = [
            'select' => [],
            'where' => [],
            'order' => [],
        ];

        if (preg_match_all('/->select\((.*?)\)/s', $statement, $matches)) {
            foreach ($matches[1] as $argument) {
                $columns['select'] = array_merge($columns['select'], $this->selectArgumentColumns($argument));
            }
        }

        if (preg_match_all('/->(where|orWhere|whereNull|whereNotNull|whereDate|whereYear|whereMonth)\(\s*[\'"]([^\'"]+)[\'"]/s', $statement, $matches)) {
            $columns['where'] = array_merge($columns['where'], $matches[2]);
        }

        if (preg_match_all('/->(orderBy|orderByDesc|latest|oldest)\(\s*[\'"]([^\'"]+)[\'"]/s', $statement, $matches)) {
            $columns['order'] = array_merge($columns['order'], $matches[2]);
        }

        return array_map(fn ($items): array => array_values(array_unique($items)), $columns);
    }

    private function selectArgumentColumns(string $argument): array
    {
        $argument = trim($argument);
        if ($argument === '') {
            return [];
        }

        if (str_starts_with($argument, '[')) {
            return $this->stringLiterals($argument);
        }

        if (str_contains($argument, '$') || str_contains($argument, '::') || str_contains($argument, '->') || str_contains($argument, '(')) {
            return [];
        }

        return $this->stringLiterals($argument);
    }

    private function writeColumns(string $statement, string $operation): ?array
    {
        if (! preg_match_all('/->' . preg_quote($operation, '/') . '\s*\(/', $statement, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $columns = [];
        $partiallyDynamic = false;

        foreach ($matches[0] as $match) {
            $argumentOffset = $match[1] + strlen($match[0]);
            $argumentOffset = $this->skipWhitespace($statement, $argumentOffset);
            if (($statement[$argumentOffset] ?? '') !== '[') {
                continue;
            }

            $arrayLiteral = $this->balancedArrayLiteral($statement, $argumentOffset);
            if ($arrayLiteral === null) {
                continue;
            }

            $columns = array_merge($columns, $this->topLevelArrayKeys($arrayLiteral));
            $partiallyDynamic = $partiallyDynamic || $this->hasTopLevelSpread($arrayLiteral);
        }

        if (empty($columns) && ! $partiallyDynamic) {
            return null;
        }

        return [
            'columns' => array_values(array_unique($columns)),
            'partially_dynamic' => $partiallyDynamic,
        ];
    }

    private function stringLiterals(string $argument): array
    {
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argument, $matches);

        $columns = [];
        foreach ($matches[1] ?? [] as $column) {
            $column = trim((string) $column);
            if (
                $column === '*'
                || str_ends_with($column, '.*')
                || str_contains(strtolower($column), ' as ')
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1
            ) {
                continue;
            }

            $columns[] = $column;
        }

        return array_values(array_unique($columns));
    }

    private function statementFromOffset(string $contents, int $offset): string
    {
        $end = strpos($contents, ';', $offset);
        if ($end === false) {
            $end = min(strlen($contents), $offset + 2000);
        }

        return substr($contents, $offset, $end - $offset + 1);
    }

    private function lineForOffset(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }

    private function normalizeTableExpression(string $tableExpression): array
    {
        $tableExpression = trim($tableExpression);
        $parts = preg_split('/\s+as\s+|\s+/i', $tableExpression);
        $table = trim((string) ($parts[0] ?? $tableExpression), '` ');
        $alias = isset($parts[1]) ? trim((string) $parts[1], '` ') : null;

        return [$table, $alias ?: null];
    }

    private function normalizeColumnName(string $column, string $table, ?string $alias): ?string
    {
        $column = trim($column, '` ');
        if ($column === '' || str_contains($column, '(') || str_contains($column, ' ')) {
            return null;
        }

        if (str_contains($column, '.')) {
            [$prefix, $name] = explode('.', $column, 2);
            if ($prefix !== $table && $prefix !== $alias) {
                return null;
            }

            $name = trim($name, '` ');
            return $name === '*' ? null : $name;
        }

        return $column === '*' ? null : $column;
    }

    private function isExternalSchemaTable(string $table): bool
    {
        return str_starts_with(strtolower($table), 'information_schema.');
    }

    private function skipWhitespace(string $contents, int $offset): int
    {
        $length = strlen($contents);
        while ($offset < $length && ctype_space($contents[$offset])) {
            $offset++;
        }

        return $offset;
    }

    private function balancedArrayLiteral(string $contents, int $start): ?string
    {
        $length = strlen($contents);
        $depth = 0;
        $quote = null;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $contents[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '[') {
                $depth++;
                continue;
            }

            if ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($contents, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function topLevelArrayKeys(string $arrayLiteral): array
    {
        $body = substr($arrayLiteral, 1, -1);
        $length = strlen($body);
        $keys = [];
        $depth = 0;
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $token = substr($body, $quoteStart + 1, $i - $quoteStart - 1);
                    $next = $this->skipWhitespace($body, $i + 1);
                    if ($depth === 0 && substr($body, $next, 2) === '=>' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token) === 1) {
                        $keys[] = $token;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $quoteStart = $i;
                continue;
            }

            if ($char === '[' || $char === '(' || $char === '{') {
                $depth++;
                continue;
            }

            if (($char === ']' || $char === ')' || $char === '}') && $depth > 0) {
                $depth--;
            }
        }

        return array_values(array_unique($keys));
    }

    private function hasTopLevelSpread(string $arrayLiteral): bool
    {
        $body = substr($arrayLiteral, 1, -1);
        $length = strlen($body);
        $depth = 0;
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '[' || $char === '(' || $char === '{') {
                $depth++;
                continue;
            }

            if (($char === ']' || $char === ')' || $char === '}') && $depth > 0) {
                $depth--;
                continue;
            }

            if ($depth === 0 && substr($body, $i, 3) === '...') {
                return true;
            }
        }

        return false;
    }

    private function reference(string $file, int $line, string $table, ?string $column, string $operation, array $columns = [], bool $partiallyDynamic = false): array
    {
        return [
            'file' => $this->relativePath($file),
            'line' => $line,
            'module' => $this->moduleForFile($file),
            'table' => $table,
            'column' => $column,
            'operation' => $operation,
            'columns' => $columns,
            'partially_dynamic' => $partiallyDynamic,
        ];
    }

    private function compareReferenceToSchema(array $reference, array $schema): array
    {
        $table = (string) $reference['table'];
        $column = $reference['column'];

        if (! isset($schema[$table])) {
            return [$this->finding($reference, 'likely_runtime_error', 'missing_table', 'table missing', 'Create the table migration or correct the DB::table reference.')];
        }

        if (
            in_array($reference['operation'], ['insert', 'insertGetId'], true)
            && ! empty($reference['columns'])
            && ! ($reference['partially_dynamic'] ?? false)
        ) {
            return $this->missingRequiredInsertColumns($reference, $schema[$table]['columns']);
        }

        if ($column === null) {
            return [];
        }

        if (! isset($schema[$table]['columns'][$column])) {
            $severity = in_array($reference['operation'], ['insert', 'insertGetId', 'update', 'upsert'], true)
                ? 'blocker'
                : 'likely_runtime_error';

            return [$this->finding(
                $reference,
                $severity,
                'missing_column',
                'column missing',
                $this->missingColumnRecommendation((string) $column),
            )];
        }

        return [];
    }

    private function missingRequiredInsertColumns(array $reference, array $columns): array
    {
        $findings = [];
        $insertColumns = array_flip($reference['columns']);

        foreach ($columns as $column => $meta) {
            if (isset($insertColumns[$column]) || $this->columnCanBeOmitted($meta)) {
                continue;
            }

            $findings[] = $this->finding(
                array_merge($reference, ['column' => $column]),
                'blocker',
                'insert_omits_required_column',
                'required column omitted',
                'Add this column to the insert payload, make it nullable/defaulted, or add a migration that aligns the schema with the service.',
            );
        }

        return $findings;
    }

    private function columnCanBeOmitted(array $meta): bool
    {
        return (bool) ($meta['nullable'] ?? false)
            || ($meta['default'] ?? null) !== null
            || (bool) ($meta['auto_increment'] ?? false);
    }

    private function missingColumnRecommendation(string $column): string
    {
        if (in_array($column, ['created_at', 'updated_at', 'deleted_at', 'deleted_by'], true)) {
            return 'Add a guarded migration if this audit/soft-delete column is canonical; otherwise guard the query with Schema::hasColumn.';
        }

        return 'Add a migration for the missing column or update the service to use the actual schema.';
    }

    private function extractSilentDatabaseErrorFindings(string $file, string $contents): array
    {
        $findings = [];
        preg_match_all('/catch\s*\([^)]+\)\s*\{(?P<body>.*?)\n\s*\}/s', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['body'] as $match) {
            [$body, $offset] = $match;
            if (! str_contains($body, 'Database error')) {
                continue;
            }

            if (preg_match('/\b(report|logger)\s*\(|Log::|\\\\Log::/', $body) === 1) {
                continue;
            }

            $findings[] = [
                'severity' => 'warning',
                'category' => 'silent_database_error',
                'file' => $this->relativePath($file),
                'line' => $this->lineForOffset($contents, $offset),
                'module' => $this->moduleForFile($file),
                'table' => '',
                'column' => '',
                'operation' => 'catch',
                'schema_status' => 'exception not logged',
                'recommendation' => 'Log or report the caught exception before returning a generic Database error response.',
            ];
        }

        return $findings;
    }

    private function finding(array $reference, string $severity, string $category, string $schemaStatus, string $recommendation): array
    {
        return [
            'severity' => $severity,
            'category' => $category,
            'file' => $reference['file'],
            'line' => $reference['line'],
            'module' => $reference['module'],
            'table' => $reference['table'],
            'column' => $reference['column'] ?? '',
            'operation' => $reference['operation'],
            'schema_status' => $schemaStatus,
            'recommendation' => $recommendation,
        ];
    }

    private function sortFindings(array $findings): array
    {
        usort($findings, function (array $left, array $right): int {
            $rank = self::SEVERITY_RANKS[$right['severity']] <=> self::SEVERITY_RANKS[$left['severity']];
            if ($rank !== 0) {
                return $rank;
            }

            return [$left['file'], $left['line'], $left['table'], $left['column']]
                <=> [$right['file'], $right['line'], $right['table'], $right['column']];
        });

        return $findings;
    }

    private function summary(array $findings, int $filesScanned, int $tablesLoaded): array
    {
        $summary = [
            'files_scanned' => $filesScanned,
            'tables_loaded' => $tablesLoaded,
            'total_findings' => count($findings),
            'blocker' => 0,
            'likely_runtime_error' => 0,
            'warning' => 0,
        ];

        foreach ($findings as $finding) {
            if (isset($summary[$finding['severity']])) {
                $summary[$finding['severity']]++;
            }
        }

        return $summary;
    }

    private function renderTableOutput(array $summary, array $findings): void
    {
        $this->info('Schema drift audit');
        $this->line("Files scanned: {$summary['files_scanned']}");
        $this->line("Tables loaded: {$summary['tables_loaded']}");
        $this->line("Findings: {$summary['total_findings']} ({$summary['blocker']} blocker, {$summary['likely_runtime_error']} likely runtime error, {$summary['warning']} warning)");

        if (empty($findings)) {
            $this->info('No schema drift findings detected.');
            return;
        }

        $this->table(
            ['Severity', 'Category', 'File', 'Line', 'Table', 'Column', 'Operation', 'Schema status', 'Recommendation'],
            array_map(static fn (array $finding): array => [
                $finding['severity'],
                $finding['category'],
                $finding['file'],
                $finding['line'],
                $finding['table'],
                $finding['column'],
                $finding['operation'],
                $finding['schema_status'],
                $finding['recommendation'],
            ], $findings),
        );
    }

    private function shouldFail(array $findings): bool
    {
        $failOn = strtolower((string) $this->option('fail-on'));
        if ($failOn === '') {
            return false;
        }

        if (! isset(self::SEVERITY_RANKS[$failOn])) {
            $this->warn('Ignoring invalid --fail-on value. Use warning, likely_runtime_error, or blocker.');
            return false;
        }

        $threshold = self::SEVERITY_RANKS[$failOn];
        foreach ($findings as $finding) {
            if (self::SEVERITY_RANKS[$finding['severity']] >= $threshold) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $file): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $normalized = str_replace('\\', '/', $file);

        return str_starts_with($normalized, $base . '/')
            ? substr($normalized, strlen($base) + 1)
            : $normalized;
    }

    private function moduleForFile(string $file): string
    {
        $relative = $this->relativePath($file);
        if (preg_match('#app/(Services|Http/Controllers|Http/Requests)/([^/\\\\]+)#', $relative, $match) === 1) {
            return $match[2];
        }

        return '';
    }
}
