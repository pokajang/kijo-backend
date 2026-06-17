<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantDetailContextBuilder;
use App\Services\Assistant\AssistantText;
use Illuminate\Http\Request;

class DetailRecordContextProvider extends ModuleContextProvider
{
    public function __construct(
        AssistantText $text,
        private readonly AssistantDetailContextBuilder $detailBuilder,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'detail_record';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return $this->detailBuilder->matchRoute($currentRoute, $request) !== null;
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $match = $this->detailBuilder->matchRoute($currentRoute, $request);
        if ($match === null) {
            return AssistantContextResult::empty($this->key());
        }

        $detail = $this->detailBuilder->build($match, $request);
        if ($detail === null) {
            return AssistantContextResult::empty($this->key());
        }

        $source = $this->source(
            $detail['slug'],
            $detail['source_type'],
            $detail['title'],
            $detail['route'],
            $detail['payload'],
            $detail['score'],
            $detail['category'],
            6000,
            [
                'supported_intent' => 'record_detail',
                'intent_tags' => ['record_detail', 'record_status', 'current_route', $detail['source_type']],
                'match_reason' => 'current_detail_route',
                'source_status' => $detail['source_status'] ?? null,
                'source_is_deleted' => $detail['source_is_deleted'] ?? null,
                'source_freshness_label' => ! empty($detail['source_is_deleted'])
                    ? 'Deleted record'
                    : ($detail['source_status'] ?? null),
            ],
        );

        if ($source === null) {
            return AssistantContextResult::empty($this->key());
        }

        return new AssistantContextResult(
            [$source],
            'live',
            $source['freshness_label'] ?? null,
            [$this->key()],
        );
    }

    public function auditMetadata(): array
    {
        return [
            'provider_key' => $this->key(),
            'supported_routes' => ['current detail routes from AssistantDetailContextBuilder'],
            'exact_ref_support' => false,
            'detail_route_support' => true,
            'list_support' => false,
            'sanitizer_coverage' => 'covered',
            'source_status_metadata' => 'covered',
            'permission_scope' => 'route spec self-or-role',
            'smoke_sample' => 'Explain this record from a supported detail route.',
            'tests_present' => 'partial',
            'classification' => 'detail-ready',
        ];
    }
}
