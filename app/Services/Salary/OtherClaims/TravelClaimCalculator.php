<?php

namespace App\Services\Salary\OtherClaims;

final class TravelClaimCalculator
{
    public function prepare(array $claim, float $mileageRate): array
    {
        $km = $this->roundMoney((float) ($claim['km'] ?? 0));
        $distanceMethod = $this->distanceMethod($claim);
        $claimableKm = $this->roundMoney($km * ($distanceMethod === 'return_same_route' ? 2 : 1));
        $rate = $this->roundRate((float) ($claim['mileageRate'] ?? $mileageRate));

        $claim['km'] = $km;
        $claim['travelCategory'] = 'mileage';
        $claim['distanceMethod'] = $distanceMethod;
        // Keep the legacy field readable by older clients. Total distance has the same multiplier as one-way.
        $claim['tripMode'] = $distanceMethod === 'return_same_route' ? 'return' : 'one_way';
        $claim['mileageRate'] = $rate;
        $claim['amount'] = $this->roundMoney($claimableKm * $rate);
        if (trim((string) ($claim['meta'] ?? '')) === '') {
            $claim['meta'] = $claimableKm <= 0
                ? 'Travel expense only'
                : match ($distanceMethod) {
                    'one_way' => $this->trimDecimal($km).' KM one-way',
                    'total_distance' => $this->trimDecimal($km).' KM total distance travelled',
                    default => $this->trimDecimal($km).' KM one-way / '.$this->trimDecimal($claimableKm).' KM return',
                };
        }

        return $claim;
    }

    private function distanceMethod(array $claim): string
    {
        $method = trim((string) ($claim['distanceMethod'] ?? ''));
        if (in_array($method, ['one_way', 'return_same_route', 'total_distance'], true)) {
            return $method;
        }

        return ($claim['tripMode'] ?? null) === 'one_way' ? 'one_way' : 'return_same_route';
    }

    private function roundMoney(float $value): float
    {
        return round($value + PHP_FLOAT_EPSILON, 2);
    }

    private function roundRate(float $value): float
    {
        return round($value + PHP_FLOAT_EPSILON, 4);
    }

    private function trimDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
