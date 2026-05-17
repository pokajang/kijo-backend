<?php

namespace App\Services\Appraisals;

use App\Http\Requests\Appraisal\StoreFinalAppraisalRequest;
use App\Http\Requests\Appraisal\StoreAppraisalRequest;
use App\Http\Requests\Appraisal\UpdateFinalAppraisalRequest;
use App\Http\Requests\Appraisal\UpdateAppraisalRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppraisalService
{
    private function appraisalRecordService(): AppraisalRecordService
    {
        return app(AppraisalRecordService::class);
    }

    private function finalAppraisalService(): FinalAppraisalService
    {
        return app(FinalAppraisalService::class);
    }

    public function store(StoreAppraisalRequest $request)
    {
        return $this->appraisalRecordService()->store($request);
    }

    public function index(Request $request)
    {
        return $this->appraisalRecordService()->index($request);
    }

    public function show(Request $request, int $id)
    {
        return $this->appraisalRecordService()->show($request, $id);
    }

    public function personal(Request $request)
    {
        return $this->appraisalRecordService()->personal($request);
    }

    public function update(UpdateAppraisalRequest $request, int $id)
    {
        return $this->appraisalRecordService()->update($request, $id);
    }

    public function destroy(Request $request, int $id)
    {
        return $this->appraisalRecordService()->destroy($request, $id);
    }

    public function finalIndex(Request $request)
    {
        return $this->finalAppraisalService()->finalIndex($request);
    }

    public function finalStore(StoreFinalAppraisalRequest $request)
    {
        return $this->finalAppraisalService()->finalStore($request);
    }

    public function finalShow(Request $request, int $id)
    {
        return $this->finalAppraisalService()->finalShow($request, $id);
    }

    public function finalUpdate(UpdateFinalAppraisalRequest $request, int $id)
    {
        return $this->finalAppraisalService()->finalUpdate($request, $id);
    }

    public function finalDestroy(Request $request, int $id)
    {
        return $this->finalAppraisalService()->finalDestroy($request, $id);
    }

}
