<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteRecordDetailContextBuilder
{
    public function __construct(
        private readonly AssistantContextSanitizer $sanitizer,
        private readonly AssistantRecordRouteParser $routes,
        private readonly ProposalTemplateDetailContextBuilder $proposals,
    ) {}

    public function detail(string $service, int $id): ?array
    {
        $config = $this->config($service);
        if (! $config || $id <= 0 || ! Schema::hasTable($config['table'])) {
            return null;
        }

        $row = DB::table($config['table'])->where('id', $id)->first();
        if (! $row) {
            return null;
        }

        $quote = (array) $row;
        $quote['service_key'] = $service;
        $quote['service_type'] = $config['label'];
        $quote['quote_ref_no'] = $quote['quote_ref_no'] ?? $config['prefix'].' #'.$id;

        $payload = [
            'quote' => $this->sanitizer->detail($quote),
            'line_items' => $this->lineItems($service, $id),
        ];

        $linkedProposal = $this->linkedProposal($service, $quote);
        if ($linkedProposal) {
            $payload['linked_proposal'] = $linkedProposal;
        } elseif (! empty($quote['attach_proposal']) || ! empty($quote['proposal_id'])) {
            $payload['linked_proposal_note'] = 'No linked proposal template content was found for this quote.';
        }

        return array_filter($payload, fn ($value): bool => $value !== null && $value !== []);
    }

    public function routeFor(string $service, int $id): string
    {
        return $this->routes->quoteRouteFor($service, $id);
    }

    public function titleFor(string $service, array $detail, int $id): string
    {
        return (string) (
            $detail['quote']['quote_ref_no']
            ?? $detail['quote']['quotation_ref_no']
            ?? $this->serviceLabel($service).' Quote #'.$id
        );
    }

    public function serviceLabel(string $service): string
    {
        return (string) ($this->config($service)['label'] ?? ucfirst($service));
    }

    private function lineItems(string $service, int $id): array
    {
        if ($service === 'equipment') {
            return $this->equipmentItems($id);
        }

        if ($service === 'special') {
            return $this->specialItems($id);
        }

        return [];
    }

    private function equipmentItems(int $id): array
    {
        if (! Schema::hasTable('quotes_equipment_items')) {
            return [];
        }

        $query = DB::table('quotes_equipment_items as qi')->where('qi.quote_id', $id);
        $columns = $this->qualifiedColumns('quotes_equipment_items', 'qi', [
            'id',
            'quote_id',
            'item_id',
            'quantity',
            'unit_price',
            'marked_up_price',
            'line_total',
        ]);
        if ($columns === [] || ! in_array('qi.quote_id', $columns, true)) {
            return [];
        }

        if (Schema::hasTable('catalog_items') && Schema::hasColumn('catalog_items', 'id')) {
            $query->leftJoin('catalog_items as ci', 'ci.id', '=', 'qi.item_id');
            $columns = array_merge($columns, $this->qualifiedColumns('catalog_items', 'ci', [
                'item_name',
                'description',
                'unit',
                'supplier_name',
                'supplier_price',
            ]));
        }

        $query->select($columns);
        if (in_array('qi.id', $columns, true)) {
            $query->orderBy('qi.id');
        }

        return $query->limit(25)->get()
            ->map(fn (object $row): array => $this->sanitizer->detail((array) $row))
            ->all();
    }

    private function specialItems(int $id): array
    {
        if (! Schema::hasTable('quotes_special_items')) {
            return [];
        }

        $columns = $this->existingColumns('quotes_special_items', [
            'id',
            'quote_id',
            'service_id',
            'line_item_title',
            'description',
            'unit',
            'quantity',
            'unit_price',
            'line_total',
            'created_at',
            'updated_at',
        ]);
        if ($columns === [] || ! in_array('quote_id', $columns, true)) {
            return [];
        }

        $query = DB::table('quotes_special_items')
            ->select($columns)
            ->where('quote_id', $id);
        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        return $query->limit(25)->get()
            ->map(fn (object $row): array => $this->sanitizer->detail((array) $row))
            ->all();
    }

    private function linkedProposal(string $service, array $quote): ?array
    {
        if (! in_array($service, ['training', 'ih', 'manpower', 'special'], true)) {
            return null;
        }

        $proposalId = (int) ($quote['proposal_id'] ?? $quote['template_id'] ?? 0);
        if ($proposalId <= 0) {
            return null;
        }

        $detail = $this->proposals->detail($service, $proposalId);
        if (! $detail) {
            return null;
        }

        return [
            'source_type' => 'proposal_template',
            'title' => $this->proposals->titleFor($service, $detail, $proposalId),
            'related_route' => $this->proposals->routeFor($service, $proposalId),
            'detail' => $detail,
        ];
    }

    private function config(string $service): ?array
    {
        return match ($service) {
            'equipment' => ['table' => 'quotes_equipment', 'label' => 'Equipment Supply', 'prefix' => 'Equipment'],
            'training' => ['table' => 'quotes_training', 'label' => 'Training', 'prefix' => 'Training'],
            'ih' => ['table' => 'quotes_ih', 'label' => 'Industrial Hygiene', 'prefix' => 'IH'],
            'manpower' => ['table' => 'quotes_manpower', 'label' => 'Manpower Supply', 'prefix' => 'Manpower'],
            'special' => ['table' => 'quotes_special', 'label' => 'Special Service', 'prefix' => 'Special'],
            default => null,
        };
    }

    private function existingColumns(string $table, array $columns): array
    {
        $available = array_flip(Schema::getColumnListing($table));

        return array_values(array_filter(
            array_unique($columns),
            static fn (string $column): bool => $column !== '' && isset($available[$column]),
        ));
    }

    private function qualifiedColumns(string $table, string $alias, array $columns): array
    {
        return array_map(
            static fn (string $column): string => "{$alias}.{$column}",
            $this->existingColumns($table, $columns),
        );
    }
}
