<?php
/**
 * Render the Special Service Proposal into the given TCPDF/FPDI instance,
 * writing the raw HTML content and then appending any PDF attachments.
 *
 * @param TCPDF $pdf
 * @param int   $proposalId
 * @param PDO   $pdo
 */
function renderSpecialProposalSection(TCPDF $pdf, int $proposalId, PDO $pdo)
{
    // --- 1) Fetch the main proposal record ---
    $stmt = $pdo->prepare("
        SELECT service_title, service_code, content
          FROM proposal_template_special
         WHERE id = ?
    ");
    $stmt->execute([$proposalId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'No proposal content found.', '', 0, 'L', true);
        return;
    }

    // --- 2) First page: title block + raw HTML content ---
    $pdf->AddPage();
    $margins = $pdf->GetMargins();
    $left    = $margins['left'];

    // Title box (wrapped and styled consistently)
    $title = $row['service_title'] . ' Service Proposal';

    $pdf->SetFont('helvetica', 'B', 13);
    $maxBoxWidth = $pdf->getPageWidth() * 0.81;
    $textHeight  = $pdf->getStringHeight($maxBoxWidth, $title) + 4;

    $x = ($pdf->getPageWidth() - $maxBoxWidth) / 2;
    $y = $pdf->GetY();

    $pdf->SetDrawColor(200, 255, 200);     // light green border
    $pdf->SetFillColor(240, 255, 240);     // light green fill
    $pdf->RoundedRect($x, $y, $maxBoxWidth, $textHeight, 1, '1111', 'DF');

    $pdf->SetTextColor(0, 60, 0);
    $pdf->SetXY($x, $y + 2);
    $pdf->MultiCell($maxBoxWidth, 0, $title, 0, 'C', false, 1);

    $pdf->Ln(6);

    // Render the HTML content directly
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(0, 0, 0);
    // writeHTML() will handle all headings, lists, etc.
    $pdf->writeHTML($row['content'], true, false, true, false, '');

    // --- 3) Append each PDF attachment into this document ---
    $attachStmt = $pdo->prepare("
        SELECT file_url
          FROM proposal_special_attachments
         WHERE proposal_id = ?
         ORDER BY id ASC
    ");
    $attachStmt->execute([$proposalId]);
    $attachments = $attachStmt->fetchAll(PDO::FETCH_ASSOC);

    // Use FPDI methods to import and append each PDF - TO DO LATER
    // foreach ($attachments as $att) {
    //     $path = __DIR__ . '/../../../' . $att['file_url'];
    //     if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
    //         continue;
    //     }
    //     // import all pages
    //     $pageCount = $pdf->setSourceFile($path);
    //     for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
    //         $tplIdx = $pdf->importPage($pageNo);
    //         $size   = $pdf->getTemplateSize($tplIdx);
    //         $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    //         $pdf->useTemplate($tplIdx);
    //     }
    // }
}
