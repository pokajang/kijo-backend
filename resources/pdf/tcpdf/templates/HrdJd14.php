<?php
//hrdjd14.php
require_once(__DIR__ . '/../tcpdf.php');

class HrdJd14 extends TCPDF
{
    public function Header()
    {
        // No default header
    }

    public function Footer()
    {
        // Move to 15mm from bottom
        $this->SetY(-30); // Adjust if needed for margin
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0);

        $reminder = <<<EOD
        <div style="text-align: justify;">
        <strong>REMINDER</strong>: You are reminded that, if you should give false or misleading statements, or make in writing, or sign any declaration which is untrue or incorrect in any particular, you will be prosecuted under Section 40 and/or Section 41 of the Pembangunan Sumber Manusia Berhad Act 2001 and shall be liable to a fine not exceeding twenty thousand ringgit or to imprisonment for a term not exceeding two years or to both. Besides, Pembangunan Sumber Manusia Berhad may, at its discretion, withdraw the grant and recover immediately any amount of the grant that may have been disbursed.
        </div>
        EOD;

        $this->writeHTML($reminder, true, false, true, false, '');



    }

    public function addJD14Header()
    {
        // --- MYCOID HEADING ---
        $this->SetFont('helvetica', 'B', 10);

        $mycoid = str_split('1062417W');
        $boxWidth = 11;
        $boxHeight = 5; // 🟢 snug height for MYCOID boxes
        $labelPadding = 2;

        $labelWidth = count($mycoid) * $boxWidth;
        $this->Cell($labelWidth, $boxHeight + $labelPadding, 'TRAINING PROVIDER MYCOID(ROC/ROB/ROS)', 1, 1, 'C');

        foreach ($mycoid as $char) {
            $this->Cell($boxWidth, $boxHeight, $char, 1, 0, 'C');
        }
        $this->Ln($boxHeight + 3);

        // --- JD/14 FORM REF LINE ---
        $this->SetFont('helvetica', 'B', 11);
        $refBoxWidth = 60;
        $refBoxHeight = $boxHeight;
        $this->Cell($refBoxWidth, $refBoxHeight, 'PSMB/SBL-KHAS /JD/14', 1, 1, 'C');
        $this->Ln(4);

        // --- TITLE (center-aligned) ---
        $this->SetFont('helvetica', 'B', 11);
        $this->MultiCell(0, 6,
            'EMPLOYER AND TRAINING PROVIDER JOINT DECLARATION FOR SBL-KHAS SCHEME CLAIMS (FEES) UNDER THE PEMBANGUNAN SUMBER MANUSIA BERHAD ACT 2001',
            0, 'C');
        $this->Ln(1);

        // --- DESCRIPTION (center-aligned) ---
        $this->SetFont('helvetica', '', 8);
        $this->MultiCell(0, 5,
            "This declaration is to certify that employer involved in the training program had agreed with the training program conducted, fees charged and allow training provider to claim with PSMB. This declaration should only be signed by employers after the training completed. This form must be attached when submitting online SBL –KHAS claim. This form must be kept at training providers premises and available for future verification by PSMB.",
            0, 'C');
        $this->Ln(4); 
    
    }
}
