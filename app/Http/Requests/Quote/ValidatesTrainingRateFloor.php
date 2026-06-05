<?php

namespace App\Http\Requests\Quote;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesTrainingRateFloor
{
    protected function trainingRateTypeRules(): array
    {
        return [
            'required',
            'string',
            'max:80',
            Rule::in($this->allowedTrainingRateTypes()),
        ];
    }

    protected function trainingTravelRegionRules(): array
    {
        return [
            'nullable',
            'string',
            'max:80',
            Rule::in($this->allowedTrainingTravelRegions()),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rateType = (string) $this->input('training_rate_type', '');
            $rate = $this->trainingRateConfig()['rates'][$rateType] ?? null;
            if (!$rate) {
                return;
            }

            if (($rate['enforceRateFloors'] ?? true) === false) {
                return;
            }

            $unitCost = (float) ($rate['unitCost'] ?? 0);
            $unitPrice = (float) $this->input('unit_price', 0);
            if ($unitCost > 0 && $unitPrice < $unitCost) {
                $validator->errors()->add(
                    'unit_price',
                    'Unit price cannot be lower than the configured training rate of RM ' .
                        number_format($unitCost, 2) . '.'
                );
            }

            $mealUnitCost = (float) ($rate['mealUnitCost'] ?? 0);
            $mealsProvided = strtolower((string) $this->input('meals_provided', ''));
            $hasMeals = in_array($mealsProvided, ['1', 'true', 'yes'], true);
            $mealPrice = (float) $this->input('meal_price', 0);
            if ($hasMeals && $mealUnitCost > 0 && $mealPrice < $mealUnitCost) {
                $validator->errors()->add(
                    'meal_price',
                    'Meal price cannot be lower than the configured rate of RM ' .
                        number_format($mealUnitCost, 2) . '.'
                );
            }

            $travelRegion = (string) $this->input('travel_region', 'none');
            $travelRate = $this->trainingRateConfig()['travelRegions'][$travelRegion] ?? null;
            $travelAmount = (float) ($travelRate['amount'] ?? 0);
            $travelCharge = (float) $this->input('travel_charge', 0);
            if ($travelAmount > 0 && $travelCharge < $travelAmount) {
                $validator->errors()->add(
                    'travel_charge',
                    'Transportation and accommodation cannot be lower than RM ' .
                        number_format($travelAmount, 2) . ' for the selected region.'
                );
            }
        });
    }

    private function allowedTrainingRateTypes(): array
    {
        return array_keys($this->trainingRateConfig()['rates'] ?? []);
    }

    private function allowedTrainingTravelRegions(): array
    {
        return array_keys($this->trainingRateConfig()['travelRegions'] ?? []);
    }

    private function trainingRateConfig(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $path = config_path('quote_rates/trainingRates.json');
        $raw = file_get_contents($path);
        $config = json_decode($raw ?: '{}', true);

        return is_array($config) ? $config : [];
    }
}
