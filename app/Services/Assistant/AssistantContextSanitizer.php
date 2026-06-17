<?php

namespace App\Services\Assistant;

use Illuminate\Support\Arr;

class AssistantContextSanitizer
{
    private const DEFAULT_MAX_ROWS = 8;

    private const REDACTED_KEYS = [
        'address',
        'approval_remarks',
        'bank_account',
        'bank_holder_name',
        'bank_name',
        'checker_remarks',
        'client_address',
        'client_full_address',
        'client_ssm',
        'client_tin',
        'email',
        'emergency_mobile',
        'emergency_name',
        'fee_breakdown',
        'file_path',
        'file_url',
        'mobile_number',
        'pic_email',
        'pic_phone',
        'quote_pic_email',
        'quote_pic_phone',
        'receipt_path',
        'receipt_url',
        'rejected_remarks',
        'returned_remarks',
        'ssm_number',
        'sst_number',
        'tax_id_no_tin',
        'workflow_progress_json',
    ];

    private const DETAIL_REDACTED_KEYS = [
        'attachment_path',
        'attachmentpath',
        'certificate_path',
        'certificatepath',
        'file_path',
        'filepath',
        'file_url',
        'fileurl',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'credential',
        'credentials',
        'password',
        'portal_password',
        'proof_path',
        'proofpath',
        'receipt_path',
        'receiptpath',
        'receipt_url',
        'receipturl',
        'session',
        'session_id',
        'stored_path',
        'storedpath',
        'token',
        'workflow_progress_json',
    ];

    public function keep(array $row, array $keys): array
    {
        return $this->sanitizeArray(Arr::only($row, $keys));
    }

    public function rows(array $rows, array $keys, int $limit = self::DEFAULT_MAX_ROWS): array
    {
        return array_values(array_map(
            fn (array $row): array => $this->keep($row, $keys),
            array_slice($rows, 0, max(1, $limit)),
        ));
    }

    public function keepDetail(array $row, array $keys): array
    {
        return $this->sanitizeArray(Arr::only($row, $keys), 0, self::DETAIL_REDACTED_KEYS, 2500);
    }

    public function detail(array $payload): array
    {
        return $this->sanitizeArray($payload, 0, self::DETAIL_REDACTED_KEYS, 2500);
    }

    public function detailRows(array $rows, array $keys, int $limit = self::DEFAULT_MAX_ROWS): array
    {
        return array_values(array_map(
            fn (array $row): array => $this->keepDetail($row, $keys),
            array_slice($rows, 0, max(1, $limit)),
        ));
    }

    public function sanitizeArray(
        array $payload,
        int $depth = 0,
        ?array $redactedKeys = null,
        int $maxScalarLength = 500,
    ): array
    {
        if ($depth > 4) {
            return [];
        }

        $redactedKeys ??= self::REDACTED_KEYS;
        $clean = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($this->shouldRedactKey($normalizedKey, $redactedKeys)) {
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    $items = array_slice($value, 0, self::DEFAULT_MAX_ROWS);
                    $clean[$key] = array_map(
                        fn ($item) => is_array($item)
                            ? $this->sanitizeArray($item, $depth + 1, $redactedKeys, $maxScalarLength)
                            : $this->scalar($item, $maxScalarLength),
                        $items,
                    );
                } else {
                    $clean[$key] = $this->sanitizeArray($value, $depth + 1, $redactedKeys, $maxScalarLength);
                }

                continue;
            }

            $scalar = $this->scalar($value, $maxScalarLength);
            if ($scalar !== null && $scalar !== '') {
                $clean[$key] = $scalar;
            }
        }

        return $clean;
    }

    private function shouldRedactKey(string $normalizedKey, array $redactedKeys): bool
    {
        if (in_array($normalizedKey, $redactedKeys, true)) {
            return true;
        }

        $compactKey = preg_replace('/[^a-z0-9]+/', '', $normalizedKey) ?: $normalizedKey;
        foreach ([
            'password',
            'token',
            'secret',
            'session',
            'credential',
            'apikey',
            'authorization',
            'cookie',
            'auth',
            'jwt',
            'privatekey',
            'accesskey',
            'secretkey',
            'clientsecret',
            'refresh',
            'csrf',
        ] as $sensitiveTerm) {
            if (str_contains($compactKey, $sensitiveTerm)) {
                return true;
            }
        }

        if (str_ends_with($compactKey, 'path')) {
            return true;
        }

        if (
            str_ends_with($compactKey, 'url')
            && preg_match('/(file|storage|download|attachment|certificate|proof|receipt)/', $compactKey)
        ) {
            return true;
        }

        return false;
    }

    private function scalar(mixed $value, int $maxLength = 500): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($this->shouldRedactScalar($text)) {
            return null;
        }

        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) : $text;
    }

    private function shouldRedactScalar(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (str_contains($text, "\0")) {
            return true;
        }

        if (preg_match('/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=_-]{12,}/i', $text)) {
            return true;
        }

        if (preg_match('/\b(?:sk|pk|rk|sess)_[A-Za-z0-9_-]{12,}\b/i', $text)) {
            return true;
        }

        if (preg_match('/\b(?:password|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|jwt|csrf)\s*[:=]\s*\S{4,}/i', $text)) {
            return true;
        }

        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/i', $text)) {
            return true;
        }

        if (preg_match('/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', $text)) {
            return true;
        }

        if (preg_match('/\b(?:[A-Za-z]:\\\\|\\\\\\\\|\/var\/www\/|\/home\/[^\/\s]+\/|storage\/app\/|app\/private\/|private\/(?:attachments|receipts|certificates|proofs|files)\/)/i', $text)) {
            return true;
        }

        if (preg_match('/\b(?:file|storage|attachment|certificate|proof|receipt|download)[_-]?url\s*[:=]\s*\S+/i', $text)) {
            return true;
        }

        if (preg_match('/https?:\/\/\S+\?(?:\S*(&|&amp;)?(?:signature|expires|X-Amz-Signature|X-Amz-Credential)=\S+)/i', $text)) {
            return true;
        }

        if (preg_match('/^data:(?:application|image)\/[A-Za-z0-9.+-]+;base64,[A-Za-z0-9+\/=\r\n]{80,}$/i', $text)) {
            return true;
        }

        if (strlen($text) > 300 && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $text)) {
            return true;
        }

        return false;
    }
}
