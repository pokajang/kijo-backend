<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuoteApprovals\QuoteApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteApprovalController extends Controller
{
    public function index(Request $request, QuoteApprovalService $approvals): JsonResponse
    {
        $items = $approvals->listFor($request);
        $pendingMine = collect($items)->where('status', 'pending')->where('can_decide', true)->count();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'pending_mine' => $pendingMine,
        ]);
    }

    public function show(int $id, Request $request, QuoteApprovalService $approvals): JsonResponse
    {
        $approval = $approvals->show($id, $request);

        return $approval
            ? response()->json(['status' => 'success', 'data' => $approval])
            : response()->json(['status' => 'error', 'message' => 'Approval request not found.'], 404);
    }

    public function approve(int $id, Request $request, QuoteApprovalService $approvals): JsonResponse
    {
        $request->validate(['remarks' => ['nullable', 'string', 'max:4000']]);
        $approval = $approvals->decide($id, $request, 'approve');

        return response()->json(['status' => 'success', 'message' => 'Quotation approved.', 'data' => $approval]);
    }

    public function reject(int $id, Request $request, QuoteApprovalService $approvals): JsonResponse
    {
        $request->validate(['remarks' => ['required', 'string', 'max:4000']]);
        $approval = $approvals->decide($id, $request, 'reject');

        return response()->json(['status' => 'success', 'message' => 'Quotation rejected.', 'data' => $approval]);
    }
}
