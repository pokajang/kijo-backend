<?php

namespace App\Services\Leaves;

use App\Http\Requests\Leave\AssignEntitlementRequest;
use App\Http\Requests\Leave\LeaveActionRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\UpdateEntitlementRequest;
use App\Http\Requests\Leave\UpdateWorkflowRecipientsRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    private function leaveRequestService(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    private function leaveEntitlementService(): LeaveEntitlementService
    {
        return app(LeaveEntitlementService::class);
    }

    private function leaveWorkflowRecipientService(): LeaveWorkflowRecipientService
    {
        return app(LeaveWorkflowRecipientService::class);
    }

    public function createLeave(StoreLeaveRequest $request): JsonResponse
    {
        return $this->leaveRequestService()->createLeave($request);
    }

    public function getAllLeavesData(Request $request): JsonResponse
    {
        return $this->leaveRequestService()->getAllLeavesData($request);
    }

    public function getPersonalLeavesRecord(Request $request): JsonResponse
    {
        return $this->leaveRequestService()->getPersonalLeavesRecord($request);
    }

    public function leaveAction(LeaveActionRequest $request): JsonResponse
    {
        return $this->leaveRequestService()->leaveAction($request);
    }

    public function cancelLeave(Request $request): JsonResponse
    {
        return $this->leaveRequestService()->cancelLeave($request);
    }

    public function getAllEntitlements(Request $request): JsonResponse
    {
        return $this->leaveEntitlementService()->getAllEntitlements($request);
    }

    public function getMyEntitlements(Request $request): JsonResponse
    {
        return $this->leaveEntitlementService()->getMyEntitlements($request);
    }

    public function assignLeavesEntitlement(AssignEntitlementRequest $request): JsonResponse
    {
        return $this->leaveEntitlementService()->assignLeavesEntitlement($request);
    }

    public function updateEntitlement(UpdateEntitlementRequest $request): JsonResponse
    {
        return $this->leaveEntitlementService()->updateEntitlement($request);
    }

    public function deleteEntitlement(Request $request, ?int $id = null): JsonResponse
    {
        return $this->leaveEntitlementService()->deleteEntitlement($request, $id);
    }

    public function getWorkflowRecipients(Request $request): JsonResponse
    {
        return $this->leaveWorkflowRecipientService()->index();
    }

    public function updateWorkflowRecipients(UpdateWorkflowRecipientsRequest $request): JsonResponse
    {
        return $this->leaveWorkflowRecipientService()->update($request, $request->validated());
    }

}
