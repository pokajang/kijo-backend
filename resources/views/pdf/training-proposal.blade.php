@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? (isset($proposal) ? ($proposal->proposal_language ?? 'en') : 'en'));
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $L('BROCHURE', 'Training Brochure') }} - {{ $proposal->training_title ?? '' }}</title>
    <style>
        @page { margin: 10mm 20mm 10mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; text-align: justify; }
        .pdf-header { color: #696969; margin-bottom: 4mm; }
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
            margin: 0 auto 5mm auto;
            width: 81%;
            border: 0.6px solid #c8f0c8;
            background: #f0fff0;
            border-radius: 2mm;
            padding: 3mm 4mm;
            text-align: center;
            font-size: 13pt;
            font-weight: 700;
            color: #003c00;
        }
        .section-title {
            margin: 0 0 1.5mm 0;
            color: #006400;
            font-size: 11pt;
            font-weight: 700;
        }
        .section-body { margin: 0 0 3.2mm 0; font-size: 10pt; }
        .section-body p,
        .section-body ul,
        .section-body ol,
        .section-body li { font-size: 10pt !important; line-height: 1.35 !important; }
        .section-body li * { font-size: inherit !important; line-height: inherit !important; }

        .agenda-heading { margin: 1mm 0 1.8mm 0; color: #006400; font-size: 11pt; font-weight: 700; }
        .day-heading { margin: 0.8mm 0 1.5mm 0; font-weight: 700; }
        .agenda-table { width: 100%; border-collapse: collapse; margin: 0 0 3mm 0; font-size: 10pt; }
        .agenda-table th, .agenda-table td { border: 0.5px solid #000; padding: 2mm 2.2mm; vertical-align: top; font-size: 10pt; }
        .agenda-table th { background: #dcdcdc; font-weight: 700; text-align: center; }
        .time-cell { width: 42mm; text-align: center; white-space: nowrap; }
        .terms-title { margin: 2mm 0 2mm 0; font-size: 10.5pt; font-weight: 700; }
        .terms-body { font-size: 10pt; line-height: 1.35; }
        .terms-list { margin: 0; padding-left: 5mm; font-size: 9pt; line-height: 1.35; }
        .terms-list li { margin-bottom: 0.8mm; }
        .section-body p { margin: 0 0 2mm 0; }
        .section-body ul, .section-body ol { margin: 0 0 2mm 0; padding-left: 5mm; }
        .section-body li { margin-bottom: 0.8mm; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'BROCHURE',
        'pdfLanguage' => $pdfLanguage,
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        @include('pdf.partials.training-proposal-content', [
            'proposalTitle' => $proposal->training_title ?? '',
            'sections' => $sections ?? [],
            'agendaByDay' => $agendaByDay ?? [],
        ])
    </main>
</body>
</html>
