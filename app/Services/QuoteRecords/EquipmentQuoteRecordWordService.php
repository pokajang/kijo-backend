<?php

namespace App\Services\QuoteRecords;

use App\Services\AuditLogService;
use App\Services\Word\WordRenderer;
use App\Services\Word\WordText;
use App\Support\AppFilePaths;
use App\Support\EquipmentQuotationLayout as Layout;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\ComplexType\TblWidth as ComplexTableWidth;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

class EquipmentQuoteRecordWordService extends WordRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private EquipmentQuoteDocumentData $documentData,
    ) {}

    public function wordEquipment(Request $request, int $id = 0): mixed
    {
        $quoteId = $id > 0 ? $id : (int) $request->query('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'quote_id is required'], 400);
        }

        $data = $this->documentData->find($quoteId);
        if ($data === null) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        $document = $this->buildDocument($data, $request);
        $this->auditLog->log($request, "Generated Equipment quotation Word document for quote ID #{$quoteId}");

        return $this->download(
            $document,
            ($data['quoteRefNo'] ?: 'quote-'.$quoteId).'_'.($data['clientName'] ?: 'client').'.docx',
        );
    }

    public function buildDocument(array $data, Request $request): PhpWord
    {
        $document = $this->createDocument();
        $document->setDefaultFontName('Arial');
        $document->setDefaultFontSize(10);
        $document->setDefaultParagraphStyle([
            'spaceAfter' => Converter::cmToTwip(0.2),
            'lineHeight' => 1.2,
        ]);
        $document->getSettings()->setUpdateFields(true);
        $document->addTableStyle('items', [
            'borderSize' => 4,
            'borderColor' => Layout::COLOR_TABLE_BORDER,
            'cellMargin' => $this->mmToTwip(Layout::TABLE_CELL_PADDING_MM),
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
        ], ['bgColor' => Layout::COLOR_TABLE_HEADER]);
        $document->addTableStyle('acceptance', [
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMargin' => 100,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
        ]);
        $document->addNumberingStyle('equipmentTerms', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'decimal',
                'text' => '%1.',
                'left' => $this->mmToTwip(Layout::WORD_TERMS_TEXT_INDENT_MM),
                'hanging' => $this->mmToTwip(Layout::WORD_TERMS_TEXT_INDENT_MM),
                'tabPos' => $this->mmToTwip(Layout::WORD_TERMS_TEXT_INDENT_MM),
                'suffix' => 'space',
            ]],
        ]);

        $section = $document->addSection([
            'pageSizeW' => $this->mmToTwip(Layout::PAGE_WIDTH_MM),
            'pageSizeH' => $this->mmToTwip(Layout::PAGE_HEIGHT_MM),
            'marginTop' => $this->mmToTwip(Layout::WORD_MARGIN_TOP_MM),
            'marginBottom' => $this->mmToTwip(Layout::MARGIN_BOTTOM_MM),
            'marginLeft' => $this->mmToTwip(Layout::MARGIN_SIDE_MM),
            'marginRight' => $this->mmToTwip(Layout::MARGIN_SIDE_MM),
            'headerHeight' => $this->mmToTwip(Layout::HEADER_DISTANCE_MM),
            'footerHeight' => $this->mmToTwip(Layout::FOOTER_DISTANCE_MM),
        ]);

        $this->addHeader($section);
        $this->addFooter($section, $request);

        if ($request->boolean('approval_preview')) {
            $section->addText('DRAFT - NOT APPROVED', ['bold' => true, 'color' => 'B42318', 'size' => 16], ['alignment' => Jc::CENTER]);
        }

        $quoteReference = WordText::clean($data['quoteRefNo']);
        $dateLine = $data['revisionNo'] > 0
            ? "Quote Number: {$quoteReference} (Rev0{$data['revisionNo']})    Rev. Date: ".WordText::clean($data['updatedDateIso']).'    Ori. Date: '.WordText::clean($data['createdDateIso'])
            : "Quote Number: {$quoteReference}    Date: ".WordText::clean($data['createdDateLegacy']);
        $section->addText($dateLine);

        $attention = $section->addTextRun(['spaceAfter' => 100]);
        $attention->addText('Attention To:', ['bold' => true]);
        $addressLines = WordText::lines($data['clientAddress']);
        $this->addLines($attention, [
            $data['picName'],
            $data['clientName'],
            ...$addressLines,
            'Email: '.WordText::clean($data['picEmail']).'    Phone: '.WordText::clean($data['picPhone']),
        ]);

        $greeting = $section->addTextRun();
        $greeting->addText('Dear ');
        $greeting->addText('Valued Customer', ['bold' => true]);
        $greeting->addText(',');
        $section->addText('Thank you for your interest in the following equipment. Please find below the quotation details.');

        $this->addItemsTable($section, $data);

        $instruction = $section->addTextRun([
            'spaceBefore' => Converter::pointToTwip(Layout::WORD_POST_TABLE_SPACING_PT),
        ]);
        $instruction->addText('Kindly review the terms and conditions outlined in the next page, and ');
        $instruction->addText('return a duly signed copy', ['bold' => true]);
        $instruction->addText(' of this quotation as confirmation of your acceptance.');
        $prepared = $section->addTextRun();
        $prepared->addText('Prepared by: ');
        $prepared->addText(WordText::clean($data['preparedByName']), ['bold' => true]);
        $this->addLines($prepared, [$data['signOffTitle'], 'AMIOSH RESOURCES SDN BHD']);
        $prepared->addTextBreak();
        $prepared->addText('[This is a computer-generated document. No signature required.]', [
            'italic' => true,
            'size' => 8,
            'color' => '666666',
        ]);

        $section->addPageBreak();
        $section->addText(
            'Customer Acceptance',
            ['bold' => true, 'size' => 11],
            ['spaceAfter' => 0, 'lineHeight' => 1.0],
        );
        $section->addText(
            'I/We hereby accept the terms and conditions stated in this quotation and confirm our intention to proceed.',
            null,
            [
                'spaceBefore' => Converter::pointToTwip(Layout::WORD_ACCEPTANCE_INTRO_TOP_SPACING_PT),
                'spaceAfter' => $this->mmToTwip(1) + Converter::pointToTwip(Layout::WORD_ACCEPTANCE_FLOW_OFFSET_PT),
                'lineHeight' => 1.2,
            ],
        );
        $acceptance = $section->addTable('acceptance');
        $acceptance->addRow($this->mmToTwip(Layout::ACCEPTANCE_HEIGHT_MM), [
            'cantSplit' => true,
            'exactHeight' => true,
        ]);
        $halfWidth = (int) round($this->printableWidthTwip() / 2);
        $this->addAcceptanceLines($acceptance->addCell($halfWidth, ['noWrap' => false]), ['', 'Name:', '', 'Position:', '', 'Signature:']);
        $this->addAcceptanceLines($acceptance->addCell($halfWidth, ['noWrap' => false]), ['', 'Company Stamp:', '', 'Date:']);

        $section->addText('Terms and Conditions', ['bold' => true, 'size' => 11], ['spaceBefore' => 200]);
        foreach ($data['terms'] as $term) {
            $section->addListItem(
                WordText::clean($term),
                0,
                null,
                'equipmentTerms',
                ['spaceAfter' => $this->mmToTwip(1.2), 'lineHeight' => 1.2],
            );
        }

        return $document;
    }

    private function addHeader(Section $section): void
    {
        $header = $section->addHeader();
        $table = $header->addTable([
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMarginTop' => 0,
            'cellMarginLeft' => 0,
            'cellMarginRight' => 0,
            'cellMarginBottom' => $this->mmToTwip(1.3),
            'borderBottomSize' => 6,
            'borderBottomColor' => Layout::COLOR_MUTED,
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        [$leftWidth, $rightWidth] = $this->percentageWidths([
            Layout::HEADER_LEFT_PERCENT,
            Layout::HEADER_RIGHT_PERCENT,
        ]);
        $left = $table->addCell($leftWidth, ['noWrap' => false, 'valign' => 'top']);
        $left->addText(Layout::COMPANY_NAME, ['bold' => true, 'color' => Layout::COLOR_MUTED, 'size' => 10], ['spaceAfter' => $this->mmToTwip(1.5), 'lineHeight' => 1.0]);
        $address = $left->addTextRun(['spaceAfter' => $this->mmToTwip(1.5), 'lineHeight' => 1.2]);
        $address->addText(Layout::COMPANY_ADDRESS_LINE_1, ['color' => Layout::COLOR_MUTED, 'size' => 10]);
        $address->addTextBreak();
        $address->addText(Layout::COMPANY_ADDRESS_LINE_2, ['color' => Layout::COLOR_MUTED, 'size' => 10]);
        $left->addText(Layout::COMPANY_CONTACT, ['bold' => true, 'color' => Layout::COLOR_MUTED, 'size' => 10], ['spaceAfter' => 0, 'lineHeight' => 1.0]);
        $right = $table->addCell($rightWidth, ['noWrap' => false, 'valign' => 'top']);
        $logoPath = AppFilePaths::tcpdfTemplatePath('logo.png');
        if (is_file($logoPath) && is_readable($logoPath)) {
            $right->addImage($logoPath, [
                'width' => $this->mmToPoint(Layout::LOGO_WIDTH_MM),
                'alignment' => Jc::RIGHT,
            ]);
        }
        $right->addText(Layout::DOCUMENT_TYPE, [
            'bold' => true,
            'color' => Layout::COLOR_MUTED,
            'size' => 10,
            'spacing' => 0.3,
        ], ['alignment' => Jc::RIGHT, 'spaceBefore' => $this->mmToTwip(2.2), 'spaceAfter' => 0]);
    }

    private function addFooter(Section $section, Request $request): void
    {
        $footer = $section->addFooter();
        $footerWidth = $this->mmToTwip(Layout::PAGE_WIDTH_MM - (Layout::FOOTER_SIDE_MM * 2));
        $table = $footer->addTable([
            'width' => $footerWidth,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMargin' => 0,
            'indent' => new ComplexTableWidth(
                -$this->mmToTwip(Layout::MARGIN_SIDE_MM - Layout::FOOTER_SIDE_MM),
                TblWidth::TWIP,
            ),
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        [$pageWidth, $stampWidth] = $this->percentageWidthsOf($footerWidth, [30, 70]);
        $font = ['italic' => true, 'size' => 8, 'color' => Layout::COLOR_FOOTER];
        $pageRun = $table->addCell($pageWidth, ['noWrap' => false])->addTextRun([
            'alignment' => Jc::LEFT,
            'spaceAfter' => 0,
        ]);
        $pageRun->addText('Page ', $font);
        $pageField = $pageRun->addField('PAGE');
        $pageField->setFontStyle($font);
        $pageRun->addText(' of ', $font);
        $pageCountField = $pageRun->addField('NUMPAGES');
        $pageCountField->setFontStyle($font);

        $stamp = 'Computer generated on: '.now()->format('d M Y, h:i A')
            .' by: '.WordText::clean((string) $request->session()->get('name_code', '-') ?: '-')
            .' ('.WordText::clean($request->session()->get('staff_id', 'Unknown')).')';
        $table->addCell($stampWidth, ['noWrap' => false])->addText(
            $stamp,
            $font,
            ['alignment' => Jc::RIGHT, 'spaceAfter' => 0],
        );
    }

    private function addItemsTable(Section $section, array $data): void
    {
        $table = $section->addTable('items');
        $headers = ['#', 'Item Description', 'Qty', 'Unit Price (RM)', 'Amount (RM)'];
        $widths = $this->percentageWidths(Layout::ITEM_COLUMN_PERCENTAGES);
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headers as $index => $header) {
            $table->addCell($widths[$index], ['noWrap' => false])->addText(
                $header,
                ['bold' => true, 'size' => 10],
                ['alignment' => $index === 1 ? Jc::LEFT : Jc::CENTER, 'spaceAfter' => 0],
            );
        }

        foreach ($data['items'] as $index => $item) {
            $table->addRow();
            $table->addCell($widths[0])->addText((string) ($index + 1), null, ['alignment' => Jc::CENTER]);
            $descriptionCell = $table->addCell($widths[1], ['noWrap' => false]);
            $descriptionCell->addText(
                WordText::clean($item['title']),
                ['bold' => true],
                ['spaceAfter' => 0, 'lineHeight' => 1.0],
            );
            if (trim((string) ($item['description'] ?? '')) !== '') {
                $this->addCellLabelledLines($descriptionCell, 'Description: ', $item['description']);
            }
            if (trim((string) ($item['item_remarks'] ?? '')) !== '') {
                $this->addCellLabelledLines($descriptionCell, 'Remarks: ', $item['item_remarks']);
            }
            $tableParagraph = ['spaceAfter' => 0, 'lineHeight' => 1.0];
            $table->addCell($widths[2])->addText((string) (int) $item['quantity'], null, [...$tableParagraph, 'alignment' => Jc::CENTER]);
            $table->addCell($widths[3])->addText(number_format((float) $item['marked_up_price'], 2), null, [...$tableParagraph, 'alignment' => Jc::RIGHT]);
            $table->addCell($widths[4])->addText(number_format((float) $item['line_total'], 2), null, [...$tableParagraph, 'alignment' => Jc::RIGHT]);
        }

        if ($data['quotationRemarks'] !== '') {
            $table->addRow();
            $cell = $table->addCell(array_sum($widths), ['gridSpan' => 5, 'noWrap' => false]);
            $run = $cell->addTextRun([
                'spaceBefore' => Converter::pointToTwip(Layout::WORD_QUOTATION_REMARKS_TOP_SPACING_PT),
                'spaceAfter' => 0,
                'lineHeight' => 1.0,
            ]);
            $run->addText('Quotation Remarks: ', ['bold' => true]);
            $run->addText(WordText::clean($data['quotationRemarks']));
        }

        $this->addAmountRow($table, 'Amount (RM)', $data['lineItemsTotal']);
        if ($data['deliveryCharge'] > 0) {
            $this->addAmountRow($table, 'Delivery Charge (RM)', $data['deliveryCharge']);
        }
        if ($data['miscCharge'] > 0) {
            $this->addAmountRow($table, 'Miscellaneous Charge (RM)', $data['miscCharge']);
        }
        if ($data['discountAmount'] > 0) {
            $this->addAmountRow($table, 'Discount (RM)', -$data['discountAmount']);
        }
        $this->addAmountRow($table, 'Subtotal (RM)', $data['subTotalNet']);
        if ($data['sstAmount'] > 0) {
            $this->addAmountRow($table, $data['sstPercentLabel'].'% SST Charge (RM)', $data['sstAmount']);
        }
        $this->addAmountRow($table, 'Grand Total (RM)', $data['grandTotal'], true);
    }

    private function addAmountRow(mixed $table, string $label, float $amount, bool $bold = false): void
    {
        $table->addRow();
        $widths = $this->percentageWidths(Layout::ITEM_COLUMN_PERCENTAGES);
        $paragraph = ['alignment' => Jc::RIGHT, 'spaceAfter' => 0, 'lineHeight' => 1.0];
        $table->addCell(array_sum(array_slice($widths, 0, 4)), ['gridSpan' => 4])->addText($label, ['bold' => $bold], $paragraph);
        $prefix = $amount < 0 ? '- RM ' : 'RM ';
        $table->addCell($widths[4])->addText($prefix.number_format(abs($amount), 2), ['bold' => $bold], $paragraph);
    }

    private function addLines(mixed $run, array $lines): void
    {
        foreach ($lines as $line) {
            $run->addTextBreak();
            $run->addText(WordText::clean($line));
        }
    }

    private function addAcceptanceLines(mixed $cell, array $lines): void
    {
        $run = $cell->addTextRun(['spaceAfter' => 0, 'lineHeight' => 1.2]);
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }
            $run->addText($line);
        }
    }

    private function addCellLabelledLines(mixed $cell, string $label, mixed $value): void
    {
        $run = $cell->addTextRun([
            'spaceBefore' => Converter::pointToTwip(1.5),
            'spaceAfter' => 0,
            'lineHeight' => 1.25,
        ]);
        foreach (WordText::lines(trim((string) $value)) as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }
            if ($index === 0) {
                $run->addText($label, ['bold' => true, 'size' => 8.5, 'color' => '444444']);
            }
            $run->addText($line, ['size' => 8.5, 'color' => '666666']);
        }
    }

    /** @param list<int> $percentages
     * @return list<int>
     */
    private function percentageWidths(array $percentages): array
    {
        return $this->percentageWidthsOf($this->printableWidthTwip(), $percentages);
    }

    /**
     * @param  list<int>  $percentages
     * @return list<int>
     */
    private function percentageWidthsOf(int $totalWidth, array $percentages): array
    {
        $widths = array_map(
            static fn (int $percentage): int => (int) round($totalWidth * $percentage / 100),
            $percentages,
        );
        $widths[array_key_last($widths)] += $totalWidth - array_sum($widths);

        return $widths;
    }

    private function printableWidthTwip(): int
    {
        return $this->mmToTwip(Layout::printableWidthMm());
    }

    private function mmToTwip(float $millimetres): int
    {
        return (int) round(Converter::cmToTwip($millimetres / 10));
    }

    private function mmToPoint(float $millimetres): float
    {
        return $millimetres * 72 / 25.4;
    }
}
