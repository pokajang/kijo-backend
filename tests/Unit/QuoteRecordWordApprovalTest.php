<?php

namespace Tests\Unit;

use App\Services\QuoteApprovals\QuoteApprovalService;
use App\Services\QuoteRecords\EquipmentQuoteRecordService;
use App\Services\QuoteRecords\IhQuoteRecordService;
use App\Services\QuoteRecords\ManpowerQuoteRecordService;
use App\Services\QuoteRecords\QuoteRecordService;
use App\Services\QuoteRecords\QuoteRecordTrainingSpecialService;
use App\Services\QuoteRecords\SpecialTrainingQuoteRecordService;
use App\Services\QuoteRecords\TrainingQuoteRecordService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class QuoteRecordWordApprovalTest extends TestCase
{
    public function test_equipment_word_generation_uses_the_existing_quote_issuance_gate(): void
    {
        $request = Request::create('/quote-records/equipment/68/word');
        $approval = Mockery::mock(QuoteApprovalService::class);
        $approval->shouldReceive('issuanceDenial')
            ->once()
            ->with('equipment', 68, $request)
            ->andReturn([
                'status' => 'error',
                'code' => 'QUOTE_APPROVAL_REQUIRED',
                'message' => 'Approval is required before this quotation can be issued.',
            ]);
        $equipment = Mockery::mock(EquipmentQuoteRecordService::class);
        $equipment->shouldNotReceive('wordEquipment');
        app()->instance(QuoteApprovalService::class, $approval);
        app()->instance(EquipmentQuoteRecordService::class, $equipment);

        $response = app(QuoteRecordService::class)->wordEquipment($request, 68);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('QUOTE_APPROVAL_REQUIRED', $response->getData(true)['code']);
    }

    public function test_ih_and_manpower_word_generation_use_the_existing_quote_issuance_gate(): void
    {
        foreach ([
            ['ih', 'wordIh', IhQuoteRecordService::class],
            ['manpower', 'wordManpower', ManpowerQuoteRecordService::class],
        ] as [$service, $method, $dependency]) {
            $request = Request::create("/quote-records/{$service}/68/word");
            $approval = Mockery::mock(QuoteApprovalService::class);
            $approval->shouldReceive('issuanceDenial')->once()->with($service, 68, $request)->andReturn([
                'status' => 'error', 'code' => 'QUOTE_APPROVAL_REQUIRED', 'message' => 'Approval required.',
            ]);
            $recordService = Mockery::mock($dependency);
            $recordService->shouldNotReceive($method);
            app()->instance(QuoteApprovalService::class, $approval);
            app()->instance($dependency, $recordService);

            $response = app(QuoteRecordService::class)->{$method}($request, 68);
            $this->assertSame(409, $response->getStatusCode());
        }
    }

    public function test_training_and_special_word_generation_use_the_existing_quote_issuance_gate(): void
    {
        foreach ([
            ['training', 'wordTraining', TrainingQuoteRecordService::class],
            ['special', 'wordSpecial', SpecialTrainingQuoteRecordService::class],
        ] as [$service, $method, $dependency]) {
            $request = Request::create("/quote-records/{$service}/68/word");
            $approval = Mockery::mock(QuoteApprovalService::class);
            $approval->shouldReceive('issuanceDenial')->once()->with($service, 68, $request)->andReturn([
                'status' => 'error', 'code' => 'QUOTE_APPROVAL_REQUIRED', 'message' => 'Approval required.',
            ]);
            $recordService = Mockery::mock($dependency);
            $recordService->shouldNotReceive($method);
            app()->instance(QuoteApprovalService::class, $approval);
            app()->instance($dependency, $recordService);

            $response = app(QuoteRecordTrainingSpecialService::class)->{$method}($request, 68);
            $this->assertSame(409, $response->getStatusCode());
        }
    }
}
