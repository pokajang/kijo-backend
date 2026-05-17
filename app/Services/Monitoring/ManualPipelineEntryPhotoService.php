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

class ManualPipelineEntryPhotoService extends ManualPipelineEntryBaseService
{

    public function viewPhoto(Request $request)
    {
        try {
            if (!$this->entriesTableReady()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Manual monitoring entries table is not available.',
                ], 409);
            }

            $id = (int) ($request->route('id') ?? 0);
            if ($id <= 0) {
                return response()->json(['status' => 'error', 'message' => 'Invalid manual entry id.'], 400);
            }

            $entry = DB::table('monitoring_manual_pipeline_entries')->where('id', $id)->first();
            if (!$entry || empty($entry->photo_path) || !AppFilePaths::storedPathExists((string) $entry->photo_path)) {
                return response()->json(['status' => 'error', 'message' => 'Screenshot proof not found.'], 404);
            }

            if (!$this->canViewEntry($request, $entry)) {
                return response()->json(['status' => 'error', 'message' => 'You are not allowed to view this screenshot proof.'], 403);
            }

            return AppFilePaths::storedPathResponse(
                (string) $entry->photo_path,
                $entry->photo_original_name ?: basename((string) $entry->photo_path),
            );
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
