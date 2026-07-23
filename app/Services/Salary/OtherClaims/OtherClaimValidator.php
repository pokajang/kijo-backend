<?php

namespace App\Services\Salary\OtherClaims;

use Illuminate\Validation\ValidationException;

final class OtherClaimValidator
{
    public function assertBusinessRules(array $claims, array $files, $preservedAttachments): void
    {
        $errors = [];
        $mileageTravelGroupCounts = collect($claims)
            ->filter(fn (array $claim): bool => ($claim['type'] ?? '') === 'Mileage')
            ->pluck('travelGroupId')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->countBy()
            ->all();

        foreach ($claims as $index => $claim) {
            $type = (string) ($claim['type'] ?? '');
            $claimId = (string) ($claim['id'] ?? '');
            $category = ClaimAttachmentData::travelCategory($claim);
            $definitions = ClaimAttachmentData::definitions($claim);
            $claimFiles = ClaimAttachmentData::filesForClaim($files, $claimId);
            $preservedIds = array_values(array_filter(array_map(
                fn (array $attachment): ?int => $attachment['id'],
                $definitions,
            )));
            $hasEvidence = $claimFiles !== [] || collect($preservedIds)->contains(
                fn (int $id): bool => $preservedAttachments->has($id),
            );

            if ($type === 'Mileage') {
                $distanceMethod = trim((string) ($claim['distanceMethod'] ?? ''));
                if ($distanceMethod === '') {
                    $distanceMethod = ($claim['tripMode'] ?? null) === 'one_way'
                        ? 'one_way'
                        : 'return_same_route';
                }
                if (empty($claim['date']) || trim((string) ($claim['description'] ?? '')) === '' || trim((string) ($claim['startLocation'] ?? '')) === '' || trim((string) ($claim['endLocation'] ?? '')) === '' || (float) ($claim['km'] ?? 0) <= 0) {
                    $errors["claims.{$index}.km"][] = 'Mileage claims require date, purpose, from, to, and a valid distance.';
                }
                if (! in_array($distanceMethod, ['one_way', 'return_same_route', 'total_distance'], true)) {
                    $errors["claims.{$index}.distanceMethod"][] = 'Select one-way, same-route return, or total distance travelled.';
                }
                if ($category !== 'mileage') {
                    $errors["claims.{$index}.travelCategory"][] = 'Mileage rows must use the mileage travel category.';
                }
                $travelGroupId = trim((string) ($claim['travelGroupId'] ?? ''));
                if ($travelGroupId !== '' && ($mileageTravelGroupCounts[$travelGroupId] ?? 0) > 1) {
                    $errors["claims.{$index}.travelGroupId"][] = 'Each travel group can contain only one mileage row.';
                }
            } elseif ($type === 'Expense' && in_array($category, ['taxi', 'toll', 'parking', 'other', 'legacy_combined'], true)) {
                $this->validateTravelExpense($errors, $index, $claim, $category, $hasEvidence);
            } else {
                if ((float) ($claim['amount'] ?? 0) <= 0) {
                    $errors["claims.{$index}.amount"][] = "{$type} claims require a valid amount.";
                }
                if (in_array($type, ['Expense', 'Medical'], true) && ! $hasEvidence) {
                    $errors["claims.{$index}.attachments"][] = "{$type} claims require an attachment.";
                }
                if ($category !== '') {
                    $errors["claims.{$index}.travelCategory"][] = 'Travel categories are only valid for Mileage or Expense rows.';
                }
                if ($type === 'Expense' && trim((string) ($claim['travelGroupId'] ?? '')) !== '') {
                    $errors["claims.{$index}.travelCategory"][] = 'Linked legacy travel expenses require a travel category.';
                }
            }

            foreach ($definitions as $attachment) {
                if ($attachment['id'] !== null && ! $preservedAttachments->has($attachment['id'])) {
                    $errors["claims.{$index}.attachments"][] = 'Attachment does not belong to this editable other claim record.';
                }
                if ($attachment['id'] !== null && $preservedAttachments->has($attachment['id'])) {
                    $existingPurpose = (string) ($preservedAttachments->get($attachment['id'])->purpose ?? '');
                    $expectedPurpose = ClaimAttachmentData::purpose($claim);
                    if ($existingPurpose !== '' && $existingPurpose !== $expectedPurpose) {
                        $errors["claims.{$index}.attachments"][] = 'Replace or re-upload supporting evidence after changing the travel category.';
                    }
                }
            }
            foreach ($claimFiles as $clientId => $file) {
                if (! $this->isSupportedAttachment($file)) {
                    $errors["claims.{$index}.attachments.{$clientId}"][] = 'Upload a PDF, JPG, JPEG, or PNG file up to 5 MB.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateTravelExpense(array &$errors, int $index, array $claim, string $category, bool $hasEvidence): void
    {
        if (empty($claim['date']) || trim((string) ($claim['description'] ?? '')) === '' || (float) ($claim['amount'] ?? 0) <= 0) {
            $errors["claims.{$index}.amount"][] = 'Travel expenses require date, purpose, and a valid amount.';
        }

        if ($category === 'taxi' && (trim((string) ($claim['startLocation'] ?? '')) === '' || trim((string) ($claim['endLocation'] ?? '')) === '')) {
            $errors["claims.{$index}.startLocation"][] = 'Taxi claims require pickup and drop-off locations.';
        }
        if ($category === 'toll' && trim((string) ($claim['startLocation'] ?? '')) === '' && trim((string) ($claim['endLocation'] ?? '')) === '' && trim((string) ($claim['locationDetail'] ?? '')) === '') {
            $errors["claims.{$index}.locationDetail"][] = 'Toll claims require from/to locations or the route taken.';
        }
        if ($category === 'parking' && trim((string) ($claim['locationDetail'] ?? '')) === '' && trim((string) ($claim['travelGroupId'] ?? '')) === '') {
            $errors["claims.{$index}.locationDetail"][] = 'Parking claims require the parking location.';
        }
        if ($category === 'other' && trim((string) ($claim['expenseType'] ?? '')) === '') {
            $errors["claims.{$index}.expenseType"][] = 'Other travel expenses require an expense type.';
        }
        if (! $hasEvidence) {
            $errors["claims.{$index}.attachments"][] = 'Attach the required travel supporting evidence before saving.';
        }
    }

    private function isSupportedAttachment(mixed $file): bool
    {
        if (! $file || ! method_exists($file, 'getClientOriginalExtension')) {
            return false;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) && (int) $file->getSize() <= 5 * 1024 * 1024;
    }
}
