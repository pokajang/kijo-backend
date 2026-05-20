@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? (isset($inv) ? ($inv->document_language ?? 'en') : 'en'));
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $L('TAX INVOICE', 'Tax Invoice') }} {{ $inv->invoice_ref_no ?? '' }}</title>
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
        .invoice-meta { margin: 3mm 0 2mm 0; font-size: 11pt; }
        .to-block { font-size: 11pt; margin: 2mm 0; }
        .greeting { font-size: 11pt; margin: 2mm 0; }
        .intro { font-size: 11pt; line-height: 1.4; margin: 1mm 0 3mm 0; }
        table.breakdown { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        table.breakdown th, table.breakdown td { border: 0.5px solid #000; padding: 4px; vertical-align: top; }
        table.breakdown th { background: #f2f2f2; font-weight: 700; text-align: center; }
        table.breakdown td.center { text-align: center; }
        .muted-row { background: #f9f9f9; }
        .bank-block { font-size: 11pt; line-height: 1.4; margin: 3mm 0; }
        .sign-table { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        .sign-table td { vertical-align: top; padding: 0; }
        .sign-left { width: 50%; font-size: 11pt; }
        .sign-right { width: 50%; text-align: right; }
        .sign-img { max-width: 22mm; height: auto; margin-right: 2mm; }
        .stamp-img { max-width: 40mm; height: auto; }
        .terms-block { font-size: 9pt; margin: 2mm 0; }
        .terms-block h4 { margin: 2mm 0 1mm 0; font-size: 10pt; }
    </style>
</head>
<body>

@include('pdf.partials.company-header', [
    'documentType' => 'TAX INVOICE',
    'pdfLanguage' => $pdfLanguage,
    'logoDataUri' => $logoDataUri ?? null,
])

{{-- Ref & Date --}}
<p class="invoice-meta">
    {{ $L('invoice_number', 'Invoice Number') }}: {{ $inv->invoice_ref_no ?? '' }}
    &nbsp;&nbsp;&nbsp;&nbsp;
    {{ $L('date', 'Date') }}: {{ $inv->invoice_date ? date('d M Y', strtotime($inv->invoice_date)) : '-' }}
</p>

{{-- Billed-to block --}}
@php
    $clientName    = trim((string) ($inv->invoice_client_name ?? ''));
    $clientSsm     = trim((string) ($inv->invoice_client_ssm  ?? ''));
    $clientTin     = trim((string) ($inv->invoice_client_tin  ?? ''));
    $clientAddress = trim((string) ($inv->invoice_client_address ?? ''));
    $clientCity    = trim((string) ($inv->invoice_client_city  ?? ''));
    $clientState   = trim((string) ($inv->invoice_client_state ?? ''));
    $clientZip     = trim((string) ($inv->invoice_client_zip   ?? ''));
    $picName       = trim((string) ($inv->invoice_pic_name  ?? ''));
    $picEmail      = trim((string) ($inv->invoice_pic_email ?? ''));
    $picPhone      = trim((string) ($inv->invoice_pic_phone ?? ''));
    $clientAddressLines = [];
    if ($clientAddress !== '') {
        $clientAddressLines[] = $clientAddress;
    }
    $clientLocationLine = implode(', ', array_filter([$clientCity, $clientState, $clientZip], static fn (string $part): bool => $part !== ''));
    if ($clientLocationLine !== '') {
        $clientAddressLines[] = $clientLocationLine;
    }
@endphp
<p class="to-block">
    <strong>{{ $L('attention_to', 'Attention To') }}:</strong><br>
    @if($picName !== ''){{ $picName }}<br>@endif
    @if($clientName !== ''){{ $clientName }}<br>@endif
    SSM No. : {{ $clientSsm !== '' ? $clientSsm : 'N/A' }}<br>
    Tax Identification Number (TIN) : {{ $clientTin !== '' ? $clientTin : 'N/A' }}<br>
    @foreach($clientAddressLines as $addressLine){{ $addressLine }}<br>@endforeach
    @if($picEmail !== '' || $picPhone !== '')
        {{ $L('email', 'Email') }}: {{ $picEmail !== '' ? $picEmail : 'N/A' }} &nbsp;&nbsp;&nbsp; {{ $L('phone', 'Phone') }}: {{ $picPhone !== '' ? $picPhone : 'N/A' }}
    @endif
</p>

<p class="greeting">{{ $pdfLanguage === 'ms-MY' ? 'Kepada' : 'Dear' }} <strong>{{ $L('dear_valued_customer', 'Valued Customer') }}</strong>,</p>
<p class="intro">{{ $L('invoice_intro', 'We appreciate your business. Please review the Tax Invoice below for your kind action.') }}</p>

{{-- Breakdown table --}}
@php
    $serviceType    = (string) ($inv->service_type ?? '');
    $invoicePurpose = (string) ($inv->invoice_purpose ?? '');
    $loaNo          = (string) ($inv->invoice_loa_no ?? '');
    $isManpower     = strtolower(trim($serviceType)) === 'manpower supply';

    // Trim "For Month(s): ..." from purpose for display header
    $purposeDisplay = $invoicePurpose;
    if ($isManpower) {
        $purposeDisplay = preg_replace('/\s*-\s*For (Month|Months):\s*.+$/i', '', $invoicePurpose);
        $purposeDisplay = trim((string) $purposeDisplay);
    }

    // Extract claim label for manpower
    $claimLabel = '';
    if ($isManpower && preg_match('/-\s*For (Month|Months):\s*(.+)$/i', $invoicePurpose, $match)) {
        $claimType  = $match[1];
        $claimValue = trim($match[2]);
        if (strcasecmp($claimType, 'Month') === 0 && preg_match('/^\d{4}-\d{2}$/', $claimValue)) {
            $dt = \DateTime::createFromFormat('Y-m', $claimValue);
            if ($dt instanceof \DateTime) { $claimValue = $dt->format('F Y'); }
        }
        $claimLabel = 'For ' . $claimType . ': ' . $claimValue;
    }

    // Split discount items to end
    $nonDiscountItems = [];
    $discountItems    = [];
    foreach ($preTax as $itm) {
        $dl = strtolower((string) ($itm->item_description ?? ''));
        if (str_contains($dl, 'discount') || str_contains($dl, 'less')) {
            $discountItems[] = $itm;
        } else {
            $nonDiscountItems[] = $itm;
        }
    }
    $orderedItems            = array_merge($nonDiscountItems, $discountItems);
    $subtotalBeforeDiscount  = 0.0;
    $netSubtotal             = 0.0;
@endphp

<table class="breakdown">
    <tr>
        <th width="5%">#</th>
        <th width="40%">{{ $L('description', 'Description') }}</th>
        <th width="15%">U/P (RM)</th>
        <th width="10%">{{ $L('qty', 'Qty') }}</th>
        <th width="10%">{{ $L('unit', 'Unit') }}</th>
        <th width="20%">{{ $L('subtotal_rm', 'Subtotal (RM)') }}</th>
    </tr>
    <tr>
        <td></td>
        <td colspan="5">
            {{ $serviceType }} - {{ $purposeDisplay !== '' ? $purposeDisplay : $invoicePurpose }}
            @if($loaNo !== '')<br>LOA/PO Number: {{ $loaNo }}@endif
        </td>
    </tr>
    @foreach($orderedItems as $i => $itm)
        @php
            $descLabel  = (string) ($itm->item_description ?? '');
            $lineDesc   = trim((string) ($itm->description ?? ''));
            $isDiscount = str_contains(strtolower($descLabel), 'discount') || str_contains(strtolower($descLabel), 'less');
            $purposeNorm     = strtolower(trim($invoicePurpose));
            $basePurposeNorm = strtolower(trim($purposeDisplay));
            $descNorm        = strtolower(trim($descLabel));
            $isManpowerBase  = $isManpower && !$isDiscount && (
                ($purposeNorm !== '' && $descNorm === $purposeNorm) ||
                ($basePurposeNorm !== '' && $descNorm === $basePurposeNorm)
            );

            $raw = (float) $itm->subtotal;
            if ($isDiscount) { $raw = -abs($raw); }

            $netSubtotal += $raw;
            if (!$isDiscount) { $subtotalBeforeDiscount += $raw; }

            $up   = number_format((float) $itm->unit_price, 2);
            $qty  = number_format((float) $itm->quantity, 2);
            $unit = (string) ($itm->unit ?? '');
            $sub  = number_format($raw, 2);
        @endphp
        <tr>
            <td class="center">{{ $i + 1 }}</td>
            <td>
                @if($isManpowerBase)
                    {{ $claimLabel !== '' ? $claimLabel : 'Claim Period' }}<br>
                    <em>{{ $L('remarks', 'Remarks') }}:</em> {{ trim((string) ($inv->remarks ?? '')) !== '' ? $inv->remarks : 'N/A' }}
                @else
                    {{ $descLabel }}
                    @if($lineDesc !== '')<br><span style="font-size:9pt;color:#555;">{{ $lineDesc }}</span>@endif
                @endif
            </td>
            <td class="center">{{ $up }}</td>
            <td class="center">{{ $qty }}</td>
            <td class="center">{{ $unit }}</td>
            <td class="center">{{ $sub }}</td>
        </tr>
    @endforeach
    <tr class="muted-row">
        <td colspan="5" style="text-align:right;"><strong>{{ $L('subtotal_rm', 'Subtotal (RM)') }}</strong></td>
        <td class="center"><strong>{{ number_format($subtotalBeforeDiscount, 2) }}</strong></td>
    </tr>
    @if((float)($inv->sst_amount ?? 0) > 0)
        @php
            $baseAmt   = $netSubtotal;
            if ($baseAmt <= 0) { $baseAmt = (float) ($inv->amount ?? 0); }
            $rateLabel = 'SST (RM)';
            if ($baseAmt > 0) {
                $rateVal  = ((float) $inv->sst_amount / $baseAmt) * 100;
                $rateTxt  = rtrim(rtrim(number_format($rateVal, 2, '.', ''), '0'), '.');
                $rateLabel = $rateTxt . '% SST (RM)';
            }
        @endphp
        <tr>
            <td colspan="5" style="text-align:right;"><strong>{{ $rateLabel }}</strong></td>
            <td class="center"><strong>{{ number_format((float) $inv->sst_amount, 2) }}</strong></td>
        </tr>
    @endif
    <tr>
        <td colspan="5" style="text-align:right;"><strong>{{ $L('grand_total_rm', 'Grand Total (RM)') }}</strong></td>
        <td class="center"><strong>{{ number_format((float) ($inv->grand_total ?? 0), 2) }}</strong></td>
    </tr>
</table>

{{-- Banking info --}}
<p class="bank-block">
    {{ $L('payment_instruction', 'Please remit payment to the following account:') }}<br>
    <strong>{{ $L('bank_name', 'Bank Name') }}</strong>: CIMB BANK BERHAD &nbsp;&nbsp;&nbsp;
    <strong>{{ $L('branch', 'Branch') }}</strong>: UNIKEB Bandar Baru Bangi<br>
    <strong>{{ $L('account_name', 'Account Name') }}</strong>: AMIOSH RESOURCES SDN BHD &nbsp;&nbsp;&nbsp;
    <strong>{{ $L('account_number', 'Account Number') }}</strong>: 8002246023
</p>

{{-- Signature block --}}
@php
    $creatorName  = $creator ? ($creator->full_name ?? '') : '';
    $signOffTitle = '';
    if ($creator) {
        $signOffTitle = $creator->signOffTitle ?? (($creator->position ?? '') . ' (' . ($creator->department ?? '') . ')');
    }
@endphp
<table class="sign-table">
    <tr>
        <td class="sign-left">
            {{ $L('prepared_by', 'Prepared by') }}:<br>
            <strong>{{ $creatorName }}</strong><br>
            {{ $signOffTitle }}<br>
            AMIOSH RESOURCES SDN BHD
        </td>
        <td class="sign-right">
            @if($signDataUri)
                <img src="{{ $signDataUri }}" class="sign-img" alt="Signature">
            @endif
            @if($stampDataUri)
                <img src="{{ $stampDataUri }}" class="stamp-img" alt="Stamp">
            @endif
            @if(!$signDataUri && !$stampDataUri)
                <span style="font-size:8pt;font-style:italic;">{{ $L('no_signature_or_stamp', '[No signature or stamp on file]') }}</span>
            @endif
        </td>
    </tr>
</table>

{{-- Terms & Conditions --}}
<div class="terms-block">
    <h4><strong>{{ $L('terms_and_conditions', 'Terms and Conditions') }}</strong></h4>
    <p style="font-size:9pt;margin:0;">
        @php
            $termsDays = (int) ($inv->payment_terms_days ?? 30);
            $invoiceTerms = \App\Support\PdfLegalTerms::get($pdfLanguage, 'invoice');
            if (!empty($invoiceTerms)) {
                $invoiceTerms[0] = $pdfLanguage === 'ms-MY'
                    ? "Bayaran perlu dijelaskan dalam tempoh {$termsDays} hari dari tarikh invois ini."
                    : "Payment is due within {$termsDays} days from the date of this invoice.";
            }
        @endphp
        @foreach($invoiceTerms as $index => $term)
            ({{ $index + 1 }}) {{ $term }}
        @endforeach
    </p>
</div>

</body>
</html>
