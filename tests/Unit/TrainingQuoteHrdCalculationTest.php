<?php

namespace Tests\Unit;

use App\Services\AuditLogService;
use App\Services\Quotes\Crud\TrainingQuoteService;
use ReflectionMethod;
use Tests\TestCase;

class TrainingQuoteHrdCalculationTest extends TestCase
{
    public function test_hrd_grant_does_not_apply_a_rate_when_zero_is_submitted(): void
    {
        $totals = $this->trainingTotals([
            'payment_method' => 'HRD Grant',
            'hrd_charge' => 0,
        ]);

        $this->assertSame(0.0, $totals['hrd_amount']);
        $this->assertSame(900.0, $totals['grand_total']);
    }

    public function test_hrd_grant_applies_an_explicit_rate_to_net_training_cost(): void
    {
        $totals = $this->trainingTotals([
            'payment_method' => 'HRD Grant',
            'hrd_charge' => 4,
        ]);

        $this->assertSame(36.0, $totals['hrd_amount']);
        $this->assertSame(936.0, $totals['grand_total']);
    }

    public function test_non_hrd_payment_ignores_a_submitted_hrd_rate(): void
    {
        $totals = $this->trainingTotals([
            'payment_method' => 'Self-Payment',
            'hrd_charge' => 4,
        ]);

        $this->assertSame(0.0, $totals['hrd_amount']);
        $this->assertSame(900.0, $totals['grand_total']);
    }

    private function trainingTotals(array $overrides): array
    {
        $service = new TrainingQuoteService($this->createMock(AuditLogService::class));
        $method = new ReflectionMethod($service, 'trainingTotals');

        return $method->invoke($service, array_replace([
            'pricing_basis' => 'per_session',
            'pax' => 25,
            'session_count' => 1,
            'duration_per_session' => 1,
            'unit_price' => 1000,
            'meals_provided' => 'No',
            'meal_price' => 0,
            'travel_charge' => 0,
            'discount_amount' => 100,
            'sst_rate' => 0,
        ], $overrides), null);
    }
}
