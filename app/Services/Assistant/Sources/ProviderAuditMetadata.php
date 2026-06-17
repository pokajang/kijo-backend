<?php

namespace App\Services\Assistant\Sources;

trait ProviderAuditMetadata
{
    private function auditMetadataRow(array $overrides): array
    {
        return array_merge([
            'provider_key' => $this->key(),
            'supported_routes' => [],
            'exact_ref_support' => false,
            'detail_route_support' => false,
            'list_support' => false,
            'sanitizer_coverage' => 'unknown',
            'source_status_metadata' => 'partial',
            'permission_scope' => 'session-role',
            'smoke_sample' => null,
            'tests_present' => 'partial',
            'classification' => 'summary-only',
        ], $overrides);
    }
}
