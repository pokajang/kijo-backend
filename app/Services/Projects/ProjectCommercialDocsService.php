<?php

namespace App\Services\Projects;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProjectCommercialDocsService
{
    public function commercialDocs(Request $request): JsonResponse
    {
        $projectId = (int) $request->input('project_id', $request->query('project_id', 0));
        if ($projectId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing project ID.'], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'invoices' => $this->invoices($projectId),
                'delivery_orders' => $this->deliveryOrders($projectId),
                'jd14' => $this->jd14Forms($projectId),
                'vendor_loas' => $this->vendorLoas($projectId),
                'vendor_payments' => $this->vendorPayments($projectId),
                'supplier_pos' => $this->supplierPurchaseOrders($projectId),
            ],
        ]);
    }

    private function invoices(int $projectId): array
    {
        if (! Schema::hasTable('invoices')) {
            return [];
        }

        return DB::table('invoices')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->select($this->selectColumns('invoices', [
                'id',
                'invoice_ref_no',
                'status',
                'grand_total',
            ]))
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function deliveryOrders(int $projectId): array
    {
        if (! Schema::hasTable('do_details')) {
            return [];
        }

        return DB::table('do_details')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->select($this->selectColumns('do_details', [
                'id',
                'do_number',
            ]))
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function jd14Forms(int $projectId): array
    {
        if (! Schema::hasTable('invoices_jd14form')) {
            return [];
        }

        return DB::table('invoices_jd14form')
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->select($this->selectColumns('invoices_jd14form', [
                'id',
                'approval_no',
            ]))
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function vendorLoas(int $projectId): array
    {
        if (! Schema::hasTable('project_vendors')) {
            return [];
        }

        $query = DB::table('project_vendors as pv')
            ->where('pv.project_id', $projectId)
            ->orderBy('pv.id')
            ->select([
                'pv.id',
                'pv.loa_ref_no',
            ]);

        if (Schema::hasTable('vendor_main_details')) {
            $query
                ->leftJoin('vendor_main_details as vmd', 'vmd.vendor_id', '=', 'pv.vendor_id')
                ->addSelect('vmd.vendor_name');
        } else {
            $query->addSelect(DB::raw('NULL as vendor_name'));
        }

        return $query
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function vendorPayments(int $projectId): array
    {
        if (! Schema::hasTable('vendor_payments')) {
            return [];
        }

        $query = DB::table('vendor_payments as vp')
            ->where('vp.project_id', $projectId)
            ->orderBy('vp.id')
            ->select([
                'vp.id',
                'vp.status',
                'vp.amount',
            ]);

        if (Schema::hasColumn('vendor_payments', 'deleted_at')) {
            $query->whereNull('vp.deleted_at');
        }

        if (Schema::hasTable('vendor_main_details')) {
            $query
                ->leftJoin('vendor_main_details as vmd', 'vmd.vendor_id', '=', 'vp.vendor_id')
                ->addSelect('vmd.vendor_name');
        } else {
            $query->addSelect(DB::raw('NULL as vendor_name'));
        }

        if (Schema::hasTable('project_vendors')) {
            $query
                ->leftJoin('project_vendors as pv', function ($join): void {
                    $join->on('pv.project_id', '=', 'vp.project_id')
                        ->on('pv.vendor_id', '=', 'vp.vendor_id');
                })
                ->addSelect('pv.id as vendor_loa_id');
        } else {
            $query->addSelect(DB::raw('NULL as vendor_loa_id'));
        }

        return $query
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function supplierPurchaseOrders(int $projectId): array
    {
        if (! Schema::hasTable('supplier_po_main')) {
            return [];
        }

        return DB::table('supplier_po_main')
            ->where('project_id', $projectId)
            ->orderBy('po_id')
            ->select($this->selectColumns('supplier_po_main', [
                'po_id',
                'po_ref_no',
                'supplier_name',
                'status',
            ]))
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function selectColumns(string $table, array $columns): array
    {
        return array_map(
            static fn (string $column) => Schema::hasColumn($table, $column)
                ? $column
                : DB::raw("NULL as {$column}"),
            $columns
        );
    }
}
