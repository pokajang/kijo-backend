<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcedureController extends Controller
{
    private const ALLOWED_CATEGORIES = [
        'IT', 'OSH', 'HR', 'FINANCE', 'OPERATION', 'SALES', 'MARKETING', 'OTHERS',
    ];

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $createdBy = trim((string) $request->query('createdBy', ''));
        $categoryRaw = trim((string) $request->query('category', ''));

        $query = DB::table('procedures as p')
            ->select([
                'p.id',
                'p.title',
                'p.description',
                'p.category',
                'p.file_path',
                'p.file_name',
                'p.file_size',
                'p.mime_type',
                'p.created_by',
                'p.created_name',
                'p.created_code',
                'p.created_at',
            ])
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('p.title', 'like', "%{$q}%")
                    ->orWhere('p.description', 'like', "%{$q}%")
                    ->orWhere('p.created_name', 'like', "%{$q}%")
                    ->orWhere('p.created_code', 'like', "%{$q}%");
            });
        }

        if ($createdBy !== '') {
            $query->where(function ($sub) use ($createdBy) {
                $sub->where('p.created_name', 'like', "%{$createdBy}%")
                    ->orWhere('p.created_code', 'like', "%{$createdBy}%");
            });
        }

        $categories = [];
        if ($categoryRaw !== '') {
            $tokens = array_values(array_filter(array_map(
                static fn ($x) => strtoupper(trim((string) $x)),
                explode(',', $categoryRaw)
            )));
            $categories = array_values(array_unique(array_intersect($tokens, self::ALLOWED_CATEGORIES)));
            if (empty($categories)) {
                return response()->json(['success' => false, 'message' => 'Invalid category filter.'], 400);
            }
            $query->whereIn('p.category', $categories);
        }

        $items = $query->get()->map(function ($item) {
            $item->file_path = AppFilePaths::publicUrlForStoredPath($item->file_path ?? '');
            return $item;
        });

        return response()->json([
            'success' => true,
            'items' => $items,
            'filters' => [
                'q' => $q,
                'createdBy' => $createdBy,
                'category' => $categories,
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid or missing id'], 400);
        }

        $item = DB::table('procedures')
            ->select([
                'id',
                'title',
                'description',
                'category',
                'file_path',
                'file_name',
                'file_size',
                'mime_type',
                'created_by',
                'created_name',
                'created_code',
                'created_at',
            ])
            ->where('id', $id)
            ->first();

        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Procedure not found'], 404);
        }

        $item->file_path = AppFilePaths::publicUrlForStoredPath($item->file_path ?? '');

        return response()->json(['success' => true, 'item' => $item]);
    }

    public function store(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));

        $title = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $category = strtoupper(trim((string) $request->input('category', '')));

        if ($title === '' || $description === '') {
            return response()->json(['success' => false, 'message' => 'Title and description are required.'], 400);
        }
        if ($category === '' || ! in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return response()->json(['success' => false, 'message' => 'Category is required and must be a valid option.'], 400);
        }
        if (! $request->hasFile('file')) {
            return response()->json(['success' => false, 'message' => 'PDF file is required.'], 400);
        }

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['success' => false, 'message' => 'File upload error.'], 400);
        }
        if ($file->getSize() > (10 * 1024 * 1024)) {
            return response()->json(['success' => false, 'message' => 'PDF must be smaller than 10 MB.'], 400);
        }
        if ($file->getMimeType() !== 'application/pdf') {
            return response()->json(['success' => false, 'message' => 'Uploaded file must be a PDF.'], 400);
        }

        $year = date('Y');
        $safeOriginal = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file->getClientOriginalName());
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $storedPath = AppFilePaths::storeFileAs("procedures/{$year}", $file, $storedName);

        if (! $storedPath || ! AppFilePaths::storedPathExists($storedPath)) {
            return response()->json(['success' => false, 'message' => 'Failed to store uploaded file.'], 500);
        }

        $fileUrl = AppFilePaths::publicUrlForStoredPath($storedPath);

        try {
            $id = (int) DB::table('procedures')->insertGetId([
                'title' => $title,
                'description' => $description,
                'category' => $category,
                'file_path' => $storedPath,
                'file_name' => $safeOriginal,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'created_by' => $staffId,
                'created_name' => $staffName,
                'created_code' => $staffCode,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            AppFilePaths::deleteStoredPath($storedPath);
            Log::error('Failed to create procedure', ['exception' => $e]);
            return response()->json(['success' => false, 'message' => 'Server error while creating procedure.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Procedure created successfully.',
            'id' => $id,
            'fileUrl' => $fileUrl,
            'category' => $category,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffName = trim((string) $request->session()->get('full_name', ''));
        $staffCode = trim((string) $request->session()->get('name_code', ''));

        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid procedure id.'], 400);
        }

        $title = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $category = strtoupper(trim((string) $request->input('category', '')));

        if ($title === '' || $description === '') {
            return response()->json(['success' => false, 'message' => 'Title and description are required.'], 400);
        }
        if ($category === '' || ! in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return response()->json(['success' => false, 'message' => 'Category is required and must be a valid option.'], 400);
        }

        $existing = DB::table('procedures')->where('id', $id)->first();
        if (! $existing) {
            return response()->json(['success' => false, 'message' => 'Procedure not found.'], 404);
        }
        if ((int) $existing->created_by !== $staffId) {
            return response()->json(['success' => false, 'message' => 'Update aborted. You are not the owner for this procedure.'], 403);
        }

        $updates = [
            'title' => $title,
            'description' => $description,
            'category' => $category,
        ];

        $newStoredPath = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            if (! $file || ! $file->isValid()) {
                return response()->json(['success' => false, 'message' => 'File upload error.'], 400);
            }
            if ($file->getSize() > (10 * 1024 * 1024)) {
                return response()->json(['success' => false, 'message' => 'PDF must be smaller than 10 MB.'], 400);
            }
            if ($file->getMimeType() !== 'application/pdf') {
                return response()->json(['success' => false, 'message' => 'Uploaded file must be a PDF.'], 400);
            }

            $year = date('Y');
            $safeOriginal = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file->getClientOriginalName());
            $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $storedPath = AppFilePaths::storeFileAs("procedures/{$year}", $file, $storedName);
            if (! $storedPath || ! AppFilePaths::storedPathExists($storedPath)) {
                return response()->json(['success' => false, 'message' => 'Failed to store uploaded file.'], 500);
            }

            $newStoredPath = $storedPath;
            $updates['file_path'] = $newStoredPath;
            $updates['file_name'] = $safeOriginal;
            $updates['file_size'] = $file->getSize();
            $updates['mime_type'] = $file->getMimeType();
        }

        try {
            DB::table('procedures')->where('id', $id)->update($updates);
        } catch (\Throwable $e) {
            AppFilePaths::deleteStoredPath($newStoredPath);
            Log::error('Failed to update procedure', ['procedure_id' => $id, 'exception' => $e]);
            return response()->json(['success' => false, 'message' => 'Server error while updating procedure.'], 500);
        }

        if ($newStoredPath) {
            $this->deleteLegacyFile((string) ($existing->file_path ?? ''));
        }

        return response()->json([
            'success' => true,
            'message' => 'Procedure updated successfully.',
            'id' => $id,
            'category' => $category,
            'fileUrl' => $newStoredPath ? AppFilePaths::publicUrlForStoredPath($newStoredPath) : null,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $method = strtoupper((string) $request->getMethod());
        if (! in_array($method, ['DELETE', 'GET'], true)) {
            return response()->json(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);

        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid ID.'], 400);
        }

        try {
            $row = DB::table('procedures')->where('id', $id)->first();
            if (! $row) {
                return response()->json(['success' => false, 'message' => 'Procedure not found.'], 404);
            }
            if ((int) $row->created_by !== $staffId) {
                return response()->json(['success' => false, 'message' => 'Deletion aborted. You are not the owner for this procedure.'], 403);
            }

            DB::table('procedures')->where('id', $id)->delete();
            $this->deleteLegacyFile((string) ($row->file_path ?? ''));
        } catch (\Throwable $e) {
            Log::error('Failed to delete procedure', ['procedure_id' => $id, 'exception' => $e]);
            return response()->json(['success' => false, 'message' => 'Server error while deleting procedure.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Procedure deleted successfully.']);
    }

    private function deleteLegacyFile(string $publicPath): void
    {
        AppFilePaths::deletePublicPath($publicPath);
    }
}
