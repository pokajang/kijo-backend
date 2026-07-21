<?php

namespace App\Services\Salary;

use App\Services\Salary\OtherClaims\TravelClaimCalculator;

class SalaryCalculator
{
    public function __construct(private TravelClaimCalculator $travelClaimCalculator) {}

    private const SOCSO_TABLE = [
        ['lower' => 0, 'upper' => 30, 'employer' => 0.4, 'employee' => 0.1],
        ['lower' => 30.01, 'upper' => 50, 'employer' => 0.7, 'employee' => 0.2],
        ['lower' => 50.01, 'upper' => 70, 'employer' => 1.1, 'employee' => 0.3],
        ['lower' => 70.01, 'upper' => 100, 'employer' => 1.5, 'employee' => 0.4],
        ['lower' => 100.01, 'upper' => 140, 'employer' => 2.1, 'employee' => 0.6],
        ['lower' => 140.01, 'upper' => 200, 'employer' => 2.95, 'employee' => 0.85],
        ['lower' => 200.01, 'upper' => 300, 'employer' => 4.35, 'employee' => 1.25],
        ['lower' => 300.01, 'upper' => 400, 'employer' => 6.15, 'employee' => 1.75],
        ['lower' => 400.01, 'upper' => 500, 'employer' => 7.85, 'employee' => 2.25],
        ['lower' => 500.01, 'upper' => 600, 'employer' => 9.65, 'employee' => 2.75],
        ['lower' => 600.01, 'upper' => 700, 'employer' => 11.35, 'employee' => 3.25],
        ['lower' => 700.01, 'upper' => 800, 'employer' => 13.15, 'employee' => 3.75],
        ['lower' => 800.01, 'upper' => 900, 'employer' => 14.85, 'employee' => 4.25],
        ['lower' => 900.01, 'upper' => 1000, 'employer' => 16.65, 'employee' => 4.75],
        ['lower' => 1000.01, 'upper' => 1100, 'employer' => 18.35, 'employee' => 5.25],
        ['lower' => 1100.01, 'upper' => 1200, 'employer' => 20.15, 'employee' => 5.75],
        ['lower' => 1200.01, 'upper' => 1300, 'employer' => 21.85, 'employee' => 6.25],
        ['lower' => 1300.01, 'upper' => 1400, 'employer' => 23.65, 'employee' => 6.75],
        ['lower' => 1400.01, 'upper' => 1500, 'employer' => 25.35, 'employee' => 7.25],
        ['lower' => 1500.01, 'upper' => 1600, 'employer' => 27.15, 'employee' => 7.75],
        ['lower' => 1600.01, 'upper' => 1700, 'employer' => 28.85, 'employee' => 8.25],
        ['lower' => 1700.01, 'upper' => 1800, 'employer' => 30.65, 'employee' => 8.75],
        ['lower' => 1800.01, 'upper' => 1900, 'employer' => 32.35, 'employee' => 9.25],
        ['lower' => 1900.01, 'upper' => 2000, 'employer' => 34.15, 'employee' => 9.75],
        ['lower' => 2000.01, 'upper' => 2100, 'employer' => 35.85, 'employee' => 10.25],
        ['lower' => 2100.01, 'upper' => 2200, 'employer' => 37.65, 'employee' => 10.75],
        ['lower' => 2200.01, 'upper' => 2300, 'employer' => 39.35, 'employee' => 11.25],
        ['lower' => 2300.01, 'upper' => 2400, 'employer' => 41.15, 'employee' => 11.75],
        ['lower' => 2400.01, 'upper' => 2500, 'employer' => 42.85, 'employee' => 12.25],
        ['lower' => 2500.01, 'upper' => 2600, 'employer' => 44.65, 'employee' => 12.75],
        ['lower' => 2600.01, 'upper' => 2700, 'employer' => 46.35, 'employee' => 13.25],
        ['lower' => 2700.01, 'upper' => 2800, 'employer' => 48.15, 'employee' => 13.75],
        ['lower' => 2800.01, 'upper' => 2900, 'employer' => 49.85, 'employee' => 14.25],
        ['lower' => 2900.01, 'upper' => 3000, 'employer' => 51.65, 'employee' => 14.75],
        ['lower' => 3000.01, 'upper' => 3100, 'employer' => 53.35, 'employee' => 15.25],
        ['lower' => 3100.01, 'upper' => 3200, 'employer' => 55.15, 'employee' => 15.75],
        ['lower' => 3200.01, 'upper' => 3300, 'employer' => 56.85, 'employee' => 16.25],
        ['lower' => 3300.01, 'upper' => 3400, 'employer' => 58.65, 'employee' => 16.75],
        ['lower' => 3400.01, 'upper' => 3500, 'employer' => 60.35, 'employee' => 17.25],
        ['lower' => 3500.01, 'upper' => 3600, 'employer' => 62.15, 'employee' => 17.75],
        ['lower' => 3600.01, 'upper' => 3700, 'employer' => 63.85, 'employee' => 18.25],
        ['lower' => 3700.01, 'upper' => 3800, 'employer' => 65.65, 'employee' => 18.75],
        ['lower' => 3800.01, 'upper' => 3900, 'employer' => 67.35, 'employee' => 19.25],
        ['lower' => 3900.01, 'upper' => 4000, 'employer' => 69.15, 'employee' => 19.75],
        ['lower' => 4000.01, 'upper' => 4100, 'employer' => 70.85, 'employee' => 20.25],
        ['lower' => 4100.01, 'upper' => 4200, 'employer' => 72.65, 'employee' => 20.75],
        ['lower' => 4200.01, 'upper' => 4300, 'employer' => 74.35, 'employee' => 21.25],
        ['lower' => 4300.01, 'upper' => 4400, 'employer' => 76.15, 'employee' => 21.75],
        ['lower' => 4400.01, 'upper' => 4500, 'employer' => 77.85, 'employee' => 22.25],
        ['lower' => 4500.01, 'upper' => 4600, 'employer' => 79.65, 'employee' => 22.75],
        ['lower' => 4600.01, 'upper' => 4700, 'employer' => 81.35, 'employee' => 23.25],
        ['lower' => 4700.01, 'upper' => 4800, 'employer' => 83.15, 'employee' => 23.75],
        ['lower' => 4800.01, 'upper' => 4900, 'employer' => 84.85, 'employee' => 24.25],
        ['lower' => 4900.01, 'upper' => 5000, 'employer' => 86.65, 'employee' => 24.75],
        ['lower' => 5000.01, 'upper' => 5100, 'employer' => 88.35, 'employee' => 25.25],
        ['lower' => 5100.01, 'upper' => 5200, 'employer' => 90.15, 'employee' => 25.75],
        ['lower' => 5200.01, 'upper' => 5300, 'employer' => 91.85, 'employee' => 26.25],
        ['lower' => 5300.01, 'upper' => 5400, 'employer' => 93.65, 'employee' => 26.75],
        ['lower' => 5400.01, 'upper' => 5500, 'employer' => 95.35, 'employee' => 27.25],
        ['lower' => 5500.01, 'upper' => 5600, 'employer' => 97.15, 'employee' => 27.75],
        ['lower' => 5600.01, 'upper' => 5700, 'employer' => 98.85, 'employee' => 28.25],
        ['lower' => 5700.01, 'upper' => 5800, 'employer' => 100.65, 'employee' => 28.75],
        ['lower' => 5800.01, 'upper' => 5900, 'employer' => 102.35, 'employee' => 29.25],
        ['lower' => 5900.01, 'upper' => 6000, 'employer' => 104.15, 'employee' => 29.75],
        ['lower' => 6000.01, 'upper' => null, 'employer' => 104.15, 'employee' => 29.75],
    ];

