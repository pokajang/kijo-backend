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
        $mileageTravelGroups = array_keys($mileageTravelGroupCounts);
        $linkedTravelExpenseGroups = collect($claims)
            ->filter(fn (array $claim): bool => ($claim['type'] ?? '') === 'Expense' && (float) ($claim['amount'] ?? 0) > 0)
            ->pluck('travelGroupId')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->all();

        foreach ($claims as $index => $claim) {
            $type = (string) ($claim['type'] ?? '');
            $claimId = (string) ($claim['id'] ?? '');
            $travelGroupId = trim((string) ($claim['travelGroupId'] ?? ''));
            $expenseCategory = trim((string) ($claim['expenseCategory'] ?? ''));
            $tripMode = trim((string) ($claim['tripMode'] ?? ''));
            $hasNewAttachment = $claimId !== '' && isset($files[$claimId]);
            $attachmentId = isset($claim['attachmentId']) && is_numeric($claim['attachmentId'])
                ? (int) $claim['attachmentId']
                : null;
            $hasPreservedAttachment = $attachmentId !== null && $preservedAttachments->has($attachmentId);

            if ($type === 'Mileage') {
                $hasMileage = (float) ($claim['km'] ?? 0) > 0;
                $hasLinkedTravelExpense = $travelGroupId !== '' && in_array($travelGroupId, $linkedTravelExpenseGroups, true);
                if (empty($claim['date']) || trim((string) ($claim['startLocation'] ?? '')) === '' || trim((string) ($claim['endLocation'] ?? '')) === '' || (! $hasMileage && ! $hasLinkedTravelExpense)) {
                    $errors["claims.{$index}.km"][] = 'Travel claims require date, from, to, and either mileage KM or a linked travel expense.';
                }
                if ($hasNewAttachment || $attachmentId !== null) {
                    $errors["claims.{$index}.attachment"][] = 'Mileage claims cannot include attachments.';
                }
                if (! empty($claim['expenseCategory'])) {
                    $errors["claims.{$index}.expenseCategory"][] = 'Mileage rows cannot contain an expense category.';
                }
                if ($travelGroupId !== '' && ($mileageTravelGroupCounts[$travelGroupId] ?? 0) > 1) {
                    $errors["claims.{$index}.travelGroupId"][] = 'Each travel group must contain exactly one mileage row.';
                }
            } elseif ((float) ($claim['amount'] ?? 0) <= 0) {
                $errors["claims.{$index}.amount"][] = "{$type} claims require a valid amount.";
            }

            if ($type !== 'Mileage' && $tripMode !== '') {
                $errors["claims.{$index}.tripMode"][] = 'Trip mode is only valid for mileage rows.';
            }
            if ($type === 'Expense') {
                if ($travelGroupId !== '' && ! in_array($travelGroupId, $mileageTravelGroups, true)) {
                    $errors["claims.{$index}.travelGroupId"][] = 'Linked travel expenses require a mileage row in the same claim.';
                }
                if ($travelGroupId !== '' && $expenseCategory === '') {
                    $errors["claims.{$index}.expenseCategory"][] = 'Linked travel expenses require an expense category.';
                }
                if ($travelGroupId === '' && $expenseCategory !== '') {
                    $errors["claims.{$index}.travelGroupId"][] = 'Travel expense categories require a linked mileage row.';
                }
            } elseif ($type !== 'Mileage' && $travelGroupId !== '') {
                $errors["claims.{$index}.travelGroupId"][] = 'Travel groups are only valid for mileage and expense rows.';
            }
            if (! in_array($type, ['Mileage', 'Expense'], true) && $expenseCategory !== '') {
                $errors["claims.{$index}.expenseCategory"][] = 'Expense categories are only valid for expense rows.';
            }
            if (in_array($type, ['Expense', 'Medical'], true) && ! $hasNewAttachment && ! $hasPreservedAttachment) {
                $errors["claims.{$index}.attachment"][] = "{$type} claims require an attachment.";
            }
            if ($attachmentId !== null && ! $hasPreservedAttachment) {
                $errors["claims.{$index}.attachmentId"][] = 'Attachment does not belong to this editable other claim record.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
