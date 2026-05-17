<?php
require_once __DIR__ . '/../tcpdf.php';

class CustomPDF extends TCPDF
{
    public $generatedTime;
    public $documentType = ''; // e.g. 'Invoice', 'Quotation'

    public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false)
    {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache);

        // Automatically set current generation timestamp
        $this->generatedTime = date('Y-m-d H:i:s');

        // Set default document layout settings
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetMargins(20, 33, 20);     // left, top, right
        $this->SetHeaderMargin(10);
        $this->SetFooterMargin(15);

        // Set default font
        $this->SetFont('times', '', 11);
    }

    public function Header()
    {
        // A) Company name
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(105,105,105);
        $this->SetXY(20, 10);
        $this->Cell(0, 5, 'AMIOSH RESOURCES SDN BHD (1062417W)', 0, 1, 'L');

        // B) Address (2 lines)
        $this->SetFont('helvetica', '', 8);
        $address = "No.5-2, Jalan Seri Putra 1/5, Bandar Seri Putra 1/5,\n"
                 . "Bandar Seri Putra Bangi, 43000 Kajang Selangor, Malaysia.";
        $this->SetXY(20, 15);
        $this->MultiCell(100, 4, $address, 0, 'L');

        // C) Contact line
        $this->SetXY(20, 22);
        $this->SetFont('helvetica', 'B', 8);
        $contactLine = 'amiosh.com  03-8210 8726';
        $this->Cell(0, 5, $contactLine, 0, 1, 'L');

        // D) Logo (right-aligned with margin, larger)
        $logoPath = __DIR__ . '/logo.png'; 
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 150, 10, 40); 
        }

        // E) Document Type (flush right alignment to page margin)
        if (!empty($this->documentType)) {
            $this->SetFont('helvetica', 'B', 9);
            $this->SetTextColor(105,105,105);
            $this->SetXY(20, 18); // X can be anything because we use full width
            $this->Cell(170, 6, strtoupper($this->documentType), 0, 0, 'R');
            // 170 = 210mm (A4 width) - 20mm left margin - 20mm right margin
        }

        // E) Separator line
        $this->SetLineWidth(0.1);
        $this->SetDrawColor(105,105,105);
        $this->Line(20, 30, 190, 30);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);

        $pageNum = 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages();
        $timestamp = 'Computer generated on: ' . (
            !empty($this->generatedTime)
                ? date('d M Y, h:i A', strtotime($this->generatedTime))
                : date('d M Y, h:i A')
        );

        // Safely read session variables
        $generatorId = isset($_SESSION['staff_id']) ? $_SESSION['staff_id'] : 'Unknown';
        $generatorCode = isset($_SESSION['name_code']) ? $_SESSION['name_code'] : '';

        $stampDetails = $timestamp . ' by: ' . $generatorCode . ' (' . $generatorId . ')';

        $this->Cell(0, 5, $pageNum, 0, 0, 'L');
        $this->Cell(0, 5, $stampDetails, 0, 0, 'R');
    }

}
