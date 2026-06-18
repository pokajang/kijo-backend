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

    public function test_bm_terms_normalize_to_expected_modules(): void
    {
        $intent = app(AssistantIntentNormalizer::class)->normalize('tunjuk bil dan slip gaji pelanggan projek ini');

        $this->assertSame('bahasa_malaysia', $intent['language']);
        $this->assertContains('invoice', $intent['module_hints']);
        $this->assertContains('salary', $intent['module_hints']);
        $this->assertContains('client', $intent['module_hints']);
        $this->assertContains('project', $intent['module_hints']);
    }

    public function test_english_billing_word_does_not_trigger_bm_language(): void
    {
        $intent = app(AssistantIntentNormalizer::class)->normalize('show billing invoice status');

        $this->assertSame('auto', $intent['language']);
        $this->assertContains('invoice', $intent['module_hints']);
    }
}
