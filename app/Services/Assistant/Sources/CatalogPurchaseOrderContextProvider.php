<?php

namespace App\Services\Assistant\Sources;

class CatalogPurchaseOrderContextProvider extends SimpleTableContextProvider
{
    public function key(): string { return 'catalog_purchase_order'; }

    protected function tokens(): array { return ['catalog', 'item', 'purchase_order', 'supplier', 'po', 'equipment']; }

    protected function routeHints(): array { return ['/catalog', '/commercial/supplier-po']; }

    protected function tableSpecs(): array
    {
        return [
            [
                'table' => 'catalog_items',
                'id' => 'id',
                'title' => 'name',
                'source_type' => 'catalog',
                'category' => 'Catalog',
                'route' => '/catalog/manage/{id}',
                'fields' => ['id', 'name', 'category', 'brand', 'model', 'supplier', 'unit_price', 'status', 'updated_at'],
                'search' => ['name', 'category', 'brand', 'model', 'supplier', 'status'],
                'order_by' => 'updated_at',
                'score' => 340,
            ],
            [
                'table' => 'supplier_po_main',
                'id' => 'po_id',
                'title' => 'po_no',
                'source_type' => 'purchase_order',
                'category' => 'Supplier POs',
                'route' => '/commercial/supplier-po/{id}',
                'fields' => ['po_id', 'po_no', 'supplier_name', 'project_id', 'status', 'total_amount', 'created_at', 'updated_at'],
                'search' => ['po_no', 'supplier_name', 'status'],
                'order_by' => 'updated_at',
                'score' => 350,
            ],
        ];
    }
}