    private const EIS_TABLE = [
        ['lower' => 1500.01, 'upper' => 1600, 'employer' => 3.1, 'employee' => 3.1],
        ['lower' => 1600.01, 'upper' => 1700, 'employer' => 3.3, 'employee' => 3.3],
        ['lower' => 1700.01, 'upper' => 1800, 'employer' => 3.5, 'employee' => 3.5],
        ['lower' => 1800.01, 'upper' => 1900, 'employer' => 3.7, 'employee' => 3.7],
        ['lower' => 1900.01, 'upper' => 2000, 'employer' => 3.9, 'employee' => 3.9],
        ['lower' => 2000.01, 'upper' => 2100, 'employer' => 4.1, 'employee' => 4.1],
        ['lower' => 2100.01, 'upper' => 2200, 'employer' => 4.3, 'employee' => 4.3],
        ['lower' => 2200.01, 'upper' => 2300, 'employer' => 4.5, 'employee' => 4.5],
        ['lower' => 2300.01, 'upper' => 2400, 'employer' => 4.7, 'employee' => 4.7],
        ['lower' => 2400.01, 'upper' => 2500, 'employer' => 4.9, 'employee' => 4.9],
        ['lower' => 2500.01, 'upper' => 2600, 'employer' => 5.1, 'employee' => 5.1],
        ['lower' => 2600.01, 'upper' => 2700, 'employer' => 5.3, 'employee' => 5.3],
        ['lower' => 2700.01, 'upper' => 2800, 'employer' => 5.5, 'employee' => 5.5],
        ['lower' => 2800.01, 'upper' => 2900, 'employer' => 5.7, 'employee' => 5.7],
        ['lower' => 2900.01, 'upper' => 3000, 'employer' => 5.9, 'employee' => 5.9],
        ['lower' => 3000.01, 'upper' => 3100, 'employer' => 6.1, 'employee' => 6.1],
        ['lower' => 3100.01, 'upper' => 3200, 'employer' => 6.3, 'employee' => 6.3],
        ['lower' => 3200.01, 'upper' => 3300, 'employer' => 6.5, 'employee' => 6.5],
        ['lower' => 3300.01, 'upper' => 3400, 'employer' => 6.7, 'employee' => 6.7],
        ['lower' => 3400.01, 'upper' => 3500, 'employer' => 6.9, 'employee' => 6.9],
        ['lower' => 3500.01, 'upper' => 3600, 'employer' => 7.1, 'employee' => 7.1],
        ['lower' => 3600.01, 'upper' => 3700, 'employer' => 7.3, 'employee' => 7.3],
        ['lower' => 3700.01, 'upper' => 3800, 'employer' => 7.5, 'employee' => 7.5],
        ['lower' => 3800.01, 'upper' => 3900, 'employer' => 7.7, 'employee' => 7.7],
        ['lower' => 3900.01, 'upper' => 4000, 'employer' => 7.9, 'employee' => 7.9],
        ['lower' => 4000.01, 'upper' => 4100, 'employer' => 8.1, 'employee' => 8.1],
        ['lower' => 4100.01, 'upper' => 4200, 'employer' => 8.3, 'employee' => 8.3],
        ['lower' => 4200.01, 'upper' => 4300, 'employer' => 8.5, 'employee' => 8.5],
        ['lower' => 4300.01, 'upper' => 4400, 'employer' => 8.7, 'employee' => 8.7],
        ['lower' => 4400.01, 'upper' => 4500, 'employer' => 8.9, 'employee' => 8.9],
        ['lower' => 4500.01, 'upper' => 4600, 'employer' => 9.1, 'employee' => 9.1],
        ['lower' => 4600.01, 'upper' => 4700, 'employer' => 9.3, 'employee' => 9.3],
        ['lower' => 4700.01, 'upper' => 4800, 'employer' => 9.5, 'employee' => 9.5],
        ['lower' => 4800.01, 'upper' => 4900, 'employer' => 9.7, 'employee' => 9.7],
        ['lower' => 4900.01, 'upper' => 5000, 'employer' => 9.9, 'employee' => 9.9],
        ['lower' => 5000.01, 'upper' => 5100, 'employer' => 10.1, 'employee' => 10.1],
        ['lower' => 5100.01, 'upper' => 5200, 'employer' => 10.3, 'employee' => 10.3],
        ['lower' => 5200.01, 'upper' => 5300, 'employer' => 10.5, 'employee' => 10.5],
        ['lower' => 5300.01, 'upper' => 5400, 'employer' => 10.7, 'employee' => 10.7],
        ['lower' => 5400.01, 'upper' => 5500, 'employer' => 10.9, 'employee' => 10.9],
        ['lower' => 5500.01, 'upper' => 5600, 'employer' => 11.1, 'employee' => 11.1],
        ['lower' => 5600.01, 'upper' => 5700, 'employer' => 11.3, 'employee' => 11.3],
        ['lower' => 5700.01, 'upper' => 5800, 'employer' => 11.5, 'employee' => 11.5],
        ['lower' => 5800.01, 'upper' => 5900, 'employer' => 11.7, 'employee' => 11.7],
        ['lower' => 5900.01, 'upper' => 6000, 'employer' => 11.9, 'employee' => 11.9],
        ['lower' => 6000.01, 'upper' => null, 'employer' => 11.9, 'employee' => 11.9],
    ];

