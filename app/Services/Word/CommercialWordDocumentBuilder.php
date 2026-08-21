<?php

namespace App\Services\Word;

use App\Support\AppFilePaths;
use App\Support\EquipmentQuotationLayout as Layout;
use App\Support\PdfLabels;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\ComplexType\TblWidth as ComplexTableWidth;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

final class CommercialWordDocumentBuilder
{
    public function build(array $data, Request $request): PhpWord
    {
        $document = new PhpWord;
        $document->setDefaultFontName('Arial');
        $document->setDefaultFontSize(10);
        $document->setDefaultParagraphStyle(['spaceAfter' => $this->mm(2), 'lineHeight' => 1.2]);
        $document->getSettings()->setUpdateFields(true);
        $this->registerStyles($document);

        $section = $document->addSection([
            'pageSizeW' => $this->mm(Layout::PAGE_WIDTH_MM),
            'pageSizeH' => $this->mm(Layout::PAGE_HEIGHT_MM),
            'marginTop' => $this->mm(Layout::WORD_MARGIN_TOP_MM),
            'marginBottom' => $this->mm(Layout::MARGIN_BOTTOM_MM),
            'marginLeft' => $this->mm(Layout::MARGIN_SIDE_MM),
            'marginRight' => $this->mm(Layout::MARGIN_SIDE_MM),
            'headerHeight' => $this->mm(Layout::HEADER_DISTANCE_MM),
            'footerHeight' => $this->mm(Layout::FOOTER_DISTANCE_MM),
        ]);
        $this->addHeader($section, $data['documentType']);
        $this->addFooter($section, $request);

        match ($data['kind']) {
            'purchase-order' => $this->addPurchaseOrder($section, $data),
            'delivery-order' => $this->addDeliveryOrder($section, $data),
            'invoice' => $this->addInvoice($section, $data),
            'receipt' => $this->addReceipt($section, $data),
            'letter-of-award' => $this->addLetterOfAward($section, $data),
            default => throw new \InvalidArgumentException('Unsupported commercial document type.'),
        };

        return $document;
    }

