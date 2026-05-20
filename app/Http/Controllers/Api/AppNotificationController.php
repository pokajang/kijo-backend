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
        ]);

        $consumed = $notifications->consumeEntity(
            $request,
            $data['module_key'],
            $data['entity_type'],
            (int) $data['entity_id'],
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
