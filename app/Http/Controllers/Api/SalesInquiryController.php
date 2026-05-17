<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\SalesInquiries\SalesInquiryService;

class SalesInquiryController extends Controller
{
    private function salesInquiryService(): SalesInquiryService
    {
        return app(SalesInquiryService::class);
    }


        public function index(Request $request): JsonResponse
    {
        return $this->salesInquiryService()->index($request);
    }


        public function show(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryService()->show($request, $id);
    }


        public function store(Request $request): JsonResponse
    {
        return $this->salesInquiryService()->store($request);
    }


        public function update(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryService()->update($request, $id);
    }


        public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryService()->destroy($request, $id);
    }


        public function proof(Request $request, int $id)
    {
        return $this->salesInquiryService()->proof($request, $id);
    }


        public function proofFile(Request $request, int $id, int $proofId)
    {
        return $this->salesInquiryService()->proofFile($request, $id, $proofId);
    }


        public function linkClient(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryService()->linkClient($request, $id);
    }


        public function linkQuote(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryService()->linkQuote($request, $id);
    }


        public function assignOwner(Request $request, int $id): JsonResponse
    {
        return $this->salesInquiryService()->assignOwner($request, $id);
    }

}
