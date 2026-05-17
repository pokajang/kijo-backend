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

class ClientService
{
    private function clientCompanyService(): ClientCompanyService
    {
        return app(ClientCompanyService::class);
    }

    private function clientCompanyLookupService(): ClientCompanyLookupService
    {
        return app(ClientCompanyLookupService::class);
    }

    private function clientPicService(): ClientPicService
    {
        return app(ClientPicService::class);
    }

    public function listAll(ListClientsRequest $request): JsonResponse
    {
        return $this->clientCompanyLookupService()->listAll($request);
    }

    public function listClients(): JsonResponse
    {
        return $this->clientCompanyLookupService()->listClients();
    }

    public function listClientOptions(): JsonResponse
    {
        return $this->clientCompanyLookupService()->listClientOptions();
    }

    public function listCompanyBranches(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientCompanyLookupService()->listCompanyBranches($request, $companyId);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        return $this->clientCompanyService()->store($request);
    }

    public function update(UpdateClientRequest $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientCompanyService()->update($request, $companyId);
    }

    public function destroy(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientCompanyService()->destroy($request, $companyId);
    }

    public function listPics(): JsonResponse
    {
        return $this->clientPicService()->listPics();
    }

    public function listCompanyPics(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientPicService()->listCompanyPics($request, $companyId);
    }

    public function updatePic(UpdateClientPicRequest $request, ?int $picId = null): JsonResponse
    {
        return $this->clientPicService()->updatePic($request, $picId);
    }

    public function unassignPic(UnassignClientPicRequest $request, ?int $companyId = null, ?int $picId = null): JsonResponse
    {
        return $this->clientPicService()->unassignPic($request, $companyId, $picId);
    }

    public function deleteUnassignedPic(DeleteUnassignedClientPicRequest $request, ?int $picId = null): JsonResponse
    {
        return $this->clientPicService()->deleteUnassignedPic($request, $picId);
    }

}
