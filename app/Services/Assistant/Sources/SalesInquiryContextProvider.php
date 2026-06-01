<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\SalesInquiries\SalesInquiryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SalesInquiryContextProvider extends ModuleContextProvider
{
    private const ROUTE_PATTERNS = [
        '~/pipeline/inquiries/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly SalesInquiryService $inquiries,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'sales_inquiry';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return Schema::hasTable('sales_inquiries')
            && (
                str_contains(strtolower($currentRoute), '/pipeline/inquiries')
                || $this->hasToken($question, [
                    'inquiry', 'inquiries', 'lead', 'leads', 'prospect',
                    'qualified', 'contacted',
                ])
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $rows = $this->inquiryRows($request);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'id',
            'companyName',
            ['companyName', 'serviceRequired', 'source', 'status', 'clientName', 'quoteRefNo'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->inquirySource((array) $resolved['row']));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'companyName', [
            'companyName', 'serviceRequired', 'source', 'status', 'clientName', 'quoteRefNo',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        $filtered = $this->filterByIntent($matches ?: $rows, $question);
        if ($filtered === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/pipeline/inquiries')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->inquiryListSource($filtered ?: array_slice($rows, 0, 8)));
    }

    private function inquiryRows(Request $request): array
    {
        $payload = $this->responseData(fn () => $this->inquiries->index(
            $this->clonedRequest($request, '/assistant/sales-inquiries'),
        ));

        return array_map(fn ($row): array => (array) $row, $payload['data'] ?? []);
    }

    private function filterByIntent(array $rows, string $question): array
    {
        foreach (['new', 'contacted', 'qualified', 'lost', 'archived'] as $status) {
            if ($this->hasToken($question, [$status])) {
                return array_values(array_filter($rows, fn (array $row): bool => strtolower((string) ($row['status'] ?? '')) === $status));
            }
        }

        return $rows;
    }

    private function inquirySource(array $inquiry): ?array
    {
        $id = (int) ($inquiry['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->source(
            "sales-inquiry:{$id}",
            'sales_inquiry',
            (string) ($inquiry['companyName'] ?? "Sales inquiry #{$id}"),
            "/pipeline/inquiries/{$id}",
            ['sales_inquiry' => $this->sanitizer->keep($inquiry, [
                'id',
                'companyName',
                'serviceRequired',
                'source',
                'sourceRemarks',
                'inquiryDate',
                'status',
                'remarks',
                'proofCount',
                'clientId',
                'clientName',
                'quoteId',
                'quoteRefNo',
                'quoteServiceType',
                'ownerStaffCode',
                'ownerStaffName',
                'createdAt',
                'updatedAt',
            ])],
            420,
            'Sales Inquiries',
        );
    }

    private function inquiryListSource(array $inquiries): ?array
    {
        $rows = $this->sanitizer->rows($inquiries, [
            'id',
            'companyName',
            'serviceRequired',
            'source',
            'inquiryDate',
            'status',
            'proofCount',
            'clientName',
            'quoteRefNo',
            'ownerStaffCode',
        ], 8);

        return $this->source(
            'sales-inquiry:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'sales_inquiry',
            'Sales inquiry matches',
            '/pipeline/inquiries',
            [
                'note' => 'Multiple sales inquiries may be relevant. Ask with the company name or inquiry ID for details.',
                'inquiries' => $rows,
            ],
            320,
            'Sales Inquiries',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'companyName',
            'serviceRequired',
            'inquiryDate',
            'status',
        ], 5);

        return $this->source(
            'sales-inquiry:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous sales inquiry matches',
            '/pipeline/inquiries',
            [
                'note' => 'The question matched multiple sales inquiries. Ask again with the exact company name or inquiry ID.',
                'matches' => $rows,
            ],
            360,
            'Sales Inquiries',
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
