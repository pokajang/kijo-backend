<?php
function renderProposalSection(TCPDF $pdf, int $proposalId, PDO $pdo)
{
  // Fetch main proposal data
  $stmt = $pdo->prepare("SELECT * FROM proposal_template_training_main WHERE id = ?");
  $stmt->execute([$proposalId]);
  $row = $stmt->fetch();

    // Fetch agenda items for this proposal template
    $agendaStmt = $pdo->prepare("
      SELECT day, start_time, end_time, topic 
      FROM proposal_template_training_agenda 
      WHERE template_id = ?
      ORDER BY day ASC, start_time ASC
    ");

    $agendaStmt->execute([$proposalId]);
    $agendaRows = $agendaStmt->fetchAll();
    
    // group agenda per day
    $agendaByDay = [];
    foreach ($agendaRows as $item) {
        $agendaByDay[$item['day']][] = $item;
    }

  if (!$row) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Write(0, 'No proposal content found.', '', 0, 'L', true);
    return;
  }

  // Add page and set margins
  $pdf->AddPage();
  $leftMargin = $pdf->GetMargins()['left']; // e.g. 20mm

  // Updated centered title block (with wrapping support)
  $pdf->SetFont('helvetica', 'B', 13);
  $headingText = $row['training_title'] . ' Training Brochure';

  $maxBoxWidth = $pdf->getPageWidth() * 0.81;
  $textHeight  = $pdf->getStringHeight($maxBoxWidth, $headingText) + 4;

  $x = ($pdf->getPageWidth() - $maxBoxWidth) / 2;
  $y = $pdf->GetY();

  $pdf->SetDrawColor(200, 255, 200);
  $pdf->SetFillColor(240, 255, 240);
  $pdf->RoundedRect($x, $y, $maxBoxWidth, $textHeight, 1, '1111', 'DF');

  $pdf->SetTextColor(0, 60, 0);
  $pdf->SetXY($x, $y + 2);
  $pdf->MultiCell($maxBoxWidth, 0, $headingText, 0, 'C', false, 1);

  $pdf->Ln(6);


  // duratin formatter
  $durationLabel = '';
    $durationRaw = trim(strtolower($row['duration'] ?? ''));

    switch ($durationRaw) {
    case '1hour':
        $durationLabel = '1 Hour';
        break;
    case '2hour':
        $durationLabel = '2 Hours';
        break;  
    case '3hour':
        $durationLabel = '3 Hours';
        break;                
    case 'halfday_am':
        $durationLabel = 'Half Day (4 hours)';
        break;
    case 'halfday_pm':
        $durationLabel = 'Half Day (4 hours)';
        break;
    case '1day':
        $durationLabel = '1 Full Day (8 hours)';
        break;
    case '2day':
        $durationLabel = '2 Days (16 hours)';
        break;
    case '3day':
        $durationLabel = '3 Days (24 hours)';
        break;
    default:
        $durationLabel = !empty($durationRaw) ? ucfirst($durationRaw) : '';
        break;
    }

  // sections icon
    $sectionIcons = [
    'HRDC Training Programme No.' => '*',      
    'Introduction' => '*',
    'Objectives' => '*',
    'Modules' => '*',
    'Training Requirements' => '*',
    'Additional Requirements' => '*',
    'Training Materials' => '*',
    'Lecture Medium' => '*',
    'Theory Method' => '*',
    'Practical Method' => '*',
    'Duration' => '*',
    ];

  // Sections to render
  $sections = [
    'HRDC Training Programme No.' => $row['hrd_no'],    
    'Introduction' => $row['introduction'],
    'Objectives' => $row['objectives'],
    'Modules' => $row['modules'],
    'Training Requirements' => $row['training_requirements'],
    'Additional Requirements' => $row['additional_requirements'],
    'Training Materials' => $row['training_materials'],
    'Lecture Medium' => $row['lecture_medium'],
    'Theory Method' => $row['method_theory'] ? $row['method_theory_desc'] : '',
    'Practical Method' => $row['method_practical'] ? $row['method_practical_desc'] : '',
    'Duration' => $durationLabel,
  ];

  // render main brochure details
    foreach ($sections as $title => $content) {
    if (!empty(trim($content))) {
        $icon = $sectionIcons[$title] ?? '*'; // fallback icon

        // Render icon in Unicode-capable font
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetTextColor(0, 100, 0);
        $pdf->SetX($leftMargin);
        $pdf->Write(0, $icon . ' ', '', 0, 'L', false);

        // Continue heading in helvetica
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, $title, '', 0, 'L', true);
        $pdf->Ln(1);

        // Content
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetX($leftMargin);
        $pdf->writeHTMLCell(0, 0, $leftMargin, '', $content, 0, 0.5, false, true, 'L', true);

        $pdf->Ln(4);
    }
    }

