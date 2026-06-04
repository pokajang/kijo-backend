<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrainingQuoteRequest extends FormRequest
{
    use ValidatesTrainingRateFloor;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id'                        => ['required', 'integer'],
            'client_snapshot'                  => ['required', 'array'],
            'client_snapshot.company_name'     => ['nullable', 'string', 'max:255'],
            'client_snapshot.ssm_number'       => ['nullable', 'string', 'max:255'],
            'client_snapshot.address'          => ['nullable', 'string', 'max:255'],
            'client_snapshot.city'             => ['nullable', 'string', 'max:255'],
            'client_snapshot.state'            => ['nullable', 'string', 'max:255'],
            'client_snapshot.zip'              => ['nullable', 'string', 'max:255'],
            'pic_snapshot'                     => ['required', 'array'],
            'pic_snapshot.full_name'           => ['nullable', 'string', 'max:2000'],
            'pic_snapshot.email'               => ['nullable', 'string', 'max:2000'],
            'pic_snapshot.mobile_number'       => ['nullable', 'string', 'max:2000'],
            'pic_snapshot.position'            => ['nullable', 'string', 'max:2000'],
            'training_id'                      => ['required', 'integer'],
            'training_title'                   => ['required', 'string', 'max:255'],
            'training_type'                    => ['required', 'string', 'max:100'],
            'payment_method'                   => ['required', 'string', 'max:100'],
            'proposed_date'                    => ['nullable', 'date'],
            'proposed_end_date'                => ['nullable', 'date', 'after_or_equal:proposed_date'],
            'to_be_confirmed'                  => ['nullable', 'boolean'],
            'venue'                            => ['nullable', 'string', 'max:255'],
            'remarks'                          => ['nullable', 'string', 'max:2000'],
            'target_groups'                    => ['nullable', 'string', 'max:1000'],
            'pax'                              => ['nullable', 'integer', 'min:1'],
            'session_count'                    => ['nullable', 'integer', 'min:1'],
            'duration_per_session'             => ['nullable', 'numeric', 'min:0'],
            'duration_unit'                    => ['nullable', 'string', 'max:50'],
            'pricing_basis'                    => ['nullable', 'in:per_pax,per_session'],
            'training_rate_type'               => $this->trainingRateTypeRules(),
            'unit_price'                       => ['nullable', 'numeric', 'min:0'],
            'travel_charge'                    => ['nullable', 'numeric', 'min:0'],
            'travel_region'                    => $this->trainingTravelRegionRules(),
            'price_exception_request_id'       => ['nullable', 'integer', 'min:1'],
            'meals_provided'                   => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (is_bool($value) || is_int($value)) {
                        return;
                    }
                    $normalized = strtolower(trim((string) $value));
                    if (in_array($normalized, ['1', '0', 'true', 'false', 'yes', 'no'], true)) {
                        return;
                    }
                    $fail('The meals_provided field must be a boolean-like value.');
                },
            ],
            'meal_price'                       => ['nullable', 'numeric', 'min:0'],
            'discount_type'                    => ['nullable', 'string', 'max:50'],
            'discount_value'                   => ['nullable', 'numeric', 'min:0'],
            'sst_rate'                         => ['nullable', 'numeric', 'min:0'],
            'hrd_charge'                       => ['nullable', 'numeric', 'min:0'],
            'training_total'                   => ['nullable', 'numeric', 'min:0'],
            'meal_total'                       => ['nullable', 'numeric', 'min:0'],
            'mobilization_cost'                => ['nullable', 'numeric', 'min:0'],
            'discount_amount'                  => ['nullable', 'numeric', 'min:0'],
            'subtotal'                         => ['nullable', 'numeric', 'min:0'],
            'sst_amount'                       => ['nullable', 'numeric', 'min:0'],
            'hrd_amount'                       => ['nullable', 'numeric', 'min:0'],
            'grand_total'                      => ['nullable', 'numeric', 'min:0'],
            'attach_proposal'                  => ['nullable', 'boolean'],
            'proposal_id'                      => ['nullable', 'integer'],
            'proposal_language'                => ['nullable', 'in:en,ms-MY'],
            'isRevision'                       => ['nullable', 'boolean'],
        ];
    }
}
