<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Knowledge\KnowledgeAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeAssistantController extends Controller
{
    public function __construct(private KnowledgeAssistantService $assistant) {}

    public function thread(Request $request): JsonResponse
    {
        return $this->assistant->thread($request);
    }

    public function createThread(Request $request): JsonResponse
    {
        return $this->assistant->createThread($request);
    }

    public function ask(Request $request): JsonResponse
    {
        return $this->assistant->ask($request);
    }

    public function feedback(Request $request, int $messageId): JsonResponse
    {
        return $this->assistant->feedback($request, $messageId);
    }

    public function clearThread(Request $request): JsonResponse
    {
        return $this->assistant->clearThread($request);
    }
}
