<?php

namespace App\Services\Invoices\Pdf;

use App\Support\AppFilePaths;

class Jd14PdfRenderer
{
    private const LEFT = 10.0;
    private const WIDTH = 190.0;
    private const PART_ONE_HEIGHT = 57.0;

    public function __construct(private readonly Jd14TextFitter $textFitter)
    {
    }

    public function render(object $row): \HrdJd14
    {
        require_once AppFilePaths::tcpdfTemplatePath('HrdJd14.php');

        $pdf = new \HrdJd14();
        $pdf->SetTitle('JD14 Declaration Form');
        $pdf->SetMargins(self::LEFT, 10, self::LEFT, true);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        $pdf->addJD14Header();

        $partOneTop = $this->sectionTitle($pdf, "PART 1 - EMPLOYER'S PARTICULAR", $pdf->GetY());
        $partOneBottom = $this->drawPartOne($pdf, $row, $partOneTop);

        $partTwoTitleTop = $partOneBottom + 4;
        $partTwoTableTop = $this->sectionTitle($pdf, 'PART 2 - CLAIM FOR COURSE FEE', $partTwoTitleTop);
        $partTwoBottom = $this->drawPartTwo($pdf, $row, $partTwoTableTop);

        $partThreeTitleTop = $partTwoBottom + 4;
        $partThreeTableTop = $this->sectionTitle(
            $pdf,
            'PART 3 - JOINT DECLARATION OF THE TRAINING PROVIDER AND THE EMPLOYER',
            $partThreeTitleTop,
        );
        $this->drawPartThree($pdf, $partThreeTableTop);
        $this->placePartThreeAssets($pdf, $partThreeTableTop);

        return $pdf;
    }

    private function sectionTitle(object $pdf, string $title, float $top): float
    {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->MultiCell(self::WIDTH, 8, $title, 0, 'C', false, 0, self::LEFT, $top, true, 0, false, true, 8, 'M');

        return $top + 8;
    }

    private function drawPartOne(object $pdf, object $row, float $top): float
    {
        $leftWidth = 95.0;
        $rightLabelWidth = 38.0;
        $rightValueWidth = 57.0;
        $topRowHeight = 28.0;
        $rightRowHeight = 7.0;
        $courseHeight = 9.0;
        $dateHeight = 8.0;
        $venueHeight = 12.0;

        $this->border($pdf, self::LEFT, $top, $leftWidth, $topRowHeight);
        $this->label($pdf, 'Registered Name and Address of Employer:', self::LEFT + 1, $top + 1, $leftWidth - 2, 4);
        $this->value(
            $pdf,
            $this->text($row, 'employer_name')."\n".$this->text($row, 'employer_address'),
            self::LEFT + 1,
            $top + 5,
            $leftWidth - 2,
            $topRowHeight - 6,
        );

        $rightX = self::LEFT + $leftWidth;
        $rightRows = [
            ['Employer Code', $this->text($row, 'employer_code')],
            ['Approval No', $this->text($row, 'approval_no')],
            ['Group Approved', $this->text($row, 'group_approved')],
            ['Group Claimed', $this->text($row, 'group_claimed')],
        ];

        foreach ($rightRows as $index => [$label, $value]) {
            $rowTop = $top + ($index * $rightRowHeight);
            $this->border($pdf, $rightX, $rowTop, $rightLabelWidth, $rightRowHeight);
            $this->border($pdf, $rightX + $rightLabelWidth, $rowTop, $rightValueWidth, $rightRowHeight);
            $this->label($pdf, $label, $rightX + 1, $rowTop + 1.25, $rightLabelWidth - 2, 4);
            $this->value($pdf, $value, $rightX + $rightLabelWidth + 1, $rowTop + 0.8, $rightValueWidth - 2, $rightRowHeight - 1.6);
        }

        $rowTop = $top + $topRowHeight;
        $this->drawPartOneRow($pdf, 'Course Title', $this->text($row, 'course_title'), $rowTop, $courseHeight);
        $rowTop += $courseHeight;
        $this->drawPartOneRow(
            $pdf,
            'Training Dates',
            'Commenced: '.$this->text($row, 'commenced_date').'    Ended: '.$this->text($row, 'end_date'),
            $rowTop,
            $dateHeight,
        );
        $rowTop += $dateHeight;
        $this->drawPartOneRow($pdf, 'Training Venue', $this->text($row, 'training_venue'), $rowTop, $venueHeight);

        return $top + self::PART_ONE_HEIGHT;
    }

