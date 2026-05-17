<?php

namespace App\Services\Handbook;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HandbookSignatureService extends HandbookBaseService
{

    public function sign(Request $request)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => ['required', 'string', 'max:255'],
            'ic_number' => ['required', 'string', 'max:50'],
            'handbook_version_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'full_name and ic_number are required.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $submittedVersionId = isset($data['handbook_version_id']) ? (int) $data['handbook_version_id'] : null;
        $alreadySigned = false;
        $versionId = null;
        $staleVersion = false;

        DB::transaction(function () use ($request, $staffId, $submittedVersionId, $data, &$alreadySigned, &$versionId, &$staleVersion) {
            $version = DB::table('hr_handbook_versions')
                ->where('is_current', 1)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (!$version) {
                $version = $this->currentVersion();
                DB::table('hr_handbook_versions')->where('id', $version->id)->lockForUpdate()->first();
            }

            $versionId = (int) $version->id;

            if ($submittedVersionId !== null && $submittedVersionId !== $versionId) {
                $staleVersion = true;
                return;
            }

            $alreadySigned = DB::table('hr_handbook_sign')
                ->where('staff_id', $staffId)
                ->where('handbook_version_id', $versionId)
                ->exists();

            if ($alreadySigned) {
                return;
            }

            DB::table('hr_handbook_sign')->insert([
                'handbook_version_id' => $versionId,
                'staff_id' => $staffId,
                'full_name' => trim((string) $data['full_name']),
                'ic_number' => trim((string) $data['ic_number']),
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        if ($staleVersion) {
            return response()->json([
                'success' => false,
                'message' => 'The handbook version changed before signing. Reload the handbook and sign the current version.',
            ], 409);
        }

        if ($alreadySigned) {
            return response()->json([
                'success' => false,
                'message' => 'You have already signed the current handbook version.',
            ]);
        }

        $this->auditLog->log($request, "Signed employee handbook version #{$versionId} (staff #{$staffId})");

        return response()->json([
            'success' => true,
            'message' => 'Handbook signed successfully.',
            'data' => [
                'handbook_version_id' => $versionId,
                'signed_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    public function signatures(Request $request)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: insufficient role to view handbook signatures.',
            ], 403);
        }

        $versionId = (int) $request->query('version_id', 0);
        $query = DB::table('hr_handbook_sign as s')
            ->leftJoin('hr_handbook_versions as v', 'v.id', '=', 's.handbook_version_id')
            ->select([
                's.id',
                's.handbook_version_id',
                'v.version_label',
                's.staff_id',
                's.full_name',
                's.signed_at',
                's.ip_address',
                's.user_agent',
            ]);

        if ($versionId > 0) {
            $query->where('s.handbook_version_id', $versionId);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('s.signed_at')->get()->map(fn ($row) => (array) $row)->values(),
        ]);
    }
}
