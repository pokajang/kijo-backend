<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffPreferenceController extends Controller
{
    private const API_KEY_CRM_VISIBLE_COLUMNS = 'crm-records-all-visible-columns';
    private const DB_KEY_CRM_VISIBLE_COLUMNS = 'crm.records.all.visibleColumns';
    private const API_KEY_SERVICE_VISIBLE_COLUMNS_PREFIX = 'crm-records-service-visible-columns.';
    private const DB_KEY_SERVICE_VISIBLE_COLUMNS_PREFIX = 'crm.records.service.visibleColumns.';
    private const API_KEY_HANDBOOK_ACKNOWLEDGEMENTS_VISIBLE_COLUMNS = 'handbook-acknowledgements-visible-columns';
    private const DB_KEY_HANDBOOK_ACKNOWLEDGEMENTS_VISIBLE_COLUMNS = 'handbook.acknowledgements.visibleColumns';
    private const API_KEY_HANDBOOK_CHANGE_LOG_VISIBLE_COLUMNS = 'handbook-change-log-visible-columns';
    private const DB_KEY_HANDBOOK_CHANGE_LOG_VISIBLE_COLUMNS = 'handbook.changeLog.visibleColumns';
    private const API_KEY_MARKETING_PIPELINE_ENTRIES_VISIBLE_COLUMNS = 'marketing-pipeline-entries-visible-columns';
    private const DB_KEY_MARKETING_PIPELINE_ENTRIES_VISIBLE_COLUMNS = 'marketing.pipelineEntries.visibleColumns';
    private const API_KEY_MEETINGS_RECORDS_VISIBLE_COLUMNS = 'meetings-records-visible-columns';
    private const DB_KEY_MEETINGS_RECORDS_VISIBLE_COLUMNS = 'meetings.records.visibleColumns';
    private const API_KEY_PROCEDURE_RECORDS_VISIBLE_COLUMNS = 'procedure-records-visible-columns';
    private const DB_KEY_PROCEDURE_RECORDS_VISIBLE_COLUMNS = 'procedure.records.visibleColumns';
    private const API_KEY_STAFF_APPRAISE_RECORDS_VISIBLE_COLUMNS = 'staff-appraise-records-visible-columns';
    private const DB_KEY_STAFF_APPRAISE_RECORDS_VISIBLE_COLUMNS = 'staff.appraise.records.visibleColumns';
    private const API_KEY_APPRAISAL_PERSONAL_RECORDS_VISIBLE_COLUMNS = 'appraisal-personal-records-visible-columns';
    private const DB_KEY_APPRAISAL_PERSONAL_RECORDS_VISIBLE_COLUMNS = 'appraisal.personal.records.visibleColumns';
    private const API_KEY_SYSTEM_ADMIN_SCHEMA_SCRIPTS_VISIBLE_COLUMNS = 'system-admin-schema-scripts-visible-columns';
    private const DB_KEY_SYSTEM_ADMIN_SCHEMA_SCRIPTS_VISIBLE_COLUMNS = 'systemAdmin.schemaScripts.visibleColumns';
    private const API_KEY_SYSTEM_ADMIN_LARAVEL_MIGRATIONS_VISIBLE_COLUMNS = 'system-admin-laravel-migrations-visible-columns';
    private const DB_KEY_SYSTEM_ADMIN_LARAVEL_MIGRATIONS_VISIBLE_COLUMNS = 'systemAdmin.laravelMigrations.visibleColumns';
    private const API_KEY_SYSTEM_ADMIN_SCHEMA_RUNS_VISIBLE_COLUMNS = 'system-admin-schema-runs-visible-columns';
    private const DB_KEY_SYSTEM_ADMIN_SCHEMA_RUNS_VISIBLE_COLUMNS = 'systemAdmin.schemaRuns.visibleColumns';
    private const API_KEY_TASK_MANAGER_TASKS_VISIBLE_COLUMNS = 'task-manager-tasks-visible-columns';
    private const DB_KEY_TASK_MANAGER_TASKS_VISIBLE_COLUMNS = 'taskManager.tasks.visibleColumns';

    private const VISIBLE_COLUMN_KEYS = [
        'service',
        'quotationId',
        'client',
        'email',
        'status',
        'subject',
        'amount',
        'created',
        'age',
        'pic',
        'remarks',
    ];

    private const SERVICE_VISIBLE_COLUMN_KEYS = [
        'quotationId',
        'client',
        'email',
        'status',
        'subject',
        'amount',
        'created',
        'age',
        'pic',
        'remarks',
    ];

    private const CRM_REQUIRED_VISIBLE_COLUMNS = ['quotationId', 'client', 'status'];

    private const HANDBOOK_ACKNOWLEDGEMENT_VISIBLE_COLUMN_KEYS = [
        'version',
        'fullName',
        'signedAt',
        'ipAddress',
        'userAgent',
    ];

    private const HANDBOOK_ACKNOWLEDGEMENT_REQUIRED_VISIBLE_COLUMNS = ['fullName', 'signedAt'];

    private const HANDBOOK_CHANGE_LOG_VISIBLE_COLUMN_KEYS = [
        'version',
        'action',
        'section',
        'summary',
        'changedBy',
        'changedAt',
    ];

    private const HANDBOOK_CHANGE_LOG_REQUIRED_VISIBLE_COLUMNS = ['version', 'summary', 'changedAt'];

    private const MARKETING_PIPELINE_ENTRY_VISIBLE_COLUMN_KEYS = [
        'entryDate',
        'entryType',
        'prospectName',
        'source',
        'segmentType',
        'serviceCategory',
        'estimatedRm',
        'ownerStaffCode',
        'photoUrl',
        'notes',
    ];

    private const MARKETING_PIPELINE_ENTRY_REQUIRED_VISIBLE_COLUMNS = [
        'entryDate',
        'entryType',
        'prospectName',
    ];

    private const MEETINGS_RECORD_VISIBLE_COLUMN_KEYS = [
        'title',
        'meetingDate',
        'meetingType',
        'pendingItems',
    ];

    private const MEETINGS_RECORD_REQUIRED_VISIBLE_COLUMNS = ['title', 'pendingItems'];

    private const PROCEDURE_RECORD_VISIBLE_COLUMN_KEYS = [
        'title',
        'category',
        'description',
        'date',
        'createdBy',
    ];

    private const PROCEDURE_RECORD_REQUIRED_VISIBLE_COLUMNS = ['title', 'category'];

    private const STAFF_APPRAISE_RECORD_VISIBLE_COLUMN_KEYS = [
        'createdAt',
        'staff',
        'appraisalBy',
        'eventDate',
        'section',
        'feedback',
    ];

    private const STAFF_APPRAISE_RECORD_REQUIRED_VISIBLE_COLUMNS = ['staff', 'section'];

    private const APPRAISAL_PERSONAL_RECORD_VISIBLE_COLUMN_KEYS = [
        'createdAt',
        'appraisedBy',
        'eventDate',
        'section',
        'feedback',
    ];

    private const APPRAISAL_PERSONAL_RECORD_REQUIRED_VISIBLE_COLUMNS = ['createdAt', 'section'];

    private const SYSTEM_ADMIN_SCHEMA_SCRIPT_VISIBLE_COLUMN_KEYS = [
        'migration',
        'fileStatus',
        'databaseStatus',
        'batch',
        'drift',
    ];

    private const SYSTEM_ADMIN_SCHEMA_SCRIPT_REQUIRED_VISIBLE_COLUMNS = ['migration', 'databaseStatus'];

    private const SYSTEM_ADMIN_SCHEMA_RUN_VISIBLE_COLUMN_KEYS = [
        'id',
        'migration',
        'batch',
        'status',
    ];

    private const SYSTEM_ADMIN_SCHEMA_RUN_REQUIRED_VISIBLE_COLUMNS = ['migration', 'batch'];

    private const TASK_MANAGER_TASK_VISIBLE_COLUMN_KEYS = [
        'title',
        'statusText',
        'createdAt',
        'dueDate',
        'daysLapsed',
        'commentSummary',
    ];

    private const TASK_MANAGER_TASK_REQUIRED_VISIBLE_COLUMNS = ['title', 'statusText'];

    public function show(Request $request, string $key): JsonResponse
    {
        $resolvedKey = $this->resolvePreferenceKey($key);
        if ($resolvedKey === null) {
            return response()->json(['status' => 'error', 'message' => 'Preference key not supported.'], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $row = DB::table('staff_preferences')
            ->where('staff_id', $staffId)
            ->where('preference_key', $resolvedKey)
            ->first();

        $storedValue = null;
        if ($row?->preference_value !== null) {
            $decoded = json_decode((string) $row->preference_value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $storedValue = $decoded;
            }
        }

        $normalizedValue = $this->normalizePreferenceValue($resolvedKey, $storedValue);

        return response()->json([
            'status' => 'success',
            'message' => 'Preference loaded.',
            'data' => [
                'key' => $key,
                'value' => $normalizedValue,
            ],
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $resolvedKey = $this->resolvePreferenceKey($key);
        if ($resolvedKey === null) {
            return response()->json(['status' => 'error', 'message' => 'Preference key not supported.'], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $validated = $request->validate([
            'value' => ['required', 'array'],
        ]);

        $normalizedValue = $this->normalizePreferenceValue($resolvedKey, $validated['value']);
        $now = now();

        DB::table('staff_preferences')->upsert(
            [[
                'staff_id' => $staffId,
                'preference_key' => $resolvedKey,
                'preference_value' => json_encode($normalizedValue),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['staff_id', 'preference_key'],
            ['preference_value', 'updated_at']
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Preference saved.',
            'data' => [
                'key' => $key,
                'value' => $normalizedValue,
            ],
        ]);
    }

    private function resolvePreferenceKey(string $key): ?string
    {
        foreach ($this->visibleColumnPreferenceKeys() as $apiKey => $dbKey) {
            $resolvedKey = $this->resolveVersionedPreferenceKey($key, $apiKey, $dbKey);
            if ($resolvedKey !== null) {
                return $resolvedKey;
            }
        }

        if (str_starts_with($key, self::API_KEY_SERVICE_VISIBLE_COLUMNS_PREFIX)) {
            $serviceKey = substr($key, strlen(self::API_KEY_SERVICE_VISIBLE_COLUMNS_PREFIX));
            if ($serviceKey === '' || !preg_match('/^[a-z0-9-]+(?:\.v\d+)?$/', $serviceKey)) {
                return null;
            }

            return self::DB_KEY_SERVICE_VISIBLE_COLUMNS_PREFIX . $serviceKey;
        }

        return null;
    }

    private function normalizePreferenceValue(string $key, mixed $value): array
    {
        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_CRM_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::VISIBLE_COLUMN_KEYS,
                self::CRM_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_HANDBOOK_ACKNOWLEDGEMENTS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::HANDBOOK_ACKNOWLEDGEMENT_VISIBLE_COLUMN_KEYS,
                self::HANDBOOK_ACKNOWLEDGEMENT_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_HANDBOOK_CHANGE_LOG_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::HANDBOOK_CHANGE_LOG_VISIBLE_COLUMN_KEYS,
                self::HANDBOOK_CHANGE_LOG_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_MARKETING_PIPELINE_ENTRIES_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::MARKETING_PIPELINE_ENTRY_VISIBLE_COLUMN_KEYS,
                self::MARKETING_PIPELINE_ENTRY_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_MEETINGS_RECORDS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::MEETINGS_RECORD_VISIBLE_COLUMN_KEYS,
                self::MEETINGS_RECORD_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_PROCEDURE_RECORDS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::PROCEDURE_RECORD_VISIBLE_COLUMN_KEYS,
                self::PROCEDURE_RECORD_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_STAFF_APPRAISE_RECORDS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::STAFF_APPRAISE_RECORD_VISIBLE_COLUMN_KEYS,
                self::STAFF_APPRAISE_RECORD_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_APPRAISAL_PERSONAL_RECORDS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::APPRAISAL_PERSONAL_RECORD_VISIBLE_COLUMN_KEYS,
                self::APPRAISAL_PERSONAL_RECORD_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_SYSTEM_ADMIN_SCHEMA_SCRIPTS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::SYSTEM_ADMIN_SCHEMA_SCRIPT_VISIBLE_COLUMN_KEYS,
                self::SYSTEM_ADMIN_SCHEMA_SCRIPT_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_SYSTEM_ADMIN_LARAVEL_MIGRATIONS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::SYSTEM_ADMIN_SCHEMA_SCRIPT_VISIBLE_COLUMN_KEYS,
                self::SYSTEM_ADMIN_SCHEMA_SCRIPT_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_SYSTEM_ADMIN_SCHEMA_RUNS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::SYSTEM_ADMIN_SCHEMA_RUN_VISIBLE_COLUMN_KEYS,
                self::SYSTEM_ADMIN_SCHEMA_RUN_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if ($this->matchesVersionedPreferenceKey($key, self::DB_KEY_TASK_MANAGER_TASKS_VISIBLE_COLUMNS)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::TASK_MANAGER_TASK_VISIBLE_COLUMN_KEYS,
                self::TASK_MANAGER_TASK_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        if (str_starts_with($key, self::DB_KEY_SERVICE_VISIBLE_COLUMNS_PREFIX)) {
            return $this->normalizeVisibleColumns(
                $value,
                self::SERVICE_VISIBLE_COLUMN_KEYS,
                self::CRM_REQUIRED_VISIBLE_COLUMNS,
            );
        }

        return [];
    }

    private function visibleColumnPreferenceKeys(): array
    {
        return [
            self::API_KEY_CRM_VISIBLE_COLUMNS => self::DB_KEY_CRM_VISIBLE_COLUMNS,
            self::API_KEY_HANDBOOK_ACKNOWLEDGEMENTS_VISIBLE_COLUMNS => self::DB_KEY_HANDBOOK_ACKNOWLEDGEMENTS_VISIBLE_COLUMNS,
            self::API_KEY_HANDBOOK_CHANGE_LOG_VISIBLE_COLUMNS => self::DB_KEY_HANDBOOK_CHANGE_LOG_VISIBLE_COLUMNS,
            self::API_KEY_MARKETING_PIPELINE_ENTRIES_VISIBLE_COLUMNS => self::DB_KEY_MARKETING_PIPELINE_ENTRIES_VISIBLE_COLUMNS,
            self::API_KEY_MEETINGS_RECORDS_VISIBLE_COLUMNS => self::DB_KEY_MEETINGS_RECORDS_VISIBLE_COLUMNS,
            self::API_KEY_PROCEDURE_RECORDS_VISIBLE_COLUMNS => self::DB_KEY_PROCEDURE_RECORDS_VISIBLE_COLUMNS,
            self::API_KEY_STAFF_APPRAISE_RECORDS_VISIBLE_COLUMNS => self::DB_KEY_STAFF_APPRAISE_RECORDS_VISIBLE_COLUMNS,
            self::API_KEY_APPRAISAL_PERSONAL_RECORDS_VISIBLE_COLUMNS => self::DB_KEY_APPRAISAL_PERSONAL_RECORDS_VISIBLE_COLUMNS,
            self::API_KEY_SYSTEM_ADMIN_SCHEMA_SCRIPTS_VISIBLE_COLUMNS => self::DB_KEY_SYSTEM_ADMIN_SCHEMA_SCRIPTS_VISIBLE_COLUMNS,
            self::API_KEY_SYSTEM_ADMIN_LARAVEL_MIGRATIONS_VISIBLE_COLUMNS => self::DB_KEY_SYSTEM_ADMIN_LARAVEL_MIGRATIONS_VISIBLE_COLUMNS,
            self::API_KEY_SYSTEM_ADMIN_SCHEMA_RUNS_VISIBLE_COLUMNS => self::DB_KEY_SYSTEM_ADMIN_SCHEMA_RUNS_VISIBLE_COLUMNS,
            self::API_KEY_TASK_MANAGER_TASKS_VISIBLE_COLUMNS => self::DB_KEY_TASK_MANAGER_TASKS_VISIBLE_COLUMNS,
        ];
    }

    private function resolveVersionedPreferenceKey(string $key, string $apiKey, string $dbKey): ?string
    {
        if ($key === $apiKey) {
            return $dbKey;
        }

        $pattern = '/^' . preg_quote($apiKey, '/') . '-v(\d+)$/';
        if (preg_match($pattern, $key, $matches) !== 1) {
            return null;
        }

        return $dbKey . '.v' . $matches[1];
    }

    private function matchesVersionedPreferenceKey(string $key, string $dbKey): bool
    {
        return $key === $dbKey || preg_match('/^' . preg_quote($dbKey, '/') . '\.v\d+$/', $key) === 1;
    }

    private function normalizeVisibleColumns(mixed $value, array $allowedKeys, array $requiredKeys): array
    {
        $input = is_array($value) ? $value : [];
        $normalized = [];

        foreach ($allowedKeys as $columnKey) {
            $raw = $input[$columnKey] ?? null;
            $normalized[$columnKey] = $raw === null ? true : $this->coerceBoolean($raw);
        }

        foreach ($requiredKeys as $columnKey) {
            $normalized[$columnKey] = true;
        }

        return $normalized;
    }

    private function coerceBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }
}
