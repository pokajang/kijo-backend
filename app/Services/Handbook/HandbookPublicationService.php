<?php

namespace App\Services\Handbook;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HandbookPublicationService extends HandbookBaseService
{

    public function versions(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to view handbook versions.',
            ], 403);
        }

        $signatureCounts = DB::table('hr_handbook_sign')
            ->select('handbook_version_id', DB::raw('COUNT(*) as signature_count'))
            ->whereNotNull('handbook_version_id')
            ->groupBy('handbook_version_id');

        $query = DB::table('hr_handbook_versions as v')
            ->leftJoinSub($signatureCounts, 'sc', 'sc.handbook_version_id', '=', 'v.id')
            ->select([
                'v.id',
                'v.version_label',
                'v.change_summary',
                'v.published_by_staff_id',
                'v.published_by_name_code',
                'v.published_at',
                'v.is_current',
                DB::raw('COALESCE(sc.signature_count, 0) as signature_count'),
            ])
            ->orderByDesc('v.published_at')
            ->orderByDesc('v.id');

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => collect($paginator->items())->map(fn ($row) => $this->formatVersionListRow($row))->values(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        $versions = $query
            ->get()
            ->map(fn ($row) => $this->formatVersionListRow($row))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $versions,
        ]);
    }

    private function formatVersionListRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'version_label' => (string) $row->version_label,
            'change_summary' => $row->change_summary,
            'published_by_staff_id' => $row->published_by_staff_id === null ? null : (int) $row->published_by_staff_id,
            'published_by_name_code' => $row->published_by_name_code,
            'published_at' => $row->published_at,
            'is_current' => (bool) $row->is_current,
            'signature_count' => (int) $row->signature_count,
        ];
    }

    public function version(Request $request, int $id)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to view handbook versions.',
            ], 403);
        }

        $version = DB::table('hr_handbook_versions')->where('id', $id)->first();
        if (!$version) {
            return response()->json([
                'success' => false,
                'message' => 'Handbook version not found.',
            ], 404);
        }

        $data = $this->formatVersion($version);
        $data['signature_count'] = (int) DB::table('hr_handbook_sign')
            ->where('handbook_version_id', $id)
            ->count();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function reactivateVersion(Request $request, int $id)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to reactivate handbook versions.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'change_summary' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Rollback summary is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $summary = trim((string) $validator->validated()['change_summary']);
        if ($summary === '') {
            return response()->json([
                'success' => false,
                'message' => 'Rollback summary is required.',
            ], 422);
        }

        $now = now();
        $result = DB::transaction(function () use ($request, $id, $summary, $now) {
            $target = DB::table('hr_handbook_versions')->where('id', $id)->lockForUpdate()->first();
            if (!$target) {
                return [
                    'success' => false,
                    'status' => 404,
                    'message' => 'Handbook version not found.',
                ];
            }

            if ((bool) $target->is_current) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'This version is already current.',
                ];
            }

            $activeDraft = Schema::hasTable('hr_handbook_drafts')
                ? DB::table('hr_handbook_drafts')
                    ->where('status', 'active')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first(['id', 'base_handbook_version_id'])
                : null;

            if ($activeDraft) {
                return [
                    'success' => false,
                    'status' => 422,
                    'message' => 'Cannot reactivate a handbook version while an active handbook draft exists. Publish or discard the draft first.',
                ];
            }

            $previousCurrent = DB::table('hr_handbook_versions')
                ->where('is_current', 1)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'version_label']);

            DB::table('hr_handbook_versions')->where('is_current', 1)->update(
                $this->currentVersionOffPayload($now),
            );

            DB::table('hr_handbook_versions')->where('id', $id)->update(
                $this->currentVersionOnPayload($now),
            );

            $this->assertSingleCurrentVersion();

            $this->insertChangeLog($request, $id, 'reactivate', $summary, $now);

            return [
                'success' => true,
                'target' => DB::table('hr_handbook_versions')->where('id', $id)->first(),
                'previous_current' => $previousCurrent,
            ];
        });

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status']);
        }

        $target = $result['target'];
        $previous = $result['previous_current'];
        $previousLabel = $previous
            ? "#{$previous->id} ({$previous->version_label})"
            : 'no previous current version';
        $this->auditLog->log(
            $request,
            "Reactivated employee handbook version #{$target->id} ({$target->version_label}) from {$previousLabel}",
        );

        return response()->json([
            'success' => true,
            'message' => 'Handbook version reactivated. Staff signatures now follow the reactivated version.',
            'data' => $this->formatVersion($target),
        ]);
    }

    public function publishDraft(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to publish handbook drafts.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'change_summary' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Publish summary is required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $current = $this->currentVersion();
        $draft = $this->activeDraft((int) $current->id);
        if (!$draft) {
            return response()->json([
                'success' => false,
                'message' => 'No active handbook draft to publish.',
            ], 422);
        }

        $changes = DB::table('hr_handbook_draft_changes')
            ->where('handbook_draft_id', $draft->id)
            ->orderBy('id')
            ->get();
        if ($changes->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No draft section changes to publish.',
            ], 422);
        }

        $staffId = (int) $request->session()->get('staff_id', 0) ?: null;
        $nameCode = (string) $request->session()->get('name_code', '');
        $summary = trim((string) $validator->validated()['change_summary']);
        if ($summary === '') {
            return response()->json([
                'success' => false,
                'message' => 'Publish summary is required.',
            ], 422);
        }
        $now = now();
        $versionId = null;

        DB::transaction(function () use ($request, $current, $draft, $changes, $staffId, $nameCode, $summary, $now, &$versionId) {
            DB::table('hr_handbook_versions')->where('id', $current->id)->lockForUpdate()->first();
            DB::table('hr_handbook_drafts')->where('id', $draft->id)->lockForUpdate()->first();

            DB::table('hr_handbook_versions')->where('is_current', 1)->update(
                $this->currentVersionOffPayload($now),
            );

            $versionPayload = [
                'version_label' => $this->nextVersionLabel(),
                'content_json' => (string) $draft->content_json,
                'change_summary' => $summary,
                'published_by_staff_id' => $staffId,
                'published_by_name_code' => mb_substr($nameCode, 0, 50),
                'published_at' => $now,
                'is_current' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($this->hasCurrentVersionGuard()) {
                $versionPayload['current_version_guard'] = 1;
            }

            $versionId = DB::table('hr_handbook_versions')->insertGetId($versionPayload);

            $this->assertSingleCurrentVersion();

            $this->insertChangeLog($request, $versionId, 'publish', $summary, $now);

            foreach ($changes as $change) {
                DB::table('hr_handbook_change_logs')->insert([
                    'handbook_version_id' => $versionId,
                    'action' => 'section',
                    'section_id' => $change->section_id,
                    'section_title' => $change->section_title,
                    'summary' => $change->summary,
                    'changed_by_staff_id' => $change->changed_by_staff_id,
                    'changed_by_name_code' => $change->changed_by_name_code,
                    'changed_at' => $change->changed_at ?: $now,
                    'ip_address' => $change->ip_address,
                    'user_agent' => $change->user_agent,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('hr_handbook_drafts')->where('id', $draft->id)->update([
                'status' => 'published',
                'published_handbook_version_id' => $versionId,
                'published_at' => $now,
                'updated_by_staff_id' => $staffId,
                'updated_by_name_code' => mb_substr($nameCode, 0, 50),
                'updated_at' => $now,
            ]);
        });

        $this->auditLog->log($request, "Published employee handbook draft as version #{$versionId}");

        return response()->json([
            'success' => true,
            'message' => 'Handbook draft published. Staff will need to endorse the new version.',
            'data' => $this->formatVersion($this->currentVersion()),
            'draft' => null,
        ]);
    }

    public function publish(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to publish handbook changes.',
            ], 403);
        }

        $validator = $this->contentValidator($request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Handbook content and change summary are required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $content = $this->sanitizeContent($data['content']);
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to encode handbook content.',
            ], 422);
        }

        $staffId = (int) $request->session()->get('staff_id', 0) ?: null;
        $nameCode = (string) $request->session()->get('name_code', '');
        $summary = trim((string) $data['change_summary']);
        $sectionId = trim((string) ($data['section_id'] ?? '')) ?: null;
        $sectionTitle = trim((string) ($data['section_title'] ?? '')) ?: null;
        $now = now();

        DB::beginTransaction();
        try {
            DB::table('hr_handbook_versions')->where('is_current', 1)->update(
                $this->currentVersionOffPayload($now),
            );

            $versionPayload = [
                'version_label' => $this->nextVersionLabel(),
                'content_json' => $encoded,
                'change_summary' => $summary,
                'published_by_staff_id' => $staffId,
                'published_by_name_code' => mb_substr($nameCode, 0, 50),
                'published_at' => $now,
                'is_current' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($this->hasCurrentVersionGuard()) {
                $versionPayload['current_version_guard'] = 1;
            }

            $versionId = DB::table('hr_handbook_versions')->insertGetId($versionPayload);

            $this->assertSingleCurrentVersion();

            $this->insertChangeLog($request, $versionId, 'publish', $summary, $now, $sectionId, $sectionTitle);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to publish handbook changes.',
            ], 500);
        }

        $this->auditLog->log($request, "Published employee handbook version #{$versionId}");

        return response()->json([
            'success' => true,
            'message' => 'Handbook changes published. Staff will need to endorse the new version.',
            'data' => $this->formatVersion($this->currentVersion()),
        ]);
    }
}
