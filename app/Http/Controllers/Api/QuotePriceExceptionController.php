<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuotePriceExceptionController extends Controller
{
    private const SERVICES = ['training', 'ih', 'manpower', 'special', 'equipment'];
    private const NEGOTIABLE_SERVICES = ['training', 'manpower'];
    private const SYSTEM_MAIL_ADDRESS = 'kijo@work.amiosh.com';
    private const SYSTEM_MAIL_NAME = 'Kijo Alert';

    private const SERVICE_TABLES = [
        'training' => 'quotes_training',
        'ih' => 'quotes_ih',
        'manpower' => 'quotes_manpower',
        'special' => 'quotes_special',
        'equipment' => 'quotes_equipment',
    ];

    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('quote_price_exception_requests')->orderByDesc('created_at');
        $status = strtolower(trim((string) $request->query('status', '')));
        $service = strtolower(trim((string) $request->query('service', '')));

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($service !== '' && $service !== 'all') {
            $query->where('service_group', $service);
        }

        if (!$this->isApprover($request)) {
            $query->where('requested_by_id', (int) $request->session()->get('staff_id', 0));
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->limit(500)->get(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $query = DB::table('quote_price_exception_requests')->where('id', $id);
        if (!$this->isApprover($request)) {
            $query->where('requested_by_id', (int) $request->session()->get('staff_id', 0));
        }

        $row = $query->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $row]);
    }

    public function pendingCount(Request $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $scope = 'ready_to_apply';
        $count = 0;

        if ($this->isApprover($request)) {
            $count = $this->countActionableRequests('pending');
            $scope = 'pending_approval';
        }

        if ($count < 1) {
            $count = $this->countActionableRequests('approved', $staffId);
            $scope = 'ready_to_apply';
        }

        return response()->json(['status' => 'success', 'count' => $count, 'scope' => $scope]);
    }

    public function createForQuote(Request $request, string $service, int $id): JsonResponse
    {
        $service = strtolower(trim($service));
        if (!in_array($service, self::SERVICES, true)) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported quote service.'], 404);
        }
        if (!in_array($service, self::NEGOTIABLE_SERVICES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Negotiation is only available for locked-rate Training and Manpower quotes.',
            ], 422);
        }

        $data = $request->validate([
            'requested_discount_amount' => ['nullable', 'required_without:requested_final_total', 'numeric', 'min:0.01'],
            'requested_final_total' => ['nullable', 'required_without:requested_discount_amount', 'numeric', 'min:0'],
            'client_negotiation_reason' => ['required', 'string', 'max:3000'],
            'requester_remarks' => ['nullable', 'string', 'max:3000'],
        ]);
        $hasRequestedDiscount = array_key_exists('requested_discount_amount', $data) && $data['requested_discount_amount'] !== null;
        $hasRequestedFinalTotal = array_key_exists('requested_final_total', $data) && $data['requested_final_total'] !== null;
        if ($hasRequestedDiscount && $hasRequestedFinalTotal) {
            return response()->json(['status' => 'error', 'message' => 'Enter either requested discount or requested final total.'], 422);
        }

        $quote = $this->quoteSnapshot($service, $id);
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }
        $eligibilityError = $this->negotiationIneligibilityMessage($request, $service, $id, $quote);
        if ($eligibilityError !== null) {
            return response()->json(['status' => 'error', 'message' => $eligibilityError['message']], $eligibilityError['status']);
        }

        $currentTotalAmount = (float) $quote['current_total_amount'];
        $requestedFinalTotal = $hasRequestedFinalTotal ? (float) $data['requested_final_total'] : null;
        $requestedDiscount = $hasRequestedDiscount
            ? (float) $data['requested_discount_amount']
            : max(0, $currentTotalAmount - (float) $requestedFinalTotal);

        if ($requestedDiscount > $currentTotalAmount) {
            return response()->json(['status' => 'error', 'message' => 'Requested discount cannot exceed the current quote amount.'], 422);
        }
        if ($hasRequestedFinalTotal && $requestedFinalTotal >= $currentTotalAmount) {
            return response()->json(['status' => 'error', 'message' => 'Requested final total must be lower than the current quote amount.'], 422);
        }
        $requestedFinalTotal ??= max(0, $currentTotalAmount - $requestedDiscount);

        $created = $this->createRequest($request, [
            'request_type' => 'quote',
            'service_group' => $service,
            'quote_id' => $id,
            'quote_ref_no' => $quote['quote_ref_no'],
            'revision_no_at_request' => $quote['revision_no'],
            'base_unit_cost' => $quote['base_unit_cost'],
            'current_unit_cost' => $quote['current_unit_cost'],
            'requested_unit_cost' => $quote['current_unit_cost'],
            'requested_discount_amount' => $requestedDiscount,
            'requested_discount_percent' => $quote['gross_amount'] > 0
                ? round(($requestedDiscount / $quote['gross_amount']) * 100, 4)
                : 0,
            'current_total_amount' => $quote['current_total_amount'],
            'requested_final_total' => $requestedFinalTotal,
            'client_negotiation_reason' => $data['client_negotiation_reason'],
            'requester_remarks' => $data['requester_remarks'] ?? null,
            'request_payload' => json_encode(['quote' => $quote]),
        ]);

        $this->notifyApprovers($created);
        $this->auditLog->log($request, "Requested {$service} quote negotiation #{$created->id} for quote #{$id}");

        return response()->json(['status' => 'success', 'data' => $created]);
    }

    public function createPreQuote(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Pre-quote negotiation is disabled. Save the quote first, then request negotiation from quote records.',
        ], 410);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approved_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'approval_remarks' => ['nullable', 'string', 'max:3000'],
        ]);

        DB::beginTransaction();
        try {
            $row = DB::table('quote_price_exception_requests')->where('id', $id)->lockForUpdate()->first();
            if (!$row) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
            }
            if ($row->status !== 'pending') {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Only pending requests can be approved.'], 409);
            }
            if (!in_array((string) $row->service_group, self::NEGOTIABLE_SERVICES, true)) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Only Training and Manpower negotiations can be approved.'], 409);
            }
            if ((string) ($row->request_type ?? '') !== 'quote' || (int) ($row->quote_id ?? 0) <= 0) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Pre-quote negotiations are view-only. Save the quote first, then request negotiation from quote records.'], 409);
            }
            if ((string) ($row->request_type ?? '') === 'quote' && (int) ($row->quote_id ?? 0) > 0) {
                $quote = $this->quoteSnapshot((string) $row->service_group, (int) $row->quote_id);
                $quoteStatus = strtolower(trim((string) ($quote['status'] ?? '')));
                if (!$quote || !in_array($quoteStatus, ['open', 'pending'], true)) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Only Open or Pending quotes can be approved for negotiation.'], 409);
                }
            }

            $currentTotalAmount = (float) ($row->current_total_amount ?? 0);
            $approvedDiscount = (float) ($data['approved_discount_amount'] ?? $row->requested_discount_amount ?? 0);
            if ($approvedDiscount <= 0 || $approvedDiscount > $currentTotalAmount) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Approved discount must be greater than zero and cannot exceed the current quote amount.'], 422);
            }
            $approvedFinalTotal = max(0, $currentTotalAmount - $approvedDiscount);

            DB::table('quote_price_exception_requests')->where('id', $id)->update([
                'status' => 'approved',
                'approved_discount_amount' => $approvedDiscount,
                'approved_discount_percent' => $currentTotalAmount > 0 ? round(($approvedDiscount / $currentTotalAmount) * 100, 4) : null,
                'approved_final_total' => $approvedFinalTotal,
                'approval_remarks' => $data['approval_remarks'] ?? null,
                'approved_by_id' => (int) $request->session()->get('staff_id', 0),
                'approved_by_name' => (string) $request->session()->get('full_name', ''),
                'approved_by_code' => (string) $request->session()->get('name_code', ''),
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            $updated = DB::table('quote_price_exception_requests')->where('id', $id)->first();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->notifyRequester($updated, 'approved');
        $this->auditLog->log($request, "Approved quote price exception #{$id}");

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approval_remarks' => ['required', 'string', 'max:3000'],
        ]);

        $affected = DB::table('quote_price_exception_requests')
            ->whereIn('service_group', self::NEGOTIABLE_SERVICES)
            ->where('request_type', 'quote')
            ->where('quote_id', '>', 0)
            ->where('id', $id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'approval_remarks' => $data['approval_remarks'],
                'approved_by_id' => (int) $request->session()->get('staff_id', 0),
                'approved_by_name' => (string) $request->session()->get('full_name', ''),
                'approved_by_code' => (string) $request->session()->get('name_code', ''),
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected < 1) {
            return response()->json(['status' => 'error', 'message' => 'Only pending quote-based Training and Manpower negotiations can be rejected.'], 409);
        }

        $updated = DB::table('quote_price_exception_requests')->where('id', $id)->first();
        $this->notifyRequester($updated, 'rejected');
        $this->auditLog->log($request, "Rejected quote price exception #{$id}");

        return response()->json(['status' => 'success', 'data' => $updated]);
    }

    private function createRequest(Request $request, array $values): object
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        $id = DB::table('quote_price_exception_requests')->insertGetId([
            ...$values,
            'requested_by_id' => $staffId,
            'requested_by_name' => (string) $request->session()->get('full_name', ''),
            'requested_by_code' => (string) $request->session()->get('name_code', ''),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('quote_price_exception_requests')->where('id', $id)->first();
    }

    private function countActionableRequests(string $status, ?int $requestedById = null): int
    {
        $total = 0;

        foreach (self::NEGOTIABLE_SERVICES as $service) {
            $table = self::SERVICE_TABLES[$service] ?? null;
            if (!$table || !Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table('quote_price_exception_requests as r')
                ->join($table . ' as q', 'q.id', '=', 'r.quote_id')
                ->where('r.request_type', 'quote')
                ->where('r.service_group', $service)
                ->where('r.quote_id', '>', 0)
                ->where('r.status', $status)
                ->whereRaw('LOWER(COALESCE(q.status, "")) in (?, ?)', ['open', 'pending']);

            if ($requestedById !== null) {
                $query->where('r.requested_by_id', $requestedById);
            }

            $total += $query->count();
        }

        return $total;
    }

    private function quoteSnapshot(string $service, int $id): ?array
    {
        $table = self::SERVICE_TABLES[$service] ?? null;
        if (!$table || !Schema::hasTable($table)) {
            return null;
        }

        $quote = DB::table($table)->where('id', $id)->first();
        if (!$quote) {
            return null;
        }

        $discount = (float) ($quote->discount_amount ?? $quote->discount_value ?? $quote->discount ?? 0);
        $subtotal = (float) ($quote->subtotal ?? $quote->sub_total ?? 0);
        $total = (float) ($quote->grand_total ?? $subtotal);
        $unit = (float) ($quote->unit_price ?? $quote->unit_cost ?? 0);

        return [
            'id' => $id,
            'quote_ref_no' => (string) ($quote->quote_ref_no ?? ''),
            'status' => (string) ($quote->status ?? ''),
            'client_name' => (string) ($quote->client_name ?? ''),
            'revision_no' => (int) ($quote->revision_no ?? 0),
            'created_by_id' => (int) ($quote->created_by_id ?? 0),
            'created_by_code' => (string) ($quote->created_by_code ?? ''),
            'price_exception_request_id' => (int) ($quote->price_exception_request_id ?? 0),
            'existing_discount' => $discount,
            'gross_amount' => $subtotal + $discount,
            'current_total_amount' => $total,
            'base_unit_cost' => $unit,
            'current_unit_cost' => $unit,
        ];
    }

    private function negotiationIneligibilityMessage(Request $request, string $service, int $quoteId, array $quote): ?array
    {
        $status = strtolower(trim((string) ($quote['status'] ?? '')));
        if (!in_array($status, ['open', 'pending'], true)) {
            return [
                'status' => 409,
                'message' => 'Only Open or Pending quotes can be negotiated.',
            ];
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $nameCode = strtolower(trim((string) $request->session()->get('name_code', '')));
        $creatorId = (int) ($quote['created_by_id'] ?? 0);
        $creatorCode = strtolower(trim((string) ($quote['created_by_code'] ?? '')));
        $isCreator = ($staffId > 0 && $creatorId > 0 && $staffId === $creatorId)
            || ($nameCode !== '' && $creatorCode !== '' && $nameCode === $creatorCode);

        if (!$isCreator) {
            return [
                'status' => 403,
                'message' => 'Only the quote creator can request negotiation.',
            ];
        }

        if ((int) ($quote['price_exception_request_id'] ?? 0) > 0) {
            return [
                'status' => 409,
                'message' => 'This quote already has an applied negotiation request.',
            ];
        }

        $activeRequestExists = DB::table('quote_price_exception_requests')
            ->where('request_type', 'quote')
            ->where('service_group', $service)
            ->where('quote_id', $quoteId)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($activeRequestExists) {
            return [
                'status' => 409,
                'message' => 'This quote already has an active negotiation request.',
            ];
        }

        return null;
    }

    private function notifyApprovers(object $row): void
    {
        $sent = false;
        foreach ($this->approverRecipients() as $recipient) {
            try {
                SendHtmlMailJob::dispatchSync(
                    $recipient['email'],
                    $recipient['name'],
                    "Quote negotiation request {$row->quote_ref_no}",
                    "<p>A quote negotiation request is pending approval.</p>"
                        . "<p><strong>Service:</strong> " . e((string) $row->service_group) . "</p>"
                        . "<p><strong>Reference:</strong> " . e((string) ($row->quote_ref_no ?: 'Pre-quote')) . "</p>"
                        . "<p><strong>Requested by:</strong> " . e((string) $row->requested_by_name) . "</p>"
                        . "<p><strong>Requested discount:</strong> RM " . number_format((float) $row->requested_discount_amount, 2) . "</p>"
                        . "<p><strong>Reason:</strong><br>" . nl2br(e((string) $row->client_negotiation_reason)) . "</p>",
                    [],
                    self::SYSTEM_MAIL_ADDRESS,
                    self::SYSTEM_MAIL_NAME
                );
                $sent = true;
            } catch (\Throwable) {
                continue;
            }
        }

        if ($sent) {
            DB::table('quote_price_exception_requests')->where('id', $row->id)->update([
                'request_email_sent_at' => now(),
            ]);
        }
    }

    private function notifyRequester(object $row, string $decision): void
    {
        $recipient = $this->requesterRecipient($row);
        if (!$recipient) {
            return;
        }

        try {
            SendHtmlMailJob::dispatchSync(
                $recipient['email'],
                $recipient['name'],
                "Quote negotiation request {$decision}",
                "<p>Your quote negotiation request has been <strong>" . e($decision) . "</strong>.</p>"
                    . "<p><strong>Reference:</strong> " . e((string) ($row->quote_ref_no ?: 'Pre-quote')) . "</p>"
                    . ($decision === 'approved'
                        ? "<p><strong>Approved discount:</strong> RM " . number_format((float) ($row->approved_discount_amount ?? 0), 2) . "</p>"
                            . "<p>This approval has not changed the quotation yet. Open Negotiations, choose Apply, review the quote revision, then save it.</p>"
                        : '')
                    . "<p><strong>Remarks:</strong><br>" . nl2br(e((string) ($row->approval_remarks ?? '-'))) . "</p>",
                [],
                self::SYSTEM_MAIL_ADDRESS,
                self::SYSTEM_MAIL_NAME
            );
        } catch (\Throwable) {
            return;
        }

        DB::table('quote_price_exception_requests')->where('id', $row->id)->update([
            'decision_email_sent_at' => now(),
        ]);
    }

    private function approverRecipients(): array
    {
        return DB::table('system_users as su')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'su.staff_id')
            ->where('su.is_active', 1)
            ->selectRaw('COALESCE(sg.full_name, su.email) as name, su.email, su.role')
            ->get()
            ->filter(fn ($row) => trim((string) $row->email) !== '')
            ->filter(fn ($row) => $this->hasApproverRole($row->role ?? null))
            ->map(fn ($row) => ['name' => (string) $row->name, 'email' => (string) $row->email])
            ->values()
            ->all();
    }

    private function hasApproverRole(mixed $rawRoles): bool
    {
        $decoded = is_string($rawRoles) ? json_decode($rawRoles, true) : null;
        $roles = is_array($decoded) ? $decoded : [$rawRoles];

        foreach ($roles as $role) {
            $normalized = strtolower(trim((string) $role));
            if ($normalized === 'manager' || $normalized === 'system admin') {
                return true;
            }
        }

        return false;
    }

    private function requesterRecipient(object $row): ?array
    {
        $staffId = (int) ($row->requested_by_id ?? 0);
        if ($staffId <= 0) {
            return null;
        }
        $staff = DB::table('staff_general')->where('staff_id', $staffId)->first();
        if (!$staff || trim((string) ($staff->email ?? '')) === '') {
            return null;
        }

        return [
            'name' => (string) ($staff->full_name ?? $row->requested_by_name ?? 'Requester'),
            'email' => (string) $staff->email,
        ];
    }

    private function isApprover(Request $request): bool
    {
        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = [$roles];
        }

        foreach ($roles as $role) {
            $normalized = strtolower(trim((string) $role));
            if ($normalized === 'manager' || $normalized === 'system admin') {
                return true;
            }
        }

        return false;
    }
}
