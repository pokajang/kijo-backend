<?php

namespace Tests\Unit;

use App\Services\AuditLogService;
use App\Services\QuoteRecords\EquipmentQuoteDocumentData;
use App\Services\QuoteRecords\EquipmentQuoteRecordWordService;
use App\Services\Word\WordText;
use App\Support\PdfText;
use App\Support\EquipmentQuotationLayout;
use App\Support\EquipmentQuotationTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Mockery;
use PhpOffice\PhpWord\IOFactory;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class EquipmentQuoteWordDocumentTest extends TestCase
{
    public function test_it_builds_a_valid_docx_with_equipment_content_and_unicode(): void
    {
        $service = new EquipmentQuoteRecordWordService(
            Mockery::mock(AuditLogService::class),
            Mockery::mock(EquipmentQuoteDocumentData::class),
        );
        $request = Request::create('/quote-records/equipment/68/word?approval_preview=1');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['staff_id' => 51, 'name_code' => 'TST']);

        $document = $service->buildDocument($this->documentData(), $request);
        $path = tempnam(sys_get_temp_dir(), 'equipment_word_test_');
        $this->assertNotFalse($path);

        try {
            IOFactory::createWriter($document, 'Word2007')->save($path);
            $bytes = file_get_contents($path);
            $this->assertIsString($bytes);
            $this->assertStringStartsWith('PK', $bytes);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
            $this->assertNotFalse($zip->locateName('word/document.xml'));
            $xml = $zip->getFromName('word/document.xml');
            $settingsXml = $zip->getFromName('word/settings.xml');
            $stylesXml = $zip->getFromName('word/styles.xml');
            $numberingXml = $zip->getFromName('word/numbering.xml');
            $headerXml = $zip->getFromName('word/header1.xml');
            $footerXml = $zip->getFromName('word/footer1.xml');
            $zip->close();

            $this->assertIsString($xml);
            $this->assertStringContainsString('QEQ26-0068TST', $xml);
            $this->assertStringContainsString('Portable Gas Detector', $xml);
            $this->assertStringContainsString('Pelanggan', $xml);
            $this->assertStringContainsString('RM 1,188.00', $xml);
            $this->assertStringContainsString('DRAFT - NOT APPROVED', $xml);
            foreach ([482, 3855, 964, 1928, 2409] as $columnWidth) {
                $this->assertStringContainsString("<w:gridCol w:w=\"{$columnWidth}\" w:type=\"dxa\"/>", $xml);
            }
            $this->assertStringContainsString('<w:pgMar w:top="2041" w:right="1134" w:bottom="907" w:left="1134" w:header="567" w:footer="369"', $xml);
            $this->assertIsString($stylesXml);
            $this->assertStringContainsString('<w:tblLayout w:type="fixed"/>', $stylesXml);
            $this->assertIsString($numberingXml);
            $this->assertStringContainsString('<w:ind w:left="221" w:hanging="221"/>', $numberingXml);
            $this->assertStringContainsString('<w:suff w:val="space"/>', $numberingXml);
            $this->assertIsString($settingsXml);
            $this->assertStringContainsString('<w:updateFields w:val="true"/>', $settingsXml);
            $this->assertIsString($headerXml);
            $this->assertStringContainsString(EquipmentQuotationLayout::COMPANY_NAME, $headerXml);
            $this->assertStringContainsString(EquipmentQuotationLayout::COMPANY_ADDRESS_LINE_1, $headerXml);
            $this->assertStringContainsString(EquipmentQuotationLayout::COMPANY_ADDRESS_LINE_2, $headerXml);
            $this->assertStringContainsString('amiosh.com', $headerXml);
            $this->assertStringContainsString('03-8210 8726', $headerXml);
            $this->assertStringContainsString('QUOTATION', $headerXml);
            $this->assertStringContainsString('width:119.055', $headerXml);
            $this->assertStringContainsString('<w:tblBorders><w:bottom w:val="single" w:sz="6" w:color="696969"', $headerXml);
            $this->assertStringNotContainsString('Occupational Safety', $headerXml);
            $this->assertIsString($footerXml);
            $this->assertStringContainsString('Page ', $footerXml);
            $this->assertStringContainsString('PAGE', $footerXml);
            $this->assertStringContainsString('NUMPAGES', $footerXml);
            $this->assertStringContainsString('Computer generated on:', $footerXml);
            $this->assertStringContainsString('by: TST (51)', $footerXml);
            $this->assertStringContainsString('<w:i w:val="1"/>', $footerXml);
            $this->assertStringContainsString('<w:sz w:val="16"/>', $footerXml);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_it_returns_a_download_response_with_a_safe_docx_filename(): void
    {
        $auditLog = Mockery::mock(AuditLogService::class);
        $documentData = Mockery::mock(EquipmentQuoteDocumentData::class);
        $documentData->shouldReceive('find')->once()->with(68)->andReturn($this->documentData());
        $auditLog->shouldReceive('log')
            ->once()
            ->with(Mockery::type(Request::class), 'Generated Equipment quotation Word document for quote ID #68');
        $service = new EquipmentQuoteRecordWordService($auditLog, $documentData);
        $request = $this->request('/quote-records/equipment/68/word');

        $response = $service->wordEquipment($request, 68);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $response->headers->get('Content-Type'),
        );
        $this->assertSame(
            'attachment; filename="QEQ26-0068TST_Pelanggan_Sdn._Bhd.docx"; filename*=UTF-8\'\'QEQ26-0068TST_Pelanggan_Sdn._Bhd.docx',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_it_generates_xml_safe_multiline_long_and_unicode_content(): void
    {
        $service = new EquipmentQuoteRecordWordService(
            Mockery::mock(AuditLogService::class),
            Mockery::mock(EquipmentQuoteDocumentData::class),
        );
        $data = $this->documentData();
        $data['clientName'] = "Syarikat R&D <Selatan> \x01 Sdn. Bhd.";
        $data['clientAddress'] = "Jalan Élan\r\nTaman Ujian\rBandar Kajang";
        $data['quotationRemarks'] = "Baris pertama & semakan\nBaris kedua <selamat>";
        $data['items'] = array_fill(0, 35, [
            'title' => 'Pengesan gas mudah alih – model Ω',
            'description' => "Huraian panjang & selamat <untuk XML>\nBaris susulan dengan simbol ©.",
            'item_remarks' => "Periksa sebelum penghantaran\x02",
            'quantity' => 1,
            'marked_up_price' => 10,
            'line_total' => 10,
        ]);

        $path = $this->saveDocument($service->buildDocument($data, $this->request('/word')));

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = (string) $zip->getNameIndex($index);
                    if (! str_ends_with($name, '.xml') && ! str_ends_with($name, '.rels')) {
                        continue;
                    }

                    $xml = $zip->getFromIndex($index);
                    $this->assertIsString($xml, "Unable to read {$name}");
                    $dom = new \DOMDocument;
                    $this->assertTrue(@$dom->loadXML($xml), "Malformed OOXML part: {$name}");
                }
                $documentXml = $zip->getFromName('word/document.xml');
            } finally {
                $zip->close();
            }

            $this->assertIsString($documentXml);
            $this->assertStringContainsString('Syarikat R&amp;D &lt;Selatan&gt;', $documentXml);
            $this->assertStringContainsString('model Ω', $documentXml);
            $this->assertStringNotContainsString("\x01", $documentXml);
            $this->assertStringNotContainsString("\x02", $documentXml);
            $this->assertGreaterThanOrEqual(6, substr_count($documentXml, '<w:br/>'));
            $this->assertStringNotContainsString('<w:tcW w:w="4850" w:type="dxa"/><w:noWrap/>', $documentXml);

            $roundTripped = IOFactory::load($path, 'Word2007');
            $roundTripPath = tempnam(sys_get_temp_dir(), 'equipment_word_roundtrip_');
            $this->assertNotFalse($roundTripPath);
            try {
                IOFactory::createWriter($roundTripped, 'Word2007')->save($roundTripPath);
                $this->assertGreaterThan(0, filesize($roundTripPath));
            } finally {
                unset($roundTripped);
                if (is_string($roundTripPath) && is_file($roundTripPath)) {
                    unlink($roundTripPath);
                }
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_long_item_text_is_split_across_item_rows_without_repeating_item_number(): void
    {
        $service = new EquipmentQuoteRecordWordService(
            Mockery::mock(AuditLogService::class),
            Mockery::mock(EquipmentQuoteDocumentData::class),
        );
        $data = $this->documentData();
        $data['items'] = [[
            'title' => 'Portable Gas Detector',
            'description' => str_repeat('Portable Gas Detector specifications with operational notes, safety advisories, and maintenance guidance. ', 45),
            'item_remarks' => str_repeat('Ensure calibration, battery replacement intervals, and pressure test records are attached before handover. ', 30),
            'quantity' => 1,
            'marked_up_price' => 500,
            'line_total' => 500,
        ]];
        $data['lineItemsTotal'] = 500.0;
        $data['deliveryCharge'] = 0.0;
        $data['miscCharge'] = 0.0;
        $data['discountAmount'] = 0.0;
        $data['subTotalNet'] = 500.0;
        $data['sstAmount'] = 0.0;
        $data['grandTotal'] = 500.0;

        $segments = PdfText::itemCellSegments($data['items'][0]['description'], $data['items'][0]['item_remarks']);
        $this->assertGreaterThan(1, count($segments), 'The seeded item content should produce multiple segments.');

        $path = $this->saveDocument($service->buildDocument($data, $this->request('/word')));
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $document = $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertIsString($document);

            $dom = new \DOMDocument;
            $this->assertTrue(@$dom->loadXML($document), 'Invalid Word document xml.');
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            $itemTable = null;
            foreach ($xpath->query('//w:tbl') as $table) {
                if (! $table instanceof \DOMElement) {
                    continue;
                }
                $headerText = [];
                foreach ($xpath->query('.//w:tr[1]//w:t', $table) as $headerCell) {
                    if ($headerCell instanceof \DOMElement) {
                        $headerText[] = trim($headerCell->textContent);
                    }
                }
                if (in_array('Item Description', $headerText, true) && in_array('Qty', $headerText, true) && in_array('Unit Price (RM)', $headerText, true)) {
                    $itemTable = $table;
                    break;
                }
            }
            $this->assertNotNull($itemTable);

            $numberRows = 0;
            $continuationRows = 0;
            $bodyRows = $xpath->query('.//w:tr[position() > 1]', $itemTable);
            foreach ($bodyRows as $row) {
                if (! $row instanceof \DOMElement) {
                    continue;
                }
                $firstCellValue = '';
                $firstCellNodes = $xpath->query('.//w:tc[1]//w:t', $row);
                if ($firstCellNodes->length > 0) {
                    $firstCellValue = trim((string) $firstCellNodes->item(0)->textContent);
                }
                if ($firstCellValue === '1') {
                    $numberRows++;
                }
                if ($firstCellValue === '') {
                    $continuationRows++;
                }
            }

            $this->assertSame(1, $numberRows);
            $this->assertSame(count($segments) - 1, $continuationRows);
            $this->assertStringContainsString('Portable Gas Detector', $document);
            $this->assertStringContainsString('Description:', $document);
            $this->assertStringContainsString('Remarks:', $document);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_word_text_normalizes_line_endings_and_xml_invalid_characters(): void
    {
        $this->assertSame("A\nB\nC & < > Ω", WordText::clean("A\r\nB\rC\x01 & < > Ω"));
        $this->assertSame(['A', 'B', 'C'], WordText::lines("A\r\nB\rC"));
    }

    public function test_libreoffice_can_convert_the_compatibility_fixture_when_available(): void
    {
        $binary = $this->libreOfficeBinary();
        if ($binary === null) {
            $this->markTestSkipped('LibreOffice is not installed; set SOFFICE_BIN to enable this compatibility gate.');
        }

        $service = new EquipmentQuoteRecordWordService(
            Mockery::mock(AuditLogService::class),
            Mockery::mock(EquipmentQuoteDocumentData::class),
        );
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kijo_word_lo_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory));
        $docxPath = $directory.DIRECTORY_SEPARATOR.'equipment-quotation.docx';

        try {
            IOFactory::createWriter(
                $service->buildDocument($this->documentData(), $this->request('/word')),
                'Word2007',
            )->save($docxPath);
            $process = new Process([
                $binary,
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $directory,
                $docxPath,
            ]);
            $process->setTimeout(60);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
            $pdfPath = $directory.DIRECTORY_SEPARATOR.'equipment-quotation.pdf';
            $this->assertFileExists($pdfPath);
            $this->assertGreaterThan(0, filesize($pdfPath));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_it_rejects_a_missing_quote_id_before_generation(): void
    {
        $service = new EquipmentQuoteRecordWordService(
            Mockery::mock(AuditLogService::class),
            Mockery::mock(EquipmentQuoteDocumentData::class),
        );

        $response = $service->wordEquipment($this->request('/quote-records/equipment/word'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('quote_id is required', $response->getData(true)['message']);
    }

    private function documentData(): array
    {
        return [
            'quoteId' => 68,
            'quoteRefNo' => 'QEQ26-0068TST',
            'revisionNo' => 1,
            'createdDateLegacy' => '13 Aug 2026',
            'createdDateIso' => '2026-08-13',
            'updatedDateIso' => '2026-08-14',
            'clientName' => 'Pelanggan Sdn. Bhd.',
            'clientAddress' => "Jalan Ujian\n43000 Kajang, Selangor",
            'picName' => 'Nur Aisyah',
            'picEmail' => 'aisyah@example.test',
            'picPhone' => '60123456789',
            'quotationRemarks' => 'Deliver before site inspection.',
            'items' => [[
                'title' => 'Portable Gas Detector',
                'description' => 'Detects oxygen and combustible gases.',
                'item_remarks' => 'Include calibration certificate.',
                'quantity' => 2,
                'marked_up_price' => 500,
                'line_total' => 1000,
            ]],
            'lineItemsTotal' => 1000.0,
            'deliveryCharge' => 100.0,
            'miscCharge' => 0.0,
            'discountAmount' => 0.0,
            'subTotalNet' => 1100.0,
            'sstAmount' => 88.0,
            'sstPercentLabel' => '8',
            'grandTotal' => 1188.0,
            'preparedByName' => 'Test Staff',
            'signOffTitle' => 'Business Consultant',
            'terms' => EquipmentQuotationTerms::all(),
        ];
    }

    private function request(string $uri): Request
    {
        $request = Request::create($uri);
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(['staff_id' => 51, 'name_code' => 'TST']);

        return $request;
    }

    private function saveDocument(mixed $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'equipment_word_fixture_');
        $this->assertNotFalse($path);
        IOFactory::createWriter($document, 'Word2007')->save($path);

        return $path;
    }

    private function libreOfficeBinary(): ?string
    {
        $configured = trim((string) getenv('SOFFICE_BIN'));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $names = PHP_OS_FAMILY === 'Windows'
            ? ['soffice.exe', 'libreoffice.exe']
            : ['soffice', 'libreoffice'];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach ($names as $name) {
                $candidate = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (array_filter([getenv('ProgramFiles'), getenv('ProgramFiles(x86)')]) as $programFiles) {
                $candidate = $programFiles.DIRECTORY_SEPARATOR.'LibreOffice'.DIRECTORY_SEPARATOR.'program'.DIRECTORY_SEPARATOR.'soffice.exe';
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
