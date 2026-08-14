<?php

namespace Tests\Feature;

use App\Http\Requests\Quote\StoreTrainingQuoteRequest;
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
}
