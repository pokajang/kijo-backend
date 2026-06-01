<?php

namespace App\Services\Mail;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SystemEmailBodyBuilder
{
    public function render(array $data): string
    {
        return view('emails.workflow-notification', $this->normalize($data))->render();
    }

    public function presentation(
        string $headerLabel,
        string $headerTitle,
        ?string $headerSubtitle = null,
        ?string $preheader = null,
        ?string $footer = null,
    ): array {
        return array_filter([
            'headerLabel' => $headerLabel,
            'headerTitle' => $headerTitle,
            'headerSubtitle' => $headerSubtitle,
            'preheader' => $preheader,
            'footer' => $footer,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function normalize(array $data): array
    {
        $intro = Arr::wrap($data['intro'] ?? []);
        $details = $this->normalizeDetails($data['details'] ?? []);
        $notice = $data['notice'] ?? null;

        return [
            'intro' => array_values(array_filter(array_map(
                static fn ($paragraph): string => trim((string) $paragraph),
                $intro,
            ), static fn (string $paragraph): bool => $paragraph !== '')),
            'detailsHeading' => (string) ($data['detailsHeading'] ?? 'Details'),
            'details' => $details,
            'status' => $this->normalizeStatus($data['status'] ?? null),
            'actionUrl' => $this->optionalString($data['actionUrl'] ?? null),
            'actionLabel' => (string) ($data['actionLabel'] ?? 'Open in KIJO'),
            'notice' => is_array($notice) ? [
                'label' => (string) ($notice['label'] ?? 'Note'),
                'body' => (string) ($notice['body'] ?? ''),
                'tone' => (string) ($notice['tone'] ?? 'info'),
            ] : null,
            'signOff' => $this->normalizeSignOff($data['signOff'] ?? true),
        ];
    }

    private function normalizeDetails(array $details): array
    {
        $rows = [];
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $label = (string) ($value['label'] ?? $key);
                $displayValue = $value['value'] ?? '';
            } else {
                $label = (string) $key;
                $displayValue = $value;
            }

            $label = trim($label);
            if ($label === '') {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'value' => trim((string) $displayValue),
            ];
        }

        return $rows;
    }

    private function normalizeStatus(mixed $status): ?array
    {
        if (! is_array($status)) {
            return null;
        }

        $label = trim((string) ($status['label'] ?? ''));
        if ($label === '') {
            return null;
        }

        return [
            'label' => $label,
            'tone' => (string) ($status['tone'] ?? 'neutral'),
        ];
    }

    private function normalizeSignOff(mixed $signOff): ?array
    {
        if ($signOff === false || $signOff === null) {
            return null;
        }

        if (is_array($signOff)) {
            return [
                'thanks' => (string) ($signOff['thanks'] ?? 'Thank you.'),
                'name' => (string) ($signOff['name'] ?? 'Kijo Alert'),
            ];
        }

        return [
            'thanks' => 'Thank you.',
            'name' => 'Kijo Alert',
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return Str::of($value)->isEmpty() ? null : $value;
    }
}
