@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en');
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $quoteRefNo ?? $L('QUOTATION', 'Special Quote') }}</title>
    <style>
        @page { margin: 36mm 20mm 16mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; text-align: justify; }
        p { margin: 0 0 2mm 0; }

        .pdf-header { position: fixed; top: -26mm; left: 0; right: 0; height: 24mm; color: #696969; margin-bottom: 0; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; border: 0; }
        .header-left { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; }
        .company-logo { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }

        .quote-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 2.2mm; font-size: 10pt; }
        .quote-table th, .quote-table td { border: 0.5px solid #000; padding: 2mm 2.2mm; vertical-align: top; }
        .quote-table th { background: #f0f0f0; text-align: center; font-weight: 700; }
        .col-no { width: 5%; text-align: center; }
        .col-item { width: 40%; }
        .col-unit { width: 15%; text-align: center; }
        .col-qty { width: 8%; text-align: center; }
        .col-unit-price { width: 13%; text-align: center; }
        .col-amount { width: 19%; text-align: center; }
        .totals-label { text-align: right; font-weight: 400; }
        .totals-value { text-align: center; }
        .muted { font-size: 9pt; color: #6c757d; }
        .small-note { font-size: 8pt; color: #666; font-style: italic; }

        .page-break { page-break-before: always; height: 0; margin: 0; padding: 0; }
        .accept-box { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        .accept-box td { border: 0.5px solid #000; width: 50%; height: 30mm; vertical-align: top; padding: 4px; text-align: left; }
        .terms-title { font-size: 11pt; font-weight: 700; margin: 3mm 0 1.5mm 0; page-break-after: avoid; break-after: avoid; }
        .terms-section-title { font-size: 10.5pt; font-weight: 700; margin: 0 0 1.5mm 0; page-break-after: avoid; break-after: avoid; }
        ol { margin: 0 0 2mm 0; padding-left: 5mm; font-size: 10pt; line-height: 1.35; }
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
        .proposal-content { font-size: 10pt; line-height: 1.35; text-align: justify; }
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
        <p>{{ $L('special_intro', 'Thank you for your interest in our service. Please find below the quotation details.') }}</p>

        <table class="quote-table">
            <thead>
                <tr>
                    <th class="col-no">#</th>
                    <th class="col-item">{{ $L('item_service_details', 'Item / Service Details') }}</th>
                    <th class="col-unit">{{ $L('unit', 'Unit') }}</th>
                    <th class="col-qty">{{ $L('qty', 'Qty') }}</th>
                    <th class="col-unit-price">{{ $L('unit_price_rm', 'Unit Price (RM)') }}</th>
                    <th class="col-amount">{{ $L('amount_rm', 'Amount (RM)') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="col-no"></td>
                    <td colspan="5">
                        <strong>{{ $L('service', 'Service') }}:</strong> {{ $serviceTitle }} ({{ $serviceCode }})<br>
                        @if(!empty($remarksHtml))
                            {{ $L('remarks', 'Remarks') }}: <span class="muted">{!! $remarksHtml !!}</span>
                        @endif
                    </td>
                </tr>

                @foreach($items as $index => $item)
                    <tr>
                        <td class="col-no">{{ $index + 1 }}</td>
                        <td class="col-item">
                            <strong>{{ $item->title }}</strong><br>
                            <span class="muted">{{ $item->description }}</span>
                        </td>
                        <td class="col-unit">{{ $item->unit }}</td>
                        <td class="col-qty">{{ (int) $item->quantity }}</td>
                        <td class="col-unit-price">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="col-amount">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="5" class="totals-label">{{ $L('amount_rm', 'Amount (RM)') }}</td>
                    <td class="totals-value">RM {{ number_format((float) $grossAmount, 2) }}</td>
                </tr>

                @if($discountAmount > 0)
                    <tr>
                        <td colspan="5" class="totals-label">{{ $L('discount_rm', 'Discount (RM)') }}</td>
                        <td class="totals-value">- RM {{ number_format((float) $discountAmount, 2) }}</td>
                    </tr>
                    @if($showSubtotal)
                        <tr>
                            <td colspan="5" class="totals-label">{{ $L('subtotal_rm', 'Subtotal (RM)') }}</td>
                            <td class="totals-value">RM {{ number_format((float) $subTotalNet, 2) }}</td>
                        </tr>
                    @endif
                @endif

                @if($sstAmount > 0)
                    <tr>
                        <td colspan="5" class="totals-label">{{ $sstPercentLabel }}% {{ $L('sst_charge_rm', 'SST Charge (RM)') }}</td>
                        <td class="totals-value">RM {{ number_format((float) $sstAmount, 2) }}</td>
                    </tr>
                @endif

                <tr>
                    <td colspan="5" class="totals-label"><strong>{{ $L('grand_total_rm', 'Grand Total (RM)') }}</strong></td>
                    <td class="totals-value"><strong>RM {{ number_format((float) $grandTotal, 2) }}</strong></td>
                </tr>
            </tbody>
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
                    {{ $L('name', 'Name') }}:<br><br>
                    {{ $L('position', 'Position') }}:<br><br>
                    {{ $L('signature', 'Signature') }}:<br>
                </td>
                <td>
                    {{ $L('company_stamp', 'Company Stamp') }}:<br><br>
                    {{ $L('date', 'Date') }}:
                </td>
            </tr>
        </table>

        <p class="terms-title">{{ $L('terms_and_conditions', 'Terms and Conditions') }}</p>

        <p class="terms-section-title">{{ $L('general', 'General') }}</p>
        <ol>
            @foreach(\App\Support\PdfLegalTerms::get($pdfLanguage, 'special_general') as $term)
                <li>{{ $term }}</li>
            @endforeach
        </ol>

        <p class="terms-section-title">{{ $L('technical', 'Technical') }}</p>
        <ol>
            @foreach(\App\Support\PdfLegalTerms::get($pdfLanguage, 'special_technical') as $term)
                <li>{{ $term }}</li>
            @endforeach
        </ol>

    </main>
</body>
</html>
