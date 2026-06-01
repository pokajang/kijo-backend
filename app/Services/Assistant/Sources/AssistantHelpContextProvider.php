<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextProvider;
use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantMetaQuestionDetector;
use App\Services\Assistant\AssistantText;
use Illuminate\Http\Request;

class AssistantHelpContextProvider implements AssistantContextProvider
{
    public function __construct(
        private readonly AssistantText $text,
        private readonly AssistantMetaQuestionDetector $metaQuestionDetector,
    ) {}

    public function key(): string
    {
        return 'assistant_help';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return $this->metaQuestionDetector->isMetaQuestion($question);
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $isBahasaMalaysia = $this->text->languageHint($question) === 'bahasa_malaysia';
        $answer = $isBahasaMalaysia ? $this->bahasaMalaysiaAnswer() : $this->englishAnswer();
        $excerpt = $isBahasaMalaysia
            ? 'Panduan ringkas tentang cara menggunakan Learn Kijo AI dan jenis soalan Kijo yang boleh ditanya.'
            : 'Quick guide for using Learn Kijo AI and the kinds of Kijo questions it can answer.';

        $source = [
            'id' => 'assistant-help:capabilities',
            'type' => 'assistant_help',
            'source_type' => 'assistant_help',
            'title' => 'Learn Kijo AI Assistant',
            'slug' => 'assistant-help:capabilities',
            'summary' => $excerpt,
            'category' => 'AI Help',
            'related_route' => null,
            'excerpt' => $excerpt,
            'fingerprint' => sha1('assistant-help:capabilities:v1'),
            'score' => 10000,
            'provider_key' => $this->key(),
            'supported_intent' => 'assistant_capabilities',
            'resolved_entity_ids' => [],
            'ambiguity_count' => 0,
            'context_quality' => 'complete',
            'missing_fields' => [],
        ];

        return new AssistantContextResult(
            [$source],
            'static',
            null,
            [$this->key()],
            'complete',
            [],
            [
                'direct_answer' => [
                    'answer_markdown' => $answer,
                    'confidence' => 'high',
                    'source_slugs' => ['assistant-help:capabilities'],
                    'suggested_queries' => [
                        'How do I create a quotation?',
                        'Who is our top returning client now?',
                        'Show unpaid invoices.',
                    ],
                    'freshness_label' => null,
                    'answer_mode' => 'static',
                    'context_quality' => 'complete',
                    'provider_key' => $this->key(),
                    'supported_intent' => 'assistant_capabilities',
                    'resolved_entity_ids' => [],
                    'missing_fields' => [],
                ],
            ],
        );
    }

    private function englishAnswer(): string
    {
        return implode("\n\n", [
            'You can ask Learn Kijo AI questions about Kijo workflows, Knowledge guides, Handbook policies, dashboards, projects, clients, vendors, invoices, debtors, quotations, inquiries, leave, tasks, and other supported app records.',
            'I answer from Kijo sources and live app data where available. If I cannot verify enough information, I will say so instead of guessing. I am read-only, so I can guide or summarize but I cannot create, update, approve, submit, or delete records.',
            "Try asking:\n- How do I create a quotation?\n- Who is our top returning client now?\n- Show unpaid invoices.\n- Explain this page.",
        ]);
    }

    private function bahasaMalaysiaAnswer(): string
    {
        return implode("\n\n", [
            'Anda boleh tanya Learn Kijo AI tentang workflow Kijo, Knowledge guides, polisi Handbook, dashboard, projek, client, vendor, invoice, debtor, quotation, inquiry, leave, task, dan rekod app lain yang disokong.',
            'Saya akan jawab berdasarkan sumber Kijo dan data live app yang tersedia. Jika maklumat tidak cukup untuk disahkan, saya akan beritahu dan tidak akan meneka. Saya hanya read-only, jadi saya boleh beri panduan atau ringkasan tetapi tidak boleh create, update, approve, submit, atau delete rekod.',
            "Contoh soalan:\n- Macam mana nak buat quotation?\n- Siapa client returning paling tinggi sekarang?\n- Tunjukkan unpaid invoices.\n- Explain this page.",
        ]);
    }
}
