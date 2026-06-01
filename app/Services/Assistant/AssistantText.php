<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class AssistantText
{
    public function __construct(private readonly AssistantIntentNormalizer $intentNormalizer) {}

    public function normalizePlainText(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    public function normalizeAssistantQueryTerms(string $text): string
    {
        return $this->normalizePlainText($this->intentNormalizer->normalizeCommonAssistantTypos($text));
    }

    public function normalizeAssistantContent(string $text): string
    {
        $text = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $text);

        return trim((string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $text));
    }

    public function plainTextFromHtml(string $html): string
    {
        $withoutUnsafeBlocks = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';

        return $this->normalizePlainText(html_entity_decode(
            strip_tags($withoutUnsafeBlocks),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    public function excerpt(string $text, int $limit = 2500): string
    {
        return Str::limit($this->normalizePlainText($text), $limit, '');
    }

    public function tokens(string $value): array
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $value));
        $stopwords = [
            'a' => true, 'an' => true, 'and' => true, 'are' => true, 'can' => true, 'do' => true,
            'for' => true, 'how' => true, 'i' => true, 'in' => true, 'is' => true, 'me' => true,
            'of' => true, 'on' => true, 'the' => true, 'to' => true, 'what' => true, 'where' => true,
            'who' => true, 'our' => true, 'now' => true, 'show' => true, 'tell' => true,
            'apa' => true, 'bagaimana' => true, 'boleh' => true, 'cara' => true, 'dan' => true,
            'dalam' => true, 'dengan' => true, 'di' => true, 'ini' => true, 'itu' => true,
            'ke' => true, 'macam' => true, 'mana' => true, 'nak' => true, 'saya' => true,
            'untuk' => true, 'yang' => true,
        ];
        $tokens = array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => strlen($token) >= 2 && ! isset($stopwords[$token]),
        );

        return $this->expandTokenSynonyms(array_map(fn (string $token): string => $this->singularize($token), $tokens));
    }

    public function languageHint(string $question): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $question));
        $tokens = array_flip(array_filter(explode(' ', $normalized)));
        $malayMarkers = [
            'apa' => true,
            'bagaimana' => true,
            'buat' => true,
            'cara' => true,
            'macam' => true,
            'mana' => true,
            'nak' => true,
            'saya' => true,
            'sebut' => true,
            'sebutharga' => true,
        ];

        foreach ($malayMarkers as $marker => $_) {
            if (isset($tokens[$marker])) {
                return 'bahasa_malaysia';
            }
        }

        return 'auto';
    }

    public function isActionToken(string $token): bool
    {
        return isset([
            'add' => true,
            'create' => true,
            'delete' => true,
            'edit' => true,
            'find' => true,
            'make' => true,
            'manage' => true,
            'buat' => true,
            'cipta' => true,
            'new' => true,
            'open' => true,
            'buka' => true,
            'remove' => true,
            'padam' => true,
            'update' => true,
            'kemaskini' => true,
            'use' => true,
            'guna' => true,
            'view' => true,
            'tengok' => true,
        ][$token]);
    }

    public function normalizedQuestionKey(string $question): string
    {
        return $this->intentNormalizer->normalizedIntent($question);
    }

    private function singularize(string $token): string
    {
        if (strlen($token) > 4 && str_ends_with($token, 'ies')) {
            return substr($token, 0, -3).'y';
        }
        if (
            strlen($token) > 3
            && str_ends_with($token, 's')
            && ! str_ends_with($token, 'ss')
            && ! str_ends_with($token, 'us')
        ) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    private function expandTokenSynonyms(array $tokens): array
    {
        $synonyms = [
            'buat' => ['create', 'make'],
            'buka' => ['open'],
            'cari' => ['find', 'search'],
            'cipta' => ['create'],
            'cuti' => ['leave'],
            'harga' => ['quotation', 'quote'],
            'invois' => ['invoice'],
            'kemaskini' => ['update', 'edit'],
            'kuotasi' => ['quotation', 'quote'],
            'laporan' => ['report'],
            'padam' => ['delete', 'remove'],
            'pelanggan' => ['client', 'customer'],
            'perkhidmatan' => ['service'],
            'permohonan' => ['request', 'application'],
            'projek' => ['project'],
            'rekod' => ['record'],
            'sebut' => ['quotation', 'quote'],
            'sebutharga' => ['quotation', 'quote'],
            'staf' => ['staff'],
            'tengok' => ['view'],
        ];

        $expanded = [];
        foreach ($tokens as $token) {
            $expanded[] = $token;
            array_push($expanded, ...($synonyms[$token] ?? []));
        }

        return array_values(array_unique($expanded));
    }
}
