<?php

namespace App\Services\Assistant;

class AssistantContextResult
{
    public function __construct(
        public readonly array $sources = [],
        public readonly string $answerMode = 'static',
        public readonly ?string $freshnessLabel = null,
        public readonly array $providerKeys = [],
        public readonly string $contextQuality = 'complete',
        public readonly array $missingFields = [],
        public readonly array $metadata = [],
    ) {}

    public static function empty(string $providerKey): self
    {
        return new self([], 'static', null, [$providerKey], 'insufficient', ['source']);
    }
}
