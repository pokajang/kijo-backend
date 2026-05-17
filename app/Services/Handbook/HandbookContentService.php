<?php

namespace App\Services\Handbook;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HandbookContentService extends HandbookBaseService
{

    public function current(Request $request)
    {
        $version = $this->currentVersion();
        $canManage = $this->canManage($request);

        return response()->json([
            'success' => true,
            'data' => $this->formatVersion($version),
            'can_manage' => $canManage,
            'current_signature' => $this->currentSignature($request, (int) $version->id),
            'draft' => $canManage ? $this->formatDraft($this->activeDraft((int) $version->id)) : null,
        ]);
    }

    public function saveDraftSection(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to edit handbook drafts.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'base_handbook_version_id' => ['required', 'integer', 'min:1'],
            'section_id' => ['required', 'string', 'max:80'],
            'section_title' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
            'change_summary' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Handbook section content and change summary are required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $submittedBaseVersionId = (int) $data['base_handbook_version_id'];
        $sectionId = trim((string) $data['section_id']);
        $sectionTitle = mb_substr(trim((string) $data['section_title']), 0, 255);
        $bodyHtml = $this->sanitizeHtml((string) $data['body_html']);
        $summary = trim((string) $data['change_summary']);

        if ($sectionId === '' || $sectionTitle === '' || $bodyHtml === '' || $summary === '') {
            return response()->json([
                'success' => false,
                'message' => 'Handbook section content and change summary are required.',
            ], 422);
        }

        $staffId = (int) $request->session()->get('staff_id', 0) ?: null;
        $nameCode = mb_substr((string) $request->session()->get('name_code', ''), 0, 50);
        $now = now();
        $draftId = null;
        $staleVersion = false;
        $missingSection = false;
        $encodeFailed = false;

        DB::transaction(function () use (
            $request,
            $submittedBaseVersionId,
            $sectionId,
            $sectionTitle,
            $bodyHtml,
            $staffId,
            $nameCode,
            $summary,
            $now,
            &$draftId,
            &$staleVersion,
            &$missingSection,
            &$encodeFailed,
        ) {
            $current = DB::table('hr_handbook_versions')
                ->where('is_current', 1)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$current) {
                $current = $this->currentVersion();
                DB::table('hr_handbook_versions')->where('id', $current->id)->lockForUpdate()->first();
            }

            $currentId = (int) $current->id;
            if ($submittedBaseVersionId !== $currentId) {
                $staleVersion = true;
                return;
            }

            $draft = $this->activeDraft($currentId, true);
            $sourceContent = json_decode((string) ($draft?->content_json ?? $current->content_json), true);
            if (!is_array($sourceContent)) {
                $sourceContent = ['title' => 'AMIOSH Employee Handbook', 'chapters' => []];
            }

            $matched = false;
            $chapters = collect($sourceContent['chapters'] ?? [])
                ->map(function ($chapter) use ($sectionId, $sectionTitle, $bodyHtml, &$matched) {
                    $chapterId = trim((string) ($chapter['id'] ?? ''));
                    if ($chapterId !== $sectionId) {
                        return [
                            'id' => mb_substr($chapterId, 0, 80),
                            'title' => mb_substr(trim((string) ($chapter['title'] ?? '')), 0, 255),
                            'bodyHtml' => (string) ($chapter['bodyHtml'] ?? ''),
                        ];
                    }

                    $matched = true;
                    return [
                        'id' => mb_substr($sectionId, 0, 80),
                        'title' => $sectionTitle,
                        'bodyHtml' => $bodyHtml,
                    ];
                })
                ->filter(fn ($chapter) => $chapter['id'] !== '' && $chapter['title'] !== '' && $chapter['bodyHtml'] !== '')
                ->values()
                ->all();

            if (!$matched) {
                $missingSection = true;
                return;
            }

            $content = [
                'title' => mb_substr(trim((string) ($sourceContent['title'] ?? 'AMIOSH Employee Handbook')), 0, 255),
                'chapters' => $chapters,
            ];
            $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                $encodeFailed = true;
                return;
            }

            if ($draft) {
                $draftId = (int) $draft->id;
                DB::table('hr_handbook_drafts')->where('id', $draftId)->update([
                    'content_json' => $encoded,
                    'updated_by_staff_id' => $staffId,
                    'updated_by_name_code' => $nameCode,
                    'updated_at' => $now,
                ]);
            } else {
                $draftId = DB::table('hr_handbook_drafts')->insertGetId([
                    'base_handbook_version_id' => $currentId,
                    'published_handbook_version_id' => null,
                    'status' => 'active',
                    'content_json' => $encoded,
                    'created_by_staff_id' => $staffId,
                    'created_by_name_code' => $nameCode,
                    'updated_by_staff_id' => $staffId,
                    'updated_by_name_code' => $nameCode,
                    'published_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('hr_handbook_draft_changes')->insert([
                'handbook_draft_id' => $draftId,
                'section_id' => mb_substr($sectionId, 0, 80),
                'section_title' => $sectionTitle,
                'summary' => $summary,
                'changed_by_staff_id' => $staffId,
                'changed_by_name_code' => $nameCode,
                'changed_at' => $now,
                'ip_address' => mb_substr((string) $request->ip(), 0, 45),
                'user_agent' => $request->userAgent(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        if ($staleVersion) {
            return response()->json([
                'success' => false,
                'message' => 'The handbook version changed before this draft save. Reload the handbook before editing.',
            ], 409);
        }

        if ($missingSection) {
            return response()->json([
                'success' => false,
                'message' => 'The handbook section was not found in the current draft.',
            ], 422);
        }

        if ($encodeFailed) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to encode handbook draft content.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Handbook section saved to draft.',
            'data' => $this->formatDraft(DB::table('hr_handbook_drafts')->where('id', $draftId)->first()),
        ]);
    }

    public function discardDraft(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to discard handbook drafts.',
            ], 403);
        }

        $current = $this->currentVersion();
        $draft = $this->activeDraft((int) $current->id);
        if (!$draft) {
            return response()->json([
                'success' => true,
                'message' => 'No active handbook draft to discard.',
                'draft' => null,
            ]);
        }

        DB::table('hr_handbook_drafts')->where('id', $draft->id)->update([
            'status' => 'discarded',
            'updated_by_staff_id' => (int) $request->session()->get('staff_id', 0) ?: null,
            'updated_by_name_code' => mb_substr((string) $request->session()->get('name_code', ''), 0, 50),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Handbook draft discarded.',
            'draft' => null,
        ]);
    }

    public function changeLogs(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to view handbook change logs.',
            ], 403);
        }

        $logs = DB::table('hr_handbook_change_logs as l')
            ->leftJoin('hr_handbook_versions as v', 'v.id', '=', 'l.handbook_version_id')
            ->select([
                'l.id',
                'l.handbook_version_id',
                'v.version_label',
                'l.action',
                'l.section_id',
                'l.section_title',
                'l.summary',
                'l.changed_by_staff_id',
                'l.changed_by_name_code',
                'l.changed_at',
                'l.ip_address',
                'l.user_agent',
            ])
            ->orderByDesc('l.changed_at')
            ->orderByDesc('l.id')
            ->limit(200)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
