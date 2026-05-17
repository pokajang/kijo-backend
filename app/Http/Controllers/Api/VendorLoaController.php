<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorLoa\FetchVendorLoaRequest;
use App\Http\Requests\VendorLoa\UpdateVendorLoaPaymentStatusRequest;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class VendorLoaController extends Controller
{
    public function __construct(private AuditLogService $auditLog) {}

    public function index(FetchVendorLoaRequest $request)
    {
        $data = $request->validated();
        $perPage = $this->resolvePerPage($data, 25);

        $latestCreatedByPair = DB::table('vendor_payments')
            ->select('vendor_id', 'project_id', DB::raw('MAX(created_at) AS latest_created'))
            ->whereNull('deleted_at')
            ->groupBy('vendor_id', 'project_id');

        $latestPayments = DB::table('vendor_payments as vp1')
            ->joinSub($latestCreatedByPair, 'vp2', function ($join) {
                $join->on('vp1.vendor_id', '=', 'vp2.vendor_id')
                    ->on('vp1.project_id', '=', 'vp2.project_id')
                    ->on('vp1.created_at', '=', 'vp2.latest_created');
            })
            ->whereNull('vp1.deleted_at')
            ->select([
                'vp1.id',
                'vp1.vendor_id',
                'vp1.project_id',
                'vp1.created_at',
                'vp1.date_approved',
                'vp1.status',
            ]);

        $query = DB::table('project_vendors as pv')
            ->leftJoin('vendor_main_details as vmd', 'vmd.vendor_id', '=', 'pv.vendor_id')
            ->leftJoin('projects_main as pm', 'pm.id', '=', 'pv.project_id')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'pv.awarded_by')
            ->leftJoinSub($latestPayments, 'vp', function ($join) {
                $join->on('vp.vendor_id', '=', 'pv.vendor_id')
                    ->on('vp.project_id', '=', 'pv.project_id');
            })
            ->select([
                'pv.id',
                'pv.vendor_id',
                'pv.project_id',
                'pv.loa_ref_no',
                'pv.services_description',
                'pv.award_value',
                'pv.award_date',
                'pv.position',
                'pv.remarks',
                'pv.venue_details',
                'pv.fee_breakdown',
                'pv.payment_terms',
                DB::raw('sg.name_code as award_by'),
                'vmd.vendor_name',
                'vmd.contact_person_name',
                'vmd.mobile_number',
                'vmd.email',
                'pm.project_name',
                DB::raw('vp.id as payment_id'),
                DB::raw('vp.created_at as payment_requested_on'),
                DB::raw('vp.date_approved as payment_approved_on'),
                DB::raw('vp.status as status'),
            ]);

        if (!empty($data['year'])) {
            $query->whereYear('pv.award_date', (int) $data['year']);
        }

        $paginator = $query->orderBy('pv.created_at', 'desc')->paginate($perPage);

        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        return response()->json([
            'status'     => 'success',
            'data'       => $paginator->items(),
            'staff'      => [
                'staff_id' => $request->session()->get('staff_id'),
                'roles'    => $roles,
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function updatePaymentStatus(UpdateVendorLoaPaymentStatusRequest $request)
    {
        $data = $request->validated();
        if (!$this->canManagePaidStatus($request->session()->get('roles', []))) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized to mark payment as Paid.',
            ], 403);
        }

        $paymentId       = (int) $data['id'];
        $vendorId        = (int) $data['vendor_id'];
        $projectId       = (int) $data['project_id'];
        $transactionDate = $data['transaction_date'];

        DB::beginTransaction();
        try {
            $record = DB::table('vendor_payments')
                ->where('id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (!$record || $record->deleted_at !== null) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment record not found.',
                ], 404);
            }

            if ((int) $record->vendor_id !== $vendorId || (int) $record->project_id !== $projectId) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment does not match selected vendor/project.',
                ], 409);
            }

            $status = strtolower(trim((string) ($record->status ?? '')));
            if ($status === 'paid') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This payment is already marked as Paid.',
                ]);
            }

            if ($status !== 'approved' || $record->date_approved === null) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot mark as Paid before payment is approved.',
                ], 409);
            }

            $affected = DB::table('vendor_payments')
                ->where('id', $paymentId)
                ->where('vendor_id', $vendorId)
                ->where('project_id', $projectId)
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->whereNotNull('date_approved')
                ->whereNull('deleted_at')
                ->update([
                    'status'    => 'Paid',
                    'paid_date' => $transactionDate,
                ]);

            if ($affected < 1) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment status is no longer eligible for update.',
                ], 409);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Unable to update payment status right now.',
            ], 500);
        }

        $this->auditLog->log(
            $request,
            "Marked payment ID #{$paymentId} as Paid for vendor #{$vendorId} / project #{$projectId}"
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Payment status updated to Paid.',
        ]);
    }

    private function resolvePerPage(array $data, int $default): int
    {
        $perPage = (int) ($data['per_page'] ?? $default);
        if ($perPage < 1) {
            return $default;
        }
        return min($perPage, 100);
    }

    private function canManagePaidStatus(array|string $roles): bool
    {
        $safeRoles = is_array($roles) ? $roles : [$roles];
        foreach ($safeRoles as $role) {
            $text = strtolower(trim((string) $role));
            if (
                str_contains($text, 'manager') ||
                str_contains($text, 'admin') ||
                str_contains($text, 'finance') ||
                str_contains($text, 'account') ||
                str_contains($text, 'bank')
            ) {
                return true;
            }
        }

        return false;
    }
}
