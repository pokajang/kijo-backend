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

class ProjectListService
{
    private static bool $dompdfAutoloaderRegistered = false;

    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', 0);
        $yearClause = ($year >= 2000 && $year <= 2100) ? 'WHERE YEAR(p.award_date) = ?' : '';
        $bindings = ($year >= 2000 && $year <= 2100) ? [$year] : [];

        $projects = DB::select("
            SELECT
                p.id,
                p.project_name,
                p.project_type,
                p.po_loa_number,
                p.quote_id,
                p.quote_value,
                p.service_start_date,
                p.service_end_date,
                p.description,
                p.status,
                p.award_date,
                p.created_at,
                p.client_id,
                COALESCE(qt.client_name, qh.client_name, qm.client_name, qs.client_name, qe.client_name, cc.company_name) AS client_name,
                COALESCE(qt.client_ssm, qh.client_ssm, qm.client_ssm, qs.client_ssm, qe.client_ssm, cc.ssm_number) AS client_ssm,
                cc.tax_id_no_tin AS client_tin,
                COALESCE(qt.client_address, qh.client_address, qm.client_address, qs.client_address, qe.client_address, cc.address) AS client_address,
                COALESCE(qt.client_city, qh.client_city, qm.client_city, qs.client_city, qe.client_city, cc.city) AS client_city,
                COALESCE(qt.client_state, qh.client_state, qm.client_state, qs.client_state, qe.client_state, cc.state) AS client_state,
                COALESCE(qt.client_zip, qh.client_zip, qm.client_zip, qs.client_zip, qe.client_zip, cc.zip) AS client_zip,
                COALESCE(qt.pic_name, qh.pic_name, qm.pic_name, qs.pic_name, qe.pic_name) AS quote_pic_name,
                COALESCE(qt.pic_email, qh.pic_email, qm.pic_email, qs.pic_email, qe.pic_email) AS quote_pic_email,
                COALESCE(qt.pic_phone, qh.pic_phone, qm.pic_phone, qs.pic_phone, qe.pic_phone) AS quote_pic_phone,
                COALESCE(qt.pic_position, qh.pic_position, qm.pic_position, qs.pic_position, qe.pic_position) AS quote_pic_position
            FROM projects_main p
            LEFT JOIN quotes_training qt ON qt.id = p.quote_id AND p.project_type = 'Training'
            LEFT JOIN quotes_ih qh ON qh.id = p.quote_id AND p.project_type = 'Industrial Hygiene'
            LEFT JOIN quotes_manpower qm ON qm.id = p.quote_id AND p.project_type = 'Manpower Supply'
            LEFT JOIN quotes_special qs ON qs.id = p.quote_id AND p.project_type = 'Special Service'
            LEFT JOIN quotes_equipment qe ON qe.id = p.quote_id AND p.project_type = 'Equipment Supply'
            LEFT JOIN client_company cc ON cc.company_id = p.client_id
            {$yearClause}
            ORDER BY
                CASE
                    WHEN p.status = 'Active' THEN 0
                    WHEN p.status = 'Completed' THEN 1
                    WHEN p.status = 'Terminated' THEN 2
                    ELSE 3
                END,
                p.created_at DESC
        ", $bindings);

        $projects = array_map(fn ($p) => (array) $p, $projects);

        $projectIds = array_column($projects, 'id');

        if (empty($projectIds)) {
            return response()->json([]);
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        $progressRows = DB::select(
            "SELECT project_id, progress_date, progress_text, updated_by, updated_on
             FROM project_progress WHERE project_id IN ({$placeholders})
             ORDER BY progress_date DESC",
            $projectIds
        );
        $progressByProject = [];
        foreach ($progressRows as $row) {
            $progressByProject[$row->project_id][] = (array) $row;
        }

        $staffRows = DB::select(
            "SELECT pc.project_id, sg.full_name, sg.name_code, pc.project_role
             FROM project_collaborators pc
             JOIN system_users su ON su.staff_id = pc.staff_id
             JOIN staff_general sg ON sg.staff_id = su.staff_id
             WHERE pc.project_id IN ({$placeholders})
             ORDER BY CASE pc.project_role WHEN 'Leader' THEN 1 WHEN 'Assistant' THEN 2 WHEN 'Collaborator' THEN 3 ELSE 99 END",
            $projectIds
        );
        $staffByProject = [];
        foreach ($staffRows as $row) {
            $staffByProject[$row->project_id][] = [
                'full_name'    => $row->full_name,
                'name_code'    => $row->name_code,
                'project_role' => $row->project_role,
            ];
        }

        $vendorRows = DB::select(
            "SELECT pv.project_id, v.vendor_id, v.vendor_name, v.contact_person_name,
                    v.mobile_number, v.email, pv.award_value, pv.position, pv.remarks,
                    pv.services_description, pv.venue_details, pv.fee_breakdown, pv.payment_terms
             FROM project_vendors pv
             JOIN vendor_main_details v ON v.vendor_id = pv.vendor_id
             WHERE pv.project_id IN ({$placeholders})",
            $projectIds
        );
        $vendorsByProject = [];
        foreach ($vendorRows as $row) {
            $vendorsByProject[$row->project_id][] = (array) $row;
        }

        $closingRows = DB::select(
            "SELECT pcd.project_id, pcd.close_date, pcd.reason, pcd.closed_at, sg.name_code AS closed_by
             FROM project_closing_details pcd
             LEFT JOIN staff_general sg ON sg.staff_id = pcd.closed_by
             WHERE pcd.project_id IN ({$placeholders})",
            $projectIds
        );
        $closingByProject = [];
        foreach ($closingRows as $row) {
            $closingByProject[$row->project_id] = [
                'close_date' => $row->close_date,
                'reason'     => $row->reason,
                'closed_at'  => $row->closed_at,
                'closed_by'  => $row->closed_by,
            ];
        }

        $clientPicRows = DB::select(
            "SELECT company_id, full_name, email, mobile_number, position
             FROM client_pic WHERE status = 'assigned' ORDER BY full_name ASC"
        );
        $clientPicsByCompany = [];
        foreach ($clientPicRows as $row) {
            $clientPicsByCompany[$row->company_id][] = [
                'full_name'     => $row->full_name,
                'email'         => $row->email,
                'mobile_number' => $row->mobile_number,
                'position'      => $row->position,
            ];
        }

        $equipmentQuoteIds = [];
        foreach ($projects as $p) {
            if ($p['project_type'] === 'Equipment Supply' && !empty($p['quote_id'])) {
                $equipmentQuoteIds[] = $p['quote_id'];
            }
        }
        $equipmentItemsByQuote = [];
        if (!empty($equipmentQuoteIds)) {
            $eqPlaceholders = implode(',', array_fill(0, count($equipmentQuoteIds), '?'));
            $eqRows = DB::select(
                "SELECT qi.quote_id, qi.id, qi.item_id, qi.quantity, qi.unit_price,
                         qi.marked_up_price, qi.line_total, ci.item_name, ci.description, ci.unit
                 FROM quotes_equipment_items qi
                 JOIN catalog_items ci ON ci.id = qi.item_id
                 WHERE qi.quote_id IN ({$eqPlaceholders})",
                $equipmentQuoteIds
            );
            foreach ($eqRows as $row) {
                $equipmentItemsByQuote[$row->quote_id][] = (array) $row;
            }
        }

        foreach ($projects as &$project) {
            $id       = $project['id'];
            $clientId = $project['client_id'];

            $addressParts = array_filter([
                $project['client_address'] ?? '',
                $project['client_city']    ?? '',
                $project['client_state']   ?? '',
                $project['client_zip']     ?? '',
            ]);
            $project['client_full_address'] = implode(', ', $addressParts);

            $project['progress_updates'] = $progressByProject[$id] ?? [];
            $project['assigned_staff']   = $staffByProject[$id]    ?? [];
            $project['vendors']          = $vendorsByProject[$id]   ?? [];
            $project['closing_details']  = $closingByProject[$id]   ?? null;

            if (!empty($project['quote_pic_name']) || !empty($project['quote_pic_email'])) {
                $project['client_pics'] = [[
                    'full_name'     => $project['quote_pic_name']     ?? '',
                    'email'         => $project['quote_pic_email']    ?? '',
                    'mobile_number' => $project['quote_pic_phone']    ?? '',
                    'position'      => $project['quote_pic_position'] ?? '',
                ]];
            } else {
                $project['client_pics'] = $clientPicsByCompany[$clientId] ?? [];
            }

            if ($project['project_type'] === 'Equipment Supply' && !empty($project['quote_id'])) {
                $project['equipment_items'] = $equipmentItemsByQuote[$project['quote_id']] ?? [];
            } else {
                $project['equipment_items'] = [];
            }

            unset(
                $project['client_id'],
                $project['quote_pic_name'],
                $project['quote_pic_email'],
                $project['quote_pic_phone'],
                $project['quote_pic_position']
            );
        }
        unset($project);

        return response()->json($projects);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $rows = DB::select("
            SELECT
                p.id,
                p.project_name,
                p.project_type,
                p.po_loa_number,
                p.quote_id,
                p.quote_value,
                p.service_start_date,
                p.service_end_date,
                p.description,
                p.status,
                p.award_date,
                p.created_at,
                p.client_id,
                COALESCE(qt.client_name, qh.client_name, qm.client_name, qs.client_name, qe.client_name, cc.company_name) AS client_name,
                COALESCE(qt.client_ssm, qh.client_ssm, qm.client_ssm, qs.client_ssm, qe.client_ssm, cc.ssm_number) AS client_ssm,
                cc.tax_id_no_tin AS client_tin,
                COALESCE(qt.client_address, qh.client_address, qm.client_address, qs.client_address, qe.client_address, cc.address) AS client_address,
                COALESCE(qt.client_city, qh.client_city, qm.client_city, qs.client_city, qe.client_city, cc.city) AS client_city,
                COALESCE(qt.client_state, qh.client_state, qm.client_state, qs.client_state, qe.client_state, cc.state) AS client_state,
                COALESCE(qt.client_zip, qh.client_zip, qm.client_zip, qs.client_zip, qe.client_zip, cc.zip) AS client_zip,
                COALESCE(qt.pic_name, qh.pic_name, qm.pic_name, qs.pic_name, qe.pic_name) AS quote_pic_name,
                COALESCE(qt.pic_email, qh.pic_email, qm.pic_email, qs.pic_email, qe.pic_email) AS quote_pic_email,
                COALESCE(qt.pic_phone, qh.pic_phone, qm.pic_phone, qs.pic_phone, qe.pic_phone) AS quote_pic_phone,
                COALESCE(qt.pic_position, qh.pic_position, qm.pic_position, qs.pic_position, qe.pic_position) AS quote_pic_position
            FROM projects_main p
            LEFT JOIN quotes_training qt ON qt.id = p.quote_id AND p.project_type = 'Training'
            LEFT JOIN quotes_ih qh ON qh.id = p.quote_id AND p.project_type = 'Industrial Hygiene'
            LEFT JOIN quotes_manpower qm ON qm.id = p.quote_id AND p.project_type = 'Manpower Supply'
            LEFT JOIN quotes_special qs ON qs.id = p.quote_id AND p.project_type = 'Special Service'
            LEFT JOIN quotes_equipment qe ON qe.id = p.quote_id AND p.project_type = 'Equipment Supply'
            LEFT JOIN client_company cc ON cc.company_id = p.client_id
            WHERE p.id = ?
            LIMIT 1
        ", [$id]);

        if (empty($rows)) {
            return response()->json(['status' => 'success', 'data' => null]);
        }

        $project = (array) $rows[0];
        $projectId = (int) $project['id'];
        $clientId = (int) ($project['client_id'] ?? 0);

        $addressParts = array_filter([
            $project['client_address'] ?? '',
            $project['client_city']    ?? '',
            $project['client_state']   ?? '',
            $project['client_zip']     ?? '',
        ]);
        $project['client_full_address'] = implode(', ', $addressParts);

        $project['progress_updates'] = array_map(
            fn ($row) => (array) $row,
            DB::select(
                "SELECT project_id, progress_date, progress_text, updated_by, updated_on
                 FROM project_progress
                 WHERE project_id = ?
                 ORDER BY progress_date DESC",
                [$projectId]
            )
        );

        $project['assigned_staff'] = array_map(
            fn ($row) => [
                'full_name'    => $row->full_name,
                'name_code'    => $row->name_code,
                'project_role' => $row->project_role,
            ],
            DB::select(
                "SELECT pc.project_id, sg.full_name, sg.name_code, pc.project_role
                 FROM project_collaborators pc
                 JOIN system_users su ON su.staff_id = pc.staff_id
                 JOIN staff_general sg ON sg.staff_id = su.staff_id
                 WHERE pc.project_id = ?
                 ORDER BY CASE pc.project_role WHEN 'Leader' THEN 1 WHEN 'Assistant' THEN 2 WHEN 'Collaborator' THEN 3 ELSE 99 END",
                [$projectId]
            )
        );

        $project['vendors'] = array_map(
            fn ($row) => (array) $row,
            DB::select(
                "SELECT pv.project_id, v.vendor_id, v.vendor_name, v.contact_person_name,
                        v.mobile_number, v.email, pv.award_value, pv.position, pv.remarks,
                        pv.services_description, pv.venue_details, pv.fee_breakdown, pv.payment_terms
                 FROM project_vendors pv
                 JOIN vendor_main_details v ON v.vendor_id = pv.vendor_id
                 WHERE pv.project_id = ?",
                [$projectId]
            )
        );

        $closing = DB::selectOne(
            "SELECT pcd.project_id, pcd.close_date, pcd.reason, pcd.closed_at, sg.name_code AS closed_by
             FROM project_closing_details pcd
             LEFT JOIN staff_general sg ON sg.staff_id = pcd.closed_by
             WHERE pcd.project_id = ?
             ORDER BY pcd.closed_at DESC
             LIMIT 1",
            [$projectId]
        );
        $project['closing_details'] = $closing ? [
            'close_date' => $closing->close_date,
            'reason'     => $closing->reason,
            'closed_at'  => $closing->closed_at,
            'closed_by'  => $closing->closed_by,
        ] : null;

        if (!empty($project['quote_pic_name']) || !empty($project['quote_pic_email'])) {
            $project['client_pics'] = [[
                'full_name'     => $project['quote_pic_name']     ?? '',
                'email'         => $project['quote_pic_email']    ?? '',
                'mobile_number' => $project['quote_pic_phone']    ?? '',
                'position'      => $project['quote_pic_position'] ?? '',
            ]];
        } else {
            $project['client_pics'] = array_map(
                fn ($row) => [
                    'full_name'     => $row->full_name,
                    'email'         => $row->email,
                    'mobile_number' => $row->mobile_number,
                    'position'      => $row->position,
                ],
                DB::select(
                    "SELECT company_id, full_name, email, mobile_number, position
                     FROM client_pic
                     WHERE status = 'assigned' AND company_id = ?
                     ORDER BY full_name ASC",
                    [$clientId]
                )
            );
        }

        if ($project['project_type'] === 'Equipment Supply' && !empty($project['quote_id'])) {
            $project['equipment_items'] = array_map(
                fn ($row) => (array) $row,
                DB::select(
                    "SELECT qi.quote_id, qi.id, qi.item_id, qi.quantity, qi.unit_price,
                             qi.marked_up_price, qi.line_total, ci.item_name, ci.description, ci.unit
                     FROM quotes_equipment_items qi
                     JOIN catalog_items ci ON ci.id = qi.item_id
                     WHERE qi.quote_id = ?",
                    [(int) $project['quote_id']]
                )
            );
        } else {
            $project['equipment_items'] = [];
        }

        unset(
            $project['client_id'],
            $project['quote_pic_name'],
            $project['quote_pic_email'],
            $project['quote_pic_phone'],
            $project['quote_pic_position']
        );

        return response()->json(['status' => 'success', 'data' => $project]);
    }

    public function crmDetails(Request $request): JsonResponse
    {
        $projectId = (int) $request->query('project_id', 0);
        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing project_id.']);
        }

        $project = DB::table('projects_main')
            ->select(['quote_id', 'project_type'])
            ->where('id', $projectId)
            ->first();

        if (!$project || empty($project->quote_id) || empty($project->project_type)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing official quotation data. This project may have been created without official quotation records.',
            ]);
        }

        $typeMap = [
            'training'          => 'quotes_training',
            'equipment supply'  => 'quotes_equipment',
            'industrial hygiene'=> 'quotes_ih',
            'manpower supply'   => 'quotes_manpower',
            'special service'   => 'quotes_special',
        ];

        $typeLower = strtolower(trim($project->project_type));
        if (!isset($typeMap[$typeLower])) {
            return response()->json(['status' => 'error', 'message' => 'Unrecognized project type.']);
        }

        $crm = DB::table($typeMap[$typeLower])
            ->select(['quote_ref_no', 'created_at', 'status', 'created_by_name', 'created_by_code', 'award_date', 'status_remarks'])
            ->where('id', $project->quote_id)
            ->first();

        if (!$crm) {
            return response()->json(['status' => 'error', 'message' => 'No CRM details found for the linked quote.']);
        }

        return response()->json($crm);
    }
}
