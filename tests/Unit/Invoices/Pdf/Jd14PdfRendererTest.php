<?php

namespace Tests\Unit\Invoices\Pdf;

use App\Services\Invoices\Pdf\Jd14PdfRenderer;
use App\Services\Invoices\Pdf\Jd14TextFitter;
use Tests\TestCase;

class Jd14PdfRendererTest extends TestCase
{
    public function test_text_fitter_compacts_and_truncates_an_oversized_value(): void
    {
        require_once base_path('resources/pdf/tcpdf/tcpdf.php');

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->AddPage();
        $fitter = new Jd14TextFitter();

        $result = $fitter->fit($pdf, str_repeat('Very long training venue content ', 50), 40, 6, 9, 6.5);

        $this->assertTrue($result['was_compacted']);
        $this->assertTrue($result['was_truncated']);
        $this->assertSame(6.5, $result['font_size']);
        $this->assertStringEndsWith('…', $result['text']);
    }

    public function test_text_fitter_handles_a_long_unbroken_value(): void
    {
        require_once base_path('resources/pdf/tcpdf/tcpdf.php');

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->AddPage();

        $result = (new Jd14TextFitter())->fit($pdf, str_repeat('X', 1000), 40, 6, 9, 6.5);

        $this->assertTrue($result['was_truncated']);
        $this->assertStringEndsWith('…', $result['text']);
    }

    public function test_renderer_keeps_long_variable_fields_on_one_a4_page(): void
    {
        $row = (object) [
            'employer_name' => str_repeat('Northern Regional Industrial Training Organisation ', 8),
            'employer_address' => implode("\n", array_fill(0, 12, 'No. 12345, Long Industrial Estate Avenue, Kuala Lumpur, Malaysia')),
            'approval_no' => str_repeat('APPROVAL-REFERENCE-', 8),
            'employer_code' => str_repeat('EMPLOYER-CODE-', 8),
            'group_approved' => str_repeat('GROUP-APPROVED-', 8),
            'group_claimed' => str_repeat('GROUP-CLAIMED-', 8),
            'course_title' => str_repeat('Advanced Incident Investigation, Reporting and Prevention ', 12),
            'training_venue' => str_repeat('Level 15, National Training and Convention Centre, Persiaran Example, Kuala Lumpur ', 12),
            'commenced_date' => '2026-03-10',
            'end_date' => '2026-03-10',
            'no_of_pax' => '20',
            'total_fee_approved' => '4500.00',
            'total_fee_claimed' => '4500.00',
        ];

        $pdf = app(Jd14PdfRenderer::class)->render($row);
        $this->assertSame(1, $pdf->getNumPages());
        $pdfBytes = $pdf->Output('jd14-test.pdf', 'S');

        $this->assertStringStartsWith('%PDF-', $pdfBytes);
    }
}
