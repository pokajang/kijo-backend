@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? (isset($proposal) ? ($proposal->proposal_language ?? 'en') : 'en'));
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
    $proposalTitle = \App\Support\ProposalTitleFormatter::formatProposalTitle(
        (string) ($proposal->service_title ?? ''),
        'Manpower Supply Service Proposal',
        'Manpower Supply Service Proposal',
        'view.pdf.manpower-proposal.title',
    );
    $proposalTitleBase = \App\Support\ProposalTitleFormatter::removeSuffix($proposalTitle, 'Manpower Supply Service Proposal');
    if ($proposalTitleBase === '') {
        $proposalTitleBase = 'Service';
    }
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $L('SERVICE PROPOSAL', 'Manpower Service Proposal') }} - {{ $proposalTitleBase }}</title>
    <style>
        @page { margin: 36mm 20mm 16mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; text-align: justify; }
        .pdf-header { position: fixed; top: -26mm; left: 0; right: 0; height: 24mm; color: #696969; margin-bottom: 0; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; margin-top: 0.8mm; }
        .header-right { width: 32%; text-align: right; }
        .company-logo { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }

        .title-box {
            margin: 0 0 6mm;
            width: 95.2%; /* Dompdf applies horizontal padding outside the percentage width. */
            border: 0.6px solid #c8ffc8;
            background: #f0fff0;
            border-radius: 2mm;
            padding: 3mm 4mm;
            text-align: center;
            font-size: 13pt;
            font-weight: 700;
            color: #003c00;
        }
        .section-title { margin: 4mm 0 1mm 0; color: #003c00; font-size: 11pt; font-weight: 700; page-break-after: avoid; break-after: avoid; }
        .section-body { margin: 0 0 4mm 0; font-size: 10pt; color: #000; line-height: 1.35; page-break-before: avoid; break-before: avoid; }
        .section-body p,
        .section-body ul,
        .section-body ol,
        .section-body li,
        .section-body table,
        .section-body th,
        .section-body td { font-size: 10pt !important; line-height: 1.35 !important; }
        .section-body li * { font-size: inherit !important; line-height: inherit !important; }
        .section-body ol > li::marker,
        .section-body ul > li::marker { font-size: 10pt !important; }
        .section-body h1,
        .section-body h2,
        .section-body h3,
        .section-body h4,
        .section-body h5,
        .section-body h6 { font-size: 11pt !important; line-height: 1.3 !important; page-break-after: avoid; break-after: avoid; }
        .section-body p *,
        .section-body li *,
        .section-body th *,
        .section-body td * { font-size: inherit !important; line-height: inherit !important; }
        .section-body p { margin: 0 0 2mm 0; }
        .section-body ul, .section-body ol { margin: 0 0 2mm 0; padding-left: 5mm; }
        .section-body li { margin-bottom: 0.8mm; }
        .section-body p,
        .section-body li { page-break-inside: avoid; break-inside: avoid; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'SERVICE PROPOSAL',
        'pdfLanguage' => $pdfLanguage,
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        @include('pdf.partials.manpower-proposal-content', [
            'proposalTitle' => $proposalTitle,
            'sections' => $sections ?? [],
        ])
    </main>
</body>
</html>
