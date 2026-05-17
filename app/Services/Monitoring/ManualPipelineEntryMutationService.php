<?php

namespace App\Services\Monitoring;

use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualPipelineEntryMutationService extends ManualPipelineEntryBaseService
{

    public function create(Request $request): JsonResponse
    {
        $storedPhotoPaths = [];

        try {
            if (!$this->entriesTableReady()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Manual monitoring entries table is not available.',
                ], 409);
            }

            if (is_string($request->input('entries'))) {
                $decodedEntries = json_decode((string) $request->input('entries'), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge(['entries' => $decodedEntries]);
                }
            }

            $data = $request->validate([
                'entry_type' => ['required_without:entries', 'in:lead,qualified,meeting_pitching,proposal,negotiation,closed'],
                'entry_date' => ['required_without:entries', 'date'],
                'source' => ['required_without:entries', 'string', 'max:80'],
                'owner_staff_code' => ['nullable', 'string', 'max:30'],
                'segment_type' => ['nullable', 'in:individual,special_project,tender'],
                'service_category' => ['nullable', 'in:' . implode(',', array_keys(self::SERVICE_CATEGORIES))],
                'estimated_rm' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'prospect_name' => ['nullable', 'string', 'max:191'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'entries' => ['nullable', 'array', 'max:100'],
                'entries.*.entry_type' => ['nullable', 'in:lead,qualified,meeting_pitching,proposal,negotiation,closed'],
                'entries.*.entry_date' => ['nullable', 'date'],
                'entries.*.source' => ['nullable', 'string', 'max:80'],
                'entries.*.prospect_name' => ['required_with:entries', 'string', 'max:191'],
                'entries.*.notes' => ['nullable', 'string', 'max:2000'],
                'entries.*.segment_type' => ['nullable', 'in:individual,special_project,tender'],
                'entries.*.service_category' => ['nullable', 'in:' . implode(',', array_keys(self::SERVICE_CATEGORIES))],
                'entries.*.estimated_rm' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'photos' => ['nullable', 'array', 'max:100'],
                'photos.*' => ['nullable', 'image', 'max:500'],
            ]);

            $entries = $data['entries'] ?? [[
                'entry_type' => $data['entry_type'] ?? '',
                'entry_date' => $data['entry_date'] ?? '',
                'source' => $data['source'] ?? '',
                'prospect_name' => $data['prospect_name'] ?? '',
                'notes' => $data['notes'] ?? '',
                'segment_type' => $data['segment_type'] ?? null,
                'service_category' => $data['service_category'] ?? null,
                'estimated_rm' => $data['estimated_rm'] ?? null,
            ]];

            $entries = collect($entries)
                ->map(fn($entry) => [
                    'entry_type' => $entry['entry_type'] ?? ($data['entry_type'] ?? null),
                    'entry_date' => $entry['entry_date'] ?? ($data['entry_date'] ?? null),
                    'source' => trim((string) ($entry['source'] ?? ($data['source'] ?? ''))),
                    'prospect_name' => trim((string) ($entry['prospect_name'] ?? '')),
                    'notes' => trim((string) ($entry['notes'] ?? '')),
                    'segment_type' => $entry['segment_type'] ?? ($data['segment_type'] ?? null),
                    'service_category' => $entry['service_category'] ?? ($data['service_category'] ?? null),
                    'estimated_rm' => $entry['estimated_rm'] ?? ($data['estimated_rm'] ?? null),
                ])
                ->filter(fn($entry) => $entry['prospect_name'] !== '')
                ->values()
                ->all();

            if (count($entries) === 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'At least one prospect name is required.',
                ], 422);
            }

            foreach ($entries as $entry) {
                if (empty($entry['entry_type']) || empty($entry['entry_date']) || $entry['source'] === '') {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Each manual entry requires a type, date, and source.',
                    ], 422);
                }

                $closedRevenueError = $this->validateClosedRevenue($entry);
                if ($closedRevenueError !== null) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $closedRevenueError,
                    ], 422);
                }
            }

            $owner = $this->resolveOwner($request, $data['owner_staff_code'] ?? null);

            if (!empty($owner['forbidden'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are not allowed to add manual entries for another staff member.',
                ], 403);
            }

            if (!empty($owner['invalid'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Selected staff owner was not found.',
                ], 422);
            }

            $creatorStaffId = (int) $request->session()->get('staff_id', 0);
            $creatorStaffCode = trim((string) $request->session()->get('name_code', ''));
            $now = now();
            $rows = [];

            foreach ($entries as $index => $entry) {
                $photo = $this->storePhoto($request, $index);
                if (!empty($photo['path'])) {
                    $storedPhotoPaths[] = $photo['path'];
                }

                $rows[] = [
                    'entry_type' => $entry['entry_type'],
                    'prospect_name' => $entry['prospect_name'],
                    'entry_date' => Carbon::parse($entry['entry_date'])->format('Y-m-d'),
                    'source' => $entry['source'] ?: null,
                    'segment_type' => $this->normalizeClosedClassification(
                        (string) $entry['entry_type'],
                        $entry['segment_type'] ?? null
                    ),
                    'service_category' => $this->normalizeServiceCategory($entry['service_category'] ?? null),
                    'estimated_rm' => $this->normalizeEstimatedRm($entry['estimated_rm'] ?? null),
                    'notes' => $entry['notes'] ?: null,
                    'photo_path' => $photo['path'],
                    'photo_original_name' => $photo['originalName'],
                    'photo_mime_type' => $photo['mimeType'],
                    'owner_staff_id' => $owner['staffId'] ?: null,
                    'owner_staff_code' => $owner['staffCode'] ?: null,
                    'owner_staff_name' => $owner['staffName'] ?: null,
                    'created_by' => $creatorStaffId ?: null,
                    'created_by_code' => $creatorStaffCode ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($rows) {
                DB::table('monitoring_manual_pipeline_entries')->insert($rows);
            });

            return response()->json(['status' => 'success', 'inserted' => count($rows)]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid manual entry.',
            ], 422);
        } catch (\Throwable $e) {
            foreach ($storedPhotoPaths as $path) {
                try {
                    AppFilePaths::deleteStoredPath((string) $path);
                } catch (\Throwable) {
                    // File cleanup should not mask the original save error.
                }
            }

            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $storedPhotoPath = null;

        try {
            if (!$this->entriesTableReady()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Manual monitoring entries table is not available.',
                ], 409);
            }

            $id = (int) ($request->route('id') ?? $request->input('id', 0));
            if ($id <= 0) {
                return response()->json(['status' => 'error', 'message' => 'Invalid manual entry id.'], 400);
            }

            $entry = DB::table('monitoring_manual_pipeline_entries')->where('id', $id)->first();
            if (!$entry) {
                return response()->json(['status' => 'error', 'message' => 'Manual entry not found.'], 404);
            }

            $staffId = (int) $request->session()->get('staff_id', 0);
            if ((int) ($entry->created_by ?? 0) !== $staffId && (int) ($entry->owner_staff_id ?? 0) !== $staffId) {
                return response()->json(['status' => 'error', 'message' => 'You can only update your own manual entries.'], 403);
            }

            $data = $request->validate([
                'entry_type' => ['required', 'in:lead,qualified,meeting_pitching,proposal,negotiation,closed'],
                'entry_date' => ['required', 'date'],
                'source' => ['required', 'string', 'max:80'],
                'segment_type' => ['nullable', 'in:individual,special_project,tender'],
                'service_category' => ['nullable', 'in:' . implode(',', array_keys(self::SERVICE_CATEGORIES))],
                'estimated_rm' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'prospect_name' => ['required', 'string', 'max:191'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'photos' => ['nullable', 'array', 'max:1'],
                'photos.*' => ['nullable', 'image', 'max:500'],
            ]);

            if (trim((string) $data['prospect_name']) === '' || trim((string) $data['source']) === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Prospect name and source are required.',
                ], 422);
            }

            $closedRevenueError = $this->validateClosedRevenue($data);
            if ($closedRevenueError !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => $closedRevenueError,
                ], 422);
            }

            $photo = $this->storePhoto($request, 0);
            if (!empty($photo['path'])) {
                $storedPhotoPath = $photo['path'];
            }

            $updates = [
                'entry_type' => $data['entry_type'],
                'prospect_name' => trim((string) $data['prospect_name']),
                'entry_date' => Carbon::parse($data['entry_date'])->format('Y-m-d'),
                'source' => trim((string) $data['source']) ?: null,
                'segment_type' => $this->normalizeClosedClassification(
                    (string) $data['entry_type'],
                    $data['segment_type'] ?? null
                ),
                'service_category' => $this->normalizeServiceCategory($data['service_category'] ?? null),
                'estimated_rm' => $this->normalizeEstimatedRm($data['estimated_rm'] ?? null),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'updated_at' => now(),
            ];

            if (!empty($photo['path'])) {
                $updates['photo_path'] = $photo['path'];
                $updates['photo_original_name'] = $photo['originalName'];
                $updates['photo_mime_type'] = $photo['mimeType'];
            }

            DB::table('monitoring_manual_pipeline_entries')->where('id', $id)->update($updates);

            if (!empty($photo['path']) && !empty($entry->photo_path)) {
                try {
                    AppFilePaths::deleteStoredPath((string) $entry->photo_path);
                } catch (\Throwable) {
                    // File cleanup should not block a successful update.
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($storedPhotoPath) {
                try {
                    AppFilePaths::deleteStoredPath((string) $storedPhotoPath);
                } catch (\Throwable) {
                    // File cleanup should not mask the validation error.
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->validator->errors()->first() ?: 'Invalid manual entry.',
            ], 422);
        } catch (\Throwable $e) {
            if ($storedPhotoPath) {
                try {
                    AppFilePaths::deleteStoredPath((string) $storedPhotoPath);
                } catch (\Throwable) {
                    // File cleanup should not mask the original update error.
                }
            }

            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function delete(Request $request): JsonResponse
    {
        try {
            if (!$this->entriesTableReady()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Manual monitoring entries table is not available.',
                ], 409);
            }

            $id = (int) ($request->route('id') ?? $request->input('id', 0));
            if ($id <= 0) {
                return response()->json(['status' => 'error', 'message' => 'Invalid manual entry id.'], 400);
            }

            $entry = DB::table('monitoring_manual_pipeline_entries')->where('id', $id)->first();
            if (!$entry) {
                return response()->json(['status' => 'error', 'message' => 'Manual entry not found.'], 404);
            }

            $staffId = (int) $request->session()->get('staff_id', 0);
            if ((int) ($entry->created_by ?? 0) !== $staffId && (int) ($entry->owner_staff_id ?? 0) !== $staffId) {
                return response()->json(['status' => 'error', 'message' => 'You can only delete your own manual entries.'], 403);
            }

            if (!empty($entry->photo_path)) {
                try {
                    AppFilePaths::deleteStoredPath((string) $entry->photo_path);
                } catch (\Throwable) {
                    // File cleanup should not block record deletion.
                }
            }

            DB::table('monitoring_manual_pipeline_entries')->where('id', $id)->delete();

            return response()->json(['status' => 'success']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
