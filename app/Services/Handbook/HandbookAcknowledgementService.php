<?php

namespace App\Services\Handbook;

use InvalidArgumentException;

class HandbookAcknowledgementService
{
    public const SCHEMA_VERSION = 2;

    public const REQUIRED_DECLARATION_IDS = [
        'handbook_receipt',
        'salary_deduction_consent',
        'confidentiality_ai_boundaries',
        'electronic_signature_validation',
    ];

    public const REQUIRED_PROFILE_FIELDS = [
        'full_name',
        'identity_number',
        'employee_code',
        'designation',
        'department',
    ];

    public function defaultDefinition(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'profileFields' => self::REQUIRED_PROFILE_FIELDS,
            'declarations' => [
                [
                    'id' => 'handbook_receipt',
                    'title' => 'Handbook Receipt',
                    'body' => 'I hereby declare that I have downloaded, read, and fully understood the terms, operational codes, rules, and guidelines stipulated within the AMIOSH Employee Handbook & Culture Guide ({{handbook_version}}). I unconditionally accept and agree to abide by all internal corporate policies, including the dual-attendance tracking framework (Physical Office Thumbprint & Kijo Cloud Logging), data privacy rules, and progressive disciplinary codes.',
                    'required' => true,
                    'order' => 1,
                ],
                [
                    'id' => 'salary_deduction_consent',
                    'title' => 'Salary Deduction Consent',
                    'body' => 'I hereby provide my express, prior, and unconditional written consent to AMIOSH Management to make lawful deductions directly from my monthly salary, commissions, or final salary settlement, in strict accordance with Section 24 of the Employment Act 1955 (Malaysia). This consent specifically covers the recovery of pro-rated medical card clawbacks (Sec 8.3), negligent asset damage costs (Sec 6.4), undocumented corporate credit card spending (Sec 8.4), and outstanding exit clearance debts (Sec 12.3).',
                    'required' => true,
                    'order' => 2,
                ],
                [
                    'id' => 'confidentiality_ai_boundaries',
                    'title' => 'Confidentiality & AI Boundaries',
                    'body' => 'I hereby undertake to maintain absolute confidentiality regarding all AMIOSH proprietary workflows, client registries, and trade secrets. I explicitly agree to adhere to the strict prohibition against unauthorized digital/audio recordings of meetings (Sec 6.5), the absolute ban on uploading company data into public AI engines (Sec 6.6), and the 12-month post-employment Non-Compete restriction (Sec 10.4).',
                    'required' => true,
                    'order' => 3,
                ],
                [
                    'id' => 'electronic_signature_validation',
                    'title' => 'Electronic Signature Validation',
                    'body' => 'I hereby agree that checking these boxes, typing my legal name below, selecting my personal signature, and clicking the "Submit Electronic Signature" button constitutes my electronic signature. I acknowledge that this electronic submission is intended to carry the same validity and enforceability as a handwritten signature, pursuant to the Electronic Commerce Act 2006 (Malaysia).',
                    'required' => true,
                    'order' => 4,
                ],
            ],
        ];
    }

    public function sanitize(?array $definition, bool $useDefaultWhenMissing = false): ?array
    {
        if (! is_array($definition)) {
            return $useDefaultWhenMissing ? $this->defaultDefinition() : null;
        }

        $declarations = collect($definition['declarations'] ?? [])
            ->map(function ($declaration, int $index): array {
                return [
                    'id' => mb_substr(trim((string) ($declaration['id'] ?? '')), 0, 80),
                    'title' => mb_substr(trim((string) ($declaration['title'] ?? '')), 0, 255),
                    'body' => trim(strip_tags((string) ($declaration['body'] ?? ''))),
                    'required' => ($declaration['required'] ?? false) === true,
                    'order' => max(1, (int) ($declaration['order'] ?? ($index + 1))),
                ];
            })
            ->filter(fn (array $declaration): bool => $declaration['id'] !== ''
                && preg_match('/^[a-z0-9_]+$/', $declaration['id']) === 1
                && $declaration['title'] !== ''
                && $declaration['body'] !== '')
            ->sortBy('order')
            ->values()
            ->all();

        $sanitized = [
            'schemaVersion' => (int) ($definition['schemaVersion'] ?? 0),
            'profileFields' => collect($definition['profileFields'] ?? [])
                ->map(fn ($field) => trim((string) $field))
                ->filter(fn (string $field): bool => preg_match('/^[a-z0-9_]+$/', $field) === 1)
                ->unique()
                ->values()
                ->all(),
            'declarations' => $declarations,
        ];

        $this->assertValid($sanitized);

        return $sanitized;
    }

    public function materialize(array $definition, string $versionLabel): array
    {
        $definition = $this->sanitize($definition) ?? [];
        $definition['declarations'] = collect($definition['declarations'])
            ->map(function (array $declaration) use ($versionLabel): array {
                $declaration['body'] = str_replace(
                    '{{handbook_version}}',
                    $versionLabel,
                    $declaration['body'],
                );

                return $declaration;
            })
            ->all();

        return $definition;
    }

    public function requiredDeclarations(?array $definition): array
    {
        $definition = $this->sanitize($definition);
        if ($definition === null) {
            return [];
        }

        return collect($definition['declarations'])
            ->filter(fn (array $declaration): bool => $declaration['required'] === true)
            ->values()
            ->all();
    }

    public function hash(?array $definition): ?string
    {
        if ($definition === null) {
            return null;
        }

        return hash('sha256', $this->canonicalJson($this->sanitize($definition)));
    }

    public function canonicalJson(array $value): string
    {
        $normalized = $this->sortRecursively($value);
        $encoded = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return $encoded;
    }

    public function assertValid(array $definition): void
    {
        if ((int) ($definition['schemaVersion'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported handbook acknowledgement schema version.');
        }

        $declarations = $definition['declarations'] ?? [];
        $ids = array_column($declarations, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Handbook acknowledgement declaration IDs must be unique.');
        }

        $allIds = collect($ids)->sort()->values()->all();
        $requiredIds = collect($declarations)
            ->filter(fn (array $declaration): bool => $declaration['required'] === true)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $expected = collect(self::REQUIRED_DECLARATION_IDS)->sort()->values()->all();

        if ($allIds !== $expected || $requiredIds !== $expected) {
            throw new InvalidArgumentException('The handbook acknowledgement must contain exactly the four required declarations.');
        }

        $orders = collect($declarations)->pluck('order')->map(fn ($order) => (int) $order)->sort()->values()->all();
        if ($orders !== [1, 2, 3, 4]) {
            throw new InvalidArgumentException('Handbook acknowledgement declaration order must be unique and sequential.');
        }

        $profileFields = collect($definition['profileFields'] ?? [])->sort()->values()->all();
        $expectedProfileFields = collect(self::REQUIRED_PROFILE_FIELDS)->sort()->values()->all();
        if ($profileFields !== $expectedProfileFields) {
            throw new InvalidArgumentException('The handbook acknowledgement profile fields are incomplete.');
        }
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortRecursively($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
