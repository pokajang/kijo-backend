<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantIntentNormalizer;
use Tests\TestCase;

class AssistantIntentNormalizerTest extends TestCase
{
    public function test_source_gap_intent_normalization_corrects_common_assistant_typos(): void
    {
        $intent = app(AssistantIntentNormalizer::class)->normalize('hot to quoute this serviece policie');

        $this->assertSame('quotation service policy', $intent['normalized_intent']);
        $this->assertContains('proposal_template', $intent['module_hints']);
    }
}
