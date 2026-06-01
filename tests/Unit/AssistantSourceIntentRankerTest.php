<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantQuestionIntent;
use App\Services\Assistant\AssistantSourceIntentRanker;
use Tests\TestCase;

class AssistantSourceIntentRankerTest extends TestCase
{
    public function test_exact_detail_route_beats_knowledge_article(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('knowledge', 'quote-guide', 'How to Create a Quotation', '/crm/quotes', 700, ['quote_creation']),
            $this->source('quote_record', 'quote-record:training:31', 'Q-TR-31', '/crm/quotes?service=training&edit=true&quoteId=31', 440, ['record_detail']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::RECORD_DETAIL, ['quote_record'], [], [], false, true, 'high'), 'summarize this quote', '/crm/quotes?service=training&edit=true&quoteId=31');

        $this->assertGreaterThan($ranked[0]['score'], $ranked[1]['score']);
    }

    public function test_exact_quote_ref_beats_quotation_how_to(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('knowledge', 'quote-guide', 'How to Create a Quotation', '/crm/quotes', 700, ['quote_creation']),
            $this->source('quote_record', 'quote-record:training:31', 'Q-TR-31', '/crm/quotes?service=training&edit=true&quoteId=31', 440, ['record_detail', 'record_status']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::RECORD_DETAIL, ['quote_record'], [], [], true, false, 'high'), 'explain quote Q-TR-31', '');

        $this->assertGreaterThan($ranked[0]['score'], $ranked[1]['score']);
    }

    public function test_quote_creation_suppresses_negotiation_source(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('knowledge', 'quote-negotiation', 'How to Request Quote Negotiations', '/crm/price-exceptions/negotiations', 800, ['quote_negotiation'], ['quote_creation']),
            $this->source('knowledge', 'quote-create', 'How to Create a Quotation', '/crm/quotes', 500, ['quote_creation']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::QUOTE_CREATION, ['knowledge'], ['quote'], [], false, false, 'high'), 'how to quote this service', '');

        $this->assertCount(1, $ranked);
        $this->assertSame('quote-create', $ranked[0]['slug']);
    }

    public function test_negotiation_intent_allows_negotiation_source(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('knowledge', 'quote-negotiation', 'How to Request Quote Negotiations', '/crm/price-exceptions/negotiations', 500, ['quote_negotiation'], ['quote_creation']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::QUOTE_NEGOTIATION, ['knowledge'], ['negotiation'], [], false, false, 'high'), 'how to negotiate this quote', '');

        $this->assertCount(1, $ranked);
        $this->assertGreaterThan(500, $ranked[0]['score']);
    }

    public function test_handbook_wins_policy_question(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('knowledge', 'working-time-guide', 'Working Time Guide', '/knowledge', 600, ['how_to']),
            $this->source('handbook', 'handbook:1:office-hours', 'Handbook: Office Hours', '/handbook', 250, ['policy_question']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::POLICY_QUESTION, ['handbook'], ['working', 'time'], [], false, false, 'high'), 'what is working time', '');

        $this->assertGreaterThan($ranked[0]['score'], $ranked[1]['score']);
    }

    public function test_proposal_detail_wins_service_explanation(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('knowledge', 'proposal-how-to', 'How to Create Proposal Template', '/templates/proposals', 600, ['how_to']),
            $this->source('proposal_template', 'proposal-template:ih:73', 'Chemical Health Risk Assessment', '/templates/proposals/industrial-hygiene/73', 430, ['service_explanation']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::SERVICE_EXPLANATION, ['proposal_template'], ['chra'], [], false, false, 'high'), 'explain chra service', '');

        $this->assertGreaterThan($ranked[0]['score'], $ranked[1]['score']);
    }

    public function test_ambiguous_source_loses_to_exact_detail_source(): void
    {
        $ranked = $this->ranker()->rank([
            $this->source('live_entity', 'quote-record:ambiguous:abc', 'Ambiguous quote record matches', '/crm/quotes', 700, ['clarification_needed']),
            $this->source('quote_record', 'quote-record:training:31', 'Q-TR-31', '/crm/quotes?service=training&edit=true&quoteId=31', 440, ['record_detail']),
        ], new AssistantQuestionIntent(AssistantQuestionIntent::RECORD_DETAIL, ['quote_record'], [], [], true, false, 'high'), 'explain quote Q-TR-31', '');

        $this->assertGreaterThan($ranked[0]['score'], $ranked[1]['score']);
    }

    private function ranker(): AssistantSourceIntentRanker
    {
        return app(AssistantSourceIntentRanker::class);
    }

    private function source(string $type, string $slug, string $title, string $route, int $score, array $tags, array $conflicts = []): array
    {
        return [
            'source_type' => $type,
            'type' => $type,
            'slug' => $slug,
            'title' => $title,
            'related_route' => $route,
            'excerpt' => $title.' '.$slug,
            'score' => $score,
            'supported_intent' => $tags[0] ?? null,
            'intent_tags' => $tags,
            'intent_conflicts' => $conflicts,
        ];
    }
}
