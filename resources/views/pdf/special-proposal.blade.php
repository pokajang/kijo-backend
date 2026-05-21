@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? (isset($proposal) ? ($proposal->proposal_language ?? 'en') : 'en'));
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $L('SERVICE PROPOSAL', 'Special Service Proposal') }} - {{ $proposal->service_title ?? '' }}</title>
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

        .proposal-content { font-size: 10pt; line-height: 1.35; }
        .proposal-content p,
        .proposal-content ul,
        .proposal-content ol,
        .proposal-content li,
        .proposal-content table,
        .proposal-content th,
        .proposal-content td { font-size: 10pt !important; line-height: 1.35 !important; }
        .proposal-content li * { font-size: inherit !important; line-height: inherit !important; }
        .proposal-content ol > li::marker,
        .proposal-content ul > li::marker { font-size: 10pt !important; }
        .proposal-content p { margin: 0 0 2mm 0; }
        .proposal-content ul, .proposal-content ol { margin: 0 0 2mm 0; padding-left: 5mm; }
        .proposal-content li { margin-bottom: 0.8mm; }
        .proposal-content p,
        .proposal-content li { page-break-inside: avoid; break-inside: avoid; }
        .proposal-content table { width: 100%; border-collapse: collapse; margin: 0 0 2mm 0; }
        .proposal-content th, .proposal-content td { border: 0.5px solid #000; padding: 2mm 2.2mm; vertical-align: top; }
        .proposal-content th { background: #dcdcdc; font-weight: 700; text-align: center; }
        .proposal-content h1, .proposal-content h2, .proposal-content h3, .proposal-content h4, .proposal-content h5, .proposal-content h6 {
            margin: 0 0 1.5mm 0;
            color: #006400;
            font-size: 11pt !important;
            line-height: 1.3;
            page-break-after: avoid;
            break-after: avoid;
        }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'SERVICE PROPOSAL',
        'pdfLanguage' => $pdfLanguage,
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        @include('pdf.partials.special-proposal-content', [
            'proposalTitle' => $proposalTitle ?? 'Service Proposal',
            'contentHtml' => $contentHtml ?? '',
        ])
    </main>
</body>
</html>
