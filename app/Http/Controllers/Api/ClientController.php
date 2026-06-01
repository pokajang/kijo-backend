<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\DeleteUnassignedClientPicRequest;
use App\Http\Requests\Client\ListClientsRequest;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UnassignClientPicRequest;
use App\Http\Requests\Client\UpdateClientPicRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Services\Clients\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    private function clientService(): ClientService
    {
        return app(ClientService::class);
    }

    public function listAll(ListClientsRequest $request): JsonResponse
    {
        return $this->clientService()->listAll($request);
    }

    public function listClients(): JsonResponse
    {
        return $this->clientService()->listClients();
    }

    public function listClientOptions(): JsonResponse
    {
        return $this->clientService()->listClientOptions();
    }

    public function show(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientService()->show($request, $companyId);
    }

    public function roiReport(Request $request): JsonResponse
    {
        return $this->clientService()->roiReport($request);
    }

    public function commercialHistory(Request $request, int $companyId): JsonResponse
    {
        return $this->clientService()->commercialHistory($request, $companyId);
    }

    public function refreshStatusFromInvoices(Request $request): JsonResponse
    {
        return $this->clientService()->refreshStatusFromInvoices($request);
    }

    public function vendorRegistrations(Request $request): JsonResponse
    {
        return $this->clientService()->vendorRegistrations($request);
    }

    public function vendorRegistrationAttentionCount(): JsonResponse
    {
        return $this->clientService()->vendorRegistrationAttentionCount();
    }

    public function showVendorRegistration(int $id): JsonResponse
    {
        return $this->clientService()->showVendorRegistration($id);
    }

    public function storeVendorRegistration(Request $request): JsonResponse
    {
        return $this->clientService()->storeVendorRegistration($request);
    }

    public function updateVendorRegistration(Request $request, int $id): JsonResponse
    {
        return $this->clientService()->updateVendorRegistration($request, $id);
    }

    public function deleteVendorRegistration(Request $request, int $id): JsonResponse
    {
        return $this->clientService()->deleteVendorRegistration($request, $id);
    }

    public function vendorRegistrationCertificate(int $id)
    {
        return $this->clientService()->vendorRegistrationCertificate($id);
    }

    public function listPics(): JsonResponse
    {
        return $this->clientService()->listPics();
    }

    public function listCompanyPics(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientService()->listCompanyPics($request, $companyId);
    }

    public function listCompanyBranches(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientService()->listCompanyBranches($request, $companyId);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        return $this->clientService()->store($request);
    }

    public function update(UpdateClientRequest $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientService()->update($request, $companyId);
    }

    public function destroy(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientService()->destroy($request, $companyId);
    }

    public function updatePic(UpdateClientPicRequest $request, ?int $picId = null): JsonResponse
    {
        return $this->clientService()->updatePic($request, $picId);
    }

    public function unassignPic(UnassignClientPicRequest $request, ?int $companyId = null, ?int $picId = null): JsonResponse
    {
        return $this->clientService()->unassignPic($request, $companyId, $picId);
    }

    public function deleteUnassignedPic(DeleteUnassignedClientPicRequest $request, ?int $picId = null): JsonResponse
    {
        return $this->clientService()->deleteUnassignedPic($request, $picId);
    }
}
