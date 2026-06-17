<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantContextRegistry;
use App\Services\Assistant\AssistantProviderAuditMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AssistantProviderAudit extends Command
{
    protected $signature = 'assistant:provider-audit';

    protected $description = 'Audit Learn Kijo assistant provider coverage and capability metadata.';

    public function handle(AssistantContextRegistry $registry): int
    {
        $rows = collect($registry->providers())
            ->map(fn ($provider): array => $this->metadataFor($provider))
            ->sortBy('provider_key')
            ->values();

        $path = 'assistant-provider-audit-'.now()->format('Ymd').'.md';
        Storage::disk('local')->put($path, $this->markdown($rows->all()));
        $fullPath = Storage::disk('local')->path($path);

        $this->info("Assistant provider audit written to {$fullPath}");
        $this->table([
            'Provider',
            'Class',
            'Classification',
            'Detail',
            'Exact Ref',
            'List',
            'Sanitizer',
            'Tests',
        ], $rows->map(fn (array $row): array => [
            $row['provider_key'],
            class_basename($row['class']),
            $row['classification'],
            $row['detail_route_support'] ? 'yes' : 'no',
            $row['exact_ref_support'] ? 'yes' : 'no',
            $row['list_support'] ? 'yes' : 'no',
            $row['sanitizer_coverage'],
            $row['tests_present'],
        ])->all());

        return self::SUCCESS;
    }

    private function metadataFor(object $provider): array
    {
        $metadata = $provider instanceof AssistantProviderAuditMetadata
            ? $provider->auditMetadata()
            : [];

        $row = [
            'provider_key' => method_exists($provider, 'key') ? $provider->key() : class_basename($provider),
            'class' => $provider::class,
            'supported_routes' => [],
            'exact_ref_support' => false,
            'detail_route_support' => false,
            'list_support' => false,
            'sanitizer_coverage' => 'unknown',
            'source_status_metadata' => 'unknown',
            'permission_scope' => 'unknown',
            'smoke_sample' => null,
            'tests_present' => 'unknown',
            'classification' => null,
        ];

        $row = array_merge($row, array_intersect_key($metadata, $row));
        $row['classification'] = $row['classification'] ?: $this->classification($row);

        return $row;
    }

    private function classification(array $row): string
    {
        if ($row['provider_key'] === 'assistant_help') {
            return 'not-applicable';
        }
        if ($row['detail_route_support'] || $row['exact_ref_support']) {
            return 'detail-ready';
        }

        return 'summary-only';
    }

    private function markdown(array $rows): string
    {
        $lines = [
            '# Assistant Provider Audit',
            '',
            '- Generated: '.now()->toDateTimeString(),
            '- Classification values: `detail-ready`, `summary-only`, `not-applicable`.',
            '',
            '| Provider | Classification | Detail | Exact Ref | List | Sanitizer | Source Status | Permission | Tests |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($rows as $row) {
            $lines[] = implode(' | ', [
                '| `'.$row['provider_key'].'`',
                $row['classification'],
                $row['detail_route_support'] ? 'yes' : 'no',
                $row['exact_ref_support'] ? 'yes' : 'no',
                $row['list_support'] ? 'yes' : 'no',
                $row['sanitizer_coverage'],
                $row['source_status_metadata'],
                $row['permission_scope'],
                $row['tests_present'].' |',
            ]);
        }

        $lines[] = '';
        $lines[] = '## Provider Details';
        foreach ($rows as $row) {
            $routes = $row['supported_routes'] ? implode(', ', array_map(
                static fn (mixed $route): string => '`'.str_replace('`', '', (string) $route).'`',
                (array) $row['supported_routes'],
            )) : 'None declared';
            $smoke = $row['smoke_sample'] ? '`'.str_replace('`', '', (string) $row['smoke_sample']).'`' : 'None declared';
            $lines[] = '- `'.$row['provider_key'].'`: routes '.$routes.'; smoke sample '.$smoke.'.';
        }

        $lines[] = '';
        $lines[] = '## Backlog Signals';
        foreach ($rows as $row) {
            if ($row['classification'] === 'summary-only') {
                $lines[] = '- `'.$row['provider_key'].'`: confirm whether detail retrieval, sanitizer tests, and source status metadata should be added.';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
