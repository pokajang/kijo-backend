<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appraisal\StoreFinalAppraisalRequest;
use App\Http\Requests\Appraisal\StoreAppraisalRequest;
use App\Http\Requests\Appraisal\UpdateFinalAppraisalRequest;
use App\Http\Requests\Appraisal\UpdateAppraisalRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Appraisals\AppraisalService;

class AppraisalController extends Controller
{
    private function appraisalService(): AppraisalService
    {
        return app(AppraisalService::class);
    }

    public function store(StoreAppraisalRequest $request)
    {
        return $this->appraisalService()->store($request);
    }

    public function index(Request $request)
    {
        return $this->appraisalService()->index($request);
    }

    public function show(Request $request, int $id)
    {
        return $this->appraisalService()->show($request, $id);
    }


        public function personal(Request $request)
    {
        return $this->appraisalService()->personal($request);
    }

    public function update(UpdateAppraisalRequest $request, int $id)
    {
        return $this->appraisalService()->update($request, $id);
    }

    public function destroy(Request $request, int $id)
    {
        return $this->appraisalService()->destroy($request, $id);
    }


        public function finalIndex(Request $request)
    {
        return $this->appraisalService()->finalIndex($request);
    }


        public function finalStore(StoreFinalAppraisalRequest $request)
    {
        return $this->appraisalService()->finalStore($request);
    }


        public function finalShow(Request $request, int $id)
    {
        return $this->appraisalService()->finalShow($request, $id);
    }


        public function finalUpdate(UpdateFinalAppraisalRequest $request, int $id)
    {
        return $this->appraisalService()->finalUpdate($request, $id);
    }


        public function finalDestroy(Request $request, int $id)
    {
        return $this->appraisalService()->finalDestroy($request, $id);
    }

}
