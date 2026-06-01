<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Clients\ClientVendorRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ClientVendorRegistrationContextProvider extends ModuleContextProvider
{
    private const ROUTE_PATTERNS = [
        '~/client/vendor-registration/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly ClientVendorRegistrationService $registrations,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'vendor_registration';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        $hasRegistrationIntent = $this->hasToken($question, ['registration', 'registrations', 'portal'])
            || (
                $this->hasToken($question, ['certificate', 'expiry', 'expired', 'expiring', 'validity'])
                && $this->hasToken($question, ['client', 'clients', 'company', 'companies', 'vendor', 'vendors'])
            );

        return Schema::hasTable('client_vendor_registrations')
            && (
                str_contains(strtolower($currentRoute), '/client/vendor-registration')
                || $hasRegistrationIntent
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $rows = $this->registrationRows($request);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'id',
            'client_name',
            ['client_name', 'client_status', 'status', 'valid_until', 'recipients'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->registrationSource((array) $resolved['row']));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'client_name', [
            'client_name', 'client_status', 'status', 'valid_until',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        $filtered = $this->filterByIntent($matches ?: $rows, $question);
        if ($filtered === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/client/vendor-registration')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->registrationListSource($filtered ?: array_slice($rows, 0, 8)));
    }

    private function registrationRows(Request $request): array
    {
        $payload = $this->responseData(fn () => $this->registrations->index(
            $this->clonedRequest($request, '/assistant/client-vendor-registrations'),
        ));

        return array_map(fn ($row): array => (array) $row, $payload['data']['rows'] ?? $payload['rows'] ?? []);
    }

    private function filterByIntent(array $rows, string $question): array
    {
        if ($this->hasToken($question, ['expired', 'expiring', 'expiry'])) {
            return array_values(array_filter($rows, fn (array $row): bool => in_array(strtolower((string) ($row['status'] ?? '')), ['expired', 'expiring soon', 'expiring_soon'], true)));
        }

        return $rows;
    }

    private function registrationSource(array $registration): ?array
    {
        $id = (int) ($registration['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->source(
            "vendor-registration:{$id}",
            'vendor_registration',
            (string) ($registration['client_name'] ?? "Vendor registration #{$id}"),
            "/client/vendor-registration/{$id}",
            ['vendor_registration' => $this->sanitizer->keep($registration, [
                'id',
                'client_id',
                'client_name',
                'client_status',
                'valid_from',
                'valid_until',
                'days_left',
                'status',
                'has_certificate',
                'certificate_original_name',
                'portal_url',
                'portal_username',
                'remarks',
                'recipients',
                'created_by_name',
                'updated_by_name',
            ])],
            420,
            'Client Vendor Registrations',
        );
    }

    private function registrationListSource(array $registrations): ?array
    {
        $rows = $this->sanitizer->rows($registrations, [
            'id',
            'client_id',
            'client_name',
            'valid_from',
            'valid_until',
            'days_left',
            'status',
            'has_certificate',
            'recipients',
        ], 8);

        return $this->source(
            'vendor-registration:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'vendor_registration',
            'Vendor registration matches',
            '/client/vendor-registration',
            [
                'note' => 'Multiple client vendor registrations may be relevant. Ask with the exact client name or registration ID for details.',
                'registrations' => $rows,
            ],
            320,
            'Client Vendor Registrations',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'client_name',
            'valid_until',
            'status',
        ], 5);

        return $this->source(
            'vendor-registration:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous vendor registration matches',
            '/client/vendor-registration',
            [
                'note' => 'The question matched multiple client vendor registrations. Ask again with the exact client name or registration ID.',
                'matches' => $rows,
            ],
            360,
            'Client Vendor Registrations',
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