    private function drawPartOneRow(object $pdf, string $label, string $value, float $top, float $height): void
    {
        $labelWidth = 38.0;
        $valueWidth = self::WIDTH - $labelWidth;
        $this->border($pdf, self::LEFT, $top, $labelWidth, $height);
        $this->border($pdf, self::LEFT + $labelWidth, $top, $valueWidth, $height);
        $this->label($pdf, $label, self::LEFT + 1, $top + 1.2, $labelWidth - 2, 4);
        $this->value($pdf, $value, self::LEFT + $labelWidth + 1, $top + 0.8, $valueWidth - 2, $height - 1.6);
    }

    private function drawPartTwo(object $pdf, object $row, float $top): float
    {
        $widths = [62.7, 62.7, 64.6];
        $labels = ['Number of Trainee(s)*', 'Total Fee Approved (RM)', 'Total Fee Claimed (RM)'];
        $values = [$this->text($row, 'no_of_pax'), $this->text($row, 'total_fee_approved'), $this->text($row, 'total_fee_claimed')];
        $headerHeight = 8.0;
        $valueHeight = 8.0;
        $x = self::LEFT;

        foreach ($widths as $index => $width) {
            $this->border($pdf, $x, $top, $width, $headerHeight);
            $this->border($pdf, $x, $top + $headerHeight, $width, $valueHeight);
            $pdf->SetFont('helvetica', 'B', 9.5);
            $pdf->MultiCell($width - 2, $headerHeight - 1, $labels[$index], 0, 'C', false, 0, $x + 1, $top + 0.5, true, 0, false, true, $headerHeight - 1, 'M');
            $this->centredValue($pdf, $values[$index], $x + 1, $top + $headerHeight + 0.5, $width - 2, $valueHeight - 1);
            $x += $width;
        }

        return $top + $headerHeight + $valueHeight;
    }

