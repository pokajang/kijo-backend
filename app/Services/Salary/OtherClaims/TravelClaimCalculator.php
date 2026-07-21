<?php

namespace App\Services\Salary\OtherClaims;

final class TravelClaimCalculator
{
    public function prepare(array $claim, float $mileageRate): array
    {
        $km = $this->roundMoney((float) ($claim['km'] ?? 0));
        $tripMode = ($claim['tripMode'] ?? null) === 'one_way' ? 'one_way' : 'return';
        $claimableKm = $this->roundMoney($km * ($tripMode === 'one_way' ? 1 : 2));

        $claim['km'] = $km;
        $claim['tripMode'] = $tripMode;
        $claim['amount'] = $this->roundMoney($claimableKm * $mileageRate);
        if (trim((string) ($claim['meta'] ?? '')) === '') {
            $claim['meta'] = $claimableKm <= 0
                ? 'Travel expense only'
                : ($tripMode === 'one_way'
                    ? $this->trimDecimal($km).' KM one-way'
                    : $this->trimDecimal($km).' KM one-way / '.$this->trimDecimal($claimableKm).' KM return');
        }

        return $claim;
    }

    private function roundMoney(float $value): float
    {
        return round($value + PHP_FLOAT_EPSILON, 2);
    }

    private function trimDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
