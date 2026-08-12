<?php

namespace App\Services\Clients;

use App\Services\Quotes\Records\QuoteRecordConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientInteractionTimelineService
{
    private const QUOTE_SERVICES = ['training', 'ih', 'manpower', 'special', 'equipment'];

    public function __construct(private QuoteRecordConfig $quoteConfig) {}

    public function forClient(int $clientId, ?array $firstTouch = null): array
    {
        $quotes = $this->quotes($clientId);
        $projects = $this->projects($clientId, $quotes);
        $entries = [];
        $firstTouchEntry = $this->firstTouchEntry($firstTouch);
        if ($firstTouchEntry !== []) {
            $entries[] = $firstTouchEntry;
        }

        foreach ($quotes as $quote) {
            $entries[] = $this->entry(
                "quote-{$quote['service']}-{$quote['id']}",
                $quote['createdAt'],
                'quotation_created',
                'Quotation created',
                $quote['label'],
                $quote['staff'],
                ['type' => 'quotation', 'id' => $quote['id'], 'reference' => $quote['reference']],
            );

            foreach ($quote['followUps'] as $followUp) {
                $entries[] = $this->entry(
                    "follow-up-{$followUp['id']}",
                    $followUp['date'],
                    'follow_up_recorded',
                    'Follow-up recorded',
                    $quote['label'],
                    $followUp['staff'],
                    ['type' => 'quotation', 'id' => $quote['id'], 'reference' => $quote['reference']],
                    $followUp['remarks'],
                );
            }
        }

        foreach ($projects as $project) {
            if ($project['awardDate']) {
                $entries[] = $this->entry(
                    "project-{$project['id']}",
                    $project['awardDate'],
                    'project_awarded',
                    'Project awarded',
                    $project['name'],
                    $project['staff'],
                    ['type' => 'project', 'id' => $project['id'], 'reference' => $project['name']],
                );
            }
        }

        foreach ($this->invoiceEntries($clientId, $projects) as $entry) {
            $entries[] = $entry;
        }

        usort($entries, static fn (array $left, array $right): int => [
            $left['date'] ?? '',
            $left['time'] ?? '',
            $left['id'],
        ] <=> [
            $right['date'] ?? '',
            $right['time'] ?? '',
            $right['id'],
        ]);

        return array_values(array_filter($entries, static fn (array $entry): bool => ! empty($entry['date'])));
    }

    /**
     * Return the earliest quotation recorded for each client. This is intentionally
     * separate from a documented first-touch claim, so callers can present a useful
     * fallback without treating a quotation as submitted evidence.
     */
    public function earliestQuotesForClients(array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_filter(
            array_map(static fn ($clientId): int => (int) $clientId, $clientIds),
        )));
        if ($clientIds === []) {
            return [];
        }

        $earliestByClient = [];
        foreach (self::QUOTE_SERVICES as $service) {
            $config = $this->quoteConfig->quoteConfig($service) ?: [];
            $table = $config['table'] ?? '';
            if (! $table || ! $this->hasColumns($table, ['id', 'client_id', 'created_at'])) {
                continue;
            }

            $columns = ['id', 'client_id', 'created_at'];
            if ($this->quoteConfig->hasColumn($table, 'quote_ref_no')) {
                $columns[] = 'quote_ref_no';
            }

            foreach (DB::table($table)
                ->whereIn('client_id', $clientIds)
                ->whereNotNull('created_at')
                ->get($columns) as $row) {
                $clientId = (int) $row->client_id;
                $candidate = [
                    'date' => $this->date($row->created_at),
                    'createdAt' => (string) $row->created_at,
                    'quoteId' => (int) $row->id,
                    'quoteReference' => trim((string) ($row->quote_ref_no ?? '')) ?: "Quote #{$row->id}",
                    'quoteService' => $service,
                ];

                if (! $candidate['date']) {
                    continue;
                }

                $current = $earliestByClient[$clientId] ?? null;
                if (! $current || [
                    $candidate['createdAt'],
                    $candidate['quoteService'],
                    $candidate['quoteId'],
                ] < [
                    $current['createdAt'],
                    $current['quoteService'],
                    $current['quoteId'],
                ]) {
                    $earliestByClient[$clientId] = $candidate;
                }
            }
        }

        return $earliestByClient;
    }

    private function firstTouchEntry(?array $firstTouch): array
    {
        if (! $firstTouch || empty($firstTouch['occurredAt'])) {
            return [];
        }

        return $this->entry(
            'first-touch-'.$firstTouch['id'],
            $firstTouch['occurredAt'],
            'first_touch',
            'First documented encounter',
            implode(' · ', array_filter([$firstTouch['sourceValue'] ?? '', $firstTouch['clientContact'] ?? ''])),
            $this->staff(
                $firstTouch['amioshContact'] ?: ($firstTouch['referrerName'] ?? ''),
                $firstTouch['amioshContactCode'] ?: ($firstTouch['referrerCode'] ?? ''),
                ! empty($firstTouch['amioshContact']) ? 'Handled by' : 'Referred through',
            ),
            ['type' => 'first_touch', 'id' => $firstTouch['id'], 'reference' => 'First touch'],
            null,
            $firstTouch['occurredTime'] ?? '',
        );
    }

    private function quotes(int $clientId): array
    {
        $quotes = [];
        $staffIds = [];

        foreach (self::QUOTE_SERVICES as $service) {
            $config = $this->quoteConfig->quoteConfig($service);
            $table = $config['table'] ?? '';
            if (! $table || ! $this->hasColumns($table, ['id', 'client_id', 'created_at'])) {
                continue;
            }

            $columns = ['id', 'created_at'];
            foreach (['quote_ref_no', 'created_by_id', 'created_by_name', 'created_by_code', 'title', 'subject', 'service_required'] as $column) {
                if ($this->quoteConfig->hasColumn($table, $column)) {
                    $columns[] = $column;
                }
            }

            foreach (DB::table($table)->where('client_id', $clientId)->get($columns) as $row) {
                $creatorId = (int) ($row->created_by_id ?? 0);
                if ($creatorId > 0) {
                    $staffIds[] = $creatorId;
                }
                $quotes[$service.'|'.(int) $row->id] = [
                    'id' => (int) $row->id,
                    'service' => $service,
                    'createdAt' => $this->date($row->created_at),
                    'reference' => trim((string) ($row->quote_ref_no ?? '')) ?: "Quote #{$row->id}",
                    'title' => $this->firstText($row, ['title', 'subject', 'service_required']),
                    'creatorId' => $creatorId,
                    'creatorName' => (string) ($row->created_by_name ?? ''),
                    'creatorCode' => (string) ($row->created_by_code ?? ''),
                    'followUps' => [],
                    'staff' => [],
                ];
            }
        }

        $staffById = $this->staffDirectory($staffIds);
        foreach ($quotes as &$quote) {
            $staff = $staffById[$quote['creatorId']] ?? null;
            $quote['staff'] = $this->staff(
                $staff['name'] ?? $quote['creatorName'],
                $staff['code'] ?? $quote['creatorCode'],
                'Created by',
            );
            $quote['label'] = implode(' · ', array_filter([$quote['reference'], $quote['title']]));
        }
        unset($quote);

        if (! $quotes || ! Schema::hasTable('quote_followups')) {
            return $quotes;
        }

        $followUps = collect();
        foreach (self::QUOTE_SERVICES as $service) {
            $quoteIds = array_values(array_map(
                static fn (array $quote): int => $quote['id'],
                array_filter($quotes, static fn (array $quote): bool => $quote['service'] === $service),
            ));
            if ($quoteIds === []) {
                continue;
            }

            $followUps = $followUps->concat(
                DB::table('quote_followups')
                    ->where('quote_type', $service)
                    ->whereIn('quote_id', $quoteIds)
                    ->orderBy('follow_up_date')
                    ->orderBy('id')
                    ->get(['id', 'quote_id', 'quote_type', 'remarks', 'follow_up_date', 'created_by']),
            );
        }
        $followUpStaff = $this->staffDirectory($followUps->pluck('created_by')->map(fn ($id): int => (int) $id)->all());
        foreach ($followUps as $followUp) {
            $key = $this->quoteConfig->normalizeServiceKey((string) $followUp->quote_type).'|'.(int) $followUp->quote_id;
            if (! isset($quotes[$key])) {
                continue;
            }
            $staff = $followUpStaff[(int) $followUp->created_by] ?? null;
            $quotes[$key]['followUps'][] = [
                'id' => (int) $followUp->id,
                'date' => $this->date($followUp->follow_up_date),
                'remarks' => trim((string) $followUp->remarks),
                'staff' => $this->staff($staff['name'] ?? '', $staff['code'] ?? '', 'Recorded by'),
            ];
        }

        return $quotes;
    }

    private function projects(int $clientId, array $quotes): array
    {
        if (! $this->hasColumns('projects_main', ['id', 'client_id'])) {
            return [];
        }

        $columns = ['id', 'project_name', 'award_date', 'quote_id'];
        foreach (['quote_type', 'project_type', 'created_by'] as $column) {
            if ($this->quoteConfig->hasColumn('projects_main', $column)) {
                $columns[] = $column;
            }
        }
        $rows = DB::table('projects_main')
            ->where('client_id', $clientId)
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) <> 'terminated'")
            ->get($columns);
        $staffByProject = $this->projectStaff($rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $creatorDirectory = $this->staffDirectory($rows->pluck('created_by')->map(fn ($id): int => (int) $id)->all());

        return $rows->map(function (object $row) use ($quotes, $staffByProject, $creatorDirectory): array {
            $service = $this->quoteConfig->normalizeServiceKey((string) ($row->quote_type ?? ''));
            if (! in_array($service, self::QUOTE_SERVICES, true)) {
                $service = $this->serviceForProjectType((string) ($row->project_type ?? ''));
            }
            $quote = $quotes[$service.'|'.(int) ($row->quote_id ?? 0)] ?? null;
            $staff = $staffByProject[(int) $row->id] ?? [];
            if (! $staff && ! empty($row->created_by)) {
                $creator = $creatorDirectory[(int) $row->created_by] ?? null;
                $staff = $this->staff($creator['name'] ?? '', $creator['code'] ?? '', 'Created by');
            }
            if (! $staff && $quote) {
                $staff = $quote['staff'];
            }

            return [
                'id' => (int) $row->id,
                'name' => trim((string) ($row->project_name ?? '')) ?: "Project #{$row->id}",
                'awardDate' => $this->date($row->award_date ?? null),
                'staff' => $staff,
            ];
        })->all();
    }

    private function invoiceEntries(int $clientId, array $projects): array
    {
        if (! $this->hasColumns('invoices', ['id', 'client_id', 'invoice_date'])) {
            return [];
        }

        $columns = ['id', 'project_id', 'invoice_date', 'grand_total'];
        foreach (['invoice_ref_no', 'created_by', 'paid_amount', 'paid_date'] as $column) {
            if ($this->quoteConfig->hasColumn('invoices', $column)) {
                $columns[] = $column;
            }
        }
        $invoices = DB::table('invoices')
            ->where('client_id', $clientId)
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) NOT IN ('cancelled', 'canceled', 'void')")
            ->get($columns);
        $projectById = collect($projects)->keyBy('id')->all();
        $staffDirectory = $this->staffDirectory($invoices->pluck('created_by')->map(fn ($id): int => (int) $id)->all());
        $ledgerByInvoice = $this->paymentsByInvoice($invoices->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $entries = [];

        foreach ($invoices as $invoice) {
            $project = $projectById[(int) ($invoice->project_id ?? 0)] ?? null;
            $creator = $staffDirectory[(int) ($invoice->created_by ?? 0)] ?? null;
            $staff = $this->staff($creator['name'] ?? '', $creator['code'] ?? '', 'Created by') ?: ($project['staff'] ?? []);
            $reference = trim((string) ($invoice->invoice_ref_no ?? '')) ?: "Invoice #{$invoice->id}";
            $context = implode(' · ', array_filter([$project['name'] ?? '', $reference, $this->money($invoice->grand_total ?? 0)]));
            $entries[] = $this->entry("invoice-{$invoice->id}", $this->date($invoice->invoice_date), 'invoice_issued', 'Invoice issued', $context, $staff, ['type' => 'invoice', 'id' => (int) $invoice->id, 'reference' => $reference]);

            foreach ($ledgerByInvoice[(int) $invoice->id] ?? [] as $payment) {
                $entries[] = $this->entry("payment-{$payment['id']}", $payment['date'], 'payment_received', 'Payment received', implode(' · ', array_filter([$reference, $this->money($payment['amount'])])), ($project['staff'] ?? []) ?: $staff, ['type' => 'invoice', 'id' => (int) $invoice->id, 'reference' => $reference]);
            }
            if (! isset($ledgerByInvoice[(int) $invoice->id]) && (float) ($invoice->paid_amount ?? 0) > 0 && ! empty($invoice->paid_date)) {
                $entries[] = $this->entry("legacy-payment-{$invoice->id}", $this->date($invoice->paid_date), 'payment_received', 'Payment received', implode(' · ', array_filter([$reference, $this->money($invoice->paid_amount)])), ($project['staff'] ?? []) ?: $staff, ['type' => 'invoice', 'id' => (int) $invoice->id, 'reference' => $reference]);
            }
        }

        return $entries;
    }

    private function paymentsByInvoice(array $invoiceIds): array
    {
        if (! $invoiceIds || ! Schema::hasTable('receivable_payments')) {
            return [];
        }

        return DB::table('receivable_payments')
            ->where('source_type', 'invoice')
            ->whereIn('source_id', $invoiceIds)
            ->whereNull('reversed_at')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get(['id', 'source_id', 'amount', 'payment_date'])
            ->groupBy('source_id')
            ->map(fn ($payments): array => $payments->map(fn (object $payment): array => [
                'id' => (int) $payment->id,
                'amount' => (float) $payment->amount,
                'date' => $this->date($payment->payment_date),
            ])->all())
            ->all();
    }

    private function projectStaff(array $projectIds): array
    {
        if (! $projectIds || ! $this->hasColumns('project_collaborators', ['project_id', 'staff_id', 'project_role'])) {
            return [];
        }

        return DB::table('project_collaborators as pc')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'pc.staff_id')
            ->whereIn('pc.project_id', $projectIds)
            ->orderByRaw("CASE LOWER(TRIM(COALESCE(pc.project_role, ''))) WHEN 'leader' THEN 0 WHEN 'owner' THEN 1 WHEN 'pic' THEN 2 WHEN 'assistant' THEN 3 WHEN 'collaborator' THEN 4 ELSE 5 END")
            ->orderBy('sg.full_name')
            ->get(['pc.project_id', 'pc.project_role', 'sg.full_name', 'sg.name_code'])
            ->groupBy('project_id')
            ->map(fn ($staff): array => $staff->map(fn (object $member): array => [
                'name' => (string) ($member->full_name ?? ''),
                'code' => (string) ($member->name_code ?? ''),
                'role' => trim((string) ($member->project_role ?? '')),
            ])->filter(fn (array $member): bool => $member['name'] !== '' || $member['code'] !== '')->values()->all())
            ->all();
    }

    private function staffDirectory(array $staffIds): array
    {
        $staffIds = array_values(array_unique(array_filter(array_map('intval', $staffIds))));
        if (! $staffIds || ! Schema::hasTable('staff_general')) {
            return [];
        }

        return DB::table('staff_general')->whereIn('staff_id', $staffIds)->get(['staff_id', 'full_name', 'name_code'])
            ->mapWithKeys(fn (object $staff): array => [(int) $staff->staff_id => ['name' => (string) $staff->full_name, 'code' => (string) ($staff->name_code ?? '')]])->all();
    }

    private function entry(string $id, ?string $date, string $kind, string $title, string $context, array $staff, array $related, ?string $remarks = null, string $time = ''): array
    {
        return ['id' => $id, 'date' => $date, 'time' => $time, 'kind' => $kind, 'type' => $kind, 'title' => $title, 'context' => $context, 'staff' => $staff, 'related' => $related, 'remarks' => $remarks];
    }

    private function staff(string $name, string $code, string $role): array
    {
        return trim($name) !== '' || trim($code) !== '' ? [['name' => trim($name), 'code' => trim($code), 'role' => $role]] : [];
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function serviceForProjectType(string $projectType): string
    {
        $type = strtolower($projectType);
        foreach (self::QUOTE_SERVICES as $service) {
            $like = $this->quoteConfig->projectTypeLike($service);
            foreach ((array) $like as $needle) {
                if (str_contains($type, trim($needle, '%'))) {
                    return $service;
                }
            }
        }

        return '';
    }

    private function firstText(object $row, array $columns): string
    {
        foreach ($columns as $column) {
            if (! empty($row->{$column})) {
                return trim((string) $row->{$column});
            }
        }

        return '';
    }

    private function date(mixed $value): ?string
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    private function money(mixed $value): string
    {
        return 'RM '.number_format((float) $value, 2);
    }
}
