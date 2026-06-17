<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Clients\ClientCommercialHistoryService;
use App\Services\Clients\ClientCompanyLookupService;
use App\Services\Clients\ClientRoiReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClientContextProvider extends ModuleContextProvider
{
    use ProviderAuditMetadata;

    private const ROUTE_PATTERNS = [
        '~/(?:client|clients|client-companies)(?:/[^/]+)?/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly ClientCompanyLookupService $clients,
        private readonly ClientCommercialHistoryService $history,
        private readonly ClientRoiReportService $roi,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'client';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return str_contains(strtolower($currentRoute), '/client')
            || $this->hasToken($question, [
                'client', 'customer', 'pelanggan', 'returning', 'roi',
                'commercial', 'branch', 'branches', 'pic',
            ]);
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        if ($this->wantsRoi($question)) {
            return $this->resultFromSource($this->roiSource());
        }

        $clientRows = $this->clientRows();
        if ($clientRows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $clientRows,
            'company_id',
            'company_name',
            ['company_name', 'client_status', 'city', 'state'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->clientSource((int) $resolved['row']['company_id'], $question, $request, (array) $resolved['row']));
        }

        $ranked = $this->resolver->rankedMatches($question, $clientRows, 'company_name', ['company_name', 'client_status', 'city', 'state']);
        $matches = array_column(array_slice($ranked, 0, 5), 'row');
        if ($matches === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/client')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->clientListSource($matches ?: array_slice($clientRows, 0, 5)));
    }

    public function auditMetadata(): array
    {
        return $this->auditMetadataRow([
            'supported_routes' => ['/client/manage', '/client/manage/{id}', '/client/roi', '/client-companies/{id}'],
            'exact_ref_support' => true,
            'detail_route_support' => true,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'permission_scope' => 'client services/session',
            'smoke_sample' => 'who is our number 1 returning client now?',
            'classification' => 'detail-ready',
        ]);
    }

    private function clientRows(): array
    {
        $payload = $this->responseData(fn () => $this->clients->listClients());

        return array_map(fn ($row): array => (array) $row, $payload['data'] ?? []);
    }

    private function clientSource(int $clientId, string $question, Request $request, array $fallbackClient = []): ?array
    {
        $payload = $this->responseData(fn () => $this->clients->show(
            $this->clonedRequest($request, "/assistant/client-companies/{$clientId}"),
            $clientId,
        ));
        $client = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        if (! $client) {
            $client = $fallbackClient;
        }
        if (! $client) {
            return null;
        }

        $context = [
            'client' => $this->sanitizer->keep($client, [
                'company_id',
                'company_name',
                'client_status',
                'payment_terms_days',
                'effective_payment_terms_days',
                'payment_terms_source',
                'city',
                'state',
                'branch_count',
                'branch_summary',
                'pic_count',
            ]),
        ];

        if ($this->hasToken($question, ['commercial', 'history', 'roi', 'returning', 'invoice', 'invoices', 'payment', 'payments', 'quote', 'quotation', 'project'])) {
            $range = $this->dateRange();
            $historyPayload = $this->responseData(fn () => $this->history->show(
                $this->clonedRequest($request, "/assistant/client-companies/{$clientId}/commercial-history", [
                    'start' => $range['start_date'],
                    'end' => $range['end_date'],
                ]),
                $clientId,
            ));
            $historyData = is_array($historyPayload['data'] ?? null) ? $historyPayload['data'] : [];
            $context['commercial_history'] = [
                'period' => $range,
                'summary' => $this->sanitizer->sanitizeArray($historyData['summary'] ?? []),
                'recent_payments' => $this->sanitizer->rows($historyData['payments'] ?? [], [
                    'invoice_ref_no',
                    'project_name',
                    'paid_date',
                    'paid_amount',
                    'status',
                    'source_type',
                ], 5),
                'recent_invoices' => $this->sanitizer->rows($historyData['invoices'] ?? [], [
                    'invoice_ref_no',
                    'project_name',
                    'invoice_date',
                    'grand_total',
                    'status',
                ], 5),
                'recent_quotes' => $this->sanitizer->rows($historyData['quotes'] ?? [], [
                    'quote_ref_no',
                    'service_type',
                    'status',
                    'created_at',
                    'quote_value',
                ], 5),
            ];
        }

        return $this->source(
            "client:{$clientId}",
            'client',
            (string) ($client['company_name'] ?? "Client #{$clientId}"),
            "/client/manage/{$clientId}",
            $context,
            450,
            'Clients',
        );
    }

    private function roiSource(): ?array
    {
        $range = $this->dateRange();
        $rows = $this->roi->reportRows($range['start_date'], $range['end_date']);
        $top = array_slice($rows, 0, 5);

        return $this->source(
            'client:roi:top:'.substr(sha1(json_encode($top)), 0, 12),
            'client',
            'Client ROI and returning client ranking',
            '/client/roi',
            [
                'period' => $range,
                'ranking_note' => 'Rows are sorted by awarded value, then actual profit.',
                'top_clients' => $this->sanitizer->rows($top, [
                    'company_id',
                    'company_name',
                    'client_status',
                    'awarded_project_count',
                    'awarded_value',
                    'invoice_count',
                    'invoiced_total',
                    'received_count',
                    'received_total',
                    'actual_profit',
                    'projected_profit',
                    'actual_roi_percent',
                    'last_paid_date',
                ], 5),
            ],
            470,
            'Clients',
        );
    }

    private function clientListSource(array $clients): ?array
    {
        $rows = $this->sanitizer->rows($clients, [
            'company_id',
            'company_name',
            'client_status',
            'payment_terms_days',
            'city',
            'state',
        ], 5);

        return $this->source(
            'client:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'client',
            'Client matches',
            '/client/manage',
            [
                'note' => 'Multiple client records may be relevant. Ask with the exact client company name for details.',
                'clients' => $rows,
            ],
            300,
            'Clients',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'company_id',
            'company_name',
            'client_status',
            'city',
            'state',
        ], 5);

        return $this->source(
            'client:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous client matches',
            '/client/manage',
            [
                'note' => 'The question matched multiple clients. Ask again with the exact company name or client ID.',
                'matches' => $rows,
            ],
            360,
            'Clients',
        );
    }

    private function wantsRoi(string $question): bool
    {
        return $this->hasToken($question, ['returning', 'roi', 'number', 'top', 'ranking', 'profitable', 'profit']);
    }

    private function dateRange(): array
    {
        return [
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date' => Carbon::now()->toDateString(),
        ];
    }

    private function resultFromSource(?array $source): AssistantContextResult
    {
        return new AssistantContextResult(
            $source ? [$source] : [],
            $source ? 'live' : 'static',
            $source ? $this->freshnessLabel() : null,
            [$this->key()],
        );
    }
}
