<?php

namespace App\Services\Salary\OtherClaims;

final class ClaimAttachmentData
{
    public static function definitions(array $claim): array
    {
        $definitions = [];
        $attachments = $claim['attachments'] ?? [];

        if (is_array($attachments)) {
            foreach ($attachments as $index => $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $clientId = trim((string) ($attachment['clientId'] ?? $index));
                if ($clientId === '') {
                    continue;
                }

                $definitions[] = [
                    'clientId' => $clientId,
                    'id' => isset($attachment['id']) && is_numeric($attachment['id'])
                        ? (int) $attachment['id']
                        : null,
                    'purpose' => self::purpose($claim, $attachment['purpose'] ?? null),
                ];
            }
        }

        if ($definitions === [] && isset($claim['attachmentId']) && is_numeric($claim['attachmentId'])) {
            $definitions[] = [
                'clientId' => 'legacy-'.(int) $claim['attachmentId'],
                'id' => (int) $claim['attachmentId'],
                'purpose' => self::purpose($claim),
            ];
        }

        return $definitions;
    }

    public static function filesForClaim(array $files, string $claimId): array
    {
        $claimFiles = $files[$claimId] ?? [];
        if (! is_array($claimFiles)) {
            return $claimFiles ? ['legacy-upload' => $claimFiles] : [];
        }

        $normalized = [];
        foreach ($claimFiles as $clientId => $file) {
            if ($file) {
                $normalized[(string) $clientId] = $file;
            }
        }

        return $normalized;
    }

    public static function purpose(array $claim, mixed $purpose = null): string
    {
        $category = self::travelCategory($claim);

        return match ($category) {
            'mileage' => 'route_proof',
            'taxi' => 'taxi_receipt',
            'toll' => 'toll_proof',
            'parking' => 'parking_receipt',
            'other', 'legacy_combined' => 'other_travel_proof',
            default => trim((string) $purpose) ?: 'receipt',
        };
    }

    public static function travelCategory(array $claim): string
    {
        $category = trim((string) ($claim['travelCategory'] ?? ''));
        if ($category !== '') {
            return $category;
        }

        if (($claim['type'] ?? '') === 'Mileage') {
            return 'mileage';
        }

        $legacyCategory = trim((string) ($claim['expenseCategory'] ?? ''));

        return $legacyCategory === 'combined' ? 'legacy_combined' : $legacyCategory;
    }
}
