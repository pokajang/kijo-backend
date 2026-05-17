<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceQueryService extends InvoiceBaseService
{

    public function index(Request $request): JsonResponse
    {
        try {
            $year = (int) $request->query('year', 0);
            $yearClause = ($year >= 2000 && $year <= 2100) ? 'WHERE YEAR(i.invoice_date) = ?' : '';
            $bindings = ($year >= 2000 && $year <= 2100) ? [$year] : [];
            $invoices = DB::select("
                SELECT
                    i.*,
                    i.invoice_loa_no    AS loa_number,
                    sg.full_name        AS created_by_name,
                    sg.name_code        AS created_by_code,
                    sg.email            AS created_by_email,
                    p.project_name      AS project_name,
                    p.award_date        AS award_date,
                    p.service_start_date AS service_start_date,
                    p.service_end_date  AS service_end_date,
                    p.description       AS project_description,
                    COALESCE(i.invoice_client_name, q.client_name, cc.company_name, CONCAT('Client #', i.client_id)) AS client_name,
                    COALESCE(i.invoice_client_ssm, q.client_ssm, cc.ssm_number)              AS client_ssm,
                    COALESCE(i.invoice_client_tin, cc.tax_id_no_tin)                         AS client_tin,
                    COALESCE(i.invoice_client_address, q.client_address, cc.address)         AS client_address,
                    COALESCE(i.invoice_client_city, q.client_city, cc.city)                  AS client_city,
                    COALESCE(i.invoice_client_state, q.client_state, cc.state)               AS client_state,
                    COALESCE(i.invoice_client_zip, q.client_zip, cc.zip)                     AS client_zip,
                    COALESCE(i.invoice_pic_name, q.pic_name, cp.full_name)                   AS pic_name,
                    COALESCE(i.invoice_pic_email, q.pic_email, cp.email)                     AS pic_email,
                    COALESCE(i.invoice_pic_phone, q.pic_phone, cp.mobile_number)             AS pic_phone,
                    COALESCE(i.invoice_pic_position, q.pic_position, cp.position)            AS pic_position
                FROM invoices i
                LEFT JOIN projects_main p ON p.id = i.project_id
                LEFT JOIN quotes_training q ON i.quote_id = q.id
                LEFT JOIN client_company cc ON i.client_id = cc.company_id
                LEFT JOIN (
                    SELECT p1.*
                    FROM client_pic p1
                    INNER JOIN (
                        SELECT company_id, MAX(pic_id) AS latest_pic_id
                        FROM client_pic
                        WHERE status = 'assigned'
                        GROUP BY company_id
                    ) p2 ON p1.company_id = p2.company_id AND p1.pic_id = p2.latest_pic_id
                ) cp ON cp.company_id = i.client_id
                LEFT JOIN staff_general sg ON sg.staff_id = i.created_by
                {$yearClause}
                ORDER BY (i.status = 'Pending') DESC, i.created_at DESC
            ", $bindings);

            $invoiceIds = array_column($invoices, 'id');
            $breakdownMap = [];
            if (!empty($invoiceIds)) {
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $rows = DB::select("
                    SELECT id, invoice_id, item_description, description, unit, quantity, unit_price, subtotal, sort_order
                    FROM invoice_breakdown
                    WHERE invoice_id IN ({$placeholders})
                      AND LOWER(item_description) NOT LIKE '%sst%'
                      AND LOWER(item_description) NOT LIKE '%hrd%'
                    ORDER BY sort_order ASC
                ", $invoiceIds);
                foreach ($rows as $row) {
                    $breakdownMap[$row->invoice_id][] = $row;
                }
            }

            foreach ($invoices as &$inv) {
                $inv->breakdown = $breakdownMap[$inv->id] ?? [];
            }
            unset($inv);

            return response()->json(['status' => 'success', 'invoices' => $invoices]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function latestByProject(Request $request): JsonResponse
    {
        $projectId   = (int) $request->query('project_id', 0);
        $serviceType = trim((string) $request->query('service_type', ''));

        if ($projectId < 1) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid project_id.'], 422);
        }

        try {
            $query = DB::table('invoices')->where('project_id', $projectId);
            if ($serviceType !== '') {
                $query->where('service_type', $serviceType);
            }

            $invoice = $query
                ->orderByRaw('COALESCE(invoice_date, created_at) DESC')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (!$invoice) {
                return response()->json(['status' => 'success', 'data' => null]);
            }

            $breakdown = DB::table('invoice_breakdown')
                ->where('invoice_id', $invoice->id)
                ->orderBy('sort_order')
                ->get(['id', 'invoice_id', 'item_description', 'description', 'unit', 'quantity', 'unit_price', 'subtotal', 'sort_order']);

            return response()->json([
                'status' => 'success',
                'data'   => ['invoice' => $invoice, 'breakdown' => $breakdown],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