    private function drawPartThree(object $pdf, float $top): void
    {
        $todayDate = now()->format('d F Y');
        $html = <<<HTML
<style>
  table.part3 { font-size: 10pt; border: 0.5px solid #000; border-collapse: collapse; }
  table.part3 td { border: none; padding: 6px 4px; vertical-align: top; }
  .label { font-weight: bold; width: 25%; }
  .colon { width: 3%; text-align: center; }
  .value { width: 72%; }
</style>
<table class="part3" width="100%" cellpadding="4" cellspacing="0">
  <tr><td colspan="2">(a) I certify that all information declared above is true and correct and the training program claimed above has been conducted with all terms and condition under this scheme has been complied. I also declared that apart from this claim, there is no other claim has been made for these expenses. All relevant documents pertaining to this claim are with us and can be inspected by the Secretariat of the Pembangunan Sumber Manusia Berhad. <strong>(Training Provider)</strong></td></tr>
  <tr>
    <td width="40%"><table width="100%"><tr><td width="35%" class="label">SIGNATURE</td><td class="colon">: </td><td class="value" height="45"><br /><br /></td></tr><tr><td width="35%" class="label">NAME</td><td class="colon">: </td><td class="value">MUHAMMAD AMIN ROZAK</td></tr><tr><td width="35%" class="label">MYKAD NO</td><td class="colon">: </td><td class="value">760628-03-5981</td></tr></table></td>
    <td width="60%"><table width="100%"><tr><td class="label">DESIGNATION</td><td class="colon">: </td><td class="value">MANAGING DIRECTOR</td></tr><tr><td class="label">COMPANY STAMP</td><td class="colon">: </td><td class="value" height="55"><br /></td></tr><tr><td class="label">DATE</td><td class="colon">: </td><td class="value">{$todayDate}</td></tr></table></td>
  </tr>
  <tr><td colspan="2">(b) I certify that the training had been completed and agreed with the fees charged above. I am responsible to the claimed above and certify all information provided here is true and correct. <strong>(Employer)</strong></td></tr>
  <tr>
    <td width="40%"><table width="100%"><tr><td width="35%" class="label">SIGNATURE</td><td class="colon">: </td><td class="value" height="45"><br /><br /></td></tr><tr><td width="35%" class="label">NAME</td><td class="colon">: </td><td class="value"></td></tr><tr><td width="35%" class="label">MYKAD NO</td><td class="colon">: </td><td class="value"></td></tr></table></td>
    <td width="60%"><table width="100%"><tr><td class="label">DESIGNATION</td><td class="colon">: </td><td class="value"></td></tr><tr><td class="label">COMPANY STAMP</td><td class="colon">: </td><td height="40" class="value" style="color:rgb(182, 182, 182); font-size: 8pt">(Shall only be certified by either<br />Managing Director/General Manager/<br />Financial Controller/Finance<br />Director of Employer)</td></tr><tr><td class="label">DATE</td><td class="colon">: </td><td class="value"></td></tr></table></td>
  </tr>
</table>
HTML;

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(self::LEFT, $top);
        $pdf->writeHTML($html, true, false, false, false, '');
    }

    private function placePartThreeAssets(object $pdf, float $partThreeTop): void
    {
        $signaturePath = AppFilePaths::tcpdfTemplatePath('assets/sign.png');
        if (is_file($signaturePath)) {
            $this->placeImage($pdf, $signaturePath, self::LEFT + 36.5, $partThreeTop + 20, 28, 14);
        }

        $stampPath = AppFilePaths::tcpdfTemplatePath('assets/stamp.png');
        if (is_file($stampPath)) {
            $this->placeImage($pdf, $stampPath, self::LEFT + 139, $partThreeTop + 31, 40, 16);
        }
    }

    private function placeImage(object $pdf, string $path, float $x, float $y, float $maxWidth, float $maxHeight): void
    {
        $size = @getimagesize($path);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return;
        }

        $aspectRatio = $size[0] / $size[1];
        $width = min($maxWidth, $maxHeight * $aspectRatio);
        $height = $width / $aspectRatio;
        $pdf->Image($path, $x + (($maxWidth - $width) / 2), $y + (($maxHeight - $height) / 2), $width, $height, 'PNG');
    }

    private function border(object $pdf, float $x, float $y, float $width, float $height): void
    {
        $pdf->SetLineWidth(0.18);
        $pdf->Rect($x, $y, $width, $height);
    }

    private function label(object $pdf, string $text, float $x, float $y, float $width, float $height): void
    {
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->MultiCell($width, $height, $text, 0, 'L', false, 0, $x, $y, true, 0, false, true, $height, 'M');
    }

    private function value(object $pdf, string $text, float $x, float $y, float $width, float $height): void
    {
        $fitted = $this->textFitter->fit($pdf, $text, $width, $height);
        $pdf->SetFont('helvetica', '', $fitted['font_size']);
        $pdf->MultiCell($width, $height, $fitted['text'], 0, 'L', false, 0, $x, $y, true, 0, false, true, $height, 'T');
    }

    private function centredValue(object $pdf, string $text, float $x, float $y, float $width, float $height): void
    {
        $fitted = $this->textFitter->fit($pdf, $text, $width, $height, 10, 6.5);
        $pdf->SetFont('helvetica', 'B', $fitted['font_size']);
        $pdf->MultiCell($width, $height, $fitted['text'], 0, 'C', false, 0, $x, $y, true, 0, false, true, $height, 'M');
    }

    private function text(object $row, string $key): string
    {
        return (string) ($row->{$key} ?? '');
    }
}
