<?php

namespace App\Services\Handbook;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HandbookService
{
    private function handbookContentService(): HandbookContentService
    {
        return app(HandbookContentService::class);
    }

    private function handbookPublicationService(): HandbookPublicationService
    {
        return app(HandbookPublicationService::class);
    }

    private function handbookSignatureService(): HandbookSignatureService
    {
        return app(HandbookSignatureService::class);
    }

    public function current(Request $request)
    {
        return $this->handbookContentService()->current($request);
    }

    public function saveDraftSection(Request $request)
    {
        return $this->handbookContentService()->saveDraftSection($request);
    }

    public function discardDraft(Request $request)
    {
        return $this->handbookContentService()->discardDraft($request);
    }

    public function changeLogs(Request $request)
    {
        return $this->handbookContentService()->changeLogs($request);
    }

    public function publishDraft(Request $request)
    {
        return $this->handbookPublicationService()->publishDraft($request);
    }

    public function publish(Request $request)
    {
        return $this->handbookPublicationService()->publish($request);
    }

    public function versions(Request $request)
    {
        return $this->handbookPublicationService()->versions($request);
    }

    public function version(Request $request, int $id)
    {
        return $this->handbookPublicationService()->version($request, $id);
    }

    public function reactivateVersion(Request $request, int $id)
    {
        return $this->handbookPublicationService()->reactivateVersion($request, $id);
    }

    public function sign(Request $request)
    {
        return $this->handbookSignatureService()->sign($request);
    }

    public function signatures(Request $request)
    {
        return $this->handbookSignatureService()->signatures($request);
    }

}
