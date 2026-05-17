<?php

namespace App\Services\Staff;

use App\Http\Requests\Staff\GenerateUserActivityReportRequest;
use App\Http\Requests\Staff\GetStaffByIdRequest;
use App\Http\Requests\Staff\ListActivityRequest;
use App\Http\Requests\Staff\ListStaffRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateProfileRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffService
{
    private function staffDirectoryService(): StaffDirectoryService
    {
        return app(StaffDirectoryService::class);
    }

    private function staffAccountService(): StaffAccountService
    {
        return app(StaffAccountService::class);
    }

    private function staffProfileService(): StaffProfileService
    {
        return app(StaffProfileService::class);
    }

    private function staffActivityService(): StaffActivityService
    {
        return app(StaffActivityService::class);
    }

    public function listStaffDetails(ListStaffRequest $request)
    {
        return $this->staffDirectoryService()->listStaffDetails($request);
    }

    public function manageStaff(ListStaffRequest $request)
    {
        return $this->staffDirectoryService()->manageStaff($request);
    }

    public function getStaffById(GetStaffByIdRequest $request)
    {
        return $this->staffDirectoryService()->getStaffById($request);
    }

    public function createStaff(StoreStaffRequest $request)
    {
        return $this->staffAccountService()->createStaff($request);
    }

    public function updateStaff(UpdateStaffRequest $request)
    {
        return $this->staffAccountService()->updateStaff($request);
    }

    public function getProfile(Request $request)
    {
        return $this->staffProfileService()->getProfile($request);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        return $this->staffProfileService()->updateProfile($request);
    }

    public function getSystemUsers(ListStaffRequest $request)
    {
        return $this->staffAccountService()->getSystemUsers($request);
    }

    public function getAllActivities(ListActivityRequest $request)
    {
        return $this->staffActivityService()->getAllActivities($request);
    }

    public function generateUserActivityReport(GenerateUserActivityReportRequest $request)
    {
        return $this->staffActivityService()->generateUserActivityReport($request);
    }

}
