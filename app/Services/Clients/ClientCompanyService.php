<?php

namespace App\Services\Clients;

use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ClientCompanyService extends ClientBaseService
{
    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $companyName = trim((string) ($data['company_name'] ?? ''));
        $clientStatus = (string) ($data['client_status'] ?? 'New');
        if (! in_array($clientStatus, ['Old', 'New'], true)) {
            $clientStatus = 'New';
        }
        $paymentTermsDays = $this->normalizeNullablePaymentTermsDays($data['payment_terms_days'] ?? null);

        $state = $this->composeState((string) ($data['state'] ?? ''), (string) ($data['country'] ?? ''), (string) ($data['intl_country'] ?? ''));

        DB::beginTransaction();
        try {
            $companyId = DB::table('client_company')->insertGetId([
                'company_name' => $companyName,
                'ssm_number' => trim((string) ($data['ssm_number'] ?? '')),
                'tax_id_no_tin' => trim((string) ($data['tax_id_no_tin'] ?? '')),
                'client_status' => $clientStatus,
                'payment_terms_days' => $paymentTermsDays,
                'address' => trim((string) ($data['address'] ?? '')),
                'city' => trim((string) ($data['city'] ?? '')),
                'state' => $state,
                'zip' => trim((string) ($data['zip'] ?? '')),
                'status' => 'active',
                'created_at' => now(),
            ]);

            foreach ($data['pic_list'] as $pic) {
                $fullName = trim((string) ($pic['full_name'] ?? ''));
                $email = trim((string) ($pic['email'] ?? ''));
                if ($fullName === '' || $email === '') {
                    continue;
                }

                DB::table('client_pic')->insert([
                    'company_id' => $companyId,
                    'full_name' => $fullName,
                    'email' => $email,
                    'mobile_number' => trim((string) ($pic['mobile_number'] ?? '')),
                    'position' => trim((string) ($pic['position'] ?? '')),
                    'status' => 'assigned',
                    'created_at' => now(),
                ]);
            }

            $branchesCreated = 0;
            foreach (($data['branch_list'] ?? []) as $branch) {
                $branchName = trim((string) ($branch['branch_name'] ?? ''));
                $address = trim((string) ($branch['address'] ?? ''));
                $city = trim((string) ($branch['city'] ?? ''));
                $branchState = trim((string) ($branch['state'] ?? ''));
                $zip = trim((string) ($branch['zip'] ?? ''));

                $countryRaw = trim((string) ($branch['country'] ?? 'Malaysia'));
                $intlCountry = trim((string) ($branch['intl_country'] ?? ''));
                $country = $countryRaw === 'Other' ? $intlCountry : $countryRaw;

                if ($branchName === '' && $address === '' && $city === '' && $branchState === '' && $zip === '' && $country === '') {
                    continue;
                }

                if ($address === '') {
                    continue;
                }

                DB::table('client_company_branch')->insert([
                    'company_id' => $companyId,
                    'branch_name' => $branchName,
                    'address' => $address,
                    'city' => $city,
                    'state' => $branchState,
                    'zip' => $zip,
                    'country' => $country,
                    'status' => 'active',
                    'created_at' => now(),
                ]);

                $branchesCreated++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->error('Database error.', 500);
        }

        $this->auditLog->log($request, "Created client company: {$companyName}");

        return $this->success([
            'company_id' => (int) $companyId,
            'branches_created' => $branchesCreated,
        ], 'Client and PIC(s) created successfully.');
    }

    public function update(UpdateClientRequest $request, ?int $companyId = null): JsonResponse
    {
        $data = $request->validated();

        $companyId = (int) ($data['company_id'] ?? $companyId);
        if ($companyId <= 0) {
            return $this->error('Missing company_id');
        }

        $companyName = trim((string) ($data['company_name'] ?? ''));
        $clientStatus = (string) ($data['client_status'] ?? 'New');
        if (! in_array($clientStatus, ['Old', 'New'], true)) {
            $clientStatus = 'New';
        }
        $paymentTermsDays = $this->normalizeNullablePaymentTermsDays($data['payment_terms_days'] ?? null);

        $state = $this->composeState((string) ($data['state'] ?? ''), (string) ($data['country'] ?? ''), (string) ($data['intl_country'] ?? ''));

        DB::beginTransaction();
        try {
            $companyUpdate = [
                'company_name' => $companyName,
                'ssm_number' => trim((string) ($data['ssm_number'] ?? '')),
                'tax_id_no_tin' => trim((string) ($data['tax_id_no_tin'] ?? '')),
                'client_status' => $clientStatus,
                'payment_terms_days' => $paymentTermsDays,
                'address' => trim((string) ($data['address'] ?? '')),
                'city' => trim((string) ($data['city'] ?? '')),
                'state' => $state,
                'zip' => trim((string) ($data['zip'] ?? '')),
            ];
            if (Schema::hasColumn('client_company', 'updated_at')) {
                $companyUpdate['updated_at'] = now();
            }

            DB::table('client_company')
                ->where('company_id', $companyId)
                ->update($companyUpdate);

            if (is_array($data['pic_list'] ?? null)) {
                $existingPicIds = DB::table('client_pic')
                    ->where('company_id', $companyId)
                    ->when(Schema::hasColumn('client_pic', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                    ->whereNotNull('pic_id')
                    ->pluck('pic_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                $keptPicIds = [];

                foreach ($data['pic_list'] as $pic) {
                    $picId = (int) ($pic['pic_id'] ?? 0);
                    $fullName = trim((string) ($pic['full_name'] ?? ''));
                    $email = trim((string) ($pic['email'] ?? ''));
                    $mobileNumber = trim((string) ($pic['mobile_number'] ?? ''));
                    $position = trim((string) ($pic['position'] ?? ''));

                    if ($picId <= 0 || ! in_array($picId, $existingPicIds, true)) {
                        continue;
                    }

                    if ($fullName === '' || $email === '') {
                        continue;
                    }

                    DB::table('client_pic')
                        ->where('pic_id', $picId)
                        ->update([
                            'full_name' => $fullName,
                            'email' => $email,
                            'mobile_number' => $mobileNumber,
                            'position' => $position,
                            'company_id' => $companyId,
                            'status' => 'assigned',
                        ]);

                    $keptPicIds[] = $picId;
                }

                $toUnassign = array_values(array_diff($existingPicIds, $keptPicIds));
                foreach ($toUnassign as $picIdToUnassign) {
                    DB::table('client_pic')
                        ->where('pic_id', $picIdToUnassign)
                        ->where('company_id', $companyId)
                        ->update([
                            'company_id' => null,
                            'status' => 'unassigned',
                        ]);
                }
            }

            foreach (($data['new_pic_list'] ?? []) as $pic) {
                $fullName = trim((string) ($pic['full_name'] ?? ''));
                $email = trim((string) ($pic['email'] ?? ''));

                if ($fullName === '' || $email === '') {
                    continue;
                }

                DB::table('client_pic')->insert([
                    'company_id' => $companyId,
                    'full_name' => $fullName,
                    'email' => $email,
                    'mobile_number' => trim((string) ($pic['mobile_number'] ?? '')),
                    'position' => trim((string) ($pic['position'] ?? '')),
                    'status' => 'assigned',
                    'created_at' => now(),
                ]);
            }

            if (is_array($data['branch_list'] ?? null)) {
                $existingBranchIds = DB::table('client_company_branch')
                    ->where('company_id', $companyId)
                    ->when(Schema::hasColumn('client_company_branch', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                    ->whereNotNull('branch_id')
                    ->pluck('branch_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                $keptExistingIds = [];

                foreach ($data['branch_list'] as $branch) {
                    $branchId = (int) ($branch['branch_id'] ?? 0);
                    $branchName = trim((string) ($branch['branch_name'] ?? ''));
                    $address = trim((string) ($branch['address'] ?? ''));
                    $city = trim((string) ($branch['city'] ?? ''));
                    $branchState = trim((string) ($branch['state'] ?? ''));
                    $zip = trim((string) ($branch['zip'] ?? ''));
                    $country = $this->normalizeBranchCountry((string) ($branch['country'] ?? ''), (string) ($branch['intl_country'] ?? ''));

                    if ($branchName === '' && $address === '' && $city === '' && $branchState === '' && $zip === '' && $country === '') {
                        continue;
                    }

                    $params = [
                        'company_id' => $companyId,
                        'branch_name' => $branchName,
                        'address' => $address,
                        'city' => $city,
                        'state' => $branchState,
                        'zip' => $zip,
                        'country' => $country !== '' ? $country : 'Malaysia',
                        'status' => 'active',
                    ];

                    if ($branchId > 0 && in_array($branchId, $existingBranchIds, true)) {
                        $branchRestore = [];
                        if (Schema::hasColumn('client_company_branch', 'deleted_at')) {
                            $branchRestore['deleted_at'] = null;
                        }
                        if (Schema::hasColumn('client_company_branch', 'deleted_by')) {
                            $branchRestore['deleted_by'] = null;
                        }

                        DB::table('client_company_branch')
                            ->where('branch_id', $branchId)
                            ->where('company_id', $companyId)
                            ->update($params + $branchRestore);

                        $keptExistingIds[] = $branchId;
                    } else {
                        DB::table('client_company_branch')->insert($params + ['created_at' => now()]);
                    }
                }

                $toDelete = array_values(array_diff($existingBranchIds, $keptExistingIds));
                foreach ($toDelete as $branchIdToDelete) {
                    $branchDelete = ['status' => 'inactive'];
                    if (Schema::hasColumn('client_company_branch', 'deleted_at')) {
                        $branchDelete['deleted_at'] = now();
                    }

                    DB::table('client_company_branch')
                        ->where('branch_id', $branchIdToDelete)
                        ->where('company_id', $companyId)
                        ->update($branchDelete);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to update client company.', [
                'company_id' => $companyId,
                'exception' => $e,
            ]);

            return $this->error('Database error.', 500);
        }

        $this->auditLog->log($request, "Updated client company: {$companyName}");

        return $this->success(null, 'Company and PICs updated.');
    }

    public function refreshStatusFromInvoices(Request $request): JsonResponse
    {
        try {
            $query = DB::table('client_company')
                ->where(function ($statusQuery): void {
                    $statusQuery
                        ->whereNull('client_status')
                        ->orWhereRaw("TRIM(COALESCE(client_status, '')) = ''")
                        ->orWhereRaw("LOWER(TRIM(client_status)) = 'new'");
                })
                ->whereExists(function ($invoiceQuery): void {
                    $invoiceQuery
                        ->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.client_id', 'client_company.company_id')
                        ->where(function ($invoiceStatusQuery): void {
                            $invoiceStatusQuery
                                ->whereNull('invoices.status')
                                ->orWhere(function ($realInvoiceStatusQuery): void {
                                    $realInvoiceStatusQuery
                                        ->whereRaw("LOWER(invoices.status) NOT LIKE '%void%'")
                                        ->whereRaw("LOWER(invoices.status) NOT LIKE '%cancel%'");
                                });
                        });
                });

            if (Schema::hasColumn('client_company', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $update = ['client_status' => 'Old'];
            if (Schema::hasColumn('client_company', 'updated_at')) {
                $update['updated_at'] = now();
            }

            $updatedCount = $query->update($update);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Failed to refresh client statuses.', 500);
        }

        $this->auditLog->log($request, "Refreshed client statuses from invoices ({$updatedCount} updated)");

        return $this->success(
            ['updated_count' => (int) $updatedCount],
            'Client statuses refreshed.',
        );
    }

    public function destroy(Request $request, ?int $companyId = null): JsonResponse
    {
        $companyId = $companyId ?? (int) $request->input('company_id', 0);
        if ($companyId <= 0) {
            return $this->error('Invalid company_id');
        }

        $deletedBy = $request->session()->get('staff_id');

        DB::beginTransaction();
        try {
            $companyDelete = ['status' => 'inactive'];
            if (Schema::hasColumn('client_company', 'deleted_at')) {
                $companyDelete['deleted_at'] = now();
            }
            if (Schema::hasColumn('client_company', 'deleted_by')) {
                $companyDelete['deleted_by'] = $deletedBy;
            }

            $affected = DB::table('client_company')
                ->where('company_id', $companyId)
                ->when(Schema::hasColumn('client_company', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->update($companyDelete);

            if ($affected === 0) {
                DB::rollBack();

                return $this->error('Company not found or already deleted.', 404);
            }

            $branchDelete = ['status' => 'inactive'];
            if (Schema::hasColumn('client_company_branch', 'deleted_at')) {
                $branchDelete['deleted_at'] = now();
            }
            if (Schema::hasColumn('client_company_branch', 'deleted_by')) {
                $branchDelete['deleted_by'] = $deletedBy;
            }

            DB::table('client_company_branch')
                ->where('company_id', $companyId)
                ->when(Schema::hasColumn('client_company_branch', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->update($branchDelete);

            DB::table('client_pic')
                ->where('company_id', $companyId)
                ->when(Schema::hasColumn('client_pic', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->update([
                    'company_id' => null,
                    'status' => 'unassigned',
                ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return $this->error('Failed to delete company.', 500);
        }

        $this->auditLog->log($request, "Soft deleted client company ID: {$companyId}");

        return $this->success(null, 'Company deleted successfully.');
    }
}
