<?php

namespace App\Services\Handbook;

class HandbookSignatureEvidenceService
{
    public function __construct(
        private HandbookAcknowledgementService $acknowledgements,
    ) {}

    public function seal(array $payload): array
    {
        $json = $this->acknowledgements->canonicalJson($payload);
        $key = $this->activeEvidenceKey();

        return [
            'json' => $json,
            'sha256' => hash('sha256', $json),
            'hmac' => hash_hmac('sha256', $json, $key),
            'key_id' => (string) config('handbook.evidence_key_id', 'app-key-v1'),
        ];
    }

    public function verify(
        string $json,
        string $sha256,
        string $hmac,
        ?string $keyId = null,
    ): bool {
        $key = $this->evidenceKeyForId($keyId);

        return hash_equals($sha256, hash('sha256', $json))
            && $key !== null
            && hash_equals($hmac, hash_hmac('sha256', $json, $key));
    }

    private function activeEvidenceKey(): string
    {
        $key = (string) config('handbook.evidence_key', '');
        if ($key === '') {
            throw new \RuntimeException('Handbook evidence key is not configured.');
        }
        if (strlen($key) < 32) {
            throw new \RuntimeException('Handbook evidence key must contain at least 32 characters.');
        }

        return $key;
    }

    private function evidenceKeyForId(?string $keyId): ?string
    {
        $activeKeyId = (string) config('handbook.evidence_key_id', 'app-key-v1');
        if ($keyId === null || hash_equals($activeKeyId, $keyId)) {
            return $this->activeEvidenceKey();
        }

        $configured = config('handbook.evidence_previous_keys', '{}');
        $previousKeys = is_array($configured)
            ? $configured
            : json_decode((string) $configured, true);
        if (! is_array($previousKeys)) {
            return null;
        }

        $key = $previousKeys[$keyId] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
