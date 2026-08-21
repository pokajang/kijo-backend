<?php

namespace App\Services\Word;

use App\Support\AppFilePaths;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

final class Jd14WordDocumentBuilder
{
    private const PAGE_WIDTH_MM = 210;
    private const PAGE_HEIGHT_MM = 297;
    private const MARGIN_MM = 10;
    private const USABLE_WIDTH_MM = self::PAGE_WIDTH_MM - (self::MARGIN_MM * 2);

    public function build(object $row): PhpWord
    {
        $document = new PhpWord;
        $document->setDefaultFontName('Arial');
        $document->setDefaultFontSize(9);
        $document->setDefaultParagraphStyle(['spaceAfter' => 0, 'lineHeight' => 1.05]);
        $document->getSettings()->setUpdateFields(true);
        $document->addTableStyle('jd14Form', [
            'borderSize' => 4,
            'borderColor' => '000000',
            'cellMargin' => $this->mm(.8),
            'width' => 5000,
            'unit' => TblWidth::PERCENT,
            'layout' => TableStyle::LAYOUT_FIXED,
        ]);

        $section = $document->addSection([
            'pageSizeW' => $this->mm(self::PAGE_WIDTH_MM),
            'pageSizeH' => $this->mm(self::PAGE_HEIGHT_MM),
            'marginTop' => $this->mm(self::MARGIN_MM),
            'marginBottom' => $this->mm(30),
            'marginLeft' => $this->mm(self::MARGIN_MM),
            'marginRight' => $this->mm(self::MARGIN_MM),
            'footerHeight' => $this->mm(10),
        ]);

        $this->addReminder($section);
        $this->addHeader($section);
        $this->addPartOne($section, $row);
        $this->addPartTwo($section, $row);
        $this->addPartThree($section, $row);

        return $document;
    }

    private function addHeader(Section $section): void
    {
        $mycoid = '1062417W';
        $table = $section->addTable('jd14Form');
        $table->addRow();
        $this->cell($table, $this->mm(88), ['gridSpan' => 8])->addText(
            'TRAINING PROVIDER MYCOID(ROC/ROB/ROS)',
            ['bold' => true, 'size' => 10],
            ['alignment' => Jc::CENTER],
        );
        $table->addRow();
        foreach (str_split($mycoid) as $character) {
            $this->cell($table, $this->mm(11))->addText($character, ['bold' => true], ['alignment' => Jc::CENTER]);
        }

        $section->addTextBreak(1);
        $reference = $section->addTable('jd14Form');
        $reference->addRow();
        $this->cell($reference, $this->mm(60))->addText('PSMB/SBL-KHAS /JD/14', ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER]);

