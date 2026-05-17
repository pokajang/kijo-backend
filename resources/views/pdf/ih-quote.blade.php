@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en');
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $quoteRefNo ?? $L('QUOTATION', 'IH Quote') }}</title>
    <style>
        @page { margin: 10mm 20mm 10mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; text-align: justify; }
        p { margin: 0 0 2mm 0; }

        .pdf-header { color: #696969; margin-bottom: 4mm; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; }
        .company-logo { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }

        .quote-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 2.2mm; font-size: 10.5pt; }
        .quote-table td { border: 0.5px solid #000; padding: 4px 5px; vertical-align: top; text-align: left; }
        .quote-table .label { width: 35%; font-weight: 700; }
        .quote-table .value { width: 65%; }
        .muted { font-size: 9pt; color: #6c757d; }
        .small-note { font-size: 8pt; color: #666; font-style: italic; }

        .page-break { page-break-before: always; }
        .accept-box { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        .accept-box td { border: 0.5px solid #000; width: 50%; height: 30mm; vertical-align: top; padding: 4px; text-align: left; }
        .terms-title { font-size: 11pt; font-weight: 700; margin: 0 0 1.5mm 0; }
        .terms-section-title { font-size: 10.5pt; font-weight: 700; margin: 0 0 1.5mm 0; }
        ol { margin: 0 0 2mm 0; padding-left: 5mm; font-size: 9pt; }
        li { margin-bottom: 1.2mm; }

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
        .section-title { margin: 0 0 1.2mm 0; color: #006400; font-size: 11pt; font-weight: 700; }
        .section-body { margin: 0 0 3mm 0; font-size: 10pt; }
        .section-body p,
        .section-body ul,
        .section-body ol,
        .section-body li { font-size: 10pt !important; line-height: 1.35 !important; }
        .section-body li * { font-size: inherit !important; line-height: inherit !important; }
        .section-body p { margin: 0 0 2mm 0; }
        .section-body ul, .section-body ol { margin: 0 0 2mm 0; padding-left: 5mm; }
        .section-body li { margin-bottom: 0.8mm; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'QUOTATION',
        'pdfLanguage' => $pdfLanguage,
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <p>
            @if(!empty($revisionNo) && (int) $revisionNo > 0)
                {{ $L('quote_number', 'Quote Number') }}: {{ $quoteRefNo }} (Rev0{{ (int) $revisionNo }}) &nbsp;&nbsp;
                {{ $L('rev_date', 'Rev. Date') }}: {{ $updatedDateIso }} &nbsp;&nbsp;
                {{ $L('ori_date', 'Ori. Date') }}: {{ $createdDateIso }}
            @else
                {{ $L('quote_number', 'Quote Number') }}: {{ $quoteRefNo }} &nbsp;&nbsp; {{ $L('date', 'Date') }}: {{ $createdDateLegacy }}
            @endif
        </p>

        <p>
            <strong>{{ $L('attention_to', 'Attention To') }}:</strong><br>
            {{ $picName }}<br>
            {{ $clientName }}<br>
            {!! nl2br(e($clientAddressBlock)) !!}<br>
            {{ $L('email', 'Email') }}: {{ $picEmail }} &nbsp;&nbsp;&nbsp;&nbsp;
            {{ $L('phone', 'Phone') }}: {{ $picPhone }}
        </p>

        <p>{{ $pdfLanguage === 'ms-MY' ? 'Kepada' : 'Dear' }} <strong>{{ $L('dear_valued_customer', 'Valued Customer') }}</strong>,</p>

        <p>
            {{ $L('ih_intro', 'Thank you for your interest in our Industrial Hygiene services. We are pleased to provide you with the following quotation for') }}
            <strong>{{ $serviceTitle }}</strong>.
        </p>

        <table class="quote-table">
            <tr>
                <td class="label">{{ $L('service_details', 'Service Details') }}</td>
                <td class="value">
                    <strong>{{ $L('service_title', 'Service Title') }}:</strong> {{ $serviceTitle }} ({{ $serviceCode }})<br>
                    <strong>{{ $L('site_address', 'Site Address') }}:</strong> {{ $siteAddress }}<br>
                    <strong>{{ $L('samples', 'Samples') }}:</strong> {{ $sampleCount }} {{ $sampleUnit }}<br>
                    <strong>{{ $L('work_units', 'Work Units') }}:</strong> {{ $workUnitsDisplay }}<br>
                    <strong>{{ $L('remarks', 'Remarks') }}:</strong> {!! $remarksHtml !!}<br>
                    <strong>{{ $L('important', 'Important') }}:</strong> <em>{{ $pdfLanguage === 'ms-MY' ? 'Caj tambahan akan dikenakan untuk unit kerja tambahan (jika berkaitan) dan bilangan sampel.' : 'Additional charges will be applied for extra work units (where applicable) and number of samples.' }}</em>
                </td>
            </tr>
            <tr>
                <td class="label">{{ $L('unit_cost', 'Unit Cost') }}</td>
                <td class="value">RM {{ number_format($unitPrice, 2) }} per unit</td>
            </tr>
            <tr>
                <td class="label">{{ $L('amount', 'Amount') }}</td>
                <td class="value">RM {{ number_format($grossSubtotal, 2) }} <span class="muted">({{ $subtotalDetail }})</span></td>
            </tr>
            @if($discountAmount > 0)
                <tr>
                    <td class="label">{{ $L('discount', 'Discount') }}</td>
                    <td class="value">- RM {{ number_format($discountAmount, 2) }}</td>
                </tr>
                @if($showNetSubtotal)
                    <tr>
                        <td class="label">{{ $L('subtotal', 'Subtotal') }}</td>
                        <td class="value">RM {{ number_format($subTotalNet, 2) }}</td>
                    </tr>
                @endif
            @endif
            @if($sstAmount > 0)
                <tr>
                    <td class="label">{{ $sstPercentLabel }}% {{ $L('sst_charge', 'SST Charge') }}</td>
                    <td class="value">RM {{ number_format($sstAmount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td class="label">{{ $L('grand_total', 'Grand Total') }}</td>
                <td class="value"><strong>RM {{ number_format($grandTotal, 2) }}</strong></td>
            </tr>
        </table>

        <p>
            {{ $L('review_terms', 'Kindly review the terms and conditions outlined in the next page, and return a duly signed copy of this quotation as confirmation of your acceptance.') }}
        </p>
        <p>
            {{ $L('prepared_by', 'Prepared by') }}: <strong>{{ $preparedByName }}</strong><br>
            {{ $signOffTitle }}<br>
            AMIOSH RESOURCES SDN BHD<br>
            <span class="small-note">{{ $L('computer_generated', '[This is a computer-generated document. No signature required.]') }}</span>
        </p>

        <div class="page-break"></div>

        <p style="margin-bottom: 1mm;"><strong>{{ $L('customer_acceptance', 'Customer Acceptance') }}</strong></p>
        <p style="font-size: 10pt;">
            {{ $L('acceptance_text', 'I/We hereby accept the terms and conditions stated in this quotation and confirm our intention to proceed.') }}
        </p>

        <table class="accept-box">
            <tr>
                <td>
                    <br>
                    {{ $L('name', 'Name') }}:<br><br>
                    {{ $L('position', 'Position') }}:<br><br>
                    {{ $L('signature', 'Signature') }}:<br>
                </td>
                <td>
                    <br>
                    {{ $L('company_stamp', 'Company Stamp') }}:<br><br>
                    {{ $L('date', 'Date') }}:
                </td>
            </tr>
        </table>

        <p class="terms-title" style="margin-top: 4mm;">{{ $L('terms_and_conditions', 'Terms and Conditions') }}</p>

        <p class="terms-section-title">A. {{ $L('general', 'General') }}</p>
        <ol>
            @foreach(\App\Support\PdfLegalTerms::get($pdfLanguage, 'ih_general') as $term)
                <li>{{ $term }}</li>
            @endforeach
        </ol>

        <p class="terms-section-title">B. {{ $L('technical', 'Technical') }}</p>
        <ol>
            @foreach(\App\Support\PdfLegalTerms::get($pdfLanguage, 'ih_technical') as $term)
                <li>{{ $term }}</li>
            @endforeach
        </ol>

        @if($appendProposal)
            <div class="page-break"></div>
            @include('pdf.partials.company-header', [
                'documentType' => 'SERVICE PROPOSAL',
                'pdfLanguage' => $pdfLanguage,
                'logoDataUri' => $logoDataUri ?? null,
            ])

            @include('pdf.partials.ih-proposal-main-content', [
                'proposalTitle' => $proposalTitle ?? 'Service Proposal',
                'sections' => $proposalSections ?? [],
            ])
        @endif

        @if($appendProposal && !empty($additionalInfoHtml))
            <div class="page-break"></div>
            @include('pdf.partials.company-header', [
                'documentType' => 'SERVICE PROPOSAL',
                'pdfLanguage' => $pdfLanguage,
                'logoDataUri' => $logoDataUri ?? null,
            ])

            @include('pdf.partials.ih-proposal-additional-content', [
                'additionalInfoHtml' => $additionalInfoHtml,
            ])
        @endif
    </main>
</body>
</html>
