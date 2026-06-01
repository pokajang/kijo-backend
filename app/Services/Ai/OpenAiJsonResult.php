<?php

namespace App\Services\Ai;

class OpenAiJsonResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $text = null,
        public readonly ?array $json = null,
        public readonly ?int $status = null,
        public readonly ?string $error = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}

    public static function success(
        string $text,
        array $json,
        ?int $status = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): self {
        return new self(true, $text, $json, $status, null, $inputTokens, $outputTokens);
    }

    public static function failure(string $error, ?int $status = null, ?string $text = null): self
    {
        return new self(false, $text, null, $status, $error);
    }
}
