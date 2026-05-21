<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Knowledge\KnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function __construct(private KnowledgeService $knowledgeService) {}

    public function index(Request $request): JsonResponse
    {
        return $this->knowledgeService->index($request);
    }

    public function mine(Request $request): JsonResponse
    {
        return $this->knowledgeService->mine($request);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        return $this->knowledgeService->show($request, $slug);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->knowledgeService->store($request);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->knowledgeService->update($request, $id);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        return $this->knowledgeService->setStatus($request, $id, 'published');
    }

    public function unpublish(Request $request, int $id): JsonResponse
    {
        return $this->knowledgeService->setStatus($request, $id, 'draft');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->knowledgeService->setStatus($request, $id, 'archived');
    }
}
