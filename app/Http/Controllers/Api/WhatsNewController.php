<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppFilePaths;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Services\WhatsNew\WhatsNewService;

class WhatsNewController extends Controller
{
    private function whatsNewService(): WhatsNewService
    {
        return app(WhatsNewService::class);
    }


        public function latestUnread(Request $request): JsonResponse
    {
        return $this->whatsNewService()->latestUnread($request);
    }


        public function markRead(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewService()->markRead($request, $id);
    }


        public function markAllRead(Request $request): JsonResponse
    {
        return $this->whatsNewService()->markAllRead($request);
    }


        public function index(Request $request): JsonResponse
    {
        return $this->whatsNewService()->index($request);
    }


        public function show(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewService()->show($request, $id);
    }


        public function store(Request $request): JsonResponse
    {
        return $this->whatsNewService()->store($request);
    }


        public function update(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewService()->update($request, $id);
    }


        public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewService()->destroy($request, $id);
    }


        public function publish(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewService()->publish($request, $id);
    }


        public function unpublish(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewService()->unpublish($request, $id);
    }

}
