<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackCommentRequest;
use App\Http\Requests\Feedback\VerifyFeedbackRequest;
use App\Services\AuditLogService;
use App\Services\Feedback\FeedbackWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackWorkflowController extends Controller
{
    public function __construct(
        private FeedbackWorkflowService $workflow,
        private AuditLogService $auditLog,
    ) {}

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            ...$this->workflow->detailPayload($request, $id),
        ]);
    }

    public function comment(StoreFeedbackCommentRequest $request, int $id): JsonResponse
    {
        $payload = $this->workflow->comment($request, $id, $request->validated()['message']);
        $this->auditLog->log($request, "Commented on feedback ticket #{$id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Comment added.',
            ...$payload,
        ]);
    }

    public function verify(VerifyFeedbackRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $payload = $this->workflow->verify(
            $request,
            $id,
            $validated['decision'],
            $validated['message'] ?? null,
        );
        $action = $validated['decision'] === 'confirm' ? 'confirmed' : 'rejected';
        $this->auditLog->log($request, ucfirst($action)." feedback ticket #{$id}");

        return response()->json([
            'status' => 'success',
            'message' => $validated['decision'] === 'confirm'
                ? 'Feedback marked as resolved.'
                : 'Fix rejected and returned to the developer.',
            ...$payload,
        ]);
    }
}
