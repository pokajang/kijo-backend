<?php

namespace Tests\Feature;

use App\Http\Requests\Quote\StoreTrainingQuoteRequest;
use App\Http\Requests\Quote\StoreEquipmentQuoteRequest;
use App\Http\Requests\Quote\StoreIhQuoteRequest;
use App\Http\Requests\Quote\StoreManpowerQuoteRequest;
use App\Http\Requests\Quote\UpdateEquipmentQuoteRequest;
use App\Http\Requests\Quote\UpdateIhQuoteRequest;
use App\Http\Requests\Quote\UpdateManpowerQuoteRequest;
use App\Http\Requests\Quote\UpdateTrainingQuoteRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TrainingQuoteCostValidationTest extends TestCase
{
    public function test_store_requires_positive_estimated_cost_and_does_not_accept_rule_version(): void
    {
        $rules = (new StoreTrainingQuoteRequest)->rules();

        $this->assertTrue(Validator::make([], [
            'estimated_total_cost' => $rules['estimated_total_cost'],
        ])->fails());
        $this->assertFalse(Validator::make(['estimated_total_cost' => 100], [
            'estimated_total_cost' => $rules['estimated_total_cost'],
        ])->fails());
        $this->assertArrayNotHasKey('traffic_light_rule_version', $rules);
    }

    public function test_update_requires_positive_estimated_cost_and_does_not_accept_rule_version(): void
    {
        $rules = (new UpdateTrainingQuoteRequest)->rules();

        $this->assertTrue(Validator::make([], [
            'estimated_total_cost' => $rules['estimated_total_cost'],
        ])->fails());
        $this->assertFalse(Validator::make(['estimated_total_cost' => 100], [
            'estimated_total_cost' => $rules['estimated_total_cost'],
        ])->fails());
        $this->assertArrayNotHasKey('traffic_light_rule_version', $rules);
    }

    public function test_current_quote_forms_own_cost_validation_and_rule_versions_are_server_owned(): void
    {
        foreach ([
            new StoreEquipmentQuoteRequest,
            new UpdateEquipmentQuoteRequest,
            new StoreManpowerQuoteRequest,
            new UpdateManpowerQuoteRequest,
            new StoreIhQuoteRequest,
        ] as $request) {
            $rules = $request->rules();

            $this->assertTrue(Validator::make([], [
                'estimated_total_cost' => $rules['estimated_total_cost'],
            ])->fails());
            $this->assertFalse(Validator::make(['estimated_total_cost' => 100], [
                'estimated_total_cost' => $rules['estimated_total_cost'],
            ])->fails());
            $this->assertArrayNotHasKey('traffic_light_rule_version', $rules);
        }

        $this->assertArrayNotHasKey(
            'traffic_light_rule_version',
            (new UpdateIhQuoteRequest)->rules(),
        );
    }
}
