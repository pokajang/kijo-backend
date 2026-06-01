<?php

namespace App\Services\Assistant\Sources;

use App\Http\Requests\Vendor\GetVendorPaymentsRequest;
use App\Http\Requests\Vendor\ListVendorsRequest;
use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Vendors\VendorCrudService;
use App\Services\Vendors\VendorPaymentService;
use Illuminate\Http\Request;

class VendorContextProvider extends ModuleContextProvider
{
    private const ROUTE_PATTERNS = [
        '~/(?:vendor|vendors)(?:/[^/]+)?/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly VendorCrudService $vendors,
        private readonly VendorPaymentService $payments,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'vendor';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return str_contains(strtolower($currentRoute), '/vendor')
            || $this->hasToken($question, [
                'vendor', 'vendors', 'supplier', 'suppliers',
            ]);
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $vendorRows = $this->vendorRows($request);
        if ($vendorRows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $vendorRows,
            'vendor_id',
            'vendor_name',
            ['vendor_name', 'status', 'category', 'trainingTopics', 'competency', 'supplierProducts', 'consultancy', 'servicesOffered'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->vendorSource((array) $resolved['row'], $question, $request));
        }

        $ranked = $this->resolver->rankedMatches(
            $question,
            $vendorRows,
            'vendor_name',
            ['vendor_name', 'status', 'category', 'trainingTopics', 'competency', 'supplierProducts', 'consultancy', 'servicesOffered'],
        );
        $matches = array_column(array_slice($ranked, 0, 5), 'row');
        if ($matches === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/vendor')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->vendorListSource($matches ?: array_slice($vendorRows, 0, 5)));
    }

    private function vendorRows(Request $request): array
    {
        $payload = $this->responseData(fn () => $this->vendors->index(
            $this->formRequest(ListVendorsRequest::class, $request, '/assistant/vendors', [
                'status' => 'all',
                'per_page' => 100,
            ]),
        ));

        return array_map(fn ($row): array => (array) $row, $payload['vendors'] ?? $payload['data'] ?? []);
    }

    private function vendorSource(array $vendor, string $question, Request $request): ?array
    {
        $vendorId = (int) ($vendor['vendor_id'] ?? 0);
        if ($vendorId <= 0) {
            return null;
        }

        $context = [
            'vendor' => $this->sanitizer->keep($vendor, [
                'vendor_id',
                'vendor_name',
                'contact_person_name',
                'status',
                'category',
                'trainingTopics',
                'competency',
                'supplierProducts',
                'consultancy',
                'servicesOffered',
                'created_at',
            ]),
        ];

        if ($this->hasToken($question, ['payment', 'payments', 'paid', 'approved', 'outstanding', 'project', 'projects'])) {
            $payload = $this->responseData(fn () => $this->payments->vendorPayments(
                $this->formRequest(GetVendorPaymentsRequest::class, $request, "/assistant/vendors/{$vendorId}/payments", [
                    'vendor_id' => $vendorId,
                    'per_page' => 50,
                ]),
            ));
            $context['payments'] = [
                'outstanding' => $payload['outstanding'] ?? null,
                'recent_history' => $this->sanitizer->rows($payload['history'] ?? [], [
                    'project_id',
                    'project_name',
                    'payment_context',
                    'amount',
                    'method',
                    'status',
                    'created_at',
                    'date_approved',
                    'paid_date',
                    'paid_amount',
                    'payment_type',
                ], 6),
            ];
        }

        return $this->source(
            "vendor:{$vendorId}",
            'vendor',
            (string) ($vendor['vendor_name'] ?? "Vendor #{$vendorId}"),
            $this->hasToken($question, ['payment', 'payments', 'paid', 'approved', 'outstanding'])
                ? "/vendor/paid/{$vendorId}"
                : '/vendor/manage',
            $context,
            440,
            'Vendors',
        );
    }

    private function vendorListSource(array $vendors): ?array
    {
        $rows = $this->sanitizer->rows($vendors, [
            'vendor_id',
            'vendor_name',
            'status',
            'category',
            'trainingTopics',
            'competency',
            'supplierProducts',
            'consultancy',
            'servicesOffered',
        ], 5);

        return $this->source(
            'vendor:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'vendor',
            'Vendor matches',
            '/vendor/manage',
            [
                'note' => 'Multiple vendor records may be relevant. Ask with the exact vendor name for details.',
                'vendors' => $rows,
            ],
            300,
            'Vendors',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'vendor_id',
            'vendor_name',
            'status',
            'category',
        ], 5);

        return $this->source(
            'vendor:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous vendor matches',
            '/vendor/manage',
            [
                'note' => 'The question matched multiple vendors. Ask again with the exact vendor name or vendor ID.',
                'matches' => $rows,
            ],
            360,
            'Vendors',
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
