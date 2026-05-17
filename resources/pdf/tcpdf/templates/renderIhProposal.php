<?php
function renderIhProposalSection(TCPDF $pdf, int $proposalId, PDO $pdo)
{
    // Fetch IH proposal data
    $stmt = $pdo->prepare("SELECT * FROM proposal_template_ih WHERE id = ?");
    $stmt->execute([$proposalId]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'No proposal content found.', '', 0, 'L', true);
        return;
    }

    // Common settings
    $benzeneIcon = '*'; // U+232C Benzene Ring
    $sectionIcons = [
        'Introduction'            => $benzeneIcon,
        'Objectives'              => $benzeneIcon,
        'Work Scope'              => $benzeneIcon,
        'Schedule'                => $benzeneIcon,
        'References'              => $benzeneIcon,
        'Additional Information'  => $benzeneIcon,
    ];
    $sections = [
        'Introduction'           => $row['introduction'] ?? '',
        'Objectives'             => $row['objectives'] ?? '',
        'Work Scope'             => $row['work_scope'] ?? '',
        'Schedule'               => $row['schedule'] ?? '',
        'References'             => $row['reference'] ?? '',
        'Additional Information' => $row['other_fields'] ?? '',
    ];

    // 1) First page: all sections except Additional Information
    $pdf->AddPage();
    $leftMargin = $pdf->GetMargins()['left'];

    // Updated title block with wrapping support
    $pdf->SetFont('helvetica', 'B', 13);
    $title = $row['service_title'] . ' Service Proposal';

    $maxBoxWidth = $pdf->getPageWidth() * 0.81;
    $textHeight  = $pdf->getStringHeight($maxBoxWidth, $title) + 4;

    $x = ($pdf->getPageWidth() - $maxBoxWidth) / 2;
    $y = $pdf->GetY();

    $pdf->SetDrawColor(200, 255, 200);
    $pdf->SetFillColor(240, 255, 240);
    $pdf->RoundedRect($x, $y, $maxBoxWidth, $textHeight, 1, '1111', 'DF');

    $pdf->SetTextColor(0, 60, 0);
    $pdf->SetXY($x, $y + 2);
    $pdf->MultiCell($maxBoxWidth, 0, $title, 0, 'C', false, 1);
    $pdf->Ln(6);

    // Render all but Additional Information
    foreach ($sections as $title => $content) {
        if ($title === 'Additional Information') {
            continue;
        }
        if (empty(trim(strip_tags($content)))) {
            continue;
        }

        // Icon + heading
        $icon = $sectionIcons[$title] ?? '*';
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetTextColor(0, 100, 0);
        $pdf->SetX($leftMargin);
        $pdf->Write(0, "$icon ", '', 0, 'L', false);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, $title, '', 0, 'L', true);
        $pdf->Ln(1);

        // Body HTML
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetX($leftMargin);
        $pdf->writeHTMLCell(
            0,     // full width
            0,     // auto height
            $leftMargin,
            '',
            $content,
            0,     // no border
            1,     // move to next line after
            false, // no fill
            true,  // reset height
            'L',   // align left
            true   // autopadding
        );
        $pdf->Ln(4);
    }

    // 2) Additional Information on its own page, if present
    $additionalHtml = trim($sections['Additional Information']);
    if (!empty(strip_tags($additionalHtml))) {
        $pdf->AddPage();
        $leftMargin = $pdf->GetMargins()['left'];

        // Icon in DejaVu Sans
        $icon = $sectionIcons['Additional Information'];
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetTextColor(0, 100, 0);
        $pdf->SetX($leftMargin);
        $pdf->Write(0, $icon, '', 0, 'L', false);

        // Heading in Helvetica, size 12
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, ' Additional Information', '', 0, 'L', true);
        $pdf->Ln(2);

        // Render the HTML content as-is
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetX($leftMargin);
        $pdf->writeHTMLCell(
            0,     // full width
            0,     // auto height
            $leftMargin,
            '',
            $additionalHtml,
            0,     // no border
            1,     // move to next line after
            false, // no fill
            true,  // reset height
            'L',   // align left
            true   // autopadding
        );
    }

}

