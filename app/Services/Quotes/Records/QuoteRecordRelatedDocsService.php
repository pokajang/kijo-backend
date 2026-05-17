<?php

namespace App\Services\Quotes\Records;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteRecordRelatedDocsService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function discoverRelatedDocs(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (!$cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 422);
        }

        $projects = $this->config->hasTable('projects_main')
            ? $this->config->linkedProjectsBase($service)->where('quote_id', $quoteId)->select(['id', 'project_type'])->get()
            : collect();
        $projectIds = $projects->pluck('id')->map(fn ($v) => (int) $v)->all();

        $deliveryOrders = [];
        if (!empty($projectIds) && $this->config->hasTable('do_details')) {
            $deliveryOrders = DB::table('do_details')
                ->whereIn('project_id', $projectIds)
                ->select(['id', DB::raw('COALESCE(do_number, CONCAT("DO #", id)) as do_number')])
                ->orderBy('id')
                ->get();
        }

        $invoices = [];
        if (!empty($projectIds) && $this->config->hasTable('invoices')) {
            $invoices = DB::table('invoices')
                ->whereIn('project_id', $projectIds)
                ->select(['id', 'invoice_ref_no', 'receipt_no'])
                ->orderBy('id')
                ->get();
        }

        $jd14 = [];
        if (!empty($projectIds) && $this->config->hasTable('invoices_jd14form')) {
            $jd14 = DB::table('invoices_jd14form')
                ->whereIn('project_id', $projectIds)
                ->select(['id', DB::raw('COALESCE(approval_no, CONCAT("JD14 #", id)) as approval_no')])
                ->orderBy('id')
                ->get();
        }

        $receipts = collect($invoices)->filter(fn ($row) => !empty($row->receipt_no))->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'projects' => $projects,
                'delivery_orders' => $deliveryOrders,
                'invoices' => $invoices,
                'receipts' => $receipts,
                'jd14' => $jd14,
            ],
        ]);
    }
}
