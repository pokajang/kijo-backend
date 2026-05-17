<?php

namespace App\Services\WhatsNew;

use App\Support\AppFilePaths;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WhatsNewMutationService extends WhatsNewBaseService
{

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->requireSystemAdmin($request)) {
            return $response;
        }

        $validated = $this->validatedPayload($request);
        $contentError = $this->validateContentPresence($validated);
        if ($contentError) {
            return $contentError;
        }
        $attachmentError = $this->validateAttachmentLimit($request);
        if ($attachmentError) {
            return $attachmentError;
        }
        $descriptionError = $this->validateAttachmentDescriptions($request);
        if ($descriptionError) {
            return $descriptionError;
        }

        $now = now();
        $isPublished = (bool) ($validated['is_published'] ?? false);
        $version = $this->nullableTrim($validated['version'] ?? null);
        $insertData = [
            'title' => trim($validated['title']),
            'summary' => $this->nullableTrim($validated['summary'] ?? null),
            'body' => $this->nullableTrim($validated['body'] ?? null),
            'items' => json_encode($this->normalizeItems($validated['items'] ?? [])),
            'action_label' => $this->nullableTrim($validated['action_label'] ?? null),
            'action_path' => $this->nullableTrim($validated['action_path'] ?? null),
            'is_published' => $isPublished,
            'published_at' => $isPublished ? $now : null,
            'created_by_staff_id' => $this->staffId($request) ?: null,
            'updated_by_staff_id' => $this->staffId($request) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::beginTransaction();
        try {
            $id = $this->insertNoticeWithVersion($insertData, $version);
            $this->storeAttachments($request, $id);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->deleteStoredPaths($this->pendingAttachmentPaths);
            throw $exception;
        } finally {
            $this->pendingAttachmentPaths = [];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'What\'s New notice created.',
            'data' => $this->findNote($id, true),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->requireSystemAdmin($request)) {
            return $response;
        }

        $existing = DB::table('whats_new_notes')->where('id', $id)->first();
        if (!$existing) {
            return response()->json(['status' => 'error', 'message' => 'Notice not found.'], 404);
        }

        $validated = $this->validatedPayload($request, $id);
        $contentError = $this->validateContentPresence($validated, $id);
        if ($contentError) {
            return $contentError;
        }
        $attachmentError = $this->validateAttachmentLimit($request, $id);
        if ($attachmentError) {
            return $attachmentError;
        }
        $descriptionError = $this->validateAttachmentDescriptions($request);
        if ($descriptionError) {
            return $descriptionError;
        }

        $now = now();
        $isPublished = (bool) ($validated['is_published'] ?? false);
        $version = $this->nullableTrim($validated['version'] ?? null) ?: (string) $existing->version;

        DB::beginTransaction();
        try {
            DB::table('whats_new_notes')->where('id', $id)->update([
                'version' => $version,
                'title' => trim($validated['title']),
                'summary' => $this->nullableTrim($validated['summary'] ?? null),
                'body' => $this->nullableTrim($validated['body'] ?? null),
                'items' => json_encode($this->normalizeItems($validated['items'] ?? [])),
                'action_label' => $this->nullableTrim($validated['action_label'] ?? null),
                'action_path' => $this->nullableTrim($validated['action_path'] ?? null),
                'is_published' => $isPublished,
                'published_at' => $isPublished ? ($existing->published_at ?: $now) : null,
                'updated_by_staff_id' => $this->staffId($request) ?: null,
                'updated_at' => $now,
            ]);
            $this->syncAttachments($request, $id);
            DB::commit();
            $this->deleteStoredPaths($this->deferredDeletePaths);
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->deleteStoredPaths($this->pendingAttachmentPaths);
            throw $exception;
        } finally {
            $this->pendingAttachmentPaths = [];
            $this->deferredDeletePaths = [];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'What\'s New notice updated.',
            'data' => $this->findNote($id, true),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->requireSystemAdmin($request)) {
            return $response;
        }

        $attachments = DB::table('whats_new_attachments')
            ->where('whats_new_note_id', $id)
            ->pluck('file_path')
            ->all();

        $deleted = DB::table('whats_new_notes')->where('id', $id)->delete();
        if (!$deleted) {
            return response()->json(['status' => 'error', 'message' => 'Notice not found.'], 404);
        }
        $this->deleteStoredPaths($attachments);

        return response()->json(['status' => 'success', 'message' => 'What\'s New notice deleted.']);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        return $this->setPublished($request, $id, true);
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        return $this->setPublished($request, $id, false);
    }
}
