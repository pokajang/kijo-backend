<?php
/**
 * Render the Manpower Service Proposal into the given TCPDF instance.
 *
 * @param TCPDF $pdf
 * @param int   $proposalId
 * @param PDO   $pdo
 */
function renderManpowerProposalSection(TCPDF $pdf, int $proposalId, PDO $pdo)
{
    // Fetch Manpower proposal data
    $stmt = $pdo->prepare("SELECT * FROM proposal_template_manpower WHERE id = ?");
    $stmt->execute([$proposalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'No proposal content found.', '', 0, 'L', true);
        return;
    }

    // Icon to use for each section
    $icon = '*'; 

    // Define the sections, renaming custom_section to "Additional Information"
    $sections = [
        'Introduction'                   => $row['introduction'] ?? '',
        'Service Deliverables'           => $row['service_deliverables'] ?? '',
        'Supplied Manpower Deliverables' => $row['supplied_manpower_deliverables'] ?? '',
        'Additional Information'         => $row['custom_section'] ?? '',
    ];

    // 1) First page: render all except Additional Information
    $pdf->AddPage();
    $leftMargin = $pdf->GetMargins()['left'];

    // Title block with word wrap support
    $title = $row['service_title'] . ' Manpower Supply Service Proposal';
    $pdf->SetFont('helvetica', 'B', 13);

    // Estimate max title width (81% of page width)
    $maxBoxWidth = $pdf->getPageWidth() * 0.81;
    $textHeight = $pdf->getStringHeight($maxBoxWidth, $title) + 4;

    $x = ($pdf->getPageWidth() - $maxBoxWidth) / 2;
    $y = $pdf->GetY();

    $pdf->SetDrawColor(200, 255, 200);
    $pdf->SetFillColor(240, 255, 240);
    $pdf->RoundedRect($x, $y, $maxBoxWidth, $textHeight, 1, '1111', 'DF');

    $pdf->SetTextColor(0, 60, 0);
    $pdf->SetXY($x, $y + 2);
    $pdf->MultiCell($maxBoxWidth, 0, $title, 0, 'C', false, 1);

    $pdf->Ln(6);


    // Render each section except Additional Information
    foreach ($sections as $heading => $html) {
        if ($heading === 'Additional Information') {
            continue;
        }
        if (!trim(strip_tags($html))) {
            continue;
        }

        // Heading with icon
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetTextColor(0, 60, 0);
        $pdf->SetX($leftMargin);
        $pdf->Write(0, "$icon ", '', 0, 'L', false);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, $heading, '', 0, 'L', true);
        $pdf->Ln(1);

        // Body HTML
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->writeHTMLCell(
            0, 0, $leftMargin, '', $html,
            0, 1, false, true, 'L', true
        );
        $pdf->Ln(4);
    }

    // 2) Additional Information on its own page (if present)
    $additionalHtml = trim($sections['Additional Information']);
    if ($additionalHtml && trim(strip_tags($additionalHtml))) {
        // $pdf->AddPage();
        $leftMargin = $pdf->GetMargins()['left'];

        // Heading with icon
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetTextColor(0, 60, 0);
        $pdf->SetX($leftMargin);
        $pdf->Write(0, $icon, '', 0, 'L', false);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, ' Additional Information', '', 0, 'L', true);
        $pdf->Ln(2);

        // Render Additional Information HTML
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->writeHTMLCell(
            0, 0, $leftMargin, '', $additionalHtml,
            0, 1, false, true, 'L', true
        );
    }
}

