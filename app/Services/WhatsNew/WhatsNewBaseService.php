<?php

namespace App\Services\WhatsNew;

use App\Support\AppFilePaths;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

abstract class WhatsNewBaseService
{
    protected function setPublished(Request $request, int $id, bool $published): JsonResponse
    {
        if ($response = $this->requireSystemAdmin($request)) {
            return $response;
        }

        $note = DB::table('whats_new_notes')->where('id', $id)->first();
        if (!$note) {
            return response()->json(['status' => 'error', 'message' => 'Notice not found.'], 404);
        }

        $now = now();
        DB::table('whats_new_notes')->where('id', $id)->update([
            'is_published' => $published,
            'published_at' => $published ? ($note->published_at ?: $now) : null,
            'updated_by_staff_id' => $this->staffId($request) ?: null,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $published ? 'What\'s New notice published.' : 'What\'s New notice unpublished.',
            'data' => $this->findNote($id, true),
        ]);
    }

    protected function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        $versionRule = Rule::unique('whats_new_notes', 'version');
        if ($ignoreId !== null) {
            $versionRule->ignore($ignoreId);
        }

        return $request->validate([
            'version' => [
                'nullable',
                'string',
                'max:191',
                $versionRule,
            ],
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'items' => ['nullable', 'array'],
            'items.*' => ['nullable', 'string', 'max:500'],
            'action_label' => ['nullable', 'string', 'max:80'],
            'action_path' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_\-\/?=&%.#]*$/'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_descriptions' => ['nullable', 'array'],
            'image_descriptions.*' => ['nullable', 'string', 'max:500'],
            'existing_attachment_ids' => ['nullable', 'array'],
            'existing_attachment_ids.*' => ['nullable', 'integer'],
            'existing_attachment_descriptions' => ['nullable', 'array'],
            'existing_attachment_descriptions.*' => ['nullable', 'string', 'max:500'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
    }

    protected function validateContentPresence(array $validated, ?int $noticeId = null): ?JsonResponse
    {
        $summary = trim((string) ($validated['summary'] ?? ''));
        $body = trim((string) ($validated['body'] ?? ''));
        $bodyText = trim((string) preg_replace(
            '/[\s\x{00A0}]+/u',
            ' ',
            html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));
        $items = $this->normalizeItems($validated['items'] ?? []);
        $hasNewAttachments = !empty($validated['images'] ?? []);
        $hasRetainedAttachments = false;

        if ($noticeId !== null && !empty($validated['existing_attachment_ids'] ?? [])) {
            $hasRetainedAttachments = DB::table('whats_new_attachments')
                ->where('whats_new_note_id', $noticeId)
                ->whereIn('id', $validated['existing_attachment_ids'])
                ->exists();
        }

        if ($summary === '' && $bodyText === '' && empty($items) && !$hasNewAttachments && !$hasRetainedAttachments) {
            return response()->json([
                'status' => 'error',
                'message' => 'Add a summary, content, or image before saving.',
            ], 422);
        }

        return null;
    }

    protected function generateVersion(): string
    {
        $base = now()->format('Y-m-d');
        $sequence = DB::table('whats_new_notes')
            ->where('version', 'like', $base . '.%')
            ->count() + 1;

        do {
            $version = $base . '.' . $sequence;
            $sequence++;
        } while (DB::table('whats_new_notes')->where('version', $version)->exists());

        return $version;
    }

    private array $pendingAttachmentPaths = [];
    private array $deferredDeletePaths = [];

    protected function validateAttachmentLimit(Request $request, ?int $noticeId = null): ?JsonResponse
    {
        $newCount = count($request->file('images', []));
        $retainedIds = $this->retainedAttachmentIds($request);
        $retainedCount = 0;

        if ($noticeId !== null && !empty($retainedIds)) {
            $retainedCount = DB::table('whats_new_attachments')
                ->where('whats_new_note_id', $noticeId)
                ->whereIn('id', $retainedIds)
                ->count();
        }

        if ($retainedCount + $newCount > 3) {
            return response()->json([
                'status' => 'error',
                'message' => 'Attach up to 3 images per notice.',
            ], 422);
        }

        return null;
    }

    protected function storeAttachments(Request $request, int $noticeId, int $startOrder = 0): void
    {
        $files = $request->file('images', []);
        if (!is_array($files) || empty($files)) {
            return;
        }

        $descriptions = (array) $request->input('image_descriptions', []);
        $now = now();
        foreach (array_values($files) as $index => $file) {
            if (!$file) {
                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
            $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName());
            $filename = uniqid('whats-new-', true) . '.' . $extension;
            $folder = 'whats-new/' . now()->format('Y/m');
            $storedPath = $file->storeAs($folder, $filename, 'public');
            $this->pendingAttachmentPaths[] = $storedPath;

            DB::table('whats_new_attachments')->insert([
                'whats_new_note_id' => $noticeId,
                'file_path' => $storedPath,
                'original_name' => $safeOriginalName,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'file_size' => (int) $file->getSize(),
                'description' => $this->nullableTrim($descriptions[$index] ?? null),
                'sort_order' => $startOrder + $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    protected function validateAttachmentDescriptions(Request $request): ?JsonResponse
    {
        $newDescriptions = (array) $request->input('image_descriptions', []);
        $newFiles = array_values((array) $request->file('images', []));
        foreach ($newFiles as $index => $file) {
            if ($file && $this->nullableTrim($newDescriptions[$index] ?? null) === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Add a description for each image.',
                ], 422);
            }
        }

        $existingDescriptions = (array) $request->input('existing_attachment_descriptions', []);
        foreach ($this->retainedAttachmentIds($request) as $attachmentId) {
            if ($this->nullableTrim($existingDescriptions[$attachmentId] ?? null) === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Add a description for each image.',
                ], 422);
            }
        }

        return null;
    }

    protected function syncAttachments(Request $request, int $noticeId): void
    {
        $retainedIds = $this->retainedAttachmentIds($request);
        $descriptions = (array) $request->input('existing_attachment_descriptions', []);
        $existing = DB::table('whats_new_attachments')
            ->where('whats_new_note_id', $noticeId)
            ->orderBy('sort_order')
            ->get();

        $sortOrder = 0;
        foreach ($existing as $attachment) {
            if (!in_array((int) $attachment->id, $retainedIds, true)) {
                $this->deferredDeletePaths[] = $attachment->file_path;
                DB::table('whats_new_attachments')->where('id', $attachment->id)->delete();
                continue;
            }

            DB::table('whats_new_attachments')->where('id', $attachment->id)->update([
                'description' => $this->nullableTrim($descriptions[$attachment->id] ?? null),
                'sort_order' => $sortOrder,
                'updated_at' => now(),
            ]);
            $sortOrder++;
        }

        $this->storeAttachments($request, $noticeId, $sortOrder);
    }

    protected function retainedAttachmentIds(Request $request): array
    {
        return array_values(array_filter(array_map(
            fn ($id) => (int) $id,
            (array) $request->input('existing_attachment_ids', []),
        ), fn ($id) => $id > 0));
    }

    protected function attachmentsForNotes(array $noteIds): array
    {
        if (empty($noteIds)) {
            return [];
        }

        $rows = DB::table('whats_new_attachments')
            ->whereIn('whats_new_note_id', $noteIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->whats_new_note_id][] = $this->formatAttachment($row);
        }

        return $grouped;
    }

    protected function attachmentsForNote(int $noteId): array
    {
        return $this->attachmentsForNotes([$noteId])[$noteId] ?? [];
    }

    protected function formatAttachment(object $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'url' => AppFilePaths::publicUrlForStoredPath($attachment->file_path),
            'description' => $attachment->description,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => (int) $attachment->file_size,
            'sort_order' => (int) $attachment->sort_order,
        ];
    }

