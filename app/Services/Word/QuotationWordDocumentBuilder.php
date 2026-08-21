<?php

namespace App\Services\Word;

use App\Support\AppFilePaths;
use App\Support\EquipmentQuotationLayout as Layout;
use Illuminate\Http\Request;
use DOMDocument;
use DOMElement;
use DOMNode;
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
            'levels' => $this->proposalListLevels('decimal', '%1.'),
        ]);
        $document->addNumberingStyle('quotationProposalBullet', [
            'type' => 'multilevel',
            'levels' => [[
                'format' => 'bullet', 'text' => '•', 'left' => $this->mm(5),
                'hanging' => $this->mm(2.5), 'tabPos' => $this->mm(5), 'suffix' => 'space',
            ]],
        ]);
        $document->addNumberingStyle('quotationProposalBulletNative', [
            'type' => 'multilevel',
            'levels' => $this->proposalListLevels('bullet', "\u{2022}"),
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
            $this->addMultilineText($table->addCell($labelWidth), $row['label'], ['bold' => true]);
            $this->addMultilineText($table->addCell($valueWidth), $row['value'], ($row['bold'] ?? false) ? ['bold' => true] : null);
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
        if (empty($data['proposalSections']) && empty($data['proposalCompanyServices']) && empty($data['proposalAdditionalSections']) && empty($data['proposalAgenda']) && empty($data['proposalTentativeTerms'])) {
            return;
        }
        $section->addPageBreak();
        $service = (string) ($data['service'] ?? '');
        $this->addProposalTitleBanner($section, $data, $service);
        if (! empty($data['proposalCompanyServices'])) {
            $this->addProposalCompanyServices($section, (array) $data['proposalCompanyServices']);
        }
        $hasProposalContent = ! empty($data['proposalCompanyServices']);
        foreach ($data['proposalSections'] ?? [] as $proposal) {
            $this->addProposalSection($section, $proposal, $service, $hasProposalContent);
            $hasProposalContent = true;
        }
        if (! empty($data['proposalAdditionalSections'])) {
            $section->addPageBreak();
            $hasProposalContent = false;
            foreach ($data['proposalAdditionalSections'] as $proposal) {
                $this->addProposalSection($section, $proposal, $service, $hasProposalContent);
                $hasProposalContent = true;
            }
        }

        if ($service !== 'training') {
            return;
        }
        foreach ($data['proposalAgenda'] ?? [] as $day => $rows) {
            if ($day === array_key_first($data['proposalAgenda'])) {
                $section->addText($data['language'] === 'ms-MY' ? 'Tentatif Program' : 'Program Tentative', ['bold' => true, 'size' => 11, 'color' => '006400'], ['keepNext' => true, 'spaceBefore' => $this->mm(1), 'spaceAfter' => $this->mm(1.8)]);
            }
            if (count($data['proposalAgenda']) > 1) {
                $section->addText(($data['language'] === 'ms-MY' ? 'Hari ' : 'Day ').$day, ['bold' => true, 'size' => 11], ['keepNext' => true, 'spaceAfter' => $this->mm(1.5)]);
            }
            $table = $section->addTable('quotationDetails');
            [$time, $topic] = $this->widths([25, 75]);
            foreach ($rows as $row) {
                $table->addRow(null, ['cantSplit' => true]);
                $table->addCell($time)->addText($row['time'], null, ['spaceAfter' => 0]);
                $this->addMultilineText($table->addCell($topic), $this->plainRichText($row['topic']));
            }
        }
        if (! empty($data['proposalTentativeTerms'])) {
            $section->addText($data['proposalTentativeTermsTitle'], ['bold' => true, 'size' => 10.5], ['keepNext' => true, 'spaceBefore' => $this->mm(2), 'spaceAfter' => $this->mm(2)]);
            foreach ($data['proposalTentativeTerms'] as $term) {
                $section->addListItem(WordText::clean($term), 0, null, 'quotationProposalNumber', ['spaceAfter' => $this->mm(.8), 'lineHeight' => 1.35]);
            }
        }
    }

    private function addProposalTitleBanner(Section $section, array $data, string $service): void
    {
        if ($service === 'training') {
            $this->addTrainingProposalTitle($section, $data);
            return;
        }
        $title = trim((string) ($data['proposalTitle'] ?? ''));
        if ($title === '') {
            return;
        }

        $table = $section->addTable([
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
            'borderSize' => 5,
            'borderColor' => $service === 'manpower' ? 'C8FFC8' : 'C8F0C8',
            'cellMarginTop' => $this->mm(3),
            'cellMarginBottom' => $this->mm(3),
            'cellMarginLeft' => $this->mm(4),
            'cellMarginRight' => $this->mm(4),
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        $table->addCell($this->printableWidth(), ['bgColor' => 'F0FFF0'])->addText($title, ['bold' => true, 'size' => 13, 'color' => '003C00'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'lineHeight' => 1.2]);
        $section->addText('', null, ['spaceAfter' => $service === 'manpower' ? $this->mm(6) : $this->mm(5)]);
    }

    private function addProposalSection(
        Section $section,
        array $proposal,
        string $service,
        bool $hasPreviousContent,
    ): void
    {
        if (! isset($proposal['title'], $proposal['content'])) {
            return;
        }
        $title = trim((string) $proposal['title']);
        $content = (string) $proposal['content'];
        if ($title === '' && trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '') {
            return;
        }
        if ($title !== '') {
            $section->addText($title, ['bold' => true, 'size' => 11, 'color' => $this->proposalSectionColor($service)], [
                'keepNext' => true,
                'spaceBefore' => $hasPreviousContent ? $this->proposalSectionSpacing($service) : 0,
                'spaceAfter' => $this->proposalSectionTitleSpacing($service),
            ]);
        }
        $this->addProposalContent($section, $content, $service);
    }

    private function addProposalContent(Section $section, string $html, string $service): void
    {
        if (trim(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '') {
            return;
        }
        $normalized = $this->normalizeProposalHtml($html);
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$normalized.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        if (! $loaded) {
            $this->addMultilineText($section, $this->plainRichText($html));
            return;
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            $this->addMultilineText($section, $this->plainRichText($html));
            return;
        }
        foreach ($body->childNodes as $node) {
            $this->renderProposalNode($section, $node, $service);
        }
    }

    private function addProposalCompanyServices(Section $section, array $payload): void
    {
        $section->addText($payload['title'] ?? 'About AMIOSH', ['bold' => true, 'size' => 10.5, 'color' => '003C00'], ['spaceAfter' => $this->mm(1)]);
        $section->addText($payload['description'] ?? '', null, ['spaceAfter' => $this->mm(.5)]);
        if (($payload['heading'] ?? '') !== '') {
            $section->addText($payload['heading'], ['bold' => true, 'size' => 9.5], ['spaceAfter' => $this->mm(.5)]);
        }
        foreach ($payload['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            if ($title === '') {
                continue;
            }
            $run = $section->addTextRun(['spaceAfter' => 0]);
            if ($description === '') {
                $run->addText(WordText::clean($title), ['bold' => true]);
            } else {
                $run->addText(WordText::clean($title).': ', ['bold' => true]);
                $run->addText(WordText::clean($description));
            }
            $section->addTextBreak();
        }
        if (($payload['ctaText'] ?? '') !== '') {
            $section->addText(WordText::clean($payload['ctaText']).' '.WordText::clean($payload['ctaLabel'] ?? $payload['ctaUrl'] ?? ''), ['italic' => true, 'size' => 9.5], ['spaceAfter' => $this->mm(4)]);
        }
    }

    private function renderProposalNode(Section $section, DOMNode $node, string $service, int $level = 0): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $this->plainRichText((string) $node->textContent);
            if ($text !== '') {
                $this->addMultilineText($section, $text);
            }
            return;
        }
        if (! $node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $text = $this->plainRichText($node->textContent);
            if ($text !== '') {
                $section->addText(WordText::clean($text), ['bold' => true, 'size' => 11], ['spaceAfter' => $this->mm(1)]);
            }
            return;
        }
        if ($tag === 'p' || $tag === 'div') {
            $text = $this->plainRichText($node->textContent);
            if ($text !== '') {
                $this->addMultilineText($section, $text);
            }
            return;
        }
        if ($tag === 'ul' || $tag === 'ol') {
            $this->addProposalList($section, $node, $tag === 'ol', $level);
            return;
        }
        if ($tag === 'table') {
            $this->addProposalTable($section, $node);
            return;
        }
        if ($tag === 'li') {
            $text = $this->plainRichText($node->textContent);
            if ($text !== '') {
                $this->addMultilineText($section, $text);
            }
            return;
        }
        foreach ($node->childNodes as $child) {
            $this->renderProposalNode($section, $child, $service, $level);
        }
    }

    private function addProposalList(Section $section, DOMNode $node, bool $ordered, int $level = 0): void
    {
        $listStyle = $ordered ? 'quotationProposalNumber' : 'quotationProposalBulletNative';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }
            $text = $this->plainNodeText($child);
            $text = $this->plainRichText($text);
            if ($text !== '') {
                $section->addListItem(WordText::clean($text), $level, null, $listStyle, ['spaceAfter' => $this->mm(1.2), 'lineHeight' => 1.2]);
            }
            foreach ($child->childNodes as $subNode) {
                if ($subNode instanceof DOMElement && in_array(strtolower($subNode->nodeName), ['ol', 'ul'], true)) {
                    $this->addProposalList($section, $subNode, strtolower($subNode->nodeName) === 'ol', $level + 1);
                }
            }
        }
    }

    private function addProposalTable(Section $section, DOMElement $table): void
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = $this->plainNodeText($td);
            }
            if ($cells === []) {
                foreach ($tr->getElementsByTagName('th') as $th) {
                    $cells[] = $this->plainNodeText($th);
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return;
        }
        $colCount = max(array_map('count', $rows));
        $widths = array_fill(0, $colCount, intdiv(100, $colCount));
        $widths = $this->widths($widths);
        $tableStyle = [
            'borderColor' => Layout::COLOR_TABLE_BORDER,
            'borderSize' => 4,
            'cellMargin' => $this->mm(0.8),
            'unit' => TblWidth::PERCENT,
            'width' => $this->printableWidth(),
            'layout' => TableStyle::LAYOUT_FIXED,
        ];
        $tableElement = $section->addTable($tableStyle);
        foreach ($rows as $row) {
            $tableElement->addRow();
            foreach ($widths as $index => $widthPercent) {
                $cellText = $this->plainRichText((string) ($row[$index] ?? ''));
                $tableElement->addCell($widthPercent)->addText($cellText, null, ['spaceAfter' => 0]);
            }
        }
    }

    /** @return list<array{format: string, text: string, left: int, hanging: int, tabPos: int, suffix: string}> */
    private function proposalListLevels(string $format, string $text): array
    {
        return array_map(
            fn (float $left): array => [
                'format' => $format,
                'text' => $text,
                'left' => $this->mm($left),
                'hanging' => $this->mm(3.175),
                'tabPos' => $this->mm($left),
                'suffix' => 'space',
            ],
            [6.35, 12.7, 19.05],
        );
    }

    private function proposalSectionColor(string $service): string
    {
        return $service === 'manpower' ? '003C00' : '006400';
    }

    private function proposalSectionSpacing(string $service): int
    {
        return match ($service) {
            'manpower' => $this->mm(4),
            'ih' => $this->mm(3.2),
            default => $this->mm(3.2),
        };
    }

    private function proposalSectionTitleSpacing(string $service): int
    {
        return $service === 'manpower' ? $this->mm(1) : $this->mm(1.5);
    }

    private function normalizeProposalHtml(string $html): string
    {
        return str_replace("\xC2\xA0", ' ', $html);
    }

    private function addTrainingProposalTitle(Section $section, array $data): void
    {
        $title = trim((string) $data['proposalTitle']);
        $brochure = $data['language'] === 'ms-MY' ? 'Brosur Latihan' : 'Training Brochure';
        if (! str_ends_with(mb_strtolower($title), mb_strtolower($brochure))) {
            $title = trim($title.' '.$brochure);
        }
        $table = $section->addTable([
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
            'borderSize' => 5,
            'borderColor' => 'C8F0C8',
            'cellMarginTop' => $this->mm(3),
            'cellMarginBottom' => $this->mm(3),
            'cellMarginLeft' => $this->mm(4),
            'cellMarginRight' => $this->mm(4),
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        $table->addCell($this->printableWidth(), ['bgColor' => 'F0FFF0'])->addText($title, ['bold' => true, 'size' => 13, 'color' => '003C00'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'lineHeight' => 1.2]);
        $section->addText('', null, ['spaceAfter' => $this->mm(5)]);
    }

    private function plainNodeText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) $node->textContent;
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        if (strtolower($node->nodeName) === 'ul' || strtolower($node->nodeName) === 'ol') {
            return '';
        }
        $parts = [];
        foreach ($node->childNodes as $child) {
            $parts[] = $this->plainNodeText($child);
        }

        return implode('', $parts);
    }

    private function addMultilineText(mixed $container, mixed $value, ?array $fontStyle = null): void
    {
        $lines = WordText::lines($value);
        foreach ($lines as $line) {
            $container->addText($line, $fontStyle, ['spaceAfter' => 0, 'lineHeight' => 1.2]);
        }
    }

    private function plainRichText(string $html): string
    {
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\/(p|div|li|h[1-6]|tr)>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/', ' ', str_replace("\xc2\xa0", ' ', $text)) ?? $text);
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
