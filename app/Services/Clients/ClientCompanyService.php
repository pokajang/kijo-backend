<?php

namespace App\Services\Clients;

use App\Http\Requests\Client\DeleteUnassignedClientPicRequest;
use App\Http\Requests\Client\ListClientsRequest;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UnassignClientPicRequest;
use App\Http\Requests\Client\UpdateClientPicRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientCompanyService extends ClientBaseService
{
    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $companyName = trim((string) ($data['company_name'] ?? ''));
        $clientStatus = (string) ($data['client_status'] ?? 'New');
        if (!in_array($clientStatus, ['Old', 'New'], true)) {
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
        if (!in_array($clientStatus, ['Old', 'New'], true)) {
            $clientStatus = 'New';
        }
        $paymentTermsDays = $this->normalizeNullablePaymentTermsDays($data['payment_terms_days'] ?? null);

        $state = $this->composeState((string) ($data['state'] ?? ''), (string) ($data['country'] ?? ''), (string) ($data['intl_country'] ?? ''));

        DB::beginTransaction();
        try {
            DB::table('client_company')
                ->where('company_id', $companyId)
                ->update([
                    'company_name' => $companyName,
                    'ssm_number' => trim((string) ($data['ssm_number'] ?? '')),
                    'tax_id_no_tin' => trim((string) ($data['tax_id_no_tin'] ?? '')),
                    'client_status' => $clientStatus,
                    'payment_terms_days' => $paymentTermsDays,
                    'address' => trim((string) ($data['address'] ?? '')),
                    'city' => trim((string) ($data['city'] ?? '')),
                    'state' => $state,
                    'zip' => trim((string) ($data['zip'] ?? '')),
                    'updated_at' => now(),
                ]);

            if (is_array($data['pic_list'] ?? null)) {
                $existingPicIds = DB::table('client_pic')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
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

                    if ($picId <= 0 || !in_array($picId, $existingPicIds, true)) {
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
                    ->whereNull('deleted_at')
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
                        DB::table('client_company_branch')
                            ->where('branch_id', $branchId)
                            ->where('company_id', $companyId)
                            ->update($params + [
                                'deleted_at' => null,
                                'deleted_by' => null,
                            ]);

                        $keptExistingIds[] = $branchId;
                    } else {
                        DB::table('client_company_branch')->insert($params + ['created_at' => now()]);
                    }
                }

                $toDelete = array_values(array_diff($existingBranchIds, $keptExistingIds));
                foreach ($toDelete as $branchIdToDelete) {
                    DB::table('client_company_branch')
                        ->where('branch_id', $branchIdToDelete)
                        ->where('company_id', $companyId)
                        ->update([
                            'deleted_at' => now(),
                            'status' => 'inactive',
                        ]);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Database error.', 500);
        }

        $this->auditLog->log($request, "Updated client company: {$companyName}");
        return $this->success(null, 'Company and PICs updated.');
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
            $affected = DB::table('client_company')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'inactive',
                    'deleted_at' => now(),
                    'deleted_by' => $deletedBy,
                ]);

            if ($affected === 0) {
                DB::rollBack();
                return $this->error('Company not found or already deleted.', 404);
            }

            DB::table('client_company_branch')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'inactive',
                    'deleted_at' => now(),
                    'deleted_by' => $deletedBy,
                ]);

            DB::table('client_pic')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->update([
                    'company_id' => null,
                    'status' => 'unassigned',
                ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to delete company.', 500);
        }

        $this->auditLog->log($request, "Soft deleted client company ID: {$companyId}");
        return $this->success(null, 'Company deleted successfully.');
    }
}