    private function registerStyles(PhpWord $document): void
    {
        $table = [
            'borderSize' => 4,
            'borderColor' => '000000',
            'cellMargin' => $this->mm(.9),
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
        ];
        $document->addTableStyle('commercialTable', $table, ['bgColor' => 'F2F2F2']);
        $document->addTableStyle('commercialAcceptance', [...$table, 'borderSize' => 6]);
        $document->addNumberingStyle('commercialTerms', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'decimal',
                'text' => '%1.',
                'left' => $this->mm(6.35),
                'hanging' => $this->mm(3.175),
                'tabPos' => $this->mm(6.35),
                'suffix' => 'space',
            ]],
        ]);
    }

    private function addPurchaseOrder(Section $section, array $data): void
    {
        $section->addText("Our Ref: {$data['reference']}    Date: {$data['date']}");
        $this->addAddressBlock($section, 'Attention To', $data['recipient']);
        $section->addText('Dear '.$data['contactName'].',');
        $section->addText('We are pleased to issue this Purchase Order for the following items under the agreed terms and conditions.');
        $this->addItemsTable($section, $data['items'], ['#', 'Item Description', 'Unit', 'Qty', 'U/P (RM)', 'Total (RM)'], [5, 35, 10, 10, 20, 20]);
        $this->addTotals($section, $data['totals']);
        if ($data['remarks'] !== '') {
            $this->addLabelledText($section, 'Quotation Remarks', $data['remarks']);
        }
        $section->addText('Please review the terms and conditions on the next page and return us a signed copy of this Purchase Order.');
        $this->addSignOff($section, 'Authorized by', $data['preparedBy']);
        $section->addText('Vendor Acknowledgement', ['bold' => true]);
        $section->addText('I hereby acknowledge and accept the terms and conditions set forth in this Purchase Order and shall deliver the items indicated with full responsibility and professionalism.');
        $this->addAcceptanceTable($section, ['Signature', 'Name', 'Position'], ['Company Stamp', 'Date']);
        $section->addPageBreak();
        $section->addText('Terms and Conditions', ['bold' => true, 'size' => 14]);
        foreach ($data['termSections'] as $term) {
            $section->addText($term['heading'], ['bold' => true]);
            if (isset($term['body'])) {
                $section->addText($term['body']);
            }
            foreach ($term['items'] ?? [] as $item) {
                $section->addListItem($item, 0, null, 'commercialTerms');
            }
        }
    }

    private function addDeliveryOrder(Section $section, array $data): void
    {
        $l = fn (string $key, string $fallback): string => PdfLabels::get($data['language'], $key, $fallback);
        $section->addText($l('delivery_order_no', 'Delivery Order No').": {$data['reference']}    ".$l('date', 'Date').": {$data['date']}");
        $table = $section->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'layout' => TableStyle::LAYOUT_FIXED]);
        $table->addRow(null, ['cantSplit' => true]);
        $this->addAddressCell($table->addCell($this->percent(49)), $l('delivered_to', 'Delivered To'), $data['recipient']);
        $table->addCell($this->percent(2));
        $this->addAddressCell($table->addCell($this->percent(49)), $l('delivered_by', 'Delivered By'), $data['sender']);
        $section->addText($data['intro'], null, ['spaceBefore' => $this->mm(3)]);
        $this->addLabelledText($section, $data['projectLabel'], $data['project']);
        $this->addItemsTable($section, $data['items'], ['#', $data['itemLabel'], $l('qty', 'Quantity')], [5, 75, 20]);
        if ($data['remarks'] !== '') {
            $this->addLabelledText($section, $l('quotation_remarks', 'Quotation Remarks'), $data['remarks']);
        }
        $section->addText($data['returnText']);
        $section->addText($data['computerGeneratedText'], ['italic' => true, 'size' => 8, 'color' => '666666']);
        $section->addText($data['acceptanceHeading'], ['bold' => true]);
        $section->addText($data['acceptanceText']);
        $this->addAcceptanceTable($section, $data['acceptanceLeft'], $data['acceptanceRight']);
    }

    private function addInvoice(Section $section, array $data): void
    {
        if (($data['layout'] ?? 'standard') === 'training') {
            $this->addTrainingInvoice($section, $data);

            return;
        }
        $l = fn (string $key, string $fallback): string => PdfLabels::get($data['language'], $key, $fallback);
        $section->addText($l('invoice_number', 'Invoice Number').": {$data['reference']}    ".$l('date', 'Date').": {$data['date']}", ['size' => 11], ['spaceBefore' => $this->mm(3), 'spaceAfter' => $this->mm(2)]);
        $this->addAddressBlock($section, $data['attentionLabel'] ?? $l('attention_to', 'Attention To'), $data['recipient'], ['size' => 11]);
        $greeting = $section->addTextRun(['spaceAfter' => $this->mm(2)]);
        $greeting->addText(($data['greetingPrefix'] ?? ($data['language'] === 'ms-MY' ? 'Kepada' : 'Dear')).' ', ['size' => 11]);
        $greeting->addText($data['greetingName'] ?? $l('dear_valued_customer', 'Valued Customer'), ['bold' => true, 'size' => 11]);
        $greeting->addText(',', ['size' => 11]);
        $section->addText($data['intro'], ['size' => 11], ['lineHeight' => 1.4, 'spaceAfter' => $this->mm(3)]);
        $this->addInvoiceItemsTable($section, $data, ['#', $l('description', 'Description'), 'U/P (RM)', $l('qty', 'Qty'), $l('unit', 'Unit'), $l('subtotal_rm', 'Subtotal (RM)')]);
        $this->addTotals($section, $data['totals']);
        if ($data['remarks'] !== '') {
            $section->addText($l('quotation_remarks', 'Quotation Remarks').':', ['bold' => true], ['spaceBefore' => $this->mm(3), 'spaceAfter' => 0]);
            foreach (preg_split('/\R/u', WordText::clean($data['remarks'])) ?: [] as $line) {
                $section->addText($line, null, ['spaceAfter' => 0]);
            }
        }
        $this->addPaymentDetails($section, $data);
        $this->addInvoiceSignOff($section, $data);
        $section->addText($data['termsHeading'], ['bold' => true, 'size' => 11], ['spaceBefore' => $this->mm(3), 'keepNext' => true]);
        foreach ($data['terms'] as $term) {
            $section->addListItem(WordText::clean($term), 0, ['size' => 9], 'commercialTerms', ['spaceAfter' => 0, 'lineHeight' => 1.2]);
        }
    }

    private function addTrainingInvoice(Section $section, array $data): void
    {
        $l = fn (string $key, string $fallback): string => PdfLabels::get($data['language'], $key, $fallback);
        $section->addText($l('invoice_number', 'Invoice Number').": {$data['reference']}    ".$l('date', 'Date').": {$data['date']}", ['size' => 11], ['spaceBefore' => $this->mm(3), 'spaceAfter' => $this->mm(2)]);
        $this->addAddressBlock($section, $data['attentionLabel'], $data['recipient'], ['size' => 11]);
        $greeting = $section->addTextRun(['spaceAfter' => $this->mm(2)]);
        $greeting->addText($data['greetingPrefix'].' ', ['size' => 11]);
        $greeting->addText($data['greetingName'], ['bold' => true, 'size' => 11]);
        $greeting->addText(',', ['size' => 11]);
        $section->addText($data['intro'], ['size' => 11], ['lineHeight' => 1.4, 'spaceAfter' => $this->mm(3)]);

        $table = $section->addTable('commercialTable');
        $widths = array_map(fn (int $value): int => $this->percent($value), [5, 50, 15, 10, 20]);
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach (['#', $l('description', 'Description'), $l('unit_price_rm', 'Unit Price (RM)'), $l('qty', 'Qty'), $l('subtotal_rm', 'Subtotal (RM)')] as $index => $header) {
            $table->addCell($widths[$index])->addText($header, ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
        foreach ($data['items'] as $index => $item) {
            $table->addRow();
            $table->addCell($widths[0])->addText($item['number'], null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $description = $table->addCell($widths[1]);
            $description->addText(WordText::clean($item['name']), null, ['spaceAfter' => 0]);
            foreach ($item['segments'] as $segment) {
                $value = trim((string) ($segment['description'] ?? ''));
                if ($value !== '') {
                    $description->addText(WordText::clean($value), ['size' => 9, 'color' => '555555'], ['spaceAfter' => 0]);
                }
            }
            if ($index === 0) {
                $description->addText('', null, ['spaceAfter' => $this->mm(2)]);
                foreach ($data['trainingDetails'] as $detail) {
                    $run = $description->addTextRun(['spaceAfter' => 0]);
                    $run->addText($detail['label'].': ', ['bold' => true]);
                    $run->addText(WordText::clean($detail['value']));
                }
            }
            foreach (['unitPrice', 'quantity', 'subtotal'] as $column => $key) {
                $table->addCell($widths[$column + 2])->addText(WordText::clean($item[$key]), null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            }
        }
        $this->addTotals($section, $data['totals']);
        $this->addPaymentDetails($section, $data);
        $this->addInvoiceSignOff($section, $data);
        $section->addText($data['termsHeading'], ['bold' => true, 'size' => 11], ['spaceBefore' => $this->mm(3), 'keepNext' => true]);
        foreach ($data['terms'] as $term) {
            $section->addListItem(WordText::clean($term), 0, ['size' => 9], 'commercialTerms', ['spaceAfter' => 0, 'lineHeight' => 1.2]);
        }
    }

    private function addInvoiceItemsTable(Section $section, array $data, array $headers): void
    {
        $table = $section->addTable('commercialTable');
        $widths = array_map(fn (int $value): int => $this->percent($value), [5, 40, 15, 10, 10, 20]);
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headers as $index => $header) {
            $table->addCell($widths[$index])->addText($header, ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }

        $table->addRow(null, ['cantSplit' => true]);
        $table->addCell($widths[0]);
        $serviceCell = $table->addCell(array_sum(array_slice($widths, 1)), ['gridSpan' => 5]);
        foreach ($data['serviceLines'] ?? [$data['service']] as $serviceLine) {
            $serviceCell->addText(WordText::clean($serviceLine), null, ['spaceAfter' => 0]);
        }

        foreach ($data['items'] as $item) {
            if (! is_array($item) || ! array_key_exists('segments', $item)) {
                $this->addLegacyInvoiceItemRow($table, $widths, $item);
                continue;
            }
            foreach ($item['segments'] as $segmentIndex => $segment) {
                $table->addRow();
                $table->addCell($widths[0])->addText($segmentIndex === 0 ? $item['number'] : '', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                $description = $table->addCell($widths[1]);
                if (($item['manpowerBase'] ?? false) === true) {
                    $description->addText(WordText::clean($item['claimLabel']), null, ['spaceAfter' => 0]);
                    $run = $description->addTextRun(['spaceAfter' => 0]);
                    $run->addText(PdfLabels::get($data['language'], 'remarks', 'Remarks').': ', ['italic' => true]);
                    $run->addText(WordText::clean($item['invoiceRemarks']));
                } elseif ($segmentIndex === 0) {
                    $description->addText(WordText::clean($item['name']) ?: '-', ['bold' => true], ['spaceAfter' => 0]);
                }
                if (($item['manpowerBase'] ?? false) !== true) {
                    $this->addInvoiceDescriptionSegment($description, $segment, $data['language']);
                }
                foreach (['unitPrice', 'quantity', 'unit', 'subtotal'] as $column => $key) {
                    $table->addCell($widths[$column + 2])->addText($segmentIndex === 0 ? WordText::clean($item[$key]) : '', null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
                }
            }
        }
    }

    private function addLegacyInvoiceItemRow($table, array $widths, array $item): void
    {
        $table->addRow();
        foreach ($item as $index => $value) {
            $cell = $table->addCell($widths[$index]);
            if (is_array($value)) {
                foreach ($value as $lineIndex => $line) {
                    $cell->addText(WordText::clean($line), $lineIndex === 0 ? ['bold' => true] : null, ['spaceAfter' => 0]);
                }
                continue;
            }
            $cell->addText(WordText::clean($value), null, ['alignment' => $index === 1 ? Jc::LEFT : Jc::CENTER, 'spaceAfter' => 0]);
        }
    }

    private function addInvoiceDescriptionSegment(Cell $cell, array $segment, string $language): void
    {
        foreach ([['description', 'show_description_label', 'description', 'Description'], ['remarks', 'show_remarks_label', 'remarks', 'Remarks']] as [$valueKey, $showKey, $labelKey, $fallback]) {
            $value = trim((string) ($segment[$valueKey] ?? ''));
            if ($value === '') {
                continue;
            }
            $run = $cell->addTextRun(['spaceAfter' => 0, 'lineHeight' => 1.25]);
            if (($segment[$showKey] ?? false) === true) {
                $run->addText(PdfLabels::get($language, $labelKey, $fallback).': ', ['bold' => true, 'size' => 8.5, 'color' => '444444']);
            }
            $run->addText(WordText::clean($value), ['size' => 8.5, 'color' => '666666']);
        }
    }

    private function addPaymentDetails(Section $section, array $data): void
    {
        if (! isset($data['paymentDetails'])) {
            foreach ($data['paymentLines'] as $line) {
                $section->addText($line);
            }

            return;
        }
        $section->addText($data['paymentLines'][0], ['size' => 11], ['spaceBefore' => $this->mm(3), 'spaceAfter' => 0]);
        foreach ($data['paymentDetails'] as $detail) {
            $run = $section->addTextRun(['spaceAfter' => 0]);
            $run->addText($detail['label'].': ', ['bold' => true, 'size' => 11]);
            $run->addText($detail['value'], ['size' => 11]);
            if (isset($detail['suffix'])) {
                [$label, $value] = explode(': ', ltrim($detail['suffix']), 2);
                $run->addText('    '.$label.': ', ['bold' => true, 'size' => 11]);
                $run->addText($value, ['size' => 11]);
            }
        }
    }

    private function addReceipt(Section $section, array $data): void
    {
        $l = fn (string $key, string $fallback): string => PdfLabels::get($data['language'], $key, $fallback);
        $section->addText($l('receipt_number', 'Receipt Number').": {$data['reference']}    ".$l('date', 'Date').": {$data['date']}");
        $this->addAddressBlock($section, $l('billed_to', 'Billed To'), $data['recipient']);
        $this->addLabelledText($section, $l('for_invoice', 'For invoice'), $data['invoiceReference'].' — '.$data['service']);
        $this->addItemsTable($section, $data['items'], ['#', $l('description', 'Description'), $l('unit_price_rm', 'Unit Price (RM)'), $l('qty', 'Qty'), $l('unit', 'Unit'), $l('subtotal_rm', 'Subtotal (RM)')], [5, 40, 15, 10, 10, 20]);
        $this->addTotals($section, $data['totals']);
        if ($data['remarks'] !== '') {
            $this->addLabelledText($section, $l('quotation_remarks', 'Quotation Remarks'), $data['remarks']);
        }
        $section->addText(PdfLabels::get($data['language'], 'receipt_thanks', 'Thank you for your payment. We are keen to serve you again.'));
        $section->addText(PdfLabels::get($data['language'], 'computer_generated', '[This is a computer-generated document. No signature is required from us.]'), ['italic' => true, 'size' => 8, 'color' => '666666']);
        $section->addText('“An ounce of prevention is worth a pound of cure.”', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceBefore' => $this->mm(4)]);
    }

    private function addLetterOfAward(Section $section, array $data): void
    {
        $section->addText("Our Ref: {$data['reference']}    Date: {$data['date']}");
        $this->addAddressBlock($section, 'Attention To', $data['recipient']);
        $section->addText('Dear '.$data['contactName'].',');
        $section->addText('We are pleased to inform you that AMIOSH RESOURCES SDN BHD hereby awards the contract for the following services under the terms outlined below.');

        $table = $section->addTable('commercialTable');
        foreach ($data['awardDetails'] as $detail) {
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell($this->percent(30))->addText($detail['label'], ['bold' => true], ['spaceAfter' => 0]);
            $this->addMultilineCell($table->addCell($this->percent(70)), $detail['value'], $detail['bold'] ?? false);
        }

        $section->addText('Please review the terms and conditions on the following page and return us a signed copy of this contract.', null, ['spaceBefore' => $this->mm(4)]);
        $this->addSignOff($section, 'With best wishes', ['Muhammad Amin Bin Rozak', 'Managing Director', 'AMIOSH RESOURCES SDN BHD']);
        $section->addText('Vendor Acknowledgement', ['bold' => true, 'size' => 10.5], ['spaceBefore' => $this->mm(5), 'spaceAfter' => $this->mm(2)]);
        $section->addText('I hereby acknowledge and accept the terms and conditions set forth in this Letter of Award and shall deliver the services indicated with full responsibility and professionalism.');
        $this->addAcceptanceTable($section, ['Signature', 'Name'], ['NRIC Number', 'Date']);

        $section->addPageBreak();
        $section->addText('Terms and Conditions', ['bold' => true, 'size' => 11], ['spaceAfter' => $this->mm(3)]);
        foreach ($data['termSections'] as $index => $term) {
            $section->addText($term['heading'], ['bold' => true], ['keepNext' => true, 'spaceBefore' => $index === 0 ? 0 : $this->mm(4), 'spaceAfter' => $this->mm(2)]);
            foreach ($term['paragraphs'] ?? [] as $paragraph) {
                $section->addText(WordText::clean($paragraph), null, ['spaceAfter' => $this->mm(2)]);
            }
            foreach ($term['items'] ?? [] as $item) {
                $section->addListItem(WordText::clean($item), 0, null, 'commercialTerms', ['spaceAfter' => $this->mm(1.5), 'lineHeight' => 1.2]);
            }
        }
    }

    private function addItemsTable(Section $section, array $items, array $headers, array $percentages): void
    {
        $table = $section->addTable('commercialTable');
        $widths = array_map(fn (int $value): int => $this->percent($value), $percentages);
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headers as $index => $header) {
            $table->addCell($widths[$index])->addText($header, ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
        foreach ($items as $item) {
            if (is_array($item) && array_key_exists('segments', $item)) {
                $description = [WordText::clean($item['name']) ?: '-'];
                foreach ($item['segments'] as $segment) {
                    if (trim((string) ($segment['description'] ?? '')) !== '') {
                        $description[] = PdfLabels::get('', 'description', 'Description').': '.WordText::clean($segment['description']);
                    }
                    if (trim((string) ($segment['remarks'] ?? '')) !== '') {
                        $description[] = PdfLabels::get('', 'remarks', 'Remarks').': '.WordText::clean($segment['remarks']);
                    }
                }
                $item = [$item['number'], $description, $item['unitPrice'], $item['quantity'], $item['unit'], $item['subtotal']];
            }
            $table->addRow();
            foreach ($item as $index => $value) {
                $cell = $table->addCell($widths[$index]);
                if (is_array($value)) {
                    foreach ($value as $lineIndex => $line) {
                        $cell->addText(WordText::clean($line), $lineIndex === 0 ? ['bold' => true] : null, ['spaceAfter' => 0]);
                    }
                } else {
                    $cell->addText(WordText::clean($value), null, ['alignment' => $index === 1 ? Jc::LEFT : Jc::CENTER, 'spaceAfter' => 0]);
                }
            }
        }
    }

    private function addTotals(Section $section, array $totals): void
    {
        $table = $section->addTable('commercialTable');
        foreach ($totals as $total) {
            if (($total['show'] ?? true) === false) {
                continue;
            }
            $table->addRow(null, ['cantSplit' => true]);
            $shade = ($total['shade'] ?? false) ? ['bgColor' => 'F9F9F9'] : [];
            $table->addCell($this->percent(80), $shade)->addText($total['label'], ['bold' => true], ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]);
            $table->addCell($this->percent(20), $shade)->addText(number_format((float) $total['value'], 2), ['bold' => $total['bold'] ?? false], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
    }

    private function addAddressBlock(Section $section, string $label, array $lines, ?array $font = null): void
    {
        $run = $section->addTextRun(['spaceAfter' => $this->mm(3)]);
        $run->addText($label.':', ['bold' => true, ...($font ?? [])]);
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $run->addTextBreak();
            $run->addText(WordText::clean($line), $font);
        }
    }

    private function addAddressCell(Cell $cell, string $label, array $lines): void
    {
        $cell->addText($label.':', ['bold' => true], ['spaceAfter' => 0]);
        foreach ($lines as $line) {
            $cell->addText(WordText::clean($line), null, ['spaceAfter' => 0]);
        }
    }

    private function addMultilineCell(Cell $cell, string $value, bool $bold = false): void
    {
        $lines = preg_split('/\R/u', WordText::clean($value)) ?: [];
        foreach ($lines ?: ['-'] as $line) {
            $cell->addText($line, $bold ? ['bold' => true] : null, ['spaceAfter' => 0, 'lineHeight' => 1.2]);
        }
    }

    private function addLabelledText(Section $section, string $label, string $value): void
    {
        $run = $section->addTextRun();
        $run->addText($label.': ', ['bold' => true]);
        $run->addText(WordText::clean($value));
    }

    private function addSignOff(Section $section, string $label, array $lines): void
    {
        $run = $section->addTextRun();
        $run->addText($label.',');
        foreach ($lines as $index => $line) {
            $run->addTextBreak();
            $run->addText(WordText::clean($line), $index === 0 ? ['bold' => true] : null);
        }
        $run->addTextBreak();
        $run->addText('[This is a computer-generated document. No signature is required from us.]', ['italic' => true, 'size' => 8, 'color' => '666666']);
    }

    private function addAcceptanceTable(Section $section, array $leftLabels, array $rightLabels): void
    {
        $table = $section->addTable('commercialAcceptance');
        $table->addRow($this->mm(30), ['cantSplit' => true]);
        $left = $table->addCell($this->percent(50), ['valign' => 'top'])->addTextRun(['spaceAfter' => 0]);
        $left->addTextBreak();
        foreach ($leftLabels as $label) {
            $left->addText(WordText::clean($label).':');
            $left->addTextBreak(2);
        }
        $right = $table->addCell($this->percent(50), ['valign' => 'top'])->addTextRun(['spaceAfter' => 0]);
        $right->addTextBreak();
        foreach ($rightLabels as $label) {
            $right->addText(WordText::clean($label).':');
            $right->addTextBreak(2);
        }
    }

    private function addInvoiceSignOff(Section $section, array $data): void
    {
        $table = $section->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'layout' => TableStyle::LAYOUT_FIXED]);
        $table->addRow(null, ['cantSplit' => true]);
        $left = $table->addCell($this->percent(50));
        $left->addText($data['preparedByLabel'].':', null, ['spaceAfter' => 0]);
        foreach ($data['preparedBy'] as $index => $line) {
            $left->addText(WordText::clean($line), $index === 0 ? ['bold' => true] : null, ['spaceAfter' => 0]);
        }
        $right = $table->addCell($this->percent(50), ['valign' => 'center']);
        $hasAsset = false;
        foreach ([[$data['signaturePath'], 22], [$data['stampPath'], 40]] as [$path, $width]) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                $right->addImage($path, ['width' => $width * 72 / 25.4, 'alignment' => Jc::RIGHT]);
                $hasAsset = true;
            }
        }
        if (! $hasAsset) {
            $right->addText($data['noSignatureText'], ['italic' => true, 'size' => 8, 'color' => '666666'], ['alignment' => Jc::RIGHT]);
        }
    }

    private function addHeader(Section $section, string $documentType): void
    {
        $table = $section->addHeader()->addTable([
            'width' => 5000, 'unit' => TblWidth::PERCENT, 'layout' => TableStyle::LAYOUT_FIXED,
            'cellMarginBottom' => $this->mm(1.3), 'borderBottomSize' => 6, 'borderBottomColor' => Layout::COLOR_MUTED,
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        $left = $table->addCell($this->percent(68));
        $left->addText(Layout::COMPANY_NAME, ['bold' => true, 'color' => Layout::COLOR_MUTED], ['spaceAfter' => $this->mm(1.5)]);
        $address = $left->addTextRun(['lineHeight' => 1.2, 'spaceAfter' => $this->mm(1.5)]);
        $address->addText(Layout::COMPANY_ADDRESS_LINE_1, ['color' => Layout::COLOR_MUTED]);
        $address->addTextBreak();
        $address->addText(Layout::COMPANY_ADDRESS_LINE_2, ['color' => Layout::COLOR_MUTED]);
        $left->addText(Layout::COMPANY_CONTACT, ['bold' => true, 'color' => Layout::COLOR_MUTED], ['spaceAfter' => 0]);
        $right = $table->addCell($this->percent(32));
        $logo = AppFilePaths::tcpdfTemplatePath('logo.png');
        if (is_readable($logo)) {
            $right->addImage($logo, ['width' => Layout::LOGO_WIDTH_MM * 72 / 25.4, 'alignment' => Jc::RIGHT]);
        }
        $right->addText($documentType, ['bold' => true, 'color' => Layout::COLOR_MUTED], ['alignment' => Jc::RIGHT, 'spaceBefore' => $this->mm(2.2), 'spaceAfter' => 0]);
    }

    private function addFooter(Section $section, Request $request): void
    {
        $width = $this->mm(Layout::PAGE_WIDTH_MM - (Layout::FOOTER_SIDE_MM * 2));
        $table = $section->addFooter()->addTable([
            'width' => $width, 'unit' => TblWidth::TWIP, 'layout' => TableStyle::LAYOUT_FIXED, 'cellMargin' => 0,
            'indent' => new ComplexTableWidth(-$this->mm(Layout::MARGIN_SIDE_MM - Layout::FOOTER_SIDE_MM), TblWidth::TWIP),
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        $font = ['italic' => true, 'size' => 8, 'color' => Layout::COLOR_FOOTER];
        $left = $table->addCell((int) round($width * .3))->addTextRun(['spaceAfter' => 0]);
        $left->addText('Page ', $font);
        $left->addField('PAGE')->setFontStyle($font);
        $left->addText(' of ', $font);
        $left->addField('NUMPAGES')->setFontStyle($font);
        $stamp = 'Computer generated on: '.now()->format('d M Y, h:i A').' by: '
            .WordText::clean((string) ($request->session()->get('name_code', '-') ?: '-')).' ('
            .WordText::clean($request->session()->get('staff_id', 'Unknown')).')';
        $table->addCell((int) round($width * .7))->addText($stamp, $font, ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]);
    }

    private function percent(int $value): int
    {
        return (int) round($this->mm(Layout::printableWidthMm()) * $value / 100);
    }

    private function mm(float $value): int
    {
        return (int) round(Converter::cmToTwip($value / 10));
    }
}
