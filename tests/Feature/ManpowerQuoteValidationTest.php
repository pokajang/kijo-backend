<?php

namespace Tests\Feature;

use App\Http\Requests\Quote\StoreManpowerQuoteRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ManpowerQuoteValidationTest extends TestCase
{
    public function test_manpower_rate_type_must_match_shared_config_rate_key(): void
    {
        $validator = $this->validatorFor([
            'manpower_rate_type' => 'unknown_rate',
            'unit_cost' => 1,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('manpower_rate_type', $validator->errors()->toArray());
    }

    public function test_known_manpower_rate_type_enforces_configured_floor(): void
    {
        $validator = $this->validatorFor([
            'manpower_rate_type' => 'hse_executive',
            'unit_cost' => 7999,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('unit_cost', $validator->errors()->toArray());
    }

    public function test_known_manpower_rate_type_at_floor_passes(): void
    {
        $validator = $this->validatorFor([
            'manpower_rate_type' => 'hse_executive',
            'unit_cost' => 8000,
        ]);

        $this->assertFalse($validator->fails());
    }

    private function validatorFor(array $overrides)
    {
        $payload = array_merge($this->basePayload(), $overrides);
        $request = StoreManpowerQuoteRequest::create('/', 'POST', $payload);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    private function basePayload(): array
    {
        return [
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_address' => '1 Test Road',
            'pic_name' => 'Test PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'mp_id' => 1,
            'service_title' => 'HSE Executive',
            'service_code' => 'HSE',
            'manpower_rate_type' => 'hse_executive',
            'billing_unit' => 'month',
            'duration_months' => 1,
            'no_of_pax' => 1,
            'unit_cost' => 8000,
        ];
    }
}
