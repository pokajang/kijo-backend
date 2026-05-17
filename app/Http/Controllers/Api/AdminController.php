<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminController extends Controller
{
    private const ARCHIVED_APPLIED_MIGRATIONS = [
        '2014_10_12_000000_create_users_table' => 'Replaced by 0001_01_01_000000_create_users_table.php.',
        '2014_10_12_100000_create_password_reset_tokens_table' => 'Replaced by 0001_01_01_000000_create_users_table.php.',
        '2019_08_19_000000_create_failed_jobs_table' => 'Replaced by 0001_01_01_000002_create_jobs_table.php.',
        '2019_12_14_000001_create_personal_access_tokens_table' => 'Sanctum token table migration is retained only as historical applied state.',
        '2026_04_30_154829_create_sessions_table' => 'Replaced by the sessions table created in 0001_01_01_000000_create_users_table.php.',
    ];

    public function migrationStatus(Request $request)
    {
        try {
            $user = $this->currentUser($request);
            if (! $this->isSystemAdmin($user['roles'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: System Admin only.',
                ], 403);
            }

            $files = $this->laravelMigrationFiles();
            $fileLookup = [];
            foreach ($files as $file) {
                $fileLookup[(string) $file['migration']] = $file;
            }

            $appliedRows = $this->appliedLaravelMigrations();
            $appliedLookup = [];
            foreach ($appliedRows as $row) {
                $appliedLookup[(string) $row->migration] = $row;
            }

            $knownMigrations = array_values(array_unique(array_merge(
                array_keys($fileLookup),
                array_keys($appliedLookup)
            )));
            sort($knownMigrations, SORT_STRING);

            $pending = [];
            $missingFiles = [];
            $fileStatus = [];
            foreach ($knownMigrations as $migration) {
                $file = $fileLookup[$migration] ?? null;
                $applied = $appliedLookup[$migration] ?? null;
                $hasFile = $file !== null;
                $isApplied = $applied !== null;
                $archivedReason = self::ARCHIVED_APPLIED_MIGRATIONS[$migration] ?? null;
                $isArchivedApplied = ! $hasFile && $isApplied && $archivedReason !== null;

                if ($hasFile && ! $isApplied) {
                    $pending[] = $migration;
                }
                if (! $hasFile && $isApplied && ! $isArchivedApplied) {
                    $missingFiles[] = $migration;
                }

                $fileStatus[] = [
                    'name' => $migration,
                    'file_name' => $file['file_name'] ?? null,
                    'file_present' => $hasFile,
                    'archived' => $isArchivedApplied,
                    'archived_reason' => $archivedReason,
                    'applied' => $isApplied,
                    'synced' => $isApplied,
                    'batch' => $applied->batch ?? null,
                ];
            }

            $appliedKnown = count(array_filter($fileStatus, static fn (array $row): bool => (bool) $row['applied']));
            $latestBatch = $appliedRows->max('batch');

            return response()->json([
                'status' => 'success',
                'user' => [
                    'authorized' => true,
                    'can_run' => false,
                    'read_only' => true,
                ],
                'environment' => [
                    'migration_source' => 'laravel',
                ],
                'summary' => [
                    'total_files' => count($files),
                    'total_known' => count($knownMigrations),
                    'applied_count' => $appliedKnown,
                    'synced_count' => $appliedKnown,
                    'pending_count' => count($pending),
                    'missing_file_count' => count($missingFiles),
                    'archived_file_count' => count(array_filter(
                        $fileStatus,
                        static fn (array $row): bool => (bool) ($row['archived'] ?? false),
                    )),
                    'latest_batch' => $latestBatch,
                ],
                'pending' => $pending,
                'missing_files' => $missingFiles,
                'files' => $fileStatus,
                'runs' => [],
                'generated_at' => now()->toDateTimeString(),
            ]);
        } catch (HttpException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Unauthorized.',
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('Failed to load Laravel migration status', ['exception' => $e]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load Laravel migration status.',
            ], 500);
        }
    }

    public function runMigrations(Request $request)
    {
        try {
            $user = $this->currentUser($request);
            if (! $this->isSystemAdmin($user['roles'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: System Admin only.',
                ], 403);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Browser-run schema sync is disabled. Run Laravel migrations with php artisan migrate during deployment or from the server terminal.',
            ], 410);
        } catch (HttpException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Unauthorized.',
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('Migration run request failed', ['exception' => $e]);
            return response()->json(['status' => 'error', 'message' => 'Migration run request failed.'], 500);
        }
    }

    private function currentUser(Request $request): array
    {
        $userId = (int) $request->session()->get('user_id', 0);
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($userId <= 0 || $staffId <= 0) {
            throw new HttpException(403, 'Unauthorized. Please log in to continue.');
        }

        $user = DB::table('system_users')
            ->select(['id', 'staff_id', 'email', 'role', 'is_active'])
            ->where('id', $userId)
            ->first();

        if (
            ! $user ||
            (int) ($user->staff_id ?? 0) !== $staffId ||
            ! (bool) ($user->is_active ?? false)
        ) {
            $request->session()->invalidate();
            throw new HttpException(403, 'Unauthorized. Please log in to continue.');
        }

        return [
            'id' => $userId,
            'email' => (string) $user->email,
            'roles' => $this->decodeRoles($user->role ?? null),
        ];
    }

    private function isSystemAdmin(array $roles): bool
    {
        return in_array(
            'system admin',
            array_map(static fn (mixed $role): string => strtolower(trim((string) $role)), $roles),
            true,
        );
    }

    private function laravelMigrationFiles(): array
    {
        $paths = glob(database_path('migrations') . DIRECTORY_SEPARATOR . '*.php');
        if ($paths === false) {
            return [];
        }

        $files = array_map(static function (string $path): array {
            $fileName = basename($path);
            return [
                'migration' => preg_replace('/\.php$/', '', $fileName),
                'file_name' => $fileName,
                'modified_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            ];
        }, $paths);

        usort($files, static fn (array $a, array $b): int => strcmp($a['migration'], $b['migration']));

        return $files;
    }

    private function decodeRoles(mixed $raw): array
    {
        if (is_array($raw)) {
            return $this->normalizeRoles($raw);
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->normalizeRoles($decoded);
        }

        return $this->normalizeRoles([$raw]);
    }

    private function normalizeRoles(array $roles): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $role): string => trim((string) $role),
            $roles,
        ), static fn (string $role): bool => $role !== ''));
    }

    private function appliedLaravelMigrations()
    {
        if (! DB::getSchemaBuilder()->hasTable('migrations')) {
            return collect();
        }

        return DB::table('migrations')
            ->select(['id', 'migration', 'batch'])
            ->orderBy('id')
            ->get();
    }
}
