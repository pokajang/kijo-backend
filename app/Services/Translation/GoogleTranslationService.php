<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;

class GoogleTranslationService implements TranslationService
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    public function translateText(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string
    {
        return $this->translate($text, $targetLanguage, $sourceLanguage, 'text');
    }

    public function translateHtml(string $html, string $targetLanguage, string $sourceLanguage = 'en'): string
    {
        return $this->translate($html, $targetLanguage, $sourceLanguage, 'html');
    }

    private function translate(string $value, string $targetLanguage, string $sourceLanguage, string $format): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        $key = (string) config('services.google_translate.key', '');
        if ($key === '') {
            throw new TranslationException('Google Translate API key is not configured.');
        }

        $response = Http::timeout(20)->acceptJson()->post(self::ENDPOINT . '?key=' . urlencode($key), [
            'q' => $value,
            'source' => $sourceLanguage,
            'target' => $targetLanguage,
            'format' => $format,
        ]);

        if (!$response->successful()) {
            throw new TranslationException('Google Translate request failed with HTTP ' . $response->status() . '.');
        }

        $translated = $response->json('data.translations.0.translatedText');
        if (!is_string($translated)) {
            throw new TranslationException('Google Translate returned an unexpected response.');
        }

        return html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
