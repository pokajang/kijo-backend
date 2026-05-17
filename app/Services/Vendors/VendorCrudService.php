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

class VendorCrudService extends VendorBaseService
{

    public function index(ListVendorsRequest $request)
    {
        $validated = $request->validated();
        $perPage   = $this->resolvePerPage($validated, 25);
        $status    = strtolower((string) ($validated['status'] ?? 'all'));

        $query = DB::table('vendor_main_details');
        if ($status === 'active') {
            $query->where('status', 'Active')->whereNull('deleted_at');
        } elseif ($status === 'inactive') {
            $query->where('status', 'Inactive');
        } else {
            $query->whereNull('deleted_at');
        }

        $paginator = $query
            ->orderBy('vendor_id', 'desc')
            ->paginate($perPage);

        $vendors = $this->attachVendorRelations($paginator->items());

        return response()->json([
            'status'     => 'success',
            'vendors'    => $vendors,
            'data'       => $vendors,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function mainDetails(ListVendorMainDetailsRequest $request)
    {
        $perPage = $this->resolvePerPage($request->validated(), 100);

        $paginator = DB::table('vendor_main_details')
            ->select([
                'vendor_id as id',
                'vendor_name as name',
                'ssm_number as ssm',
                'sst_number as sst',
                'mobile_number',
                'email',
                'status',
            ])
            ->where('status', '!=', 'Deleted')
            ->orderBy('vendor_id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'vendors'    => $paginator->items(),
            'count'      => $paginator->total(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreVendorRequest $request)
    {
        $data    = $request->validated();
        $staffId = (int) $request->session()->get('staff_id', 0);

        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $vendorId = DB::table('vendor_main_details')->insertGetId([
                'vendor_name'          => $data['vendorName'],
                'ssm_number'           => $data['ssmNumber'] ?? null,
                'sst_number'           => $data['sstNo'] ?? null,
                'address'              => $data['address'] ?? null,
                'city'                 => $data['city'] ?? null,
                'state'                => $data['state'] ?? null,
                'zip'                  => $data['zip'] ?? null,
                'contact_person_name'  => $data['contactPersonName'] ?? null,
                'mobile_number'        => $data['mobileNumber'],
                'email'                => $data['email'] ?? null,
                'website'              => $data['companyWebsite'] ?? null,
                'emergency_name'       => $data['emergencyContactName'] ?? null,
                'emergency_relation'   => $data['emergencyRelationship'] ?? null,
                'emergency_mobile'     => $data['emergencyMobileNumber'] ?? null,
                'bank_name'            => $data['bankName'],
                'bank_account'         => $data['bankAccountNumber'],
                'bank_holder_name'     => $data['bankHolderName'],
                'status'               => $data['status'] ?? 'Active',
                'created_by'           => $staffId,
            ]);

            $this->syncVendorRelatedTable($vendorId, 'vendor_categories', 'category', $data['category'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_training_details', 'topic', $data['trainingTopics'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_competency_details', 'competency', $data['competency'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_supplies_details', 'product_name', $data['supplierProducts'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_consultancy_details', 'consulting_area', $data['consultancy'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_other_services_details', 'service_name', $data['servicesOffered'] ?? []);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to create vendor'], 500);
        }

        $this->auditLog->log($request, "Created new vendor #{$vendorId}");
        return response()->json([
            'status'    => 'success',
            'message'   => 'Vendor created successfully',
            'vendor_id' => $vendorId,
        ]);
    }

    public function update(UpdateVendorRequest $request, ?int $id = null)
    {
        $data      = $request->validated();
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $vendorId  = $this->resolveId($id, $data, 'vendor_id');

        if (!$vendorId) {
            return response()->json(['status' => 'error', 'message' => 'Missing vendor_id or route id'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $existing = DB::table('vendor_main_details')
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->first();

            if (!$existing) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Vendor not found'], 404);
            }

            DB::table('vendor_main_details')
                ->where('vendor_id', $vendorId)
                ->update([
                    'vendor_name'          => $data['vendorName'],
                    'ssm_number'           => $data['ssmNumber'] ?? null,
                    'sst_number'           => $data['sstNo'] ?? null,
                    'address'              => $data['address'] ?? null,
                    'city'                 => $data['city'] ?? null,
                    'state'                => $data['state'] ?? null,
                    'zip'                  => $data['zip'] ?? null,
                    'contact_person_name'  => $data['contactPersonName'] ?? null,
                    'mobile_number'        => $data['mobileNumber'],
                    'email'                => $data['email'] ?? null,
                    'website'              => $data['companyWebsite'] ?? null,
                    'emergency_name'       => $data['emergencyContactName'] ?? null,
                    'emergency_relation'   => $data['emergencyRelationship'] ?? null,
                    'emergency_mobile'     => $data['emergencyMobileNumber'] ?? null,
                    'bank_name'            => $data['bankName'],
                    'bank_account'         => $data['bankAccountNumber'],
                    'bank_holder_name'     => $data['bankHolderName'],
                    'status'               => $data['status'] ?? 'Active',
                    'updated_at'           => now(),
                    'updated_by'           => $staffId,
                ]);

            $this->syncVendorRelatedTable($vendorId, 'vendor_categories', 'category', $data['category'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_training_details', 'topic', $data['trainingTopics'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_competency_details', 'competency', $data['competency'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_supplies_details', 'product_name', $data['supplierProducts'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_consultancy_details', 'consulting_area', $data['consultancy'] ?? []);
            $this->syncVendorRelatedTable($vendorId, 'vendor_other_services_details', 'service_name', $data['servicesOffered'] ?? []);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Database error'], 500);
        }

        $this->auditLog->log($request, "Updated vendor ID #{$vendorId}");
        return response()->json(['status' => 'success', 'message' => 'Vendor updated successfully.']);
    }

    public function deactivate(DeactivateVendorRequest $request, ?int $id = null)
    {
        $data     = $request->validated();
        $staffId  = (int) $request->session()->get('staff_id', 0);
        $vendorId = $this->resolveId($id, $data, 'vendor_id');

        if (!$vendorId) {
            return response()->json(['status' => 'error', 'message' => 'Missing vendor_id or route id'], 400);
        }
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
        }

        $affected = DB::table('vendor_main_details')
            ->where('vendor_id', $vendorId)
            ->update([
                'deleted_at'    => now(),
                'deleted_by'    => $staffId,
                'delete_reason' => $data['delete_reason'] ?? null,
                'status'        => 'Inactive',
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'Vendor not found.'], 404);
        }

        $this->auditLog->log($request, "Soft deleted vendor ID #{$vendorId}");
        return response()->json([
            'status'  => 'success',
            'message' => "Vendor ID {$vendorId} was marked as inactive and soft-deleted.",
        ]);
    }

    public function reactivate(ReactivateVendorRequest $request, ?int $id = null)
    {
        $data     = $request->validated();
        $vendorId = $this->resolveId($id, $data, 'vendor_id');

        if (!$vendorId) {
            return response()->json(['status' => 'error', 'message' => 'Missing vendor_id or route id'], 400);
        }

        $affected = DB::table('vendor_main_details')
            ->where('vendor_id', $vendorId)
            ->update([
                'status'        => 'Active',
                'deleted_at'    => null,
                'deleted_by'    => null,
                'delete_reason' => null,
            ]);

        if ($affected === 0) {
            return response()->json(['status' => 'error', 'message' => 'Vendor not found or already active']);
        }

        $this->auditLog->log($request, "Reactivated vendor ID #{$vendorId} and cleared delete details.");
        return response()->json(['status' => 'success']);
    }

    public function destroy(PermanentDeleteVendorRequest $request, ?int $id = null)
    {
        $data     = $request->validated();
        $vendorId = $this->resolveId($id, $data, 'vendor_id');

        if (!$vendorId) {
            return response()->json(['status' => 'error', 'message' => 'Missing vendor_id or route id'], 400);
        }

        DB::beginTransaction();
        try {
            foreach ([
                'vendor_categories',
                'vendor_training_details',
                'vendor_competency_details',
                'vendor_supplies_details',
                'vendor_consultancy_details',
                'vendor_other_services_details',
            ] as $table) {
                DB::table($table)->where('vendor_id', $vendorId)->delete();
            }

            $deleted = DB::table('vendor_main_details')
                ->where('vendor_id', $vendorId)
                ->delete();

            if ($deleted < 1) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Vendor not found.'], 404);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to delete vendor.'], 500);
        }

        $this->auditLog->log($request, "Permanently deleted vendor ID #{$vendorId} and related records.");
        return response()->json(['status' => 'success', 'message' => 'Vendor successfully deleted.']);
    }
}
