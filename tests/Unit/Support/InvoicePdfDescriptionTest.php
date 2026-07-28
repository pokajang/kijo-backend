<?php

namespace Tests\Unit\Support;

use App\Support\InvoicePdfDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoicePdfDescriptionTest extends TestCase
{
    #[DataProvider('internalCalculationProvider')]
    public function test_internal_calculations_are_hidden(string $description): void
    {
        $this->assertSame('', InvoicePdfDescription::clientVisible($description));
    }

    #[DataProvider('clientDescriptionProvider')]
    public function test_client_descriptions_remain_visible(string $description): void
    {
        $this->assertSame($description, InvoicePdfDescription::clientVisible($description));
    }

    public static function internalCalculationProvider(): array
    {
        return [
            'IH rate formula' => ['14 chemical(s) x 4 work unit(s) x RM 88.00/unit'],
            'IH legacy complexity formula' => [
                '10 sample(s) x 2 work units x RM 650.00/unit x complexity 4 (1.3x)',
            ],
            'IH historical lump sum' => [
                '120 sample(s) - Lump Sum Work Unit; preserved historical quoted amount',
            ],
            'manpower duration' => ['2 pax x 3 months'],
            'manpower rate formula' => ['2 pax x 1 month(s) x RM 500.00/pax/month'],
            'special-service rate formula' => ['2.00 day x RM 100.00/day'],
        ];
    }

    public static function clientDescriptionProvider(): array
    {
        return [
            'empty' => [''],
            'ordinary note' => ['Analysis of collected samples.'],
            'duration prose' => ['Client requested 2 pax for 3 months.'],
            'scope prose' => ['Assessment covers 14 chemicals across 4 work units.'],
            'hyphenated duration' => ['2-day assessment at the client site.'],
        ];
    }
}
