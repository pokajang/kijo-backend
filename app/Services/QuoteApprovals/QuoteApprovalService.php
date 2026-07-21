<?php

namespace App\Services\QuoteApprovals;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class QuoteApprovalService
{
    public const SERVICES = ['training', 'ih', 'manpower', 'equipment', 'special'];

    private const TABLES = [
        'training' => 'quotes_training',
        'ih' => 'quotes_ih',
        'manpower' => 'quotes_manpower',
        'equipment' => 'quotes_equipment',
        'special' => 'quotes_special',
    ];

    public function current(string $service, int $quoteId, bool $notify = true): ?object
    {
        $service = strtolower($service);
        $table = self::TABLES[$service] ?? null;
        if (! $table || ! Schema::hasTable('quote_approval_requests')) {
            return null;
        }
        $quote = DB::table($table)->where('id', $quoteId)->first();
        if (! $quote) {
            return null;
        }

        $evaluation = $this->evaluate($service, $quote);
        $current = DB::table('quote_approval_requests')
            ->where('service', $service)->where('quote_id', $quoteId)->where('is_current', true)
            ->orderByDesc('id')->first();
        if (
            $current
            && hash_equals((string) $current->commercial_fingerprint, $evaluation['fingerprint'])
            && ! ($current->status === 'cancelled' && $this->isOpenQuote($quote))
        ) {
            return $current;
        }

        return DB::transaction(function () use ($service, $quoteId, $table, $notify): object {
            $quote = DB::table($table)->where('id', $quoteId)->lockForUpdate()->first();
            if (! $quote) {
                abort(response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404));
            }
            $evaluation = $this->evaluate($service, $quote);
            $existing = DB::table('quote_approval_requests')
                ->where('service', $service)->where('quote_id', $quoteId)->where('is_current', true)
                ->orderByDesc('id')->lockForUpdate()->first();
            if (
                $existing
                && hash_equals((string) $existing->commercial_fingerprint, $evaluation['fingerprint'])
                && ! ($existing->status === 'cancelled' && $this->isOpenQuote($quote))
            ) {
                return $existing;
            }

            $supersededIds = DB::table('quote_approval_requests')
                ->where('service', $service)->where('quote_id', $quoteId)->where('is_current', true)
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();

            DB::table('quote_approval_requests')
                ->where('service', $service)->where('quote_id', $quoteId)->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);

            $status = $evaluation['zone'] === 'green' ? 'approved' : 'pending';
            $step = match ($evaluation['zone']) {
                'yellow' => 'hod',
                'red' => 'bd',
                default => null,
            };
            $requesterId = $this->requesterId($quote);
            $id = DB::table('quote_approval_requests')->insertGetId([
                'service' => $service,
                'quote_id' => $quoteId,
                'quote_ref_no' => $quote->quote_ref_no ?? null,
                'revision_no' => (int) ($quote->revision_no ?? 0),
                'commercial_fingerprint' => $evaluation['fingerprint'],
                'rule_version' => (string) config('quote_approval.rule_version'),
                'zone' => $evaluation['zone'],
                'status' => $status,
                'required_step' => $step,
                'quoted_total' => $evaluation['total'],
                'estimated_cost' => $evaluation['cost'],
                'margin_percent' => $evaluation['margin'],
                'trigger_reasons' => json_encode($evaluation['reasons']),
                'is_current' => true,
                'requested_by_id' => $requesterId,
                'requested_at' => now(),
                'decided_at' => $status === 'approved' ? now() : null,
                'decision_remarks' => $status === 'approved' ? 'Automatically approved by the traffic-light policy.' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $quoteUpdates = [
                'approval_request_id' => $id,
                'approval_zone' => $evaluation['zone'],
                'approval_status' => $status,
                'approval_fingerprint' => $evaluation['fingerprint'],
            ];
            if ($requesterId && (int) ($quote->created_by_id ?? 0) !== $requesterId) {
                $quoteUpdates['created_by_id'] = $requesterId;
            }
            DB::table($table)->where('id', $quoteId)->update($quoteUpdates);
            $approval = DB::table('quote_approval_requests')->where('id', $id)->first();
            if ($supersededIds !== [] || ($notify && $status === 'pending')) {
                DB::afterCommit(function () use ($approval, $notify, $status, $supersededIds): void {
                    try {
                        $notifications = app(QuoteApprovalNotificationService::class);
                        foreach ($supersededIds as $supersededId) {
                            $notifications->resolve($supersededId);
                        }
                        if ($notify && $status === 'pending') {
                            $notifications->pending($approval);
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $approval;
        });
    }

    public function listFor(Request $request): array
    {
        if (! Schema::hasTable('quote_approval_requests')) {
            return [];
        }

        $this->reconcileInactivePending();

        $rows = DB::table('quote_approval_requests as qar')
            ->leftJoin('staff_general as requester', 'requester.staff_id', '=', 'qar.requested_by_id')
            ->where('qar.is_current', true)
            ->orderByRaw("CASE qar.status WHEN 'pending' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")
            ->orderByDesc('qar.created_at')
            ->get(['qar.*', 'requester.full_name as requested_by_name']);

        return $rows
            ->filter(fn (object $row): bool => $this->canView($row, $request))
            ->map(fn (object $row): array => $this->payload($row, $request))
            ->values()
            ->all();
    }

    public function show(int $id, Request $request): ?array
    {
        if (! Schema::hasTable('quote_approval_requests')) {
            return null;
        }

        $row = DB::table('quote_approval_requests as qar')
            ->leftJoin('staff_general as requester', 'requester.staff_id', '=', 'qar.requested_by_id')
            ->where('qar.id', $id)
            ->first(['qar.*', 'requester.full_name as requested_by_name']);

        return $row && $this->canView($row, $request) ? $this->payload($row, $request) : null;
    }

    public function decide(int $id, Request $request, string $decision): object
    {
        if (! Schema::hasTable('quote_approval_requests')) {
            abort(response()->json(['status' => 'error', 'message' => 'Approval request not found.'], 404));
        }

        $remarks = trim((string) $request->input('remarks', ''));
        if ($decision === 'reject' && $remarks === '') {
            throw ValidationException::withMessages(['remarks' => 'Remarks are required when rejecting a quotation.']);
        }

        $candidate = DB::table('quote_approval_requests')->where('id', $id)->first();
        if (! $candidate || ! isset(self::TABLES[$candidate->service])) {
            abort(response()->json(['status' => 'error', 'message' => 'Approval request not found.'], 404));
        }

        $row = DB::transaction(function () use ($candidate, $id, $request, $decision, $remarks): object {
            $quote = DB::table(self::TABLES[$candidate->service])
                ->where('id', $candidate->quote_id)
                ->lockForUpdate()
                ->first();
            $approval = DB::table('quote_approval_requests')->where('id', $id)->lockForUpdate()->first();
            if (! $approval || ! $approval->is_current) {
                abort(response()->json(['status' => 'error', 'message' => 'Approval request not found.'], 404));
            }
            if (! $quote) {
                abort(response()->json(['status' => 'error', 'message' => 'Quotation no longer exists.'], 410));
            }
            if ($approval->status !== 'pending') {
                abort(response()->json(['status' => 'error', 'message' => 'This approval request has already been decided.'], 409));
            }
            $fingerprint = $this->evaluate((string) $approval->service, $quote)['fingerprint'];
            if (! hash_equals((string) $approval->commercial_fingerprint, $fingerprint)) {
                abort(response()->json([
                    'status' => 'error',
                    'code' => 'QUOTE_APPROVAL_STALE',
                    'message' => 'The quotation changed after this approval request was created. Refresh and review the new request.',
                ], 409));
            }
            if (! app(QuoteApprovalRecipientService::class)->canDecide($request, (string) $approval->required_step)) {
                abort(response()->json(['status' => 'error', 'message' => 'You are not assigned to this approval step.'], 403));
            }

            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $actorId = (int) $request->session()->get('staff_id', 0);
            $actorName = (string) ($request->session()->get('full_name') ?: $request->session()->get('name') ?: 'Approver');
            DB::table('quote_approval_requests')->where('id', $id)->update([
                'status' => $status,
                'decided_by_id' => $actorId ?: null,
                'decided_by_name' => $actorName,
                'decision_remarks' => $remarks ?: null,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table(self::TABLES[$approval->service])->where('id', $approval->quote_id)->update([
                'approval_status' => $status,
            ]);

            return DB::table('quote_approval_requests')->where('id', $id)->first();
        });

        try {
            app(QuoteApprovalNotificationService::class)->decided($row);
        } catch (\Throwable $exception) {
            report($exception);
        }
        try {
            app(AuditLogService::class)->log(
                $request,
                ($decision === 'approve' ? 'Approved' : 'Rejected').' '.strtoupper((string) $row->zone).' quotation approval '
                    .($row->quote_ref_no ?: '#'.$row->quote_id).' (request #'.$row->id.')',
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $row;
    }

    public function cancelCurrent(string $service, int $quoteId, string $reason): void
    {
        if (! Schema::hasTable('quote_approval_requests')) {
            return;
        }
        $service = strtolower($service);
        $table = self::TABLES[$service] ?? null;
        if (! $table) {
            return;
        }

        $cancelledIds = DB::transaction(function () use ($service, $quoteId, $table, $reason): array {
            $quote = DB::table($table)->where('id', $quoteId)->lockForUpdate()->first();
            $requests = DB::table('quote_approval_requests')
                ->where('service', $service)
                ->where('quote_id', $quoteId)
                ->where('is_current', true)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();
            if ($requests->isEmpty()) {
                return [];
            }

            $ids = $requests->pluck('id')->map(fn ($id): int => (int) $id)->all();
            DB::table('quote_approval_requests')->whereIn('id', $ids)->update([
                'status' => 'cancelled',
                'decision_remarks' => $reason,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
            if ($quote) {
                DB::table($table)->where('id', $quoteId)->update(['approval_status' => 'cancelled']);
            }

            return $ids;
        });

        foreach ($cancelledIds as $cancelledId) {
            try {
                app(QuoteApprovalNotificationService::class)->resolve($cancelledId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function issuanceDenial(string $service, int $quoteId, ?Request $request = null): ?array
    {
        $approval = $this->current($service, $quoteId);
        if (! $approval) {
            $table = self::TABLES[strtolower($service)] ?? null;
            if ($table && Schema::hasTable($table) && ! DB::table($table)->where('id', $quoteId)->exists()) {
                return null;
            }

            return ['status' => 'error', 'message' => 'Quotation approval could not be evaluated.'];
        }
        if ($approval->status === 'approved') {
            return null;
        }
        if (
            $request?->boolean('approval_preview')
            && $approval->status === 'pending'
            && app(QuoteApprovalRecipientService::class)->canDecide($request, (string) $approval->required_step)
        ) {
            return null;
        }

        return [
            'status' => 'error',
            'code' => 'QUOTE_APPROVAL_REQUIRED',
            'message' => $approval->status === 'rejected'
                ? 'This quotation was rejected. Revise it before issuing or awarding it.'
                : 'This quotation requires '.strtoupper((string) $approval->required_step).' approval before it can be issued or awarded.',
            'approval' => $this->payload($approval),
        ];
    }

    private function evaluate(string $service, object $quote): array
    {
        $total = isset($quote->grand_total) ? (float) $quote->grand_total : 0.0;
        $cost = isset($quote->estimated_total_cost) && $quote->estimated_total_cost !== null
            ? (float) $quote->estimated_total_cost : null;
        $margin = $cost !== null && $cost > 0 ? (($total - $cost) / $cost) * 100 : null;
        $reasons = [];

        if ($service === 'special') {
            $zone = 'red';
            $reasons[] = 'Special/custom quotation requires BD final approval.';
        } elseif ($cost === null || $cost <= 0) {
            $zone = 'red';
            $reasons[] = 'Estimated total cost is missing; profitability cannot be validated.';
        } else {
            $threshold = config("quote_approval.thresholds.{$service}");
            if ($margin >= (float) $threshold['green']) {
                $zone = 'green';
                $reasons[] = 'Markup meets the automatic approval threshold.';
            } elseif ($margin < (float) $threshold['red']) {
                $zone = 'red';
                $reasons[] = 'Markup is below the red threshold.';
            } else {
                $zone = 'yellow';
                $reasons[] = 'Markup falls in the HOD review range.';
            }
        }

        if ($service === 'manpower' && ! empty($quote->requires_management_approval)) {
            $zone = 'red';
            $reasons[] = 'The selected manpower rate requires management approval.';
        }
        if ($service === 'training') {
            $discountPercent = $this->trainingDiscountPercent($quote);
            if ($discountPercent > 20) {
                $zone = 'red';
                $reasons[] = 'Training discount is higher than the 10%-20% HOD review band.';
            } elseif ($discountPercent >= 10 && $zone === 'green') {
                $zone = 'yellow';
                $reasons[] = 'Training discount is between 10% and 20%.';
            }
        }
        if (
            $service === 'training'
            && (string) ($quote->training_rate_type ?? '') === 'client_site_special_approval'
        ) {
            $zone = 'red';
            $reasons[] = 'Special training or special-client pricing requires BD final approval.';
        }
        if ($service === 'ih' && (float) ($quote->travel_charge ?? 0) > 0 && $zone === 'green') {
            $zone = 'yellow';
            $reasons[] = 'Industrial Hygiene quotation includes travel/outstation charges.';
        }

        $commercial = [
            'service' => $service,
            'quote_id' => (int) $quote->id,
            'revision_no' => (int) ($quote->revision_no ?? 0),
            'grand_total' => round($total, 2),
            'estimated_total_cost' => $cost === null ? null : round($cost, 2),
            'discount_type' => $quote->discount_type ?? null,
            'discount_value' => $quote->discount_value ?? ($quote->discount ?? null),
            'rule_version' => config('quote_approval.rule_version'),
            'line_items' => $this->commercialLineItems($service, (int) $quote->id),
        ];
        foreach ([
            'sub_total', 'subtotal', 'sst_percent', 'sst_rate', 'sst_amount', 'hrd_charge',
            'discount_amount', 'training_total', 'meal_total',
            'unit_price', 'unit_cost', 'travel_charge', 'delivery_charge', 'misc_charge',
            'meal_price', 'mobilization_cost', 'pax', 'no_of_pax', 'quantity',
            'session_count', 'duration_per_session', 'duration_months', 'duration_hours',
            'training_type', 'training_rate_type', 'pricing_basis', 'travel_region',
            'manpower_rate_type', 'requires_management_approval',
            'sample_counts', 'num_work_units', 'service_id',
        ] as $field) {
            if (property_exists($quote, $field)) {
                $commercial[$field] = $quote->{$field};
            }
        }

        return [
            'zone' => $zone,
            'total' => $total,
            'cost' => $cost,
            'margin' => $margin,
            'reasons' => $reasons,
            'fingerprint' => hash('sha256', json_encode($commercial, JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    private function trainingDiscountPercent(object $quote): float
    {
        $discountAmount = max(0, (float) ($quote->discount_amount ?? $quote->discount_value ?? 0));
        $subtotal = max(0, (float) ($quote->subtotal ?? $quote->sub_total ?? 0));
        $preDiscountTotal = $subtotal + $discountAmount;

        return $discountAmount > 0 && $preDiscountTotal > 0
            ? ($discountAmount / $preDiscountTotal) * 100
            : 0.0;
    }

    private function isOpenQuote(object $quote): bool
    {
        return in_array(strtolower(trim((string) ($quote->status ?? ''))), ['open', 'pending'], true);
    }

    private function requesterId(object $quote): ?int
    {
        $creatorId = (int) ($quote->created_by_id ?? 0);
        if ($creatorId > 0 && ! Schema::hasTable('staff_general')) {
            return $creatorId;
        }

        if ($creatorId > 0) {
            $creatorQuery = DB::table('staff_general')->where('staff_id', $creatorId);
            if (Schema::hasColumn('staff_general', 'status')) {
                $creatorQuery->whereRaw('LOWER(COALESCE(status, "active")) = ?', ['active']);
            }
            if (Schema::hasColumn('staff_general', 'deleted_at')) {
                $creatorQuery->whereNull('deleted_at');
            }
            if ($creatorQuery->exists()) {
                return $creatorId;
            }
        }

        $creatorCode = trim((string) ($quote->created_by_code ?? ''));
        if ($creatorCode === '' || ! Schema::hasTable('staff_general')) {
            return null;
        }

        $query = DB::table('staff_general')->whereRaw('LOWER(name_code) = ?', [strtolower($creatorCode)]);
        if (Schema::hasColumn('staff_general', 'status')) {
            $query->whereRaw('LOWER(COALESCE(status, "active")) = ?', ['active']);
        }
        if (Schema::hasColumn('staff_general', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $matches = $query->limit(2)->pluck('staff_id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    private function commercialLineItems(string $service, int $quoteId): array
    {
        $table = match ($service) {
            'equipment' => 'quotes_equipment_items',
            'ih' => 'quotes_ih_items',
            'special' => 'quotes_special_items',
            default => null,
        };
        if (! $table || ! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->where('quote_id', $quoteId)
            ->orderBy('id')
            ->get()
            ->map(function (object $row): array {
                $item = (array) $row;
                unset($item['id'], $item['quote_id'], $item['created_at'], $item['updated_at']);

                return $item;
            })
            ->all();
    }

    private function reconcileInactivePending(): void
    {
        $pending = DB::table('quote_approval_requests')
            ->where('is_current', true)
            ->where('status', 'pending')
            ->get(['service', 'quote_id']);

        foreach ($pending as $approval) {
            $table = self::TABLES[$approval->service] ?? null;
            $quoteStatus = $table && Schema::hasTable($table)
                ? DB::table($table)->where('id', $approval->quote_id)->value('status')
                : null;
            if (! in_array(strtolower(trim((string) $quoteStatus)), ['open', 'pending'], true)) {
                $this->cancelCurrent(
                    (string) $approval->service,
                    (int) $approval->quote_id,
                    $quoteStatus === null ? 'Quotation no longer exists.' : 'Quotation is no longer open.',
                );
            }
        }
    }

    private function payload(object $row, ?Request $request = null): array
    {
        $reasons = is_array($row->trigger_reasons ?? null)
            ? $row->trigger_reasons
            : (json_decode((string) ($row->trigger_reasons ?? '[]'), true) ?: []);
        $canDecide = $request && $row->status === 'pending'
            ? app(QuoteApprovalRecipientService::class)->canDecide($request, (string) $row->required_step)
            : false;

        return [
            'id' => (int) $row->id,
            'request_id' => (int) $row->id,
            'service' => (string) $row->service,
            'quote_id' => (int) $row->quote_id,
            'quote_ref_no' => $row->quote_ref_no,
            'revision_no' => (int) $row->revision_no,
            'zone' => (string) $row->zone,
            'status' => (string) $row->status,
            'required_step' => $row->required_step,
            'quoted_total' => $row->quoted_total === null ? null : (float) $row->quoted_total,
            'estimated_cost' => $row->estimated_cost === null ? null : (float) $row->estimated_cost,
            'margin_percent' => $row->margin_percent === null ? null : (float) $row->margin_percent,
            'trigger_reasons' => $reasons,
            'requested_by_name' => $row->requested_by_name ?? null,
            'decision_remarks' => $row->decision_remarks ?? null,
            'decided_by_name' => $row->decided_by_name ?? null,
            'requested_at' => $row->requested_at ?? null,
            'decided_at' => $row->decided_at ?? null,
            'can_decide' => $canDecide,
            'can_issue' => $row->status === 'approved',
        ];
    }

    private function canView(object $approval, Request $request): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId > 0 && $staffId === (int) ($approval->requested_by_id ?? 0)) {
            return true;
        }

        return app(QuoteApprovalRecipientService::class)->canDecide(
            $request,
            (string) ($approval->required_step ?? ''),
        );
    }
}