        $section->addText('EMPLOYER AND TRAINING PROVIDER JOINT DECLARATION FOR SBL-KHAS SCHEME CLAIMS'."\n".'(FEES) UNDER THE PEMBANGUNAN SUMBER MANUSIA BERHAD ACT 2001', ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER, 'spaceBefore' => $this->mm(4), 'spaceAfter' => $this->mm(1)]);
        $section->addText('This declaration is to certify that employer involved in the training program had agreed with the training program conducted, fees charged and allow training provider to claim with PSMB. This declaration should only be signed by employers after the training completed. This form must be attached when submitting online SBL-KHAS claim. This form must be kept at training providers premises and available for future verification by PSMB.', ['size' => 8], ['alignment' => Jc::CENTER, 'lineHeight' => 1.1, 'spaceAfter' => $this->mm(3)]);
    }

    private function addPartOne(Section $section, object $row): void
    {
        $this->sectionHeading($section, "PART 1 - EMPLOYER'S PARTICULAR");
        $table = $section->addTable('jd14Form');
        $left = $this->mm(95);
        $label = $this->mm(38);
        $value = $this->mm(57);
        $table->addRow();
        $address = $this->cell($table, $left, ['vMerge' => 'restart', 'valign' => 'top']);
        $address->addText('Registered Name and Address of Employer:');
        $address->addText(WordText::clean($row->employer_name ?? ''));
        foreach (WordText::lines($row->employer_address ?? '') as $line) {
            $address->addText($line);
        }
        $this->labelValueRow($table, $label, $value, 'Employer Code', $row->employer_code ?? '');
        foreach ([
            ['Approval No', $row->approval_no ?? ''],
            ['Group Approved', $row->group_approved ?? ''],
            ['Group Claimed', $row->group_claimed ?? ''],
        ] as [$heading, $content]) {
            $table->addRow();
            $this->cell($table, $left, ['vMerge' => 'continue']);
            $this->labelValueCells($table, $label, $value, $heading, $content);
        }
        foreach ([
            ['Course Title', $row->course_title ?? ''],
            ['Training Dates', 'Commenced: '.WordText::clean($row->commenced_date ?? '').'    Ended: '.WordText::clean($row->end_date ?? '')],
            ['Training Venue', $row->training_venue ?? ''],
        ] as [$heading, $content]) {
            $table->addRow();
            $this->cell($table, $label)->addText($heading);
            $this->cell($table, $this->mm(152), ['gridSpan' => 2])->addText(WordText::clean($content));
        }
    }

    private function addPartTwo(Section $section, object $row): void
    {
        $this->sectionHeading($section, 'PART 2 - CLAIM FOR COURSE FEE');
        $table = $section->addTable('jd14Form');
        $widths = [$this->mm(62.7), $this->mm(62.7), $this->mm(64.6)];
        $table->addRow();
        foreach (['Number of Trainee(s)*', 'Total Fee Approved (RM)', 'Total Fee Claimed (RM)'] as $index => $heading) {
            $this->cell($table, $widths[$index])->addText($heading, ['bold' => true], ['alignment' => Jc::CENTER]);
        }
        $table->addRow($this->mm(10));
        foreach ([$row->no_of_pax ?? '', $row->total_fee_approved ?? '', $row->total_fee_claimed ?? ''] as $index => $amount) {
            $this->cell($table, $widths[$index], ['valign' => 'center'])->addText(WordText::clean($amount), ['bold' => true], ['alignment' => Jc::CENTER]);
        }
    }

    private function addPartThree(Section $section, object $row): void
    {
        $this->sectionHeading($section, 'PART 3 - JOINT DECLARATION OF THE TRAINING PROVIDER AND THE EMPLOYER');
        $table = $section->addTable('jd14Form');
        $table->addRow();
        $this->cell($table, $this->mm(self::USABLE_WIDTH_MM), ['gridSpan' => 2])->addText('(a) I certify that all information declared above is true and correct and the training program claimed above has been conducted with all terms and condition under this scheme has been complied. I also declared that apart from this claim, there is no other claim has been made for these expenses. All relevant documents pertaining to this claim are with us and can be inspected by the Secretariat of the Pembangunan Sumber Manusia Berhad. (Training Provider)', ['size' => 8.5], ['lineHeight' => 1.05]);
        $table->addRow(null, ['cantSplit' => true]);
        $providerLeft = $this->cell($table, $this->mm(76), ['valign' => 'top']);
        $providerRight = $this->cell($table, $this->mm(114), ['valign' => 'top']);
        $this->addProviderSignature($providerLeft);
        $this->addProviderStamp($providerRight);
        $table->addRow();
        $this->cell($table, $this->mm(self::USABLE_WIDTH_MM), ['gridSpan' => 2])->addText('(b) I certify that the training had been completed and agreed with the fees charged above. I am responsible to the claimed above and certify all information provided here is true and correct. (Employer)', ['size' => 8.5], ['lineHeight' => 1.05]);
        $table->addRow(null, ['cantSplit' => true]);
        $employerLeft = $this->cell($table, $this->mm(76), ['valign' => 'top']);
        $employerRight = $this->cell($table, $this->mm(114), ['valign' => 'top']);
        $this->addBlankSignature($employerLeft);
        $this->addEmployerStamp($employerRight);
    }

    private function addProviderSignature(Cell $cell): void
    {
        $this->addLabel($cell, 'SIGNATURE');
        $signature = AppFilePaths::tcpdfTemplatePath('assets/sign.png');
        if (is_file($signature) && is_readable($signature)) {
            $cell->addImage($signature, ['width' => 28 * 72 / 25.4, 'alignment' => Jc::CENTER]);
        } else {
            $cell->addTextBreak(2);
        }
        $this->addLabel($cell, 'NAME', 'MUHAMMAD AMIN ROZAK');
        $this->addLabel($cell, 'MYKAD NO', '760628-03-5981');
    }

    private function addProviderStamp(Cell $cell): void
    {
        $this->addLabel($cell, 'DESIGNATION', 'MANAGING DIRECTOR');
        $this->addLabel($cell, 'COMPANY STAMP');
        $stamp = AppFilePaths::tcpdfTemplatePath('assets/stamp.png');
        if (is_file($stamp) && is_readable($stamp)) {
            $cell->addImage($stamp, ['width' => 40 * 72 / 25.4, 'alignment' => Jc::CENTER]);
        } else {
            $cell->addTextBreak(2);
        }
        $this->addLabel($cell, 'DATE', now()->format('d F Y'));
    }

    private function addBlankSignature(Cell $cell): void
    {
        $this->addLabel($cell, 'SIGNATURE');
        $cell->addTextBreak(2);
        $this->addLabel($cell, 'NAME');
        $this->addLabel($cell, 'MYKAD NO');
    }

    private function addEmployerStamp(Cell $cell): void
    {
        $this->addLabel($cell, 'DESIGNATION');
        $this->addLabel($cell, 'COMPANY STAMP');
        $cell->addText('(Shall only be certified by either'."\n".'Managing Director/General Manager/'."\n".'Financial Controller/Finance'."\n".'Director of Employer)', ['size' => 8, 'color' => 'B6B6B6'], ['alignment' => Jc::RIGHT]);
        $this->addLabel($cell, 'DATE');
    }

    private function addLabel(Cell $cell, string $label, string $value = ''): void
    {
        $run = $cell->addTextRun(['spaceAfter' => 0]);
        $run->addText($label, ['bold' => true]);
        $run->addText(' : '.WordText::clean($value));
    }

    private function labelValueRow($table, int $labelWidth, int $valueWidth, string $label, mixed $value): void
    {
        $this->cell($table, $labelWidth)->addText($label);
        $this->cell($table, $valueWidth)->addText(WordText::clean($value));
    }

    private function labelValueCells($table, int $labelWidth, int $valueWidth, string $label, mixed $value): void
    {
        $this->labelValueRow($table, $labelWidth, $valueWidth, $label, $value);
    }

    private function sectionHeading(Section $section, string $heading): void
    {
        $section->addText($heading, ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER, 'spaceBefore' => $this->mm(3), 'spaceAfter' => $this->mm(1), 'keepNext' => true]);
    }

    private function addReminder(Section $section): void
    {
        $section->addFooter()->addText('REMINDER: You are reminded that, if you should give false or misleading statements, or make in writing, or sign any declaration which is untrue or incorrect in any particular, you will be prosecuted under Section 40 and/or Section 41 of the Pembangunan Sumber Manusia Berhad Act 2001 and shall be liable to a fine not exceeding twenty thousand ringgit or to imprisonment for a term not exceeding two years or to both. Besides, Pembangunan Sumber Manusia Berhad may, at its discretion, withdraw the grant and recover immediately any amount of the grant that may have been disbursed.', ['size' => 7], ['alignment' => Jc::BOTH, 'lineHeight' => 1.0, 'spaceAfter' => 0]);
    }

    private function cell(mixed $table, int $width, array $style = []): Cell
    {
        return $table->addCell($width, ['noWrap' => false, ...$style]);
    }

    private function mm(float $value): int
    {
        return (int) round(Converter::cmToTwip($value / 10));
    }
}