if ($agendaRows && count($agendaRows) > 0) {
    // Table styles
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.1);

    $col1Width = 50;
    $col2Width = 120;

    // ---- safety constants ----
    $FOOTER_BAND = 12.0;   // reserve space above footer
    $ROW_GUARD   = 1.2;    // extra guard so rows don't touch the margin
    $HEADING_EST = 9.0;    // approx height for "Program Tentative" + small gap

    // bottom-limit helper (accounts for footer band + break margin)
    $bottomLimit = function() use ($pdf, $FOOTER_BAND) {
        return $pdf->getPageHeight() - $pdf->getBreakMargin() - $FOOTER_BAND;
    };

    // Helpers
    $printSectionHeading = function() use ($pdf) {
        $pdf->SetFont('dejavusans', 'B', 12); // emoji-capable
        $pdf->SetTextColor(0, 100, 0);
        $pdf->Write(0, '* ', '', 0, 'L', false);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Write(0, 'Program Tentative', '', 0, 'L', true);
        $pdf->Ln(3);
        $pdf->SetTextColor(0, 0, 0); // reset for table
    };

    $printTableHeader = function() use ($pdf, $col1Width, $col2Width) {
        $pdf->SetFont('helvetica','B',11);
        $pdf->SetFillColor(220);
        $pdf->Cell($col1Width, 8, 'Time',   1, 0, 'C', true);
        $pdf->Cell($col2Width, 8, 'Agenda', 1, 1, 'C', true);
        $pdf->SetFont('helvetica','',11);
        // default tighter padding
        $pdf->setCellPaddings(0.5, 0.5, 0.5, 0.5);
    };

    // HTML-aware height estimator (rounded up, with padding + guard)
    $estimateRowHeight = function($html) use ($pdf, $col2Width, $ROW_GUARD) {
        $topic = trim((string)($html ?? '')) ?: '&nbsp;';
        $pdf->SetFont('helvetica','',11); // same as render
        if (method_exists($pdf, 'getStringHeight')) {
            // getStringHeight($w, $txt, $reseth=true, $autopadding=true, $cellpadding='', $border=0)
            $hText = (float)$pdf->getStringHeight($col2Width, $topic, true, true, '', 0);
            $pad   = 1.0 /*top*/ + 1.0 /*bottom*/;
            $h     = $hText + $pad + $ROW_GUARD;
            // round UP to nearest 0.1mm to avoid razor-edge spills
            return max(6.4, ceil($h * 10) / 10.0);
        }
        return 6.8; // conservative fallback
    };

    $printedTentativeHeading = false;

    foreach ($agendaByDay as $day => $items) {
        // Keep a whole Day together if it fits
        $yStart  = $pdf->GetY();
        $bottomY = $bottomLimit();

        // Estimate whole day
        $estimated = 0.0;
        if (count($agendaByDay) > 1) {
            $estimated += 6.0; // "Day N"
            $estimated += 2.0; // gap
        }
        $estimated += 8.0; // header
        foreach ($items as $r) {
            $estimated += $estimateRowHeight($r['topic'] ?? '');
        }
        $estimated += 3.0; // space after table

        // If heading not printed yet, include it in the fit check so it sticks to the table
        $headingAllowance = $printedTentativeHeading ? 0.0 : $HEADING_EST;

        if ($yStart + $headingAllowance + $estimated > $bottomY) {
            $pdf->AddPage();
            $yStart  = $pdf->GetY();
            $bottomY = $bottomLimit();
        }

        // Print the section heading exactly where the table will start (only once)
        if (!$printedTentativeHeading) {
            $printSectionHeading();
            $printedTentativeHeading = true;
        }

        // Render Day
        if (count($agendaByDay) > 1) {
            $pdf->SetFont('helvetica','B',11);
            $pdf->Cell(0, 6, "Day $day", 0, 1, 'L');
            $pdf->Ln(2);
        }

        $printTableHeader();

        // Rows (with per-row pre-check + fixed-height HTML cell)
        foreach ($items as $row) {
            $start = date('g:i A', strtotime($row['start_time']));
            $end   = date('g:i A', strtotime($row['end_time']));
            $timeRange = "$start - $end";

            $x = $pdf->GetX();
            $y = $pdf->GetY();

            $topicHtml = $row['topic'] ?? '';
            $hEst      = $estimateRowHeight($topicHtml);

            // Per-row fit check before drawing
            $bottomY = $bottomLimit();
            if ($y + $hEst > $bottomY) {
                $pdf->AddPage();
                if (count($agendaByDay) > 1) {
                    $pdf->SetFont('helvetica','B',11);
                    $pdf->Cell(0, 6, "Day $day (cont.)", 0, 1, 'L');
                    $pdf->Ln(2);
                }
                $printTableHeader();
                $x = $pdf->GetX();
                $y = $pdf->GetY();
            }

            // More padding just for the Agenda cell
            $pdf->setCellPaddings(2.0, 0.8, 2.0, 0.8);

            // Agenda first -- FIXED height so TCPDF won't auto-break the cell
            $pdf->writeHTMLCell(
                $col2Width, $hEst,
                $x + $col1Width, $y,
                $topicHtml,
                1, 0, false, true, 'L', true
            );

            // Reset default paddings for other cells/rows
            $pdf->setCellPaddings(0.5, 0.5, 0.5, 0.5);

            // Reconcile with actual used height (ensure >= estimate)
            $hAgenda = $pdf->getLastH();
            if (!is_numeric($hAgenda) || $hAgenda <= 0) {
                $hAgenda = $hEst;
            } else {
                $hAgenda = max((float)$hAgenda, $hEst);
            }

            // Time cell, same height
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($col1Width, $hAgenda, $timeRange, 1, 'C', false, 0);

            // Next row baseline
            $pdf->SetXY($x, $y + $hAgenda);
        }

        $pdf->Ln(3);
    }
}

// Guard before Terms & Conditions so it never overlaps trailing rows
$bottomY   = $pdf->getPageHeight() - $pdf->getBreakMargin() - 12.0; // match FOOTER_BAND
$yNow      = $pdf->GetY();
$termsEstH = 10 /* heading */ + 4 /* gap */ + 60 /* conservative body estimate */;
if ($yNow + $termsEstH > $bottomY) {
    $pdf->AddPage();
}



    // Tentative Terms and Conditions
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', '', 9);
    $termsHtml = <<<EOD
    <h4>Tentative Terms and Conditions</h4>
    <div>
    (1) This tentative program is intended solely as a general guide and does not represent a fixed or final agenda.
    (2) Adjustments to the schedule may be made on-site based on real-time conditions such as weather (for outdoor programs), participant response and interaction levels, or logistical constraints.
    (3) During actual training session, the sequence and timing of modules or sessions may be adjusted accordingly to ensure optimal delivery and learning effectiveness.
    (4) Break times and session durations may be modified to accommodate unforeseen delays or to suit the dynamics of the training group.
    (5) For HRD Corp claimable programs, the total training hours shall comply with the approved grant.
    </div>
    EOD;
    $pdf->writeHTML($termsHtml, true, false, true, false, '');
}

