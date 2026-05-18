<?php

namespace App\Http\Requests\Quote;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Support\Facades\DB;

trait ValidatesManpowerRateFloor
{
    protected function manpowerRateTypeRules(): array
    {
        return [
            'required',
            'string',
            'max:50',
            Rule::in($this->allowedManpowerRateTypes()),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('requires_management_approval')) {
                if (!$this->filled('price_exception_request_id') && !$this->hasExistingManpowerApproval()) {
                    $validator->errors()->add(
                        'price_exception_request_id',
                        'Management approval is required before saving this manpower quote override.'
                    );
                }
                return;
            }

            $minimumUnitCost = $this->stipulatedManpowerUnitCost(
                (string) $this->input('manpower_rate_type', ''),
                $this->input('duration_months')
            );

            if ($minimumUnitCost <= 0) {
                return;
            }

            $unitCost = (float) $this->input('unit_cost', 0);
            if ($unitCost >= $minimumUnitCost) {
                return;
            }

            $validator->errors()->add(
                'unit_cost',
                'Unit cost cannot be lower than the stipulated manpower rate of RM ' .
                    number_format($minimumUnitCost, 2) . '.'
            );
        });
    }

    private function stipulatedManpowerUnitCost(string $rateType, $durationMonths): float
    {
        $rate = $this->manpowerRateConfig()['rates'][$rateType] ?? null;
        if (!$rate) {
            return 0.00;
        }

        $months = (float) ($durationMonths ?: 0);
        foreach (($rate['tiers'] ?? []) as $tier) {
            if (
                array_key_exists('durationMonthsGreaterThan', $tier) &&
                $months > (float) $tier['durationMonthsGreaterThan']
            ) {
                return (float) ($tier['unitCost'] ?? 0);
            }
        }

        foreach (($rate['tiers'] ?? []) as $tier) {
            if (!empty($tier['default'])) {
                return (float) ($tier['unitCost'] ?? 0);
            }
        }

        return (float) ($rate['unitCost'] ?? 0);
    }

    private function allowedManpowerRateTypes(): array
    {
        return array_keys($this->manpowerRateConfig()['rates'] ?? []);
    }

    private function hasExistingManpowerApproval(): bool
    {
        $quoteId = (int) ($this->route('id') ?? 0);
        if ($quoteId <= 0) {
            return false;
        }

        return (int) DB::table('quotes_manpower')
            ->where('id', $quoteId)
            ->value('price_exception_request_id') > 0;
    }

    private function manpowerRateConfig(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $path = config_path('quote_rates/manpowerRates.json');
        $raw = file_get_contents($path);
        $config = json_decode($raw ?: '{}', true);

        return is_array($config) ? $config : [];
    }
}