    protected function deleteStoredPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('public')->delete($path);
            }
        }
    }

    protected function insertNoticeWithVersion(array $insertData, ?string $version): int
    {
        if ($version !== null) {
            return DB::table('whats_new_notes')->insertGetId($insertData + ['version' => $version]);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::table('whats_new_notes')->insertGetId(
                    $insertData + ['version' => $this->generateVersion()]
                );
            } catch (QueryException $exception) {
                if (!$this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
            }
        }

        return DB::table('whats_new_notes')->insertGetId(
            $insertData + ['version' => now()->format('Y-m-d') . '.' . uniqid()]
        );
    }

    protected function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000';
    }

    protected function findNote(int $id, bool $includeAdminFields = false): ?array
    {
        $note = DB::table('whats_new_notes')->where('id', $id)->first();
        return $note ? $this->formatNote($note, $includeAdminFields, $this->attachmentsForNote($id)) : null;
    }

    protected function formatNote(
        object $note,
        bool $includeAdminFields = false,
        array $attachments = [],
    ): array
    {
        $items = [];
        if ($note->items !== null) {
            $decoded = json_decode((string) $note->items, true);
            $items = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        $payload = [
            'id' => (int) $note->id,
            'version' => (string) $note->version,
            'title' => (string) $note->title,
            'summary' => $note->summary,
            'body' => $note->body,
            'items' => $items,
            'action_label' => $note->action_label ?? null,
            'action_path' => $note->action_path ?? null,
            'is_published' => (bool) $note->is_published,
            'published_at' => $note->published_at,
            'read_at' => $note->read_at ?? null,
            'is_read' => isset($note->read_at) && $note->read_at !== null,
            'attachments' => $attachments,
        ];

        if ($includeAdminFields) {
            $payload += [
                'created_at' => $note->created_at ?? null,
                'updated_at' => $note->updated_at ?? null,
                'created_by_name_code' => $note->created_by_name_code ?? null,
                'updated_by_name_code' => $note->updated_by_name_code ?? null,
                'read_count' => (int) ($note->read_count ?? 0),
            ];
        }

        return $payload;
    }

    protected function normalizeItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $items,
        ), fn ($item) => $item !== ''));
    }

    protected function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function staffId(Request $request): int
    {
        return (int) $request->session()->get('staff_id', 0);
    }

    protected function requireSystemAdmin(Request $request): ?JsonResponse
    {
        if (!$this->isSystemAdmin($request)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: System Admin only.',
            ], 403);
        }

        return null;
    }

    protected function isSystemAdmin(Request $request): bool
    {
        return in_array('System Admin', (array) $request->session()->get('roles', []), true);
    }
}
