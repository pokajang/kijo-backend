<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Invoices\InvoiceQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InvoiceContextProvider extends ModuleContextProvider
{
    private const ROUTE_PATTERNS = [
        '~/(?:commercial/)?invoice/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly InvoiceQueryService $invoices,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'invoice';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return Schema::hasTable('invoices')
            && (
                str_contains(strtolower($currentRoute), '/commercial/invoice')
                || $this->hasToken($question, [
                    'invoice', 'invoices', 'billing', 'bill', 'receipt', 'hrd', 'claim',
                ])
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $rows = $this->invoiceRows($request);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'id',
            'invoice_ref_no',
            ['invoice_ref_no', 'client_name', 'project_name', 'status', 'service_type'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->invoiceSource((array) $resolved['row']));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'invoice_ref_no', [
            'invoice_ref_no', 'client_name', 'project_name', 'status', 'service_type',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        $filtered = $this->filterByIntent($matches ?: $rows, $question);
        if ($filtered === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/commercial/invoice')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->invoiceListSource($filtered ?: array_slice($rows, 0, 8)));
    }

    private function invoiceRows(Request $request): array
    {
        $payload = $this->responseData(fn () => $this->invoices->index(
            $this->clonedRequest($request, '/assistant/invoices'),
        ));

        return array_map(fn ($row): array => (array) $row, $payload['invoices'] ?? []);
    }

    private function filterByIntent(array $rows, string $question): array
    {
        if ($this->hasToken($question, ['unpaid', 'pending', 'outstanding'])) {
            return array_values(array_filter($rows, fn (array $row): bool => ! in_array(strtolower((string) ($row['status'] ?? '')), ['paid', 'cancelled', 'canceled', 'void'], true)));
        }

        if ($this->hasToken($question, ['paid', 'received', 'receipt'])) {
            return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'paid' || ! empty($row['paid_date'])));
        }

        return $rows;
    }

    private function invoiceSource(array $invoice): ?array
    {
        $id = (int) ($invoice['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->source(
            "invoice:{$id}",
            'invoice',
            (string) ($invoice['invoice_ref_no'] ?? "Invoice #{$id}"),
            "/commercial/invoice/{$id}",
            [
                'invoice' => $this->sanitizer->keep($invoice, [
                    'id',
                    'invoice_ref_no',
                    'invoice_date',
                    'service_type',
                    'project_id',
                    'project_name',
                    'client_id',
                    'client_name',
                    'grand_total',
                    'status',
                    'paid_date',
                    'paid_amount',
                    'payment_method',
                    'hrd_claim_ref',
                    'loa_number',
                ]),
                'breakdown' => $this->sanitizer->rows($invoice['breakdown'] ?? [], [
                    'item_description',
                    'description',
                    'unit',
                    'quantity',
                    'unit_price',
                    'subtotal',
                ], 6),
            ],
            430,
            'Invoices',
        );
    }

    private function invoiceListSource(array $invoices): ?array
    {
        $rows = $this->sanitizer->rows($invoices, [
            'id',
            'invoice_ref_no',
            'invoice_date',
            'client_name',
            'project_name',
            'service_type',
            'grand_total',
            'status',
            'paid_date',
            'paid_amount',
        ], 8);

        return $this->source(
            'invoice:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'invoice',
            'Invoice matches',
            '/commercial/invoice',
            [
                'note' => 'Multiple invoice records may be relevant. Ask with an invoice reference for one invoice.',
                'invoices' => $rows,
            ],
            310,
            'Invoices',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'invoice_ref_no',
            'client_name',
            'project_name',
            'status',
            'grand_total',
        ], 5);

        return $this->source(
            'invoice:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous invoice matches',
            '/commercial/invoice',
            [
                'note' => 'The question matched multiple invoices. Ask again with the exact invoice reference or invoice ID.',
                'matches' => $rows,
            ],
            360,
            'Invoices',
        );
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
