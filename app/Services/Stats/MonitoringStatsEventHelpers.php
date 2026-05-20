<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsEventHelpers
{
    private function monitoringQuoteActivityEvents(
        iterable $quotes,
        string $keyPrefix,
        ?string $segment = null,
        bool $includeValue = false
    ): array
    {
        $events = [];

        foreach ($quotes as $quote) {
            if (empty($quote->created_at)) {
                continue;
            }

            $event = [
                'date' => Carbon::parse($quote->created_at)->format('Y-m-d'),
                'key' => $keyPrefix . ':' . $quote->service_group . ':' . (int) $quote->quote_id,
            ];
            $event['contributor'] = $this->monitoringQuoteContributor($quote, $event['date'], $keyPrefix);

            if ($segment !== null) {
                $event['segment'] = $segment;
            }

            if ($includeValue) {
                $event['value'] = (float) ($quote->value ?? 0);
            }

            $events[] = $event;
        }

        return $events;
    }

    private function monitoringSystemClosedEvents(array $context, array $staffFilter): array
    {
        $query = $this->baseQuoteLifecycleQuery()
            ->whereIn(DB::raw('UPPER(quote_status)'), ['AWARDED', 'WON'])
            ->whereBetween(DB::raw('DATE(award_date)'), [$context['monthStart'], $context['monthEnd']]);

        if (!empty($staffFilter['code'])) {
            $query->whereRaw('UPPER(staff_code) = ?', [$staffFilter['code']]);
        }

        $events = [];

        foreach ($query->get() as $quote) {
            $date = Carbon::parse($quote->award_date)->format('Y-m-d');
            $events[] = [
                'date' => $date,
                'key' => 'closed-quote:' . $quote->service_group . ':' . (int) $quote->quote_id,
                'segment' => 'individual',
                'value' => (float) ($quote->value ?? 0),
                'contributor' => $this->monitoringQuoteContributor($quote, $date, 'closed-quote'),
            ];
        }

        return $events;
    }

    private function monitoringSystemLeadEvents(array $context, array $staffFilter): array
    {
        $events = [];

        if (Schema::hasTable('google_call_records')) {
            $calls = DB::table('google_call_records as gcr')
                ->selectRaw("
                    gcr.id,
                    gcr.called_at,
                    gcr.created_at,
                    gcr.called_by_code,
                    gcr.note,
                    gcr.outcome,
                    gcr.duration_sec,
                    gcr.next_action_at
                ")
                ->whereBetween(DB::raw('DATE(COALESCE(gcr.called_at, gcr.created_at))'), [$context['monthStart'], $context['monthEnd']]);

            if (Schema::hasTable('google_contacts') && Schema::hasColumn('google_call_records', 'contact_id')) {
                $calls
                    ->leftJoin('google_contacts as gc', 'gc.id', '=', 'gcr.contact_id')
                    ->addSelect([
                        'gc.name as contact_name',
                        'gc.phone as contact_phone',
                        'gc.address as contact_address',
                        'gc.note as contact_note',
                    ]);
            } else {
                $calls->selectRaw("
                    NULL AS contact_name,
                    NULL AS contact_phone,
                    NULL AS contact_address,
                    NULL AS contact_note
                ");
            }

            if (!empty($staffFilter['code'])) {
                $calls->whereRaw('UPPER(gcr.called_by_code) = ?', [$staffFilter['code']]);
            }

            foreach ($calls->get() as $call) {
                $date = Carbon::parse($call->called_at ?: $call->created_at)->format('Y-m-d');
                $events[] = [
                    'date' => $date,
                    'key' => 'lead-call:' . (int) $call->id,
                    'contributor' => $this->monitoringCallContributor($call, $date),
                ];
            }
        }

        return $events;
    }

    private function monitoringSystemQualifiedEvents(iterable $quotes): array
    {
        return $this->monitoringQuoteActivityEvents($quotes, 'qualified-quote');
    }

    private function monitoringQuoteNegotiationEvents(array $context, array $staffFilter): array
    {
        if (!$this->monitoringQuoteNegotiationRequestsReady()) {
            return [];
        }

        $query = DB::table('quote_price_exception_requests')
            ->whereIn('service_group', ['training', 'manpower'])
            ->where('request_type', 'quote')
            ->where('quote_id', '>', 0)
            ->whereBetween(DB::raw('DATE(created_at)'), [$context['monthStart'], $context['monthEnd']])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!empty($staffFilter['code'])) {
            $query->whereRaw('UPPER(requested_by_code) = ?', [$staffFilter['code']]);
        }

        $events = [];

        foreach ($query->get() as $row) {
            $date = Carbon::parse($row->created_at)->format('Y-m-d');
            $events[] = [
                'date' => $date,
                'key' => 'negotiation:' . (int) $row->id,
                'segment' => 'individual',
                'contributor' => $this->monitoringQuoteNegotiationContributor($row, $date),
            ];
        }

        return $events;
    }

    private function monitoringQuoteNegotiationRequestsReady(): bool
    {
        if (!Schema::hasTable('quote_price_exception_requests')) {
            return false;
        }

        $requiredColumns = [
            'id',
            'service_group',
            'request_type',
            'quote_id',
            'quote_ref_no',
            'requested_by_name',
            'requested_by_code',
            'requested_discount_amount',
            'approved_discount_amount',
            'client_negotiation_reason',
            'requester_remarks',
            'approval_remarks',
            'status',
            'created_at',
        ];

        foreach ($requiredColumns as $column) {
            if (!Schema::hasColumn('quote_price_exception_requests', $column)) {
                return false;
            }
        }

        return true;
    }

    private function monitoringQuoteContributor($quote, string $date, string $eventType): array
    {
        $remarks = $this->monitoringFirstText(
            $quote->remarks ?? null,
            $quote->inquiry_remarks ?? null,
            $quote->status_remarks ?? null
        );
        $quoteRefNo = $this->monitoringCleanText($quote->quote_ref_no ?? '');
        $serviceGroup = $this->monitoringCleanText($quote->service_group ?? '');
        $quoteId = (int) ($quote->quote_id ?? 0);

        return [
            'sourceType' => 'quote',
            'sourceId' => 'quote:' . $serviceGroup . ':' . $quoteId,
            'eventType' => $eventType,
            'date' => $date,
            'clientName' => $this->monitoringFirstText($quote->client_name ?? null, $quoteRefNo, 'Quote #' . $quoteId),
            'serviceType' => $serviceGroup,
            'subject' => $this->monitoringCleanText($quote->service_title ?? ''),
            'value' => (float) ($quote->value ?? 0),
            'quoteStatus' => $this->monitoringCleanText($quote->quote_status ?? ''),
            'quoteRefNo' => $quoteRefNo,
            'source' => 'Quotation',
            'notes' => $remarks,
            'ownerStaffCode' => $this->monitoringCleanText($quote->staff_code ?? ''),
            'ownerStaffName' => $this->monitoringCleanText($quote->staff_name ?? ''),
            'segment' => 'individual',
        ];
    }

    private function monitoringQuoteNegotiationContributor($row, string $date): array
    {
        $requestedDiscount = is_numeric($row->requested_discount_amount ?? null)
            ? 'Requested discount: RM ' . number_format((float) $row->requested_discount_amount, 2)
            : '';
        $approvedDiscount = is_numeric($row->approved_discount_amount ?? null)
            ? 'Approved discount: RM ' . number_format((float) $row->approved_discount_amount, 2)
            : '';
        $status = $this->monitoringCleanText($row->status ?? '');
        $notes = array_filter([
            $status !== '' ? 'Status: ' . ucfirst($status) : '',
            $requestedDiscount,
            $approvedDiscount,
            $this->monitoringCleanText($row->requester_remarks ?? ''),
            $this->monitoringCleanText($row->approval_remarks ?? ''),
        ]);
        $serviceType = match ((string) ($row->service_group ?? '')) {
            'training' => 'Training',
            'manpower' => 'Manpower',
            default => $this->monitoringCleanText($row->service_group ?? ''),
        };
        $quoteRefNo = $this->monitoringCleanText($row->quote_ref_no ?? '');
        $quoteId = (int) ($row->quote_id ?? 0);

        return [
            'sourceType' => 'negotiation',
            'sourceId' => 'negotiation:' . (int) ($row->id ?? 0),
            'eventType' => 'NEGOTIATION',
            'date' => $date,
            'clientName' => $this->monitoringFirstText($quoteRefNo, 'Negotiation #' . (int) ($row->id ?? 0)),
            'serviceType' => $serviceType,
            'subject' => $this->monitoringCleanText($row->client_negotiation_reason ?? ''),
            'value' => null,
            'quoteStatus' => $status,
            'quoteRefNo' => $quoteRefNo,
            'source' => 'Quote Negotiation',
            'notes' => implode(' | ', $notes),
            'ownerStaffCode' => $this->monitoringCleanText($row->requested_by_code ?? ''),
            'ownerStaffName' => $this->monitoringCleanText($row->requested_by_name ?? ''),
            'segment' => 'individual',
            'quoteId' => $quoteId > 0 ? $quoteId : null,
        ];
    }

    private function monitoringCallContributor($call, string $date): array
    {
        $contactName = $this->monitoringFirstText(
            $call->contact_name ?? null,
            $call->contact_phone ?? null,
            'Call record #' . (int) ($call->id ?? 0)
        );

        return [
            'sourceType' => 'call',
            'sourceId' => 'call:' . (int) ($call->id ?? 0),
            'eventType' => 'lead-call',
            'date' => $date,
            'clientName' => $contactName,
            'serviceType' => '',
            'subject' => $this->monitoringCleanText($call->outcome ?? ''),
            'value' => null,
            'source' => 'Call',
            'notes' => $this->monitoringFirstText($call->note ?? null, $call->contact_note ?? null),
            'ownerStaffCode' => $this->monitoringCleanText($call->called_by_code ?? ''),
            'ownerStaffName' => '',
            'segment' => 'individual',
            'phone' => $this->monitoringCleanText($call->contact_phone ?? ''),
            'address' => $this->monitoringCleanText($call->contact_address ?? ''),
            'outcome' => $this->monitoringCleanText($call->outcome ?? ''),
            'durationSec' => isset($call->duration_sec) ? (int) $call->duration_sec : null,
            'nextActionAt' => !empty($call->next_action_at)
                ? Carbon::parse($call->next_action_at)->format('Y-m-d')
                : null,
        ];
    }

    private function monitoringContributorFromEvent(array $event): ?array
    {
        $contributor = $event['contributor'] ?? null;
        if (!is_array($contributor)) {
            return null;
        }

        if (array_key_exists('value', $event) && is_numeric($event['value'])) {
            $contributor['value'] = (float) $event['value'];
        }

        if (!empty($event['segment'])) {
            $contributor['segment'] = (string) $event['segment'];
        }

        return $contributor;
    }

    private function monitoringAppendStatusContributor(
        array &$row,
        string $weekKey,
        ?string $segment,
        ?array $contributor
    ): void {
        if ($contributor === null) {
            return;
        }

        if (isset($row['details']['weekly'][$weekKey])) {
            $this->monitoringStoreDetailItem($row['details']['weekly'][$weekKey]['qty'], $contributor);
            $this->monitoringStoreDetailItem($row['details']['weekly'][$weekKey]['rm'], $contributor);
        }

        $this->monitoringStoreDetailItem($row['details']['total']['qty'], $contributor);
        $this->monitoringStoreDetailItem($row['details']['total']['rm'], $contributor);

        if ($segment !== null) {
            $segmentKey = $this->monitoringSegmentDetailKey($segment);
            $this->monitoringStoreDetailItem($row['details']['segments'][$segmentKey]['qty'], $contributor);
            $this->monitoringStoreDetailItem($row['details']['segments'][$segmentKey]['rm'], $contributor);
        }
    }

    private function monitoringDistinctEventCount(array $events): int
    {
        $keys = [];
        foreach ($events as $event) {
            $key = (string) ($event['key'] ?? '');
            if ($key === '') {
                $contributor = is_array($event['contributor'] ?? null) ? $event['contributor'] : [];
                $key = $this->monitoringDetailItemKey($contributor, (string) ($event['date'] ?? 'event'));
            }
            $keys[$key] = true;
        }

        return count($keys);
    }

    private function monitoringEventValueTotal(array $events): float
    {
        $valuesByKey = [];
        foreach ($events as $event) {
            $key = (string) ($event['key'] ?? '');
            if ($key === '') {
                $contributor = is_array($event['contributor'] ?? null) ? $event['contributor'] : [];
                $key = $this->monitoringDetailItemKey($contributor, (string) ($event['date'] ?? 'event'));
            }
            $valuesByKey[$key] = isset($event['value']) && is_numeric($event['value'])
                ? (float) $event['value']
                : 0.0;
        }

        return array_sum($valuesByKey);
    }
}
