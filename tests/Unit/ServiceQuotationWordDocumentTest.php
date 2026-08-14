<?php

namespace Tests\Unit;

use App\Services\Word\QuotationWordDocumentBuilder;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\IOFactory;
use Tests\TestCase;
use ZipArchive;

class ServiceQuotationWordDocumentTest extends TestCase
{
    public function test_native_service_quotation_document_contains_shared_sections_and_proposal(): void
    {
        $request = Request::create('/word?approval_preview=1');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['staff_id' => 51, 'name_code' => 'TST']);
        $path = tempnam(sys_get_temp_dir(), 'service_quote_word_');
        $this->assertNotFalse($path);

        try {
            IOFactory::createWriter(app(QuotationWordDocumentBuilder::class)->build($this->data(), $request), 'Word2007')->save($path);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $document = $zip->getFromName('word/document.xml');
            $numbering = $zip->getFromName('word/numbering.xml');
            $header = $zip->getFromName('word/header1.xml');
            $footer = $zip->getFromName('word/footer1.xml');
            $zip->close();

            $this->assertIsString($document);
            foreach (['DRAFT - NOT APPROVED', 'QTR26-0001TST', 'Training Details', 'Customer Acceptance', 'Terms and Conditions', 'Service Proposal', 'Introduction', 'First native item', 'Second native item', 'Day 1'] as $text) {
                $this->assertStringContainsString($text, $document);
            }
            $this->assertStringNotContainsString('&lt;p&gt;', $document);
            $this->assertGreaterThanOrEqual(3, substr_count($document, '<w:numPr>'));
            $this->assertIsString($numbering);
            $this->assertStringContainsString('<w:ind w:left="221" w:hanging="221"/>', $numbering);
            $this->assertStringContainsString('<w:suff w:val="space"/>', $numbering);
            $this->assertIsString($header);
            $this->assertStringContainsString('AMIOSH RESOURCES SDN BHD (1062417W)', $header);
            $this->assertIsString($footer);
            $this->assertStringContainsString('PAGE', $footer);
            $this->assertStringContainsString('NUMPAGES', $footer);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function data(): array
    {
        return [
            'quoteRefNo' => 'QTR26-0001TST', 'revisionNo' => 0, 'createdDateLegacy' => '13 Aug 2026',
            'createdDateIso' => '2026-08-13', 'updatedDateIso' => '2026-08-13', 'picName' => 'Nur Aisyah',
            'clientName' => 'Parity Test Sdn. Bhd.', 'clientAddress' => "No. 1, Jalan Ujian\n43000 Kajang",
            'picEmail' => 'aisyah@example.test', 'picPhone' => '60123456789', 'preparedByName' => 'Azam Azmi',
            'signOffTitle' => 'Business Consultant', 'language' => 'en',
            'labels' => [
                'quoteNumber' => 'Quote Number', 'revDate' => 'Rev. Date', 'oriDate' => 'Ori. Date', 'date' => 'Date',
                'attentionTo' => 'Attention To', 'email' => 'Email', 'phone' => 'Phone', 'preparedBy' => 'Prepared by',
                'customerAcceptance' => 'Customer Acceptance', 'name' => 'Name', 'position' => 'Position',
                'signature' => 'Signature', 'companyStamp' => 'Company Stamp', 'terms' => 'Terms and Conditions',
                'amount' => 'Amount (RM)', 'lineItem' => 'Line Item', 'service' => 'Service', 'notes' => 'Notes',
            ],
            'greeting' => 'Dear Valued Customer,', 'intro' => 'Thank you for your interest in our training services.',
            'details' => [['label' => 'Training Details', 'value' => "Course Title: Safety\nVenue: Kajang", 'show' => true, 'bold' => false]],
            'items' => [], 'totals' => [], 'serviceSummary' => '',
            'reviewText' => 'Kindly review the terms and conditions outlined in the next page.',
            'computerGeneratedText' => '[This is a computer-generated document. No signature required.]',
            'acceptanceText' => 'I/We hereby accept the terms and conditions stated in this quotation.',
            'terms' => [['title' => '', 'items' => ['This quotation is valid for thirty days.']]],
            'proposalTitle' => 'Service Proposal',
            'proposalSections' => [['title' => 'Introduction', 'content' => '<p>Safe &amp; structured content.</p><ol><li>First native item</li><li>Second native item</li></ol>']],
            'proposalAgenda' => [1 => [['time' => '9:00 AM - 10:00 AM', 'topic' => '<p>Opening</p>']]],
        ];
    }
}
