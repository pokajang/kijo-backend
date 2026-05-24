<?php

namespace App\Services\Vendors;

use App\Http\Requests\Vendor\ApproveVendorPaymentRequest;
use App\Http\Requests\Vendor\DeactivateVendorRequest;
use App\Http\Requests\Vendor\DeleteVendorPaymentRequest;
use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListProjectVendorsRequest;
use App\Http\Requests\Vendor\ListVendorMainDetailsRequest;
use App\Http\Requests\Vendor\ListVendorsRequest;
use App\Http\Requests\Vendor\ListVendorPaymentsRequest;
use App\Http\Requests\Vendor\PermanentDeleteVendorRequest;
use App\Http\Requests\Vendor\ReactivateVendorRequest;
use App\Http\Requests\Vendor\StoreVendorPaymentRequest;
use App\Http\Requests\Vendor\StoreVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorPaymentService extends VendorBaseService
{

    public function vendorPayments(GetVendorPaymentsRequest $request)
    {
        $data     = $request->validated();
        $vendorId = (int) $data['vendor_id'];
        $perPage  = $this->resolvePerPage($data, 50);

        $query = DB::table('vendor_payments as vp')
            ->leftJoin('projects_main as pm', 'vp.project_id', '=', 'pm.id')
            ->where('vp.vendor_id', $vendorId)
            ->whereNull('vp.deleted_at')
            ->select([
                'vp.id',
                'vp.vendor_id',
                'vp.project_id',
                'vp.payment_context',
                'vp.remarks',
                'vp.amount',
                'vp.method',
                'vp.status',
                'vp.created_at',
                'vp.date_approved',
                'vp.payment_type',
                'vp.receipt_path',
                'vp.created_by',
                'vp.created_by_full_name',
                'vp.created_by_name_code',
                'pm.project_name',
                DB::raw('pm.description as project_description'),
            ]);

        if (!empty($data['year'])) {
            $query->whereYear('vp.created_at', (int) $data['year']);
        }

        $paginator = $query->orderBy('vp.created_at', 'asc')->paginate($perPage);

        $history = collect($paginator->items())
            ->map(fn ($row) => $this->normalizePaymentRow((array) $row))
            ->values()
            ->all();

        $outstanding = (float) DB::table('vendor_payments')
            ->where('vendor_id', $vendorId)
            ->whereNull('deleted_at')
            ->where('status', 'Approved')
            ->when(!empty($data['year']), fn ($query) => $query->whereYear('created_at', (int) $data['year']))
            ->sum('amount');

        return response()->json([
            'status'      => 'success',
            'outstanding' => $outstanding,
            'history'     => $history,
            'pagination'  => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function listPayments(ListVendorPaymentsRequest $request)
    {
        $data = $request->validated();
        $perPage = $this->resolvePerPage($data, 50);

        $query = DB::table('vendor_payments as vp')
            ->leftJoin('vendor_main_details as vmd', 'vp.vendor_id', '=', 'vmd.vendor_id')
            ->leftJoin('projects_main as pm', 'vp.project_id', '=', 'pm.id')
            ->leftJoin('staff_general as sg_approved', 'vp.approved_by', '=', 'sg_approved.staff_id')
            ->whereNull('vp.deleted_at')
            ->select([
                'vp.id',
                'vp.vendor_id',
                'vmd.vendor_name',
                'vp.project_id',
                'pm.project_name',
                DB::raw('pm.description as project_description'),
                'vp.payment_context',
                'vp.remarks',
                'vp.amount',
                'vp.method',
                'vp.status',
                'vp.created_at',
                'vp.date_approved',
                'vp.payment_type',
                'vp.receipt_path',
                'vp.created_by',
                'vp.created_by_full_name',
                'vp.created_by_name_code',
                'vp.approved_by',
                DB::raw('sg_approved.name_code as approved_by_name_code'),
            ]);

        if (!empty($data['year'])) {
            $query->whereYear('vp.created_at', (int) $data['year']);
        }

        $paginator = $query->orderBy('vp.created_at', 'desc')->paginate($perPage);

        $history = collect($paginator->items())
            ->map(fn ($row) => $this->normalizePaymentRow((array) $row))
            ->values()
            ->all();

        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        return response()->json([
            'status'     => 'success',
            'staff'      => [
                'staff_id'  => $request->session()->get('staff_id'),
                'roles'     => $roles,
                'full_name' => $request->session()->get('full_name', '-'),
                'name_code' => $request->session()->get('name_code', '-'),
            ],
            'history'    => $history,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function storePayment(StoreVendorPaymentRequest $request)
    {
        $data      = $request->validated();
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $fullName  = (string) $request->session()->get('full_name', '');
        $nameCode  = (string) $request->session()->get('name_code', '');

        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $file      = $request->file('receipt');
            $year      = now()->format('Y');
            $month     = now()->format('m');
            $filename = 'receipt_' . Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
            $storedPath = "payments/{$year}/{$month}/{$filename}";

            $receiptPath = AppFilePaths::storeFileAs("payments/{$year}/{$month}", $file, $filename);
        }

        try {
            $paymentId = DB::table('vendor_payments')->insertGetId([
                'vendor_id'             => $data['vendor_id'],
                'project_id'            => $data['project_id'] ?? null,
                'payment_context'       => $data['payment_context'],
                'payment_type'          => $data['payment_type'],
                'amount'                => $data['amount'],
                'method'                => $data['method'],
                'remarks'               => $data['remarks'] ?? '',
                'receipt_path'          => $receiptPath,
                'created_by'            => $staffId,
                'created_by_full_name'  => $fullName,
                'created_by_name_code'  => $nameCode,
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        $this->auditLog->log($request, "Created vendor payment request #{$paymentId}");
        return response()->json(['status' => 'success', 'id' => $paymentId]);
    }

    public function approvePayment(ApproveVendorPaymentRequest $request, ?int $id = null)
    {
        $data      = $request->validated();
        $staffId   = (int) $request->session()->get('staff_id', 0);

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');

        if (!$paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid payment ID'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $affected = DB::table('vendor_payments')
            ->where('id', $paymentId)
            ->whereNull('deleted_at')
            ->update([
                'status'        => 'Approved',
                'date_approved' => now(),
                'approved_by'   => $staffId,
            ]);

        if ($affected < 1) {
            return response()->json(['status' => 'error', 'message' => 'No payment record updated.']);
        }

        $this->auditLog->log($request, "Approved payment ID #{$paymentId}");
        return response()->json(['status' => 'success', 'message' => 'Payment approved.']);
    }

    public function deletePayment(DeleteVendorPaymentRequest $request, ?int $id = null)
    {
        $data      = $request->validated();
        $staffId   = (int) $request->session()->get('staff_id', 0);

        if ($id !== null && $id > 0 && isset($data['id']) && (int) $data['id'] !== $id) {
            return response()->json(['status' => 'error', 'message' => 'Payment ID mismatch.'], 409);
        }

        $paymentId = $this->resolveId($id, $data, 'id');

        if (!$paymentId) {
            return response()->json(['status' => 'error', 'message' => 'Missing payment ID'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $affected = DB::table('vendor_payments')
            ->where('id', $paymentId)
            ->whereNull('deleted_at')
            ->update([
                'deleted_at' => now(),
                'deleted_by' => $staffId,
            ]);

        if ($affected < 1) {
            return response()->json(['status' => 'error', 'message' => 'No payment deleted or already deleted.']);
        }

        $this->auditLog->log($request, "Soft deleted payment ID #{$paymentId}");
        return response()->json(['status' => 'success', 'message' => 'Payment soft deleted.']);
    }
}
