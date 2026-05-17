<?php

namespace App\Services\WhatsNew;

use App\Support\AppFilePaths;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WhatsNewService
{
    private function whatsNewReadService(): WhatsNewReadService
    {
        return app(WhatsNewReadService::class);
    }

    private function whatsNewMutationService(): WhatsNewMutationService
    {
        return app(WhatsNewMutationService::class);
    }

    public function latestUnread(Request $request): JsonResponse
    {
        return $this->whatsNewReadService()->latestUnread($request);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewReadService()->markRead($request, $id);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return $this->whatsNewReadService()->markAllRead($request);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->whatsNewReadService()->index($request);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewReadService()->show($request, $id);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->whatsNewMutationService()->store($request);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewMutationService()->update($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewMutationService()->destroy($request, $id);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewMutationService()->publish($request, $id);
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        return $this->whatsNewMutationService()->unpublish($request, $id);
    }

}
