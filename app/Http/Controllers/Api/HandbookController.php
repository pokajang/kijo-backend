<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use App\Services\Handbook\HandbookService;

class HandbookController extends Controller
{
    private function handbookService(): HandbookService
    {
        return app(HandbookService::class);
    }


        public function current(Request $request)
    {
        return $this->handbookService()->current($request);
    }


        public function saveDraftSection(Request $request)
    {
        return $this->handbookService()->saveDraftSection($request);
    }


        public function publishDraft(Request $request)
    {
        return $this->handbookService()->publishDraft($request);
    }


        public function discardDraft(Request $request)
    {
        return $this->handbookService()->discardDraft($request);
    }


        public function publish(Request $request)
    {
        return $this->handbookService()->publish($request);
    }

        public function versions(Request $request)
    {
        return $this->handbookService()->versions($request);
    }


        public function version(Request $request, int $id)
    {
        return $this->handbookService()->version($request, $id);
    }


        public function reactivateVersion(Request $request, int $id)
    {
        return $this->handbookService()->reactivateVersion($request, $id);
    }


        public function changeLogs(Request $request)
    {
        return $this->handbookService()->changeLogs($request);
    }


    public function sign(Request $request)
    {
        return $this->handbookService()->sign($request);
    }

    public function acknowledgementStatus(Request $request)
    {
        return $this->handbookService()->acknowledgementStatus($request);
    }


    public function signatures(Request $request)
    {
        return $this->handbookService()->signatures($request);
    }

}
