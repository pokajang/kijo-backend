<?php

namespace Tests\Feature;

use App\Http\Requests\Quote\StoreIhQuoteRequest;
use App\Http\Requests\Quote\StoreManpowerQuoteRequest;
use App\Http\Requests\Quote\StoreSpecialQuoteRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class QuoteTemplateIdValidationTest extends TestCase
{
    public function test_manpower_quote_requires_template_id(): void
    {
        $validator = $this->validatorFor(StoreManpowerQuoteRequest::class, [
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_address' => '1 Test Road',
            'pic_name' => 'Test PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'service_title' => 'HSE Executive',
            'service_code' => 'HSE',
            'manpower_rate_type' => 'hse_executive',
            'billing_unit' => 'month',
            'duration_months' => 1,
            'no_of_pax' => 1,
            'unit_cost' => 8000,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('mp_id', $validator->errors()->toArray());
    }

    public function test_ih_quote_requires_template_id(): void
    {
        $validator = $this->validatorFor(StoreIhQuoteRequest::class, [
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_address' => '1 Test Road',
            'pic_name' => 'Test PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'service_title' => 'Noise Monitoring',
            'service_code' => 'NM',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('service_id', $validator->errors()->toArray());
    }

    public function test_special_quote_requires_template_id(): void
    {
        $validator = $this->validatorFor(StoreSpecialQuoteRequest::class, [
            'client_id' => 1,
            'client_name' => 'Client A',
            'client_address' => '1 Test Road',
            'pic_name' => 'Test PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'service_title' => 'Special Inspection',
            'service_code' => 'SP',
            'line_items' => [
                [
                    'item_name' => 'Inspection',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'total_price' => 100,
                ],
            ],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sp_id', $validator->errors()->toArray());
    }

    private function validatorFor(string $requestClass, array $payload)
    {
        $request = $requestClass::create('/', 'POST', $payload);
        $validator = Validator::make($request->all(), $request->rules());
        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        return $validator;
    }
}
