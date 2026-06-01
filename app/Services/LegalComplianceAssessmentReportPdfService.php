<?php

namespace App\Services;

use App\Services\Pdf\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegalComplianceAssessmentReportPdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private LegalComplianceAssessmentSnapshotService $snapshotService,
    ) {}

    public function export(Request $request, int $id)
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Not authenticated.'], 401);
        }

        $record = DB::table('legal_compliance_assessments as assessments')
            ->leftJoin('staff_general as staff', 'staff.staff_id', '=', 'assessments.staff_id')
            ->leftJoin('staff_general as submitted_staff', 'submitted_staff.staff_id', '=', 'assessments.submitted_by_staff_id')
            ->leftJoin('legal_compliance_templates as templates', 'templates.id', '=', 'assessments.template_id')
            ->leftJoin('legal_compliance_template_versions as versions', 'versions.id', '=', 'assessments.template_version_id')
            ->where('assessments.id', $id)
            ->whereNull('assessments.deleted_at')
            ->select([
                'assessments.*',
                'staff.full_name as created_by_name',
                'staff.name_code as created_by_code',
                'submitted_staff.full_name as submitted_by_name',
                'submitted_staff.email as submitted_by_email',
                'templates.name as template_name',
                'versions.version_number as published_version_number',
            ])
            ->first();

        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Assessment record not found.'], 404);
        }

        if (! $this->canAccessAssessment($request, $record, $staffId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to export this assessment record.',
            ], 403);
        }

        if ($record->stage !== 'submitted') {
            return response()->json([
                'status' => 'error',
                'message' => 'Only submitted assessment reports can be exported to PDF.',
            ], 422);
        }

        $snapshotResolution = $this->snapshotService->resolve($record);
        $templateSnapshot = $snapshotResolution['snapshot'];
        $clauseResponses = json_decode((string) $record->clause_responses, true) ?: [];
        $selectedAssessors = json_decode((string) $record->selected_assessors, true) ?: [];
        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');

        $html = view('pdf.legal-compliance-assessment-report', [
            'record' => $record,
            'templateSnapshot' => $templateSnapshot,
            'templateSnapshotUnresolved' => (bool) $snapshotResolution['unresolved'],
            'templateSnapshotResolutionSource' => $snapshotResolution['source'],
            'groups' => $templateSnapshot['groups'] ?? [],
            'clauseResponses' => $clauseResponses,
            'selectedAssessors' => $selectedAssessors,
            'generatedDate' => $generatedAt->format('d M Y, h:i A'),
            'generatedByCode' => $generatorCode,
            'generatedById' => $generatorId,
            'logoDataUri' => $this->companyLogoDataUri(),
        ])->render();

        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);
        $this->auditLog->log($request, "Generated PDF for legal compliance assessment #{$id}");

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"legal-compliance-assessment-{$id}.pdf\"",
        ]);
    }

    private function canAccessAssessment(Request $request, object $assessment, int $staffId): bool
    {
        return (int) $assessment->staff_id === $staffId
            || $this->hasAnyRole($request, ['Manager', 'System Admin']);
    }

    private function hasAnyRole(Request $request, array $allowedRoles): bool
    {
        $roles = $request->attributes->get('auth.roles', $request->session()->get('roles', []));
        $roles = is_array($roles) ? $roles : [$roles];
        $normalizedRoles = array_map(static fn ($role) => strtolower(trim((string) $role)), $roles);
        if (in_array('system admin', $normalizedRoles, true)) {
            return true;
        }

        $normalizedAllowed = array_map(static fn ($role) => strtolower(trim((string) $role)), $allowedRoles);

        return ! empty(array_intersect($normalizedRoles, $normalizedAllowed));
    }
}