    public function prepareClaims(array $claims, float $mileageRate): array
    {
        return array_map(function (array $claim) use ($mileageRate): array {
            if (($claim['type'] ?? '') === 'Mileage') {
                $claim = $this->travelClaimCalculator->prepare($claim, $mileageRate);
            } else {
                $claim['amount'] = $this->roundMoney((float) ($claim['amount'] ?? 0));
            }

            return $claim;
        }, $claims);
    }

    public function summarize(float $basicSalary, array $claims): array
    {
        $basicSalary = $this->roundMoney($basicSalary);
        $claimsTotal = $this->roundMoney(array_reduce(
            $claims,
            fn (float $total, array $claim): float => $total + (float) ($claim['amount'] ?? 0),
            0.0,
        ));
        $deductions = $this->statutoryDeductions($basicSalary);

        return [
            'basicSalary' => $basicSalary,
            'claimsTotal' => $claimsTotal,
            'employeeDeductions' => $deductions['employeeTotal'],
            'employerContributions' => $deductions['employerTotal'],
            'payableSalary' => $this->roundMoney($basicSalary + $claimsTotal - $deductions['employeeTotal']),
            'deductions' => $deductions,
        ];
    }

    public function statutoryDeductions(float $basicSalary): array
    {
        $basicSalary = (float) $basicSalary;
        $effectiveSalary = 0.0;
        if ($basicSalary > 0) {
            $effectiveSalary = $basicSalary <= 5000
                ? ceil($basicSalary / 20) * 20
                : ceil($basicSalary / 100) * 100;
        }

        $employerEpf = $effectiveSalary > 0
            ? ceil($effectiveSalary * ($basicSalary <= 5000 ? 0.13 : 0.12))
            : 0.0;
        $employeeEpf = $effectiveSalary > 0 ? ceil($effectiveSalary * 0.11) : 0.0;
        $socso = $this->matchContribution($basicSalary, self::SOCSO_TABLE);
        $eis = $this->matchContribution($basicSalary, self::EIS_TABLE);

        return [
            'employerEpf' => $this->roundMoney($employerEpf),
            'employeeEpf' => $this->roundMoney($employeeEpf),
            'employerSocso' => $this->roundMoney($socso['employer']),
            'employeeSocso' => $this->roundMoney($socso['employee']),
            'employerEis' => $this->roundMoney($eis['employer']),
            'employeeEis' => $this->roundMoney($eis['employee']),
            'employerTotal' => $this->roundMoney($employerEpf + $socso['employer'] + $eis['employer']),
            'employeeTotal' => $this->roundMoney($employeeEpf + $socso['employee'] + $eis['employee']),
        ];
    }

    private function matchContribution(float $basicSalary, array $table): array
    {
        foreach ($table as $row) {
            if ($basicSalary > $row['lower'] && ($row['upper'] === null || $basicSalary <= $row['upper'])) {
                return ['employer' => (float) $row['employer'], 'employee' => (float) $row['employee']];
            }
        }

        return ['employer' => 0.0, 'employee' => 0.0];
    }

    private function roundMoney(float $value): float
    {
        return round($value + PHP_FLOAT_EPSILON, 2);
    }

    private function trimDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
