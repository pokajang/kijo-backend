<?php

namespace Tests\Unit;

use App\Services\Assistant\AssistantQuestionIntent;
use App\Services\Assistant\AssistantQuestionIntentResolver;
use App\Services\Assistant\AssistantText;
use Tests\TestCase;

class AssistantQuestionIntentResolverTest extends TestCase
{
    public function test_resolves_quote_creation(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('how to quote this service');

        $this->assertSame(AssistantQuestionIntent::QUOTE_CREATION, $intent->primaryIntent);
    }

    public function test_resolves_quote_negotiation(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('how to negotiate this quote');

        $this->assertSame(AssistantQuestionIntent::QUOTE_NEGOTIATION, $intent->primaryIntent);
    }

    public function test_resolves_record_status(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('what is status of this quote');

        $this->assertSame(AssistantQuestionIntent::RECORD_STATUS, $intent->primaryIntent);
    }

    public function test_resolves_policy_question(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('what is working time in amiosh');

        $this->assertSame(AssistantQuestionIntent::POLICY_QUESTION, $intent->primaryIntent);
    }

    public function test_resolves_service_explanation(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('explain chra service');

        $this->assertSame(AssistantQuestionIntent::SERVICE_EXPLANATION, $intent->primaryIntent);
    }

    public function test_resolves_service_code_explanation_without_service_word(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('explain CHRA');

        $this->assertSame(AssistantQuestionIntent::SERVICE_EXPLANATION, $intent->primaryIntent);
    }

    public function test_resolves_action_request(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('create this for me');

        $this->assertSame(AssistantQuestionIntent::ACTION_REQUEST, $intent->primaryIntent);
    }

    public function test_explicit_create_for_me_overrides_quote_creation_intent(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('create quotation for me now');

        $this->assertSame(AssistantQuestionIntent::ACTION_REQUEST, $intent->primaryIntent);
    }

    public function test_unrelated_question_does_not_look_like_service_code(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('where can i park nearby');

        $this->assertSame(AssistantQuestionIntent::UNKNOWN, $intent->primaryIntent);
    }

    public function test_common_quote_and_service_typos_still_resolve_quote_creation(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('hot to quoute this servise');

        $this->assertSame(AssistantQuestionIntent::QUOTE_CREATION, $intent->primaryIntent);
    }

    public function test_policy_typo_still_resolves_policy_question(): void
    {
        $intent = app(AssistantQuestionIntentResolver::class)->resolve('what is bonus policie');

        $this->assertSame(AssistantQuestionIntent::POLICY_QUESTION, $intent->primaryIntent);
    }

    public function test_language_hint_detects_bm_and_keeps_english_auto(): void
    {
        $text = app(AssistantText::class);

        $this->assertSame('bahasa_malaysia', $text->languageHint('apa status projek ini?'));
        $this->assertSame('bahasa_malaysia', $text->languageHint('tunjuk invois belum bayar'));
        $this->assertSame('auto', $text->languageHint('what is project status?'));
    }

    public function test_bm_quote_creation_policy_status_and_action_intents(): void
    {
        $resolver = app(AssistantQuestionIntentResolver::class);

        $this->assertSame(
            AssistantQuestionIntent::QUOTE_CREATION,
            $resolver->resolve('cara buat sebut harga service ni')->primaryIntent,
        );
        $this->assertSame(
            AssistantQuestionIntent::POLICY_QUESTION,
            $resolver->resolve('apa waktu kerja amiosh?')->primaryIntent,
        );
        $this->assertSame(
            AssistantQuestionIntent::POLICY_QUESTION,
            $resolver->resolve('rehat tengah hari macam mana?')->primaryIntent,
        );
        $this->assertSame(
            AssistantQuestionIntent::RECORD_STATUS,
            $resolver->resolve('apa status projek ini?')->primaryIntent,
        );
        $this->assertSame(
            AssistantQuestionIntent::ACTION_REQUEST,
            $resolver->resolve('tolong approve cuti ini sekarang')->primaryIntent,
        );
    }
}
