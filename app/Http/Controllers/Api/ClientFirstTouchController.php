<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ResolveClientFirstTouchConflictRequest;
use App\Http\Requests\Client\RespondClientFirstTouchClarificationRequest;
use App\Http\Requests\Client\StoreClientFirstTouchClaimRequest;
use App\Http\Requests\Client\StoreClientFirstTouchDisputeRequest;
use App\Http\Requests\Client\UpdateClientFirstTouchClaimRequest;
use App\Services\Clients\FirstTouch\ClientFirstTouchMutationService;
use App\Services\Clients\FirstTouch\ClientFirstTouchQueryService;
use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientFirstTouchController extends Controller
{
    public function __construct(
        private ClientFirstTouchQueryService $query,
        private ClientFirstTouchMutationService $mutations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->query->index($request));
    }

    public function show(Request $request, int $companyId): JsonResponse
    {
        $record = $this->query->show($companyId, $request);
        if (! $record) {
            return response()->json(['status' => 'error', 'message' => 'Client company not found.'], 404);
        }

        return $this->success($record);
    }

    public function staffOptions(): JsonResponse
    {
        return $this->success($this->query->staffOptions());
    }

    public function inquiryOptions(int $companyId): JsonResponse
    {
        return $this->success($this->query->inquiryOptions($companyId));
    }

    public function storeClaim(StoreClientFirstTouchClaimRequest $request, int $companyId): JsonResponse
    {
        return $this->success($this->mutations->storeClaim($request, $companyId), 'First-touch evidence submitted.', 201);
    }

    public function updateClaim(UpdateClientFirstTouchClaimRequest $request, int $companyId, int $claimId): JsonResponse
    {
        return $this->success($this->mutations->updateClaim($request, $companyId, $claimId), 'First-touch evidence updated.');
    }

    public function storeDispute(StoreClientFirstTouchDisputeRequest $request, int $companyId): JsonResponse
    {
        return $this->success($this->mutations->storeDispute($request, $companyId), 'First-touch dispute submitted.', 201);
    }

    public function conflicts(Request $request): JsonResponse
    {
        $records = collect($this->query->index($request))
            ->filter(fn (array $record): bool => in_array($record['conflict']['status'] ?? '', ['open', 'clarification_requested'], true))
            ->filter(fn (array $record): bool => (bool) ($record['permissions']['canReviewConflict'] ?? false))
            ->map(fn (array $record): ?array => $this->query->show((int) $record['companyId'], $request))
            ->filter()
            ->values()
            ->all();

        return $this->success($records);
    }

    public function resolve(ResolveClientFirstTouchConflictRequest $request, int $conflictId): JsonResponse
    {
        return $this->success($this->mutations->resolveConflict($request, $conflictId), 'First-touch conflict updated.');
    }

    public function respondToClarification(
        RespondClientFirstTouchClarificationRequest $request,
        int $conflictId,
        int $clarificationId,
    ): JsonResponse {
        return $this->success(
            $this->mutations->respondToClarification($request, $conflictId, $clarificationId),
            'First-touch clarification submitted.',
        );
    }

    public function evidence(int $evidenceId)
    {
        $evidence = DB::table('client_first_touch_evidence')->where('id', $evidenceId)->first();
        if (! $evidence) {
            return response()->json(['status' => 'error', 'message' => 'Evidence image not found.'], 404);
        }

        return AppFilePaths::storedPathResponse($evidence->path, $evidence->original_name);
    }

    private function success(mixed $data, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], fn (mixed $value): bool => $value !== null), $status);
    }
}
