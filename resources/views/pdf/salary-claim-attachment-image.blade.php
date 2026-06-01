<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle ?? 'Claim Attachment' }} {{ $attachment['name'] ?? '' }}</title>
    <style>
        @page { margin: 10mm 18mm 13mm 18mm; }
        body {
            margin: 0;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.75pt;
            line-height: 1.32;
        }
        .pdf-header { color: #5f6673; margin-bottom: 5mm; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name { color: #273142; font-size: 10.5pt; font-weight: 700; margin-bottom: 1.2mm; }
        .company-address { font-size: 8.5pt; line-height: 1.25; margin-bottom: 1.4mm; }
        .company-contact { color: #273142; font-size: 8.75pt; font-weight: 700; }
        .company-logo { width: 39mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { color: #111827; font-family: Helvetica, Arial, sans-serif; font-size: 11pt; font-weight: 700; margin-top: 2mm; letter-spacing: 0.4px; text-transform: uppercase; }
        .header-separator { margin-top: 2mm; border-bottom: 0.6px solid #d6dbe3; }
        .meta {
            border: 0.6px solid #d8dee8;
            border-radius: 5px;
            background: #f8fafc;
            margin: 0 0 4mm 0;
            color: #647081;
            font-size: 9pt;
            padding: 1.8mm 2.2mm;
            white-space: nowrap;
        }
        .meta-title {
            color: #111827;
            font-weight: 700;
            margin-right: 6mm;
        }
        .meta span { display: inline-block; margin-right: 6mm; }
        .meta strong { color: #111827; }
        .attachment-frame {
            border: 0.6px solid #d8dee8;
            border-radius: 6px;
            background: #f8fafc;
            padding: 4mm;
            text-align: center;
        }
        .attachment-image {
            max-width: 100%;
            max-height: 205mm;
        }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'CLAIM ATTACHMENT',
        'pdfLanguage' => 'en',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <div class="meta">
            <span class="meta-title">Claim Attachment {{ $attachmentIndex ?? 1 }} of {{ $attachmentCount ?? $attachmentIndex ?? 1 }}</span>
            <span>{{ $periodLabel ?? 'Salary Period' }}: <strong>{{ $periodValue ?? $record['salaryMonth'] ?? '-' }}</strong></span>
            <span>Claim: <strong>{{ $claim['type'] ?? '-' }} - {{ $claim['description'] ?? '-' }}</strong></span>
        </div>

        <div class="attachment-frame">
            <img src="{{ $imageDataUri }}" alt="{{ $attachment['name'] ?? 'Claim attachment' }}" class="attachment-image">
        </div>
    </main>
</body>
</html>
