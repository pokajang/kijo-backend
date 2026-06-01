<?php

namespace App\Services\Clients;

use App\Http\Requests\Client\DeleteUnassignedClientPicRequest;
use App\Http\Requests\Client\ListClientsRequest;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UnassignClientPicRequest;
use App\Http\Requests\Client\UpdateClientPicRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function clientRoiReportService(): ClientRoiReportService
    {
        return app(ClientRoiReportService::class);
    }

    private function clientCommercialHistoryService(): ClientCommercialHistoryService
    {
        return app(ClientCommercialHistoryService::class);
    }

    private function clientVendorRegistrationService(): ClientVendorRegistrationService
    {
        return app(ClientVendorRegistrationService::class);
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

    public function show(Request $request, ?int $companyId = null): JsonResponse
    {
        return $this->clientCompanyLookupService()->show($request, $companyId);
    }

    public function roiReport(Request $request): JsonResponse
    {
        return $this->clientRoiReportService()->index($request);
    }

    public function commercialHistory(Request $request, int $companyId): JsonResponse
    {
        return $this->clientCommercialHistoryService()->show($request, $companyId);
    }

    public function refreshStatusFromInvoices(Request $request): JsonResponse
    {
        return $this->clientCompanyService()->refreshStatusFromInvoices($request);
    }

    public function vendorRegistrations(Request $request): JsonResponse
    {
        return $this->clientVendorRegistrationService()->index($request);
    }

    public function vendorRegistrationAttentionCount(): JsonResponse
    {
        return $this->clientVendorRegistrationService()->attentionCount();
    }

    public function showVendorRegistration(int $id): JsonResponse
    {
        return $this->clientVendorRegistrationService()->show($id);
    }

    public function storeVendorRegistration(Request $request): JsonResponse
    {
        return $this->clientVendorRegistrationService()->store($request);
    }

    public function updateVendorRegistration(Request $request, int $id): JsonResponse
    {
        return $this->clientVendorRegistrationService()->update($request, $id);
    }

    public function deleteVendorRegistration(Request $request, int $id): JsonResponse
    {
        return $this->clientVendorRegistrationService()->destroy($request, $id);
    }

    public function vendorRegistrationCertificate(int $id)
    {
        return $this->clientVendorRegistrationService()->certificate($id);
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
