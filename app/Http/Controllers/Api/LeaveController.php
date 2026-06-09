<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\AssignEntitlementRequest;
use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateEntitlementRequest;
use App\Services\Leaves\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    private function leaveService(): LeaveService
    {
        return app(LeaveService::class);
    }


    public function createLeave(StoreLeaveRequest $request): JsonResponse
    {
        return $this->leaveService()->createLeave($request);
    }


    public function getAllLeavesData(Request $request): JsonResponse
    {
        return $this->leaveService()->getAllLeavesData($request);
    }


    public function getPersonalLeavesRecord(Request $request): JsonResponse
    {
        return $this->leaveService()->getPersonalLeavesRecord($request);
    }


    public function leaveAction(LeaveActionRequest $request, ?int $id = null): JsonResponse
    {
        return $this->leaveService()->leaveAction($request, $id);
    }


    public function cancelLeave(Request $request, ?int $id = null): JsonResponse
    {
        return $this->leaveService()->cancelLeave($request, $id);
    }


    public function getAllEntitlements(Request $request): JsonResponse
    {
        return $this->leaveService()->getAllEntitlements($request);
    }


    public function getMyEntitlements(Request $request): JsonResponse
    {
        return $this->leaveService()->getMyEntitlements($request);
    }


    public function getEntitlementHistory(Request $request): JsonResponse
    {
        return $this->leaveService()->getEntitlementHistory($request);
    }

    public function getMyEntitlementHistory(Request $request): JsonResponse
    {
        return $this->leaveService()->getMyEntitlementHistory($request);
    }


    public function assignLeavesEntitlement(AssignEntitlementRequest $request): JsonResponse
    {
        return $this->leaveService()->assignLeavesEntitlement($request);
    }


    public function updateEntitlement(UpdateEntitlementRequest $request, ?int $id = null): JsonResponse
    {
        return $this->leaveService()->updateEntitlement($request, $id);
    }


    public function deleteEntitlement(Request $request, ?int $id = null): JsonResponse
    {
        return $this->leaveService()->deleteEntitlement($request, $id);
    }
}
