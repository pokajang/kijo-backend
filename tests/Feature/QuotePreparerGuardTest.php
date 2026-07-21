<?php

namespace Tests\Feature;

use App\Http\Requests\Quote\StoreTrainingQuoteRequest;
use App\Services\Quotes\QuoteCrudService;
use Tests\TestCase;

class QuotePreparerGuardTest extends TestCase
{
    public function test_quotation_creation_requires_an_authenticated_staff_identity(): void
    {
        $request = StoreTrainingQuoteRequest::create('/quotes/training', 'POST');
        $request->setLaravelSession(app('session')->driver());

        $response = app(QuoteCrudService::class)->storeTraining($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('QUOTE_PREPARER_REQUIRED', $response->getData(true)['code']);
    }
}
