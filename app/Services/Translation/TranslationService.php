<?php

namespace App\Services\Translation;

interface TranslationService
{
    public function translateText(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string;

    public function translateHtml(string $html, string $targetLanguage, string $sourceLanguage = 'en'): string;
}
