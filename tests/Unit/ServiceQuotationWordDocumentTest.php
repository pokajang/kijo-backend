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
        $base = $this->data(['showApprovalPreview' => true]);
        $document = $this->documentXml($base);
        $numbering = $this->documentPart($base, 'word/numbering.xml');
        $header = $this->documentPart($base, 'word/header1.xml');
        $footer = $this->documentPart($base, 'word/footer1.xml');

        $this->assertIsString($document);
        foreach (['DRAFT - NOT APPROVED', 'QTR26-0001TST', 'Training Details', 'Customer Acceptance', 'Terms and Conditions', 'Service Proposal', 'Training Brochure', 'Introduction', 'First native item', 'Second native item', 'Program Tentative', 'Tentative Terms and Conditions', 'This tentative program is intended solely as a general guide'] as $text) {
            $this->assertStringContainsString($text, $document);
        }
        $this->assertStringNotContainsString('&lt;p&gt;', $document);
        $this->assertStringContainsString('w:fill="F0FFF0"', $document);
        $this->assertStringContainsString('w:color="C8F0C8"', $document);
        $this->assertStringContainsString('<w:ind w:left="360" w:hanging="180"/>', $numbering);
        $this->assertStringContainsString('<w:ind w:left="720" w:hanging="180"/>', $numbering);
        $this->assertMatchesRegularExpression('/Course Title: Safety.*?<\\/w:p>.*?<w:p[^>]*>.*?Venue: Kajang/s', $document);
        $this->assertGreaterThanOrEqual(3, substr_count($document, '<w:numPr>'));
        $this->assertIsString($numbering);
        $this->assertStringContainsString('<w:ind w:left="221" w:hanging="221"/>', $numbering);
        $this->assertStringContainsString('<w:suff w:val="space"/>', $numbering);
        $this->assertIsString($header);
        $this->assertStringContainsString('AMIOSH RESOURCES SDN BHD (1062417W)', $header);
        $this->assertIsString($footer);
        $this->assertStringContainsString('PAGE', $footer);
        $this->assertStringContainsString('NUMPAGES', $footer);
    }

    public function test_ih_and_manpower_proposal_blocks_render_with_service_styles(): void
    {
        $ih = $this->documentXml($this->data([
            'service' => 'ih',
            'proposalTitle' => 'Service Proposal',
            'proposalSections' => [
                ['title' => 'Introduction', 'content' => '<p>IH service content</p><ol><li>First point in proposal section.</li><li>Second point in proposal section.</li></ol>'],
            ],
            'proposalAdditionalSections' => [
                ['title' => 'Additional Information', 'content' => '<p>Addendum content</p>'],
            ],
        ]));
        $manpower = $this->documentXml($this->data([
            'service' => 'manpower',
            'proposalTitle' => 'Manpower Service Proposal',
            'proposalSections' => [
                ['title' => 'Introduction', 'content' => '<p>MP supply content</p><ul><li>First bullet</li><li>Second bullet</li></ul>'],
            ],
            'proposalCompanyServices' => [
                'title' => 'About AMIOSH',
                'description' => 'Provider of safety services in Malaysia.',
                'heading' => 'Our Integrated Services',
                'items' => [
                    ['title' => 'Service 1', 'description' => 'Description'],
                ],
            ],
        ]));

        $this->assertStringContainsString('Proposal', $ih);
        $this->assertStringContainsString('Additional Information', $ih);
        $this->assertStringContainsString('About AMIOSH', $ih);
        $this->assertStringContainsString('w:color="C8F0C8"', $ih);
        $this->assertStringContainsString('w:fill="F0FFF0"', $ih);

        $this->assertStringContainsString('Manpower Service Proposal', $manpower);
        $this->assertStringContainsString('About AMIOSH', $manpower);
        $this->assertStringContainsString('First bullet', $manpower);
        $this->assertStringContainsString('w:color="C8FFC8"', $manpower);
        $this->assertStringNotContainsString('&lt;li&gt;', $manpower);
        $this->assertStringNotContainsString('&lt;ul&gt;', $manpower);
    }

    private function documentXml(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'service_quote_word_');
        $this->assertNotFalse($path);
        $request = Request::create(($data['showApprovalPreview'] ?? false) ? '/word?approval_preview=1' : '/word');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['staff_id' => 51, 'name_code' => 'TST']);

        try {
            IOFactory::createWriter(app(QuotationWordDocumentBuilder::class)->build($data, $request), 'Word2007')->save($path);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $document = $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertIsString($document);

            return $document;
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function documentPart(array $data, string $part): string
    {
        $path = tempnam(sys_get_temp_dir(), 'service_quote_word_');
        $this->assertNotFalse($path);
        $request = Request::create(($data['showApprovalPreview'] ?? false) ? '/word?approval_preview=1' : '/word');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['staff_id' => 51, 'name_code' => 'TST']);

        try {
            IOFactory::createWriter(app(QuotationWordDocumentBuilder::class)->build($data, $request), 'Word2007')->save($path);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $content = $zip->getFromName($part);
            $zip->close();
            $this->assertIsString($content);

            return $content;
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function data(array $overrides = []): array
    {
        $base = [
            'quoteRefNo' => 'QTR26-0001TST', 'revisionNo' => 0, 'createdDateLegacy' => '13 Aug 2026',
            'createdDateIso' => '2026-08-13', 'updatedDateIso' => '2026-08-13', 'picName' => 'Nur Aisyah',
            'clientName' => 'Parity Test Sdn. Bhd.', 'clientAddress' => "No. 1, Jalan Ujian\n43000 Kajang",
            'picEmail' => 'aisyah@example.test', 'picPhone' => '60123456789', 'preparedByName' => 'Azam Azmi',
            'signOffTitle' => 'Business Consultant', 'language' => 'en', 'service' => 'training',
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
            'proposalTentativeTermsTitle' => 'Tentative Terms and Conditions',
            'proposalTentativeTerms' => ['This tentative program is intended solely as a general guide and does not represent a fixed or final agenda.'],
            'proposalCompanyServices' => [
                'title' => 'About AMIOSH',
                'description' => 'Established in 2010, AMIOSH is a provider of occupational safety, health, and environmental services in Malaysia.',
                'heading' => 'Our Integrated Services',
                'items' => [
                    ['title' => 'Service 1', 'description' => 'Description'],
                ],
            ],
            'proposalAdditionalSections' => [],
        ];

        return array_replace_recursive($base, $overrides);
    }
}
