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

class ClientPicService extends ClientBaseService
{

    public function listPics(): JsonResponse
    {
        $pics = DB::table('client_pic')
            ->select(['pic_id', 'full_name', 'email', 'mobile_number', 'position', 'status'])
            ->whereNull('deleted_at')
            ->get();

        return $this->success($pics);
    }

    public function listCompanyPics(Request $request, ?int $companyId = null): JsonResponse
    {
        $companyId = $companyId ?? (int) $request->input('company_id', 0);
        if ($companyId <= 0) {
            return $this->error('Invalid company_id');
        }

        $pics = DB::table('client_pic')
            ->select(['pic_id', 'company_id', 'full_name', 'email', 'mobile_number', 'position', 'status'])
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->orderBy('pic_id')
            ->get();

        return $this->success($pics);
    }

    public function updatePic(UpdateClientPicRequest $request, ?int $picId = null): JsonResponse
    {
        $data = $request->validated();
        $picId = (int) ($data['pic_id'] ?? $picId ?? 0);
        if ($picId <= 0) {
            return $this->error('Missing pic_id');
        }

        $companyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
        if ($companyId !== null && $companyId <= 0) {
            $companyId = null;
        }

        $status = $companyId ? 'assigned' : 'unassigned';
        $fullName = trim((string) ($data['full_name'] ?? ''));

        try {
            DB::table('client_pic')
                ->where('pic_id', $picId)
                ->update([
                    'full_name' => $fullName,
                    'email' => trim((string) ($data['email'] ?? '')),
                    'mobile_number' => trim((string) ($data['mobile_number'] ?? '')),
                    'position' => trim((string) ($data['position'] ?? '')),
                    'company_id' => $companyId,
                    'status' => $status,
                ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Database error.', 500);
        }

        $companyLabel = $companyId ?? 'NULL';
        $this->auditLog->log($request, "Updated client person in charge: {$fullName} for client ID: {$companyLabel}");
        return $this->success(null, 'PIC updated successfully.');
    }

    public function unassignPic(UnassignClientPicRequest $request, ?int $companyId = null, ?int $picId = null): JsonResponse
    {
        $data = $request->validated();
        $companyId = (int) ($data['company_id'] ?? $companyId ?? 0);
        $picId = (int) ($data['pic_id'] ?? $picId ?? 0);

        try {
            DB::table('client_pic')
                ->where('company_id', $companyId)
                ->where('pic_id', $picId)
                ->update([
                    'company_id' => null,
                    'status' => 'unassigned',
                ]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Database error.', 500);
        }

        $this->auditLog->log($request, "Unassign client person in charge ID: {$picId} from client ID: {$companyId}");
        return $this->success(null, 'PIC unassigned from company.');
    }

    public function deleteUnassignedPic(DeleteUnassignedClientPicRequest $request, ?int $picId = null): JsonResponse
    {
        $data = $request->validated();
        $picId = (int) ($data['pic_id'] ?? $picId ?? 0);
        if ($picId <= 0) {
            return $this->error('Missing pic_id');
        }

        try {
            DB::table('client_pic')
                ->where('pic_id', $picId)
                ->where('status', 'unassigned')
                ->update(['deleted_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            return $this->error('Failed to delete PIC.', 500);
        }

        $this->auditLog->log($request, "Soft deleted unassigned Client person in charge ID: {$picId}");
        return $this->success(null, 'PIC soft-deleted successfully.');
    }
}
