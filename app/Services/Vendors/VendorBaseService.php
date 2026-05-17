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

abstract class VendorBaseService
{
    public function __construct(protected AuditLogService $auditLog) {}

    protected function normalizePaymentRow(array $row): array
    {
        $receiptUrl = AppFilePaths::publicUrlForStoredPath($row['receipt_path'] ?? '');
        $row['receipt_path'] = $receiptUrl;
        $row['receipt_url'] = $receiptUrl;

        return $row;
    }

    protected function resolvePerPage(array $data, int $default): int
    {
        $perPage = (int) ($data['per_page'] ?? $default);
        if ($perPage < 1) {
            return $default;
        }
        return min($perPage, 100);
    }

    protected function resolveId(?int $routeId, array $data, string $key): ?int
    {
        if ($routeId && $routeId > 0) {
            return $routeId;
        }

        $id = (int) ($data[$key] ?? 0);
        return $id > 0 ? $id : null;
    }

    protected function attachVendorRelations(array $vendors): array
    {
        if (empty($vendors)) {
            return [];
        }

        $vendorIds = array_values(array_filter(array_map(
            fn ($vendor) => (int) ($vendor->vendor_id ?? 0),
            $vendors
        )));

        if (empty($vendorIds)) {
            return $vendors;
        }

        $relationMap = [
            'category'         => ['vendor_categories', 'category'],
            'trainingTopics'   => ['vendor_training_details', 'topic'],
            'competency'       => ['vendor_competency_details', 'competency'],
            'supplierProducts' => ['vendor_supplies_details', 'product_name'],
            'consultancy'      => ['vendor_consultancy_details', 'consulting_area'],
            'servicesOffered'  => ['vendor_other_services_details', 'service_name'],
        ];

        $valuesByFieldAndVendor = [];
        foreach ($relationMap as $field => [$table, $column]) {
            $rows = DB::table($table)
                ->whereIn('vendor_id', $vendorIds)
                ->orderBy('id', 'asc')
                ->select(['vendor_id', $column])
                ->get();

            foreach ($rows as $row) {
                $valuesByFieldAndVendor[$field][(int) $row->vendor_id][] = $row->{$column};
            }
        }

        foreach ($vendors as $vendor) {
            $vendorId = (int) ($vendor->vendor_id ?? 0);
            foreach (array_keys($relationMap) as $field) {
                $vendor->{$field} = $valuesByFieldAndVendor[$field][$vendorId] ?? [];
            }
        }

        return $vendors;
    }

    protected function syncVendorRelatedTable(int $vendorId, string $table, string $column, array $rawValues): void
    {
        DB::table($table)->where('vendor_id', $vendorId)->delete();

        $values = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            $rawValues
        ), static fn ($value) => $value !== ''));

        if (empty($values)) {
            return;
        }

        DB::table($table)->insert(array_map(
            fn ($value) => ['vendor_id' => $vendorId, $column => $value],
            $values
        ));
    }
}
