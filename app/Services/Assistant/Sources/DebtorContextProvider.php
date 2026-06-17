<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Debtors\DebtorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DebtorContextProvider extends ModuleContextProvider
{
    use ProviderAuditMetadata;

    public function __construct(
        AssistantText $text,
        private readonly DebtorService $debtors,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'debtor';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        $hasDebtorIntent = $this->hasToken($question, ['debtor', 'debtors', 'receivable', 'receivables'])
            || (
                $this->hasToken($question, ['overdue', 'outstanding'])
                && $this->hasToken($question, ['amount', 'client', 'clients', 'invoice', 'invoices', 'payment', 'payments', 'debt'])
            );

        return Schema::hasTable('invoices')
            && (
                str_contains(strtolower($currentRoute), '/commercial/debtors')
                || $hasDebtorIntent
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $rows = $this->debtorRows($question, $request);
        if ($rows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'sourceId',
            'invoiceRef',
            ['invoiceRef', 'client', 'purpose', 'serviceType', 'status'],
            ['~/commercial/debtors/manual/(\d+)(?:/|$)~i'],
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->debtorSource((array) $resolved['row']));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'invoiceRef', [
            'invoiceRef', 'client', 'purpose', 'serviceType', 'status',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');
        if ($matches === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/commercial/debtors')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->debtorListSource($matches ?: array_slice($rows, 0, 8)));
    }

    public function auditMetadata(): array
    {
        return $this->auditMetadataRow([
            'supported_routes' => ['/commercial/debtors', '/commercial/debtors/manual/{id}'],
            'exact_ref_support' => true,
            'detail_route_support' => true,
            'list_support' => true,
            'sanitizer_coverage' => 'covered',
            'permission_scope' => 'debtor services/session',
            'smoke_sample' => 'show overdue debtors',
            'classification' => 'detail-ready',
        ]);
    }

    private function debtorRows(string $question, Request $request): array
    {
        $status = $this->hasToken($question, ['paid']) ? 'paid' : ($this->hasToken($question, ['all']) ? 'all' : 'open');
        $payload = $this->responseData(fn () => $this->debtors->index(
            $this->clonedRequest($request, '/assistant/debtors', ['status' => $status]),
        ));

        return array_map(fn ($row): array => (array) $row, $payload['debtors'] ?? []);
    }

    private function debtorSource(array $debtor): ?array
    {
        $id = (int) ($debtor['sourceId'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->source(
            "debtor:".($debtor['sourceType'] ?? 'record').":{$id}",
            'debtor',
            (string) ($debtor['invoiceRef'] ?? "Debtor #{$id}"),
            '/commercial/debtors',
            ['debtor' => $this->sanitizer->keep($debtor, [
                'sourceType',
                'sourceId',
                'invoiceRef',
                'client',
                'serviceType',
                'purpose',
                'invoiceDate',
                'paymentTermsDays',
                'dueDate',
                'ageDays',
                'overdueDays',
                'grandTotal',
                'status',
                'paidDate',
                'paidAmount',
                'paymentMethod',
                'internalPicCode',
            ])],
            430,
            'Debtors',
        );
    }

    private function debtorListSource(array $debtors): ?array
    {
        $rows = $this->sanitizer->rows($debtors, [
            'sourceType',
            'sourceId',
            'invoiceRef',
            'client',
            'serviceType',
            'purpose',
            'invoiceDate',
            'dueDate',
            'overdueDays',
            'grandTotal',
            'status',
        ], 8);

        return $this->source(
            'debtor:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'debtor',
            'Debtor matches',
            '/commercial/debtors',
            [
                'note' => 'Multiple debtor records may be relevant. Ask with an invoice reference or client name for a narrower answer.',
                'debtors' => $rows,
            ],
            320,
            'Debtors',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'sourceType',
            'sourceId',
            'invoiceRef',
            'client',
            'status',
            'grandTotal',
        ], 5);

        return $this->source(
            'debtor:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous debtor matches',
            '/commercial/debtors',
            [
                'note' => 'The question matched multiple debtor records. Ask again with the exact invoice reference or debtor ID.',
                'matches' => $rows,
            ],
            360,
            'Debtors',
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
