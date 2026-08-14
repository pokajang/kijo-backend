<?php

namespace Tests\Unit;

use App\Services\Invoices\InvoiceDocumentAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceDocumentAssetServiceTest extends TestCase
{
    public function test_it_resolves_the_same_private_signature_and_stamp_for_pdf_and_word(): void
    {
        Storage::fake('private');
        Storage::fake('public');
        Storage::disk('private')->put('signatures/51-AZA.png', 'signature-bytes');
        Storage::disk('private')->put('invoice-assets/stamp.jpg', 'stamp-bytes');

        $request = Request::create('/invoice/word');
        $request->setLaravelSession(app('session')->driver());
        $service = app(InvoiceDocumentAssetService::class);
        $invoice = (object) ['created_by' => 51];
        $creator = (object) ['name_code' => 'AZA'];

        $paths = $service->paths($request, $invoice, $creator);
        [$signatureDataUri, $stampDataUri] = $service->dataUris($request, $invoice, $creator);

        $this->assertSame(Storage::disk('private')->path('signatures/51-AZA.png'), $paths['signature']);
        $this->assertSame(Storage::disk('private')->path('invoice-assets/stamp.jpg'), $paths['stamp']);
        $this->assertSame('data:image/png;base64,'.base64_encode('signature-bytes'), $signatureDataUri);
        $this->assertSame('data:image/jpeg;base64,'.base64_encode('stamp-bytes'), $stampDataUri);
    }
}
