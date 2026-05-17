<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use App\Services\Staff\StaffService;

class StaffController extends Controller
{
    private function staffService(): StaffService
    {
        return app(StaffService::class);
    }


        public function listStaffDetails(ListStaffRequest $request)
    {
        return $this->staffService()->listStaffDetails($request);
    }


        public function manageStaff(ListStaffRequest $request)
    {
        return $this->staffService()->manageStaff($request);
    }


        public function getStaffById(GetStaffByIdRequest $request)
    {
        return $this->staffService()->getStaffById($request);
    }


        public function createStaff(StoreStaffRequest $request)
    {
        return $this->staffService()->createStaff($request);
    }


        public function updateStaff(UpdateStaffRequest $request)
    {
        return $this->staffService()->updateStaff($request);
    }


        public function getProfile(Request $request)
    {
        return $this->staffService()->getProfile($request);
    }


        public function updateProfile(UpdateProfileRequest $request)
    {
        return $this->staffService()->updateProfile($request);
    }


        public function getSystemUsers(ListStaffRequest $request)
    {
        return $this->staffService()->getSystemUsers($request);
    }


        public function getAllActivities(ListActivityRequest $request)
    {
        return $this->staffService()->getAllActivities($request);
    }


        public function generateUserActivityReport(GenerateUserActivityReportRequest $request)
    {
        return $this->staffService()->generateUserActivityReport($request);
    }

}
