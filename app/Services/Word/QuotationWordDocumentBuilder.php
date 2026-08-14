<?php

namespace App\Services\Word;

use App\Support\AppFilePaths;
use App\Support\EquipmentQuotationLayout as Layout;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\ComplexType\TblWidth as ComplexTableWidth;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

final class QuotationWordDocumentBuilder
{
    public function build(array $data, Request $request): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);
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
        $this->addHeader($section);
        $this->addFooter($section, $request);

        if ($request->boolean('approval_preview')) {
            $section->addText('DRAFT - NOT APPROVED', ['bold' => true, 'color' => 'B42318', 'size' => 16], ['alignment' => Jc::CENTER]);
        }

        $this->addQuoteHeading($section, $data);
        $this->addAttention($section, $data);
        $section->addText($data['greeting']);
        $section->addText($data['intro']);

        if (! empty($data['items'])) {
            $this->addItemsTable($section, $data);
        } else {
            $this->addDetailsTable($section, $data['details']);
        }

        $section->addText($data['reviewText'], null, ['spaceBefore' => Converter::pointToTwip(7)]);
        $prepared = $section->addTextRun();
        $prepared->addText($data['labels']['preparedBy'].': ');
        $prepared->addText(WordText::clean($data['preparedByName']), ['bold' => true]);
        foreach ([$data['signOffTitle'], 'AMIOSH RESOURCES SDN BHD'] as $line) {
            $prepared->addTextBreak();
            $prepared->addText(WordText::clean($line));
        }
        $prepared->addTextBreak();
        $prepared->addText($data['computerGeneratedText'], ['italic' => true, 'size' => 8, 'color' => '666666']);

        $section->addPageBreak();
        $this->addAcceptance($section, $data);
        $this->addTerms($section, $data);
        $this->addProposal($section, $data);

        return $document;
    }

    private function registerStyles(PhpWord $document): void
    {
        $table = [
            'borderSize' => 4,
            'borderColor' => Layout::COLOR_TABLE_BORDER,
            'cellMargin' => $this->mm(Layout::TABLE_CELL_PADDING_MM),
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
        ];
        $document->addTableStyle('quotationDetails', $table);
        $document->addTableStyle('quotationItems', $table, ['bgColor' => Layout::COLOR_TABLE_HEADER]);
        $document->addTableStyle('quotationAcceptance', [
            ...$table,
            'borderSize' => 6,
            'borderColor' => '000000',
        ]);
        $document->addNumberingStyle('quotationTerms', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'decimal',
                'text' => '%1.',
                'left' => $this->mm(Layout::WORD_TERMS_TEXT_INDENT_MM),
                'hanging' => $this->mm(Layout::WORD_TERMS_TEXT_INDENT_MM),
                'tabPos' => $this->mm(Layout::WORD_TERMS_TEXT_INDENT_MM),
                'suffix' => 'space',
            ]],
        ]);
        $document->addNumberingStyle('quotationProposalNumber', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'decimal', 'text' => '%1.', 'left' => $this->mm(5),
                'hanging' => $this->mm(2.5), 'tabPos' => $this->mm(5), 'suffix' => 'space',
            ]],
        ]);
        $document->addNumberingStyle('quotationProposalBullet', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'bullet', 'text' => '•', 'left' => $this->mm(5),
                'hanging' => $this->mm(2.5), 'tabPos' => $this->mm(5), 'suffix' => 'space',
            ]],
        ]);
    }

    private function addHeader(Section $section): void
    {
        $table = $section->addHeader()->addTable([
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMarginTop' => 0,
            'cellMarginLeft' => 0,
            'cellMarginRight' => 0,
            'cellMarginBottom' => $this->mm(1.3),
            'borderBottomSize' => 6,
            'borderBottomColor' => Layout::COLOR_MUTED,
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        [$leftWidth, $rightWidth] = $this->widths([68, 32]);
        $left = $table->addCell($leftWidth, ['valign' => 'top']);
        $left->addText(Layout::COMPANY_NAME, ['bold' => true, 'color' => Layout::COLOR_MUTED], ['spaceAfter' => $this->mm(1.5), 'lineHeight' => 1]);
        $address = $left->addTextRun(['spaceAfter' => $this->mm(1.5), 'lineHeight' => 1.2]);
        $address->addText(Layout::COMPANY_ADDRESS_LINE_1, ['color' => Layout::COLOR_MUTED]);
        $address->addTextBreak();
        $address->addText(Layout::COMPANY_ADDRESS_LINE_2, ['color' => Layout::COLOR_MUTED]);
        $left->addText(Layout::COMPANY_CONTACT, ['bold' => true, 'color' => Layout::COLOR_MUTED], ['spaceAfter' => 0]);
        $right = $table->addCell($rightWidth, ['valign' => 'top']);
        $logo = AppFilePaths::tcpdfTemplatePath('logo.png');
        if (is_readable($logo)) {
            $right->addImage($logo, ['width' => Layout::LOGO_WIDTH_MM * 72 / 25.4, 'alignment' => Jc::RIGHT]);
        }
        $right->addText(Layout::DOCUMENT_TYPE, ['bold' => true, 'color' => Layout::COLOR_MUTED], ['alignment' => Jc::RIGHT, 'spaceBefore' => $this->mm(2.2), 'spaceAfter' => 0]);
    }

    private function addFooter(Section $section, Request $request): void
    {
        $width = $this->mm(Layout::PAGE_WIDTH_MM - (Layout::FOOTER_SIDE_MM * 2));
        $table = $section->addFooter()->addTable([
            'width' => $width,
            'unit' => TblWidth::TWIP,
            'layout' => TableStyle::LAYOUT_FIXED,
            'cellMargin' => 0,
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

    private function addQuoteHeading(Section $section, array $data): void
    {
        $ref = WordText::clean($data['quoteRefNo']);
        $text = $data['revisionNo'] > 0
            ? "{$data['labels']['quoteNumber']}: {$ref} (Rev0{$data['revisionNo']})    {$data['labels']['revDate']}: {$data['updatedDateIso']}    {$data['labels']['oriDate']}: {$data['createdDateIso']}"
            : "{$data['labels']['quoteNumber']}: {$ref}    {$data['labels']['date']}: {$data['createdDateLegacy']}";
        $section->addText($text);
    }

    private function addAttention(Section $section, array $data): void
    {
        $run = $section->addTextRun(['spaceAfter' => 100]);
        $run->addText($data['labels']['attentionTo'].':', ['bold' => true]);
        foreach ([$data['picName'], $data['clientName'], ...WordText::lines($data['clientAddress']), "{$data['labels']['email']}: {$data['picEmail']}    {$data['labels']['phone']}: {$data['picPhone']}"] as $line) {
            $run->addTextBreak();
            $run->addText(WordText::clean($line));
        }
    }

    private function addDetailsTable(Section $section, array $rows): void
    {
        $table = $section->addTable('quotationDetails');
        [$labelWidth, $valueWidth] = $this->widths([30, 70]);
        foreach ($rows as $row) {
            if (($row['show'] ?? true) === false) {
                continue;
            }
            $table->addRow(null, ['cantSplit' => true]);
            $table->addCell($labelWidth)->addText(WordText::clean($row['label']), ['bold' => true], ['spaceAfter' => 0]);
            $table->addCell($valueWidth)->addText(WordText::clean($row['value']), $row['bold'] ?? false ? ['bold' => true] : null, ['spaceAfter' => 0]);
        }
    }

    private function addItemsTable(Section $section, array $data): void
    {
        $table = $section->addTable('quotationItems');
        $widths = $this->widths([5, 20, 75]);
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach (['#', $data['labels']['amount'], $data['labels']['lineItem']] as $index => $header) {
            $table->addCell($widths[$index])->addText($header, ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
        $table->addRow(null, ['cantSplit' => true]);
        $table->addCell($widths[0])->addText('');
        $service = $table->addCell($widths[1] + $widths[2], ['gridSpan' => 2])->addTextRun(['spaceAfter' => 0]);
        $service->addText($data['labels']['service'].': ', ['bold' => true]);
        $service->addText(WordText::clean($data['serviceSummary']));
        foreach ($data['items'] as $index => $item) {
            $table->addRow();
            $table->addCell($widths[0])->addText((string) ($index + 1), null, ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $table->addCell($widths[1])->addText(number_format((float) $item['amount'], 2), null, ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]);
            $cell = $table->addCell($widths[2]);
            $cell->addText(WordText::clean($item['title']), ['bold' => true], ['spaceAfter' => 0]);
            if ($item['description'] !== '') {
                $cell->addText($data['labels']['notes'].': '.WordText::clean($item['description']), ['italic' => true, 'size' => 8, 'color' => '666666'], ['spaceAfter' => 0]);
            }
        }
        foreach ($data['totals'] as $row) {
            if (($row['show'] ?? true) === false) {
                continue;
            }
            $table->addRow();
            $table->addCell($widths[0] + $widths[1], ['gridSpan' => 2])->addText($row['label'], ['bold' => $row['bold']], ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]);
            $table->addCell($widths[2])->addText($row['value'], ['bold' => $row['bold']], ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]);
        }
    }

    private function addAcceptance(Section $section, array $data): void
    {
        $section->addText($data['labels']['customerAcceptance'], ['bold' => true, 'size' => 11], ['spaceAfter' => 0]);
        $section->addText($data['acceptanceText'], null, ['spaceBefore' => Converter::pointToTwip(3.8), 'spaceAfter' => $this->mm(1)]);
        $table = $section->addTable('quotationAcceptance');
        $table->addRow($this->mm(30.6), ['cantSplit' => true, 'exactHeight' => true]);
        $width = (int) round($this->printableWidth() / 2);
        $this->acceptanceCell($table->addCell($width), [$data['labels']['name'], $data['labels']['position'], $data['labels']['signature']]);
        $this->acceptanceCell($table->addCell($width), [$data['labels']['companyStamp'], $data['labels']['date']]);
    }

    private function acceptanceCell(mixed $cell, array $labels): void
    {
        $run = $cell->addTextRun(['spaceAfter' => 0, 'lineHeight' => 1.2]);
        $run->addTextBreak();
        foreach ($labels as $label) {
            $run->addText(WordText::clean($label).':');
            $run->addTextBreak(2);
        }
    }

    private function addTerms(Section $section, array $data): void
    {
        $section->addText($data['labels']['terms'], ['bold' => true, 'size' => 11], ['spaceBefore' => 200]);
        foreach ($data['terms'] as $group) {
            if ($group['title'] !== '') {
                $section->addText($group['title'], ['bold' => true, 'size' => 10.5], ['spaceAfter' => $this->mm(1.5), 'keepNext' => true]);
            }
            foreach ($group['items'] as $term) {
                $section->addListItem(WordText::clean($term), 0, null, 'quotationTerms', ['spaceAfter' => $this->mm(1.2), 'lineHeight' => 1.2]);
            }
        }
    }

    private function addProposal(Section $section, array $data): void
    {
        if (empty($data['proposalSections'])) {
            return;
        }
        $section->addPageBreak();
        $section->addText($data['proposalTitle'], ['bold' => true, 'size' => 13, 'color' => '006400'], ['alignment' => Jc::CENTER, 'spaceAfter' => $this->mm(5)]);
        foreach ($data['proposalSections'] as $proposal) {
            $section->addText($proposal['title'], ['bold' => true, 'size' => 11, 'color' => '006400'], ['keepNext' => true, 'spaceAfter' => $this->mm(1.5)]);
            foreach ($this->proposalBlocks($proposal['content']) as $block) {
                if ($block['list'] !== null) {
                    $section->addListItem($block['text'], 0, null, $block['list'], ['spaceAfter' => $this->mm(1)]);
                } else {
                    $section->addText($block['text']);
                }
            }
        }
        foreach ($data['proposalAgenda'] ?? [] as $day => $rows) {
            $section->addText('Day '.$day, ['bold' => true, 'size' => 11], ['keepNext' => true]);
            $table = $section->addTable('quotationDetails');
            [$time, $topic] = $this->widths([25, 75]);
            foreach ($rows as $row) {
                $table->addRow(null, ['cantSplit' => true]);
                $table->addCell($time)->addText($row['time'], null, ['spaceAfter' => 0]);
                $table->addCell($topic)->addText($this->plainRichText($row['topic']), null, ['spaceAfter' => 0]);
            }
        }
    }

    private function plainRichText(string $html): string
    {
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|li|h[1-6]|tr)>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/', ' ', str_replace("\xc2\xa0", ' ', $text)) ?? $text);
    }

    /** @return list<array{text: string, list: ?string}> */
    private function proposalBlocks(string $html): array
    {
        $marked = preg_replace_callback('/<(ol|ul)\b[^>]*>(.*?)<\/\1>/is', function (array $match): string {
            $style = strtolower($match[1]) === 'ol' ? 'quotationProposalNumber' : 'quotationProposalBullet';

            return preg_replace_callback('/<li\b[^>]*>(.*?)<\/li>/is', function (array $item) use ($style): string {
                return "\n[[{$style}]]".$this->plainRichText($item[1])."\n";
            }, $match[2]) ?? $match[2];
        }, $html) ?? $html;
        $marked = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $marked) ?? $marked;
        $marked = preg_replace('/<\/(p|div|h[1-6]|tr)>/i', "\n", $marked) ?? $marked;
        $text = html_entity_decode(strip_tags($marked), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $blocks = [];
        foreach (WordText::lines($text) as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', str_replace("\xc2\xa0", ' ', $line)) ?? $line);
            if ($line === '') {
                continue;
            }
            $list = null;
            if (preg_match('/^\[\[(quotationProposal(?:Number|Bullet))\]\](.*)$/u', $line, $match)) {
                $list = $match[1];
                $line = trim($match[2]);
            }
            if ($line !== '') {
                $blocks[] = ['text' => WordText::clean($line), 'list' => $list];
            }
        }

        return $blocks;
    }

    /** @return list<int> */
    private function widths(array $percentages): array
    {
        $widths = array_map(fn (int $percentage): int => (int) round($this->printableWidth() * $percentage / 100), $percentages);
        $widths[array_key_last($widths)] += $this->printableWidth() - array_sum($widths);

        return $widths;
    }

    private function printableWidth(): int
    {
        return $this->mm(Layout::printableWidthMm());
    }

    private function mm(float $value): int
    {
        return (int) round(Converter::cmToTwip($value / 10));
    }
}
