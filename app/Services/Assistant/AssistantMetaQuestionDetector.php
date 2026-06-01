<?php

namespace App\Services\Assistant;

class AssistantMetaQuestionDetector
{
    public function isMetaQuestion(string $question): bool
    {
        $normalized = $this->normalized($question);

        if (in_array($normalized, ['help', 'ai help', 'assistant help', 'assistant', 'ai chat', 'chatbot', 'chatbox'], true)) {
            return true;
        }

        $patterns = [
            '/\bhow (?:do i|to) use (?:this )?(?:ai chat|chatbox|chatbot|assistant|learn kijo ai)\b/',
            '/\bhow (?:do i|to) use (?:this )?(?:ai|kijo ai|learn kijo)\b/',
            '/\bwhat can (?:i )?(?:ask|ask you)\b/',
            '/\bwhat can (?:you|the assistant|this ai) (?:answer|do|help with)\b/',
            '/\bwhat (?:questions|question) can (?:i )?ask\b/',
            '/\b(?:ai chat|chatbox|chatbot|assistant|learn kijo ai)\b.*\b(?:use|ask|answer|help|do|capabilit)/',
            '/\b(?:macam mana|bagaimana|cara) (?:guna|menggunakan) (?:ai|chat|chatbot|assistant)\b/',
            '/\bapa (?:yang )?(?:boleh|dapat) (?:saya )?(?:tanya|bertanya)\b/',
            '/\b(?:boleh|dapat) jawab apa\b/',
            '/\b(?:ai|chatbot|assistant) (?:boleh|dapat) (?:jawab|buat|bantu) apa\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    public function normalized(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $value))));
    }
}
