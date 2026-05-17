<?php

namespace App\Services\SalesInquiries;

use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SalesInquiryService
{
    private function salesInquiryCrudService(): SalesInquiryCrudService
    {
        return app(SalesInquiryCrudService::class);
    }

    private function salesInquiryProofService(): SalesInquiryProofService
    {
        return app(SalesInquiryProofService::class);
    }

    private function salesInquiryLinkService(): SalesInquiryLinkService
    {
        return app(SalesInquiryLinkService::class);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->salesInquiryCrudService()->index($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryCrudService()->show($request, $id);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->salesInquiryCrudService()->store($request);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryCrudService()->update($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryCrudService()->destroy($request, $id);
    }

    public function proof(Request $request, int $id)
    {
        return $this->salesInquiryProofService()->proof($request, $id);
    }

    public function proofFile(Request $request, int $id, int $proofId)
    {
        return $this->salesInquiryProofService()->proofFile($request, $id, $proofId);
    }

    public function linkClient(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryLinkService()->linkClient($request, $id);
    }

    public function linkQuote(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryLinkService()->linkQuote($request, $id);
    }

    public function assignOwner(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryLinkService()->assignOwner($request, $id);
    }

}
