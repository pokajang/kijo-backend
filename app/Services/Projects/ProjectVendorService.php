<?php

namespace App\Services\Projects;

use App\Http\Requests\Project\AddCollaboratorRequest;
use App\Http\Requests\Project\AddExpenseRequest;
use App\Http\Requests\Project\AddProgressRequest;
use App\Http\Requests\Project\AssignVendorRequest;
use App\Http\Requests\Project\CloseProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProgressRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectVendorService
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function __construct(private AuditLogService $auditLog) {}

    public function addCollaborator(AddCollaboratorRequest $request): JsonResponse
    {
        $data      = $request->validated();
        $projectId = (int) $data['project_id'];
        $staffId   = (int) $data['staff_id'];
        $role      = $data['project_role'] ?? null;

        if ($role === 'Leader') {
            $existingLeader = DB::table('project_collaborators')
                ->where('project_id', $projectId)
                ->where('project_role', 'Leader')
                ->where('staff_id', '!=', $staffId)
                ->exists();

            if ($existingLeader) {
                return response()->json(['status' => 'error', 'message' => 'Only one Leader can be assigned per project.']);
            }
        }

        DB::statement("
            INSERT INTO project_collaborators (project_id, staff_id, project_role, role_description)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE project_role = VALUES(project_role), role_description = VALUES(role_description)
        ", [$projectId, $staffId, $role, $data['role_description'] ?? null]);

        $nameCode = DB::table('staff_general')->where('staff_id', $staffId)->value('name_code')
            ?: "STAFF#{$staffId}";

        $this->insertProgress($projectId, "Staff {$nameCode} assigned as {$role}.", $request);
        $this->auditLog->log($request, "Assigned staff {$nameCode} as {$role} in project ID {$projectId}");

        return response()->json(['status' => 'success', 'message' => 'Collaborator added/updated.']);
    }

    public function listCollaborators(Request $request): JsonResponse
    {
        $projectId = (int) $request->input('project_id', 0);
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id.']);
        }

        $results = DB::select("
            SELECT
                pc.staff_id,
                pc.project_role,
                pc.role_description,
                sg.full_name AS name,
                sg.name_code AS code,
                sg.email,
                sg.mobile_number AS mobileNumber
            FROM project_collaborators pc
            JOIN staff_general sg ON pc.staff_id = sg.staff_id
            WHERE pc.project_id = ?
            ORDER BY
                CASE pc.project_role
                    WHEN 'Leader' THEN 1
                    WHEN 'Assistant' THEN 2
                    WHEN 'Collaborator' THEN 3
                    ELSE 99
                END
        ", [$projectId]);

        return response()->json($results);
    }

    public function removeCollaborator(Request $request): JsonResponse
    {
        $projectId = (int) $request->input('project_id', 0);
        $staffId   = (int) $request->input('staff_id', 0);

        if (!$projectId || !$staffId) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id or staff_id.']);
        }

        $deleted = DB::table('project_collaborators')
            ->where('project_id', $projectId)
            ->where('staff_id', $staffId)
            ->delete();

        $nameCode = DB::table('staff_general')->where('staff_id', $staffId)->value('name_code')
            ?: "STAFF#{$staffId}";

        $this->insertProgress($projectId, "Staff {$nameCode} removed from project.", $request);
        $this->auditLog->log($request, "Removed staff ID #{$staffId} from project ID #{$projectId}");

        return response()->json([
            'status'  => $deleted ? 'success' : 'error',
            'message' => $deleted ? 'Collaborator removed.' : 'Failed to remove collaborator.',
        ]);
    }

    public function assignVendor(AssignVendorRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data       = $request->validated();
        $projectId  = (int) $data['project_id'];
        $vendorId   = (int) $data['vendor_id'];
        $awardValue = (float) $data['award_value'];
        $awardDate  = $data['award_date'];

        $project = DB::table('projects_main')->where('id', $projectId)->exists();
        if (!$project) {
            return response()->json(['status' => 'error', 'message' => 'Project not found.'], 404);
        }

        $vendorRow = DB::table('vendor_main_details')->where('vendor_id', $vendorId)->first();
        if (!$vendorRow) {
            return response()->json(['status' => 'error', 'message' => 'Vendor not found.'], 404);
        }

        $vendorStatus = strtolower(trim((string) ($vendorRow->status ?? 'active')));
        if (!empty($vendorRow->deleted_at) || ($vendorStatus !== '' && $vendorStatus !== 'active')) {
            return response()->json(['status' => 'error', 'message' => 'Vendor is not active.'], 422);
        }

        $awardYear    = (int) date('Y', strtotime($awardDate));
        $awardYearTwo = date('y', strtotime($awardDate));
        $lockName     = "loa_{$awardYear}";
        $lockAcquired = false;

        try {
            DB::beginTransaction();

            $lockResult = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
            if (!$lockResult || !$lockResult->acquired) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Could not acquire LOA lock. Please retry.'], 503);
            }
            $lockAcquired = true;

            $lastNo  = (int) DB::table('project_vendors')
                ->whereYear('award_date', $awardYear)
                ->where('loa_ref_no', 'like', "LOA{$awardYearTwo}-%")
                ->lockForUpdate()
                ->max('loa_running_no');
            $nextNo  = $lastNo + 1;
            $padded  = str_pad((string) $nextNo, 3, '0', STR_PAD_LEFT);

            $nameCode = strtoupper((string) (DB::table('staff_general')
                ->where('staff_id', $staffId)
                ->value('name_code') ?: 'XX'));

            $refNo = "LOA{$awardYearTwo}-{$padded}{$nameCode}";

            $normalizeText = function ($value, int $maxLen = 5000): ?string {
                if ($value === null) return null;
                $text = trim((string) $value);
                if ($text === '') return null;
                return strlen($text) > $maxLen ? substr($text, 0, $maxLen) : $text;
            };

            DB::table('project_vendors')->insert([
                'project_id'          => $projectId,
                'vendor_id'           => $vendorId,
                'award_value'         => $awardValue,
                'award_date'          => $awardDate,
                'awarded_by'          => $staffId,
                'position'            => $normalizeText($data['position'] ?? null, 1000),
                'remarks'             => $normalizeText($data['remarks'] ?? null),
                'services_description'=> $normalizeText($data['services_description'] ?? null),
                'venue_details'       => $normalizeText($data['venue_details'] ?? null),
                'fee_breakdown'       => $normalizeText($data['fee_breakdown'] ?? null),
                'payment_terms'       => $normalizeText($data['payment_terms'] ?? null),
                'loa_running_no'      => $nextNo,
                'loa_ref_no'          => $refNo,
            ]);

            $this->insertProgress(
                $projectId,
                "Vendor {$vendorRow->vendor_name} assigned with LOA ref {$refNo}.",
                $request,
                $staffId,
                $awardDate
            );

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            report($e);
            return response()->json([
                'status'  => 'error',
                'message' => 'Unable to assign vendor at the moment.',
            ], 500);
        } finally {
            if ($lockAcquired) {
                try {
                    DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (\Throwable $releaseError) {
                    report($releaseError);
                }
            }
        }

        $this->auditLog->log($request, "Assigned vendor ID #{$vendorId} to project ID #{$projectId} (LOA ref: {$refNo})");

        return response()->json([
            'status'  => 'success',
            'action'  => 'added',
            'message' => 'Vendor successfully assigned with LOA reference.',
            'ref_no'  => $refNo,
        ]);
    }

    public function listVendors(Request $request): JsonResponse
    {
        $projectId = (int) $request->query('project_id', 0);
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id parameter.']);
        }

        $vendors = DB::select("
            SELECT
                pv.id AS assignment_id,
                v.vendor_id,
                v.vendor_name,
                v.contact_person_name,
                v.mobile_number,
                v.email,
                pv.award_value,
                pv.award_date,
                pv.position,
                pv.remarks,
                pv.services_description,
                pv.venue_details,
                pv.fee_breakdown,
                pv.payment_terms,
                pv.loa_ref_no
            FROM project_vendors pv
            JOIN vendor_main_details v ON v.vendor_id = pv.vendor_id
            WHERE pv.project_id = ?
            ORDER BY pv.award_date DESC, pv.id DESC
        ", [$projectId]);

        return response()->json(['status' => 'success', 'vendors' => $vendors]);
    }

    public function removeVendor(Request $request): JsonResponse
    {
        $projectId    = (int) $request->input('project_id', 0);
        $assignmentId = (int) $request->input('assignment_id', 0);
        $vendorId     = (int) $request->input('vendor_id', 0);
        $removedBy    = (int) $request->session()->get('staff_id', 0);

        if (!$projectId || !$removedBy || (!$assignmentId && !$vendorId)) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id, assignment_id/vendor_id, or session.']);
        }

        $vendorName = '';
        $loaRefNo   = '';

        if ($assignmentId) {
            $row = DB::table('project_vendors as pv')
                ->join('vendor_main_details as v', 'v.vendor_id', '=', 'pv.vendor_id')
                ->select(['pv.id', 'pv.vendor_id', 'pv.loa_ref_no', 'v.vendor_name'])
                ->where('pv.id', $assignmentId)
                ->where('pv.project_id', $projectId)
                ->first();

            if (!$row) {
                return response()->json(['status' => 'error', 'message' => 'Vendor assignment not found.']);
            }

            $vendorId   = (int) $row->vendor_id;
            $vendorName = $row->vendor_name ?? "Vendor#{$vendorId}";
            $loaRefNo   = $row->loa_ref_no ?? '';

            $deleted = DB::table('project_vendors')
                ->where('id', $assignmentId)
                ->where('project_id', $projectId)
                ->limit(1)
                ->delete();

            if ($deleted < 1) {
                return response()->json(['status' => 'error', 'message' => 'Vendor assignment not found.']);
            }
        } else {
            $vendorName = DB::table('vendor_main_details')->where('vendor_id', $vendorId)->value('vendor_name')
                ?: "Vendor#{$vendorId}";

            $deleted = DB::table('project_vendors')
                ->where('project_id', $projectId)
                ->where('vendor_id', $vendorId)
                ->delete();

            if ($deleted < 1) {
                return response()->json(['status' => 'error', 'message' => 'Vendor assignment not found.']);
            }
        }

        $refSuffix   = $loaRefNo ? " (LOA ref: {$loaRefNo})" : '';
        $progressMsg = "Vendor {$vendorName} removed from project{$refSuffix}.";
        $this->insertProgress($projectId, $progressMsg, $request, $removedBy);

        $logMsg = $assignmentId
            ? "Removed vendor assignment ID #{$assignmentId} (vendor ID #{$vendorId}) from project ID #{$projectId}"
            : "Removed vendor ID #{$vendorId} from project ID #{$projectId}";

        $this->auditLog->log($request, $logMsg);

        return response()->json(['status' => 'success', 'message' => 'Vendor removed and progress updated.']);
    }

    public function updateVendor(Request $request): JsonResponse
    {
        $assignmentId = (int) $request->input('assignment_id', 0);
        $projectId    = (int) $request->input('project_id', 0);
        $vendorId     = (int) $request->input('vendor_id', 0);
        $awardValueRaw= $request->input('award_value');
        $awardValue   = is_numeric($awardValueRaw) ? (float) $awardValueRaw : null;
        $updatedBy    = (int) $request->session()->get('staff_id', 0);

        if (!$assignmentId || !$projectId || !$vendorId || !$updatedBy || $awardValue === null) {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.']);
        }

        if (!is_finite($awardValue) || $awardValue <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Award value must be greater than 0.']);
        }

        $projectExists = DB::table('projects_main')->where('id', $projectId)->exists();
        if (!$projectExists) {
            return response()->json(['status' => 'error', 'message' => 'Project not found.']);
        }

        $vendorRow = DB::table('vendor_main_details')->where('vendor_id', $vendorId)->first();
        if (!$vendorRow) {
            return response()->json(['status' => 'error', 'message' => 'Vendor not found.']);
        }

        $vendorStatus = strtolower(trim((string) ($vendorRow->status ?? 'active')));
        if (!empty($vendorRow->deleted_at) || ($vendorStatus !== '' && $vendorStatus !== 'active')) {
            return response()->json(['status' => 'error', 'message' => 'Vendor is not active.']);
        }

        $existing = DB::table('project_vendors')
            ->where('id', $assignmentId)
            ->where('project_id', $projectId)
            ->first();

        if (!$existing) {
            return response()->json(['status' => 'error', 'message' => 'Vendor assignment not found.']);
        }

        $normalizeText = function ($value, int $maxLen = 5000): ?string {
            if ($value === null) return null;
            $text = trim((string) $value);
            if ($text === '') return null;
            return strlen($text) > $maxLen ? substr($text, 0, $maxLen) : $text;
        };

        DB::table('project_vendors')
            ->where('id', $assignmentId)
            ->where('project_id', $projectId)
            ->limit(1)
            ->update([
                'vendor_id'           => $vendorId,
                'award_value'         => $awardValue,
                'position'            => $normalizeText($request->input('position'), 1000),
                'remarks'             => $normalizeText($request->input('remarks')),
                'services_description'=> $normalizeText($request->input('services_description')),
                'venue_details'       => $normalizeText($request->input('venue_details')),
                'fee_breakdown'       => $normalizeText($request->input('fee_breakdown')),
                'payment_terms'       => $normalizeText($request->input('payment_terms')),
            ]);

        $loaRef      = $existing->loa_ref_no ?: 'N/A';
        $progressMsg = "Vendor assignment updated for {$vendorRow->vendor_name} (LOA ref: {$loaRef}).";
        $this->insertProgress($projectId, $progressMsg, $request, $updatedBy);
        $this->auditLog->log($request, "Updated vendor assignment ID #{$assignmentId} for project ID #{$projectId} (LOA ref: {$loaRef})");

        return response()->json(['status' => 'success', 'action' => 'updated', 'message' => 'Vendor assignment updated successfully.']);
    }

    public function allVendors(Request $request): JsonResponse
    {
        $vendors = DB::table('vendor_main_details')
            ->select(['vendor_id', 'vendor_name', 'contact_person_name', 'mobile_number', 'email'])
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('status', 'Active')->orWhereNull('status');
            })
            ->orderBy('vendor_name')
            ->get();

        return response()->json(['status' => 'success', 'vendors' => $vendors]);
    }

    private function insertProgress(
        int $projectId,
        string $activity,
        $request,
        ?int $updatedBy = null,
        ?string $date = null
    ): void {
        if (!$projectId || $activity === '') {
            return;
        }

        $staffId = $updatedBy ?? (int) $request->session()->get('staff_id', 0);
        $date    = $date ?? now()->format('Y-m-d');

        try {
            DB::table('project_progress')->insert([
                'project_id'    => $projectId,
                'progress_date' => $date,
                'progress_text' => $activity,
                'updated_by'    => $staffId ?: null,
                'updated_on'    => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
