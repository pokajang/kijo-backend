<?php

namespace Tests\Unit;

use App\Services\Word\CommercialWordDocumentBuilder;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class CommercialWordDocumentTest extends TestCase
{
    #[DataProvider('documentProvider')]
    public function test_commercial_documents_are_valid_native_docx(array $data, array $expected): void
    {
        $request = Request::create('/word');
        $request->setLaravelSession(app('session')->driver());
        $document = app(CommercialWordDocumentBuilder::class)->build($data, $request);
        $path = tempnam(sys_get_temp_dir(), 'commercial-word-');
        self::assertNotFalse($path);
        IOFactory::createWriter($document, 'Word2007')->save($path);

        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $header = (string) $zip->getFromName('word/header1.xml');
        $footer = (string) $zip->getFromName('word/footer1.xml');
        foreach ($expected as $text) {
            self::assertStringContainsString($text, html_entity_decode(strip_tags($xml)));
        }
        self::assertStringContainsString('AMIOSH RESOURCES SDN BHD', html_entity_decode(strip_tags($header)));
        self::assertStringContainsString('NUMPAGES', $footer);
        self::assertStringNotContainsString('<html', strtolower($xml));
        $zip->close();
        unlink($path);
    }

    public static function documentProvider(): array
    {
        $base = ['language' => 'en', 'recipient' => ['ACME Sdn Bhd', 'Kajang'], 'items' => [['1', ['Safety Shoes', 'Description: S3'], 'pair', '2', '100.00', '200.00']], 'totals' => [['label' => 'Grand Total (RM)', 'value' => 200, 'bold' => true]], 'remarks' => 'Handle carefully'];

        return [
            'purchase order' => [[...$base, 'kind' => 'purchase-order', 'documentType' => 'PURCHASE ORDER', 'reference' => 'PO-1', 'date' => '13 Aug 2026', 'contactName' => 'Vendor', 'preparedBy' => ['Aza', 'Consultant', 'AMIOSH RESOURCES SDN BHD'], 'termSections' => [['heading' => 'A. Compliance', 'body' => 'Comply.']]], ['PO-1', 'Safety Shoes', 'Vendor Acknowledgement']],
            'delivery order' => [['kind' => 'delivery-order', 'documentType' => 'DELIVERY ORDER', 'language' => 'en', 'reference' => 'DO-1', 'date' => '13 Aug 2026', 'recipient' => ['Client'], 'sender' => ['AMIOSH'], 'intro' => 'Review delivery.', 'projectLabel' => 'For Project', 'project' => 'Project One', 'itemLabel' => 'Item Description', 'items' => [['1', ['Safety Shoes'], '2 pair']], 'remarks' => 'Deliver together.', 'returnText' => 'Return a signed copy.', 'acceptanceHeading' => 'Customer Acceptance', 'acceptanceText' => 'Goods received.', 'acceptanceLeft' => ['Name', 'Position', 'Signature'], 'acceptanceRight' => ['Company Stamp', 'Date'], 'computerGeneratedText' => 'Computer generated.'], ['DO-1', 'Project One', 'Deliver together.', 'Return a signed copy.', 'Customer Acceptance', 'Signature']],
            'invoice' => [[...$base, 'kind' => 'invoice', 'documentType' => 'TAX INVOICE', 'reference' => 'INV-1', 'date' => '13 Aug 2026', 'intro' => 'Review invoice.', 'service' => 'Equipment - Supply', 'preparedByLabel' => 'Prepared by', 'preparedBy' => ['Aza', 'Consultant'], 'signaturePath' => null, 'stampPath' => null, 'noSignatureText' => '[No signature or stamp on file]', 'paymentLines' => ['CIMB BANK BERHAD'], 'termsHeading' => 'Terms and Conditions', 'terms' => ['Payment is due within 30 days.']], ['INV-1', 'Equipment - Supply', 'CIMB BANK BERHAD', 'No signature or stamp on file', 'Terms and Conditions', 'Payment is due within 30 days.']],
            'receipt' => [[...$base, 'kind' => 'receipt', 'documentType' => 'OFFICIAL RECEIPT', 'reference' => 'RCPT-1', 'invoiceReference' => 'INV-1', 'date' => '13 Aug 2026', 'service' => 'Equipment - Supply'], ['RCPT-1', 'INV-1', 'Thank you for your payment']],
        ];
    }
}
