<?php

namespace App\Services\Clients\FirstTouch;

use App\Services\Clients\ClientRoiReportService;
use App\Services\Projects\ProjectValueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientFirstTouchQueryService
{
    public function __construct(private ClientFirstTouchAuthorizationService $authorization) {}

    public function index(?Request $request = null): array
    {
        $clients = DB::table('client_company')
            ->select(['company_id', 'company_name'])
            ->whereNull('deleted_at')
            ->orderBy('company_name')
            ->get();

        $clientIds = $clients->pluck('company_id')->map(fn ($id): int => (int) $id)->all();
        $claims = $this->currentClaims($clientIds);
        $conflicts = $this->openConflicts($clientIds);
        $roi = collect(app(ClientRoiReportService::class)->reportRows(null, null))
            ->keyBy(fn (array $row): int => (int) $row['company_id']);

        return $clients->map(function (object $client) use ($claims, $conflicts, $roi, $request): array {
            $clientId = (int) $client->company_id;
            $claim = $claims[$clientId] ?? null;
            $conflict = $conflicts[$clientId] ?? null;

            return [
                'companyId' => $clientId,
                'companyName' => (string) $client->company_name,
                'firstTouch' => $claim ? $this->claim($claim) : null,
                'claims' => $claim ? [$this->claim($claim)] : [],
                'disputes' => [],
                'conflict' => $conflict ? $this->conflict($conflict) : null,
                'permissions' => $request
                    ? $this->authorization->permissions(
                        $request,
                        $claim ? (int) $claim->submitted_by_staff_id : null,
                        $conflict ? (int) $conflict->id : null,
                        $conflict?->status,
                        $conflict?->clarification_recipient_staff_id ? (int) $conflict->clarification_recipient_staff_id : null,
                    )
                    : [],
                'contribution' => $this->contribution($roi->get($clientId)),
                'projects' => [],
                'timeline' => [],
            ];
        })->all();
    }

    public function show(int $clientId, ?Request $request = null): ?array
    {
        $client = DB::table('client_company')
            ->select(['company_id', 'company_name'])
            ->where('company_id', $clientId)
            ->whereNull('deleted_at')
            ->first();

        if (! $client) {
            return null;
        }

        $claimRows = DB::table('client_first_touch_claims')
            ->where('client_id', $clientId)
            ->orderByDesc('is_current')
            ->orderByDesc('submitted_at')
            ->get();
        $claimIds = $claimRows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $evidence = $this->evidence('claim', $claimIds);
        $revisions = $this->revisions($claimIds);
        $claims = $claimRows->map(fn (object $row): array => $this->claim(
            $row,
            $evidence[(int) $row->id] ?? [],
            $revisions[(int) $row->id] ?? [],
        ))->all();
        $firstTouch = collect($claims)->first(fn (array $claim): bool => (bool) ($claim['isCurrent'] ?? false));

        $disputeRows = DB::table('client_first_touch_disputes')
            ->where('client_id', $clientId)
            ->orderByDesc('submitted_at')
            ->get();
        $disputeEvidence = $this->evidence(
            'dispute',
            $disputeRows->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );
        $disputes = $disputeRows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'claimId' => (int) $row->claim_id,
            'reason' => (string) $row->reason,
            'explanation' => (string) $row->explanation,
            'status' => (string) $row->status,
            'resolution' => $row->resolution,
            'submittedByStaffId' => (int) $row->submitted_by_staff_id,
            'submittedBy' => (string) $row->submitted_by_name,
            'submittedAt' => $this->iso($row->submitted_at),
            'resolvedAt' => $this->iso($row->resolved_at),
            'proofs' => $disputeEvidence[(int) $row->id] ?? [],
        ])->all();

        $clarificationRows = Schema::hasTable('client_first_touch_clarifications')
            ? DB::table('client_first_touch_clarifications')
                ->where('client_id', $clientId)
                ->orderByDesc('created_at')
                ->get()
            : collect();
        $clarificationEvidence = $this->evidence(
            'clarification',
            $clarificationRows->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );
        $clarifications = $clarificationRows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'conflictId' => (int) $row->conflict_id,
            'requestedFromStaffId' => (int) $row->requested_from_staff_id,
            'requestedFrom' => (string) $row->requested_from_name,
            'requestedByStaffId' => (int) $row->requested_by_staff_id,
            'requestedBy' => (string) $row->requested_by_name,
            'requestNote' => (string) $row->request_note,
            'status' => (string) $row->status,
            'response' => (string) ($row->response ?? ''),
            'respondedByStaffId' => $row->responded_by_staff_id ? (int) $row->responded_by_staff_id : null,
            'respondedBy' => (string) ($row->responded_by_name ?? ''),
            'respondedAt' => $this->iso($row->responded_at),
            'createdAt' => $this->iso($row->created_at),
            'proofs' => $clarificationEvidence[(int) $row->id] ?? [],
        ])->all();

        $conflictRow = DB::table('client_first_touch_conflicts')
            ->where('client_id', $clientId)
            ->orderByDesc('id')
            ->first();
        $roi = app(ClientRoiReportService::class)->rowForClient($clientId, null, null);
        $projects = $this->projects($clientId);

        return [
            'companyId' => (int) $client->company_id,
            'companyName' => (string) $client->company_name,
            'firstTouch' => $firstTouch,
            'claims' => $claims,
            'disputes' => $disputes,
            'clarifications' => $clarifications,
            'conflict' => $conflictRow ? $this->conflict($conflictRow, $claims, $disputes) : null,
            'permissions' => $request
                ? $this->authorization->permissions(
                    $request,
                    $firstTouch ? (int) $firstTouch['submittedByStaffId'] : null,
                    $conflictRow ? (int) $conflictRow->id : null,
                    $conflictRow?->status,
                    $conflictRow?->clarification_recipient_staff_id ? (int) $conflictRow->clarification_recipient_staff_id : null,
                )
                : [],
            'contribution' => $this->contribution($roi),
            'projects' => $projects,
            'timeline' => $this->timeline($firstTouch, $projects),
        ];
    }

    public function staffOptions(): array
    {
        $rows = DB::table('staff_general')
            ->select(['staff_id', 'full_name', 'name_code', 'start_date', 'status', 'terminated_at', 'deleted_at'])
            ->orderByRaw("CASE WHEN LOWER(COALESCE(status, '')) = 'active' AND terminated_at IS NULL AND deleted_at IS NULL THEN 0 ELSE 1 END")
            ->orderBy('full_name')
            ->get();

        return $rows->map(function (object $staff): array {
            $status = strtolower(trim((string) ($staff->status ?? '')));
            $endedAt = $staff->terminated_at ?: $staff->deleted_at;
            $departureType = str_contains($status, 'resign') ? 'resigned'
                : (str_contains($status, 'terminat') ? 'terminated' : ($endedAt ? 'former' : null));

            return [
                'id' => (int) $staff->staff_id,
                'fullName' => (string) $staff->full_name,
                'nameCode' => (string) ($staff->name_code ?? ''),
                'startedAt' => $this->date($staff->start_date),
                'endedAt' => $this->date($endedAt),
                'departureType' => $departureType,
                'directoryState' => $endedAt || $status !== 'active' ? 'historical' : 'active',
            ];
        })->all();
    }

    public function inquiryOptions(int $clientId): array
    {
        return DB::table('sales_inquiries')
            ->where('client_id', $clientId)
            ->whereNull('deleted_at')
            ->orderByDesc('inquiry_date')
            ->orderByDesc('id')
            ->limit(250)
            ->get(['id', 'company_name', 'contact_name', 'service_required', 'source', 'inquiry_date', 'status'])
            ->map(fn (object $inquiry): array => [
                'id' => (int) $inquiry->id,
                'reference' => 'INQ-'.str_pad((string) $inquiry->id, 6, '0', STR_PAD_LEFT),
                'companyName' => (string) $inquiry->company_name,
                'contactName' => (string) ($inquiry->contact_name ?? ''),
                'serviceRequired' => (string) ($inquiry->service_required ?? ''),
                'source' => (string) ($inquiry->source ?? ''),
                'inquiryDate' => $this->date($inquiry->inquiry_date),
                'status' => (string) $inquiry->status,
            ])->all();
    }

    private function currentClaims(array $clientIds): array
    {
        if (! $clientIds) {
            return [];
        }

        return DB::table('client_first_touch_claims')
            ->select('client_first_touch_claims.*')
            ->selectSub(function ($query): void {
                $query->from('client_first_touch_evidence')
                    ->selectRaw('COUNT(*)')
                    ->where('owner_type', 'claim')
                    ->whereColumn('owner_id', 'client_first_touch_claims.id');
            }, 'proof_count')
            ->whereIn('client_id', $clientIds)
            ->where('is_current', true)
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->client_id)
            ->all();
    }

    private function openConflicts(array $clientIds): array
    {
        if (! $clientIds) {
            return [];
        }

        return DB::table('client_first_touch_conflicts')
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', ['open', 'clarification_requested'])
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->client_id)
            ->all();
    }

    private function claim(object $row, array $proofs = [], array $revisions = []): array
    {
        return [
            'id' => (int) $row->id,
            'version' => (int) $row->version,
            'status' => (string) $row->status,
            'isCurrent' => (bool) $row->is_current,
            'sourceGroup' => (string) $row->source_group,
            'sourceValue' => (string) $row->source_value,
            'channel' => (string) $row->channel,
            'method' => (string) $row->method,
            'occurredAt' => $this->date($row->occurred_on),
            'occurredTime' => $row->occurred_time ? substr((string) $row->occurred_time, 0, 5) : '',
            'occurrencePrecision' => (string) $row->occurrence_precision,
            'occurrenceTimezone' => (string) $row->occurrence_timezone,
            'chronologyNeedsReview' => (bool) $row->chronology_needs_review,
            'clientContact' => (string) ($row->client_contact ?? ''),
            'contactMode' => (string) $row->contact_mode,
            'amioshContactStaffId' => $row->amiosh_contact_staff_id ? (int) $row->amiosh_contact_staff_id : null,
            'amioshContact' => (string) ($row->amiosh_contact_name ?? ''),
            'amioshContactCode' => (string) ($row->amiosh_contact_code ?? ''),
            'referrerStaffId' => $row->referrer_staff_id ? (int) $row->referrer_staff_id : null,
            'referrerName' => (string) ($row->referrer_name ?? ''),
            'referrerCode' => (string) ($row->referrer_code ?? ''),
            'employmentContext' => $row->employment_context,
            'employmentBoundary' => $row->employment_boundary,
            'employmentEndedAt' => $this->date($row->employment_ended_on),
            'employmentDepartureType' => $row->employment_departure_type,
            'linkedInquiryId' => $row->linked_inquiry_id ? (int) $row->linked_inquiry_id : null,
            'inquiryRef' => (string) ($row->inquiry_ref ?? ''),
            'notes' => (string) ($row->notes ?? ''),
            'submittedByStaffId' => (int) $row->submitted_by_staff_id,
            'submittedBy' => (string) $row->submitted_by_name,
            'submittedAt' => $this->iso($row->submitted_at),
            'updatedBy' => $row->updated_by_name,
            'updatedAt' => $this->iso($row->updated_at),
            'proofCount' => count($proofs) ?: (int) ($row->proof_count ?? 0),
            'proofs' => $proofs,
            'revisions' => $revisions,
        ];
    }

    private function evidence(string $ownerType, array $ownerIds): array
    {
        if (! $ownerIds) {
            return [];
        }

        $grouped = [];
        foreach (DB::table('client_first_touch_evidence')
            ->where('owner_type', $ownerType)
            ->whereIn('owner_id', $ownerIds)
            ->orderBy('id')
            ->get() as $row) {
            $grouped[(int) $row->owner_id][] = [
                'id' => (int) $row->id,
                'platform' => (string) ($row->platform ?? 'Evidence image'),
                'author' => (string) ($row->author ?? 'Evidence attachment'),
                'date' => $this->date($row->evidence_date),
                'originalName' => (string) $row->original_name,
                'fileSize' => (int) $row->size,
                'mimeType' => (string) $row->mime_type,
                'previewUrl' => "client-first-touch/evidence/{$row->id}",
            ];
        }

        return $grouped;
    }

    private function revisions(array $claimIds): array
    {
        if (! $claimIds) {
            return [];
        }

        $grouped = [];
        foreach (DB::table('client_first_touch_revisions')
            ->whereIn('claim_id', $claimIds)
            ->orderBy('revised_at')
            ->get() as $row) {
            $grouped[(int) $row->claim_id][] = [
                'reason' => (string) $row->reason,
                'previous' => json_decode((string) $row->previous_snapshot, true) ?: [],
                'revisedBy' => (string) $row->revised_by_name,
                'revisedAt' => $this->iso($row->revised_at),
            ];
        }

        return $grouped;
    }

    private function conflict(object $row, array $claims = [], array $disputes = []): array
    {
        $competingClaimIds = collect($claims)
            ->filter(fn (array $claim): bool => $claim['status'] === 'competing')
            ->pluck('id')->values()->all();
        $openDisputeIds = collect($disputes)
            ->filter(fn (array $dispute): bool => $dispute['status'] === 'open')
            ->pluck('id')->values()->all();

        return [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'openedAt' => $this->iso($row->created_at),
            'currentClaimId' => $row->current_claim_id ? (int) $row->current_claim_id : null,
            'competingClaimIds' => $competingClaimIds,
            'disputeIds' => $openDisputeIds,
            'resolution' => $row->resolution,
            'comment' => $row->comment,
            'clarificationRecipient' => $row->clarification_recipient,
            'clarificationRecipientStaffId' => isset($row->clarification_recipient_staff_id)
                && $row->clarification_recipient_staff_id
                    ? (int) $row->clarification_recipient_staff_id
                    : null,
            'reviewedBy' => $row->reviewed_by_name,
            'reviewedAt' => $this->iso($row->reviewed_at),
            'resolvedBy' => $row->resolved_by_name,
            'resolvedAt' => $this->iso($row->resolved_at),
        ];
    }

    private function contribution(?array $roi): array
    {
        return [
            'awarded' => round((float) ($roi['awarded_value'] ?? 0), 2),
            'invoiced' => round((float) ($roi['invoiced_total'] ?? 0), 2),
            'collected' => round((float) ($roi['received_total'] ?? 0), 2),
            'grossProfit' => round((float) ($roi['actual_profit'] ?? 0), 2),
            'asOf' => now()->toDateString(),
        ];
    }

    private function projects(int $clientId): array
    {
        if (! Schema::hasTable('projects_main')) {
            return [];
        }

        $valueExpression = app(ProjectValueService::class)->resolvedProjectValueExpression('p');
        $invoiceTotals = DB::table('invoices')
            ->selectRaw("project_id, COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'canceled', 'void') THEN grand_total ELSE 0 END), 0) AS invoiced, COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'paid' THEN paid_amount ELSE 0 END), 0) AS collected")
            ->whereNotNull('project_id')
            ->groupBy('project_id');
        $vendorCosts = DB::table('project_vendors')
            ->selectRaw('project_id, COALESCE(SUM(award_value), 0) AS vendor_cost')
            ->groupBy('project_id');
        $expenseCosts = DB::table('project_expenses')
            ->selectRaw('project_id, COALESCE(SUM(amount), 0) AS expense_cost')
            ->groupBy('project_id');
        $owners = DB::table('project_collaborators as pc')
            ->join('staff_general as sg', 'sg.staff_id', '=', 'pc.staff_id')
            ->selectRaw("pc.project_id, GROUP_CONCAT(sg.full_name ORDER BY CASE WHEN LOWER(pc.project_role) IN ('owner', 'leader') THEN 0 ELSE 1 END, sg.full_name SEPARATOR ', ') AS sales_owner, GROUP_CONCAT(sg.name_code ORDER BY CASE WHEN LOWER(pc.project_role) IN ('owner', 'leader') THEN 0 ELSE 1 END, sg.full_name SEPARATOR ', ') AS sales_owner_code")
            ->whereIn(DB::raw('LOWER(pc.project_role)'), ['owner', 'leader', 'pic'])
            ->groupBy('pc.project_id');

        return DB::table('projects_main as p')
            ->leftJoinSub($invoiceTotals, 'i', fn ($join) => $join->on('i.project_id', '=', 'p.id'))
            ->leftJoinSub($vendorCosts, 'v', fn ($join) => $join->on('v.project_id', '=', 'p.id'))
            ->leftJoinSub($expenseCosts, 'e', fn ($join) => $join->on('e.project_id', '=', 'p.id'))
            ->leftJoinSub($owners, 'o', fn ($join) => $join->on('o.project_id', '=', 'p.id'))
            ->where('p.client_id', $clientId)
            ->whereRaw("LOWER(TRIM(COALESCE(p.status, ''))) <> 'terminated'")
            ->selectRaw("p.id, p.project_name, p.status, p.award_date, {$valueExpression} AS awarded, COALESCE(i.invoiced, 0) AS invoiced, COALESCE(i.collected, 0) AS collected, COALESCE(v.vendor_cost, 0) + COALESCE(e.expense_cost, 0) AS total_cost, o.sales_owner, o.sales_owner_code")
            ->orderByDesc('p.award_date')
            ->orderByDesc('p.id')
            ->get()
            ->map(fn (object $project): array => [
                'id' => (int) $project->id,
                'name' => (string) $project->project_name,
                'awarded' => round((float) $project->awarded, 2),
                'invoiced' => round((float) $project->invoiced, 2),
                'collected' => round((float) $project->collected, 2),
                'grossProfit' => round((float) $project->collected - (float) $project->total_cost, 2),
                'salesOwner' => (string) ($project->sales_owner ?? ''),
                'salesOwnerCode' => (string) ($project->sales_owner_code ?? ''),
                'status' => strtolower((string) $project->status),
                'awardDate' => $this->date($project->award_date),
            ])->all();
    }

    private function timeline(?array $firstTouch, array $projects): array
    {
        $entries = [];
        if ($firstTouch) {
            $entries[] = [
                'id' => 'first-touch-'.$firstTouch['id'],
                'date' => $firstTouch['occurredAt'],
                'title' => 'First documented encounter',
                'description' => trim($firstTouch['clientContact'].' encountered Amiosh via '.$firstTouch['sourceValue'], ' .'),
                'type' => 'origin',
            ];
        }
        foreach ($projects as $project) {
            if (! $project['awardDate']) {
                continue;
            }
            $entries[] = [
                'id' => 'project-'.$project['id'],
                'date' => $project['awardDate'],
                'title' => 'Project awarded',
                'description' => $project['name'].($project['salesOwner'] ? ' — sales credit: '.$project['salesOwner'] : ''),
                'type' => 'award',
            ];
        }

        usort($entries, fn (array $left, array $right): int => strcmp((string) $left['date'], (string) $right['date']));

        return $entries;
    }

    private function date(mixed $value): ?string
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    private function iso(mixed $value): ?string
    {
        return $value ? date(DATE_ATOM, strtotime((string) $value)) : null;
    }
}
