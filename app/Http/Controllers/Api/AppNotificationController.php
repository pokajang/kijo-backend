<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppNotificationController extends Controller
{
    public function summary(Request $request, AppNotificationService $notifications): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $notifications->summary($request),
        ]);
    }

    public function consumeEntity(Request $request, AppNotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'module_key' => ['required', 'string', 'max:80'],
            'entity_type' => ['required', 'string', 'max:80'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'route_prefix' => ['nullable', 'string', 'max:255'],
        ]);

        $consumed = $notifications->consumeEntity(
            $request,
            $data['module_key'],
            $data['entity_type'],
            (int) $data['entity_id'],
            $data['route_prefix'] ?? null,
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'consumed_count' => $consumed,
                'consumed' => $consumed,
            ],
        ]);
    }

    public function consumeRouteGroup(Request $request, AppNotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'route_prefix' => ['required', 'string', 'max:255'],
            'module_keys' => ['required', 'array', 'min:1'],
            'module_keys.*' => ['string', 'max:80'],
        ]);

        $consumed = $notifications->consumeRouteGroup(
            $request,
            $data['route_prefix'],
            $data['module_keys'] ?? null,
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'consumed_count' => $consumed,
                'consumed' => $consumed,
            ],
        ]);
    }
}
