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
        .invoice-meta { font-size: 11pt; margin: 3mm 0 2mm 0; }
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
    $paymentMethod = (string) ($inv->payment_method ?? '');
    $isHrdGrant    = strcasecmp($paymentMethod, 'HRD Grant') === 0;
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

@if($isHrdGrant)
<p class="to-block">
    <strong>{{ $L('attention_to', 'Attention To') }}:</strong><br>
    Human Resource Development Corporation<br>
    SSM No. : {{ $clientSsm !== '' ? $clientSsm : 'N/A' }}<br>
    Tax Identification Number (TIN) : {{ $clientTin !== '' ? $clientTin : 'N/A' }}<br>
    Wisma HRD Corp<br>
    Jalan Beringin, Bukit Damansara,<br>
    50490 Kuala Lumpur.
</p>
@else
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
@endif

@if($isHrdGrant)
    <p class="greeting">{{ $pdfLanguage === 'ms-MY' ? 'Kepada' : 'Dear' }} <strong>{{ $L('dear_hrd_officer', 'Respected HRD Officer') }}</strong>,</p>
    <p class="intro">{{ $L('invoice_training_intro', 'Kindly find the tax invoice for the training program we conducted as detailed below.') }}</p>
@else
    <p class="greeting">{{ $pdfLanguage === 'ms-MY' ? 'Kepada' : 'Dear' }} <strong>{{ $L('dear_valued_customer', 'Valued Customer') }}</strong>,</p>
    <p class="intro">{{ $L('invoice_intro', 'We appreciate your business. Please review the Tax Invoice below for your kind action.') }}</p>
@endif

{{-- Build ordered items: training fee → meal total → mobilization → custom → discounts --}}
@php
    $baseItems   = ['training' => null, 'meal' => null, 'mobilization' => null];
    $customItems = [];
    $discounts   = [];
    $loaNo       = (string) ($inv->invoice_loa_no ?? '');

    foreach ($preTax as $itm) {
        $dk = strtolower(trim((string) ($itm->item_description ?? '')));
        if (str_contains($dk, 'discount') || str_contains($dk, 'less')) {
            $discounts[] = $itm;
        } elseif (str_contains($dk, 'training fee')) {
            $baseItems['training'] = $itm;
        } elseif (str_contains($dk, 'meal total')) {
            $baseItems['meal'] = $itm;
        } elseif (str_contains($dk, 'mobilization')) {
            $baseItems['mobilization'] = $itm;
        } else {
            $customItems[] = $itm;
        }
    }

    $orderedItems = array_values(array_filter([$baseItems['training'], $baseItems['meal'], $baseItems['mobilization']]));
    $orderedItems = array_merge($orderedItems, $customItems, $discounts);

    $projectTitle   = (string) ($project->project_name ?? '');
    $companyName    = (string) ($project->company_name ?? '');
    $companySsm     = (string) ($project->ssm_number ?? '');
    $svcStartDate   = $project ? ($project->service_start_date ?? null) : null;
    $svcEndDate     = $project ? ($project->service_end_date ?? null) : null;
    $startFmt       = $svcStartDate ? date('d M Y', strtotime($svcStartDate)) : '-';
    $endFmt         = $svcEndDate   ? date('d M Y', strtotime($svcEndDate))   : '-';
    $remarksVal     = trim((string) ($inv->remarks ?? ''));
    $grantApproval  = (string) ($inv->grant_approval_no ?? '');

    $runningSubtotal = 0.0;
@endphp

<table class="breakdown">
    <tr>
        <th width="5%">#</th>
        <th width="50%">{{ $L('description', 'Description') }}</th>
        <th width="15%">{{ $L('unit_price_rm', 'Unit Price (RM)') }}</th>
        <th width="10%">{{ $L('qty', 'Qty') }}</th>
        <th width="20%">{{ $L('subtotal_rm', 'Subtotal (RM)') }}</th>
    </tr>
    @foreach($orderedItems as $index => $itm)
        @php
            $descLabel = (string) ($itm->item_description ?? '');
            $lineDesc  = trim((string) ($itm->description ?? ''));
            $isDisc    = str_contains(strtolower($descLabel), 'discount') || str_contains(strtolower($descLabel), 'less');
            $rawSub    = (float) $itm->subtotal;
            if ($isDisc) { $rawSub = -abs($rawSub); }
            $runningSubtotal += $rawSub;
            $up  = number_format((float) $itm->unit_price, 2);
            $qty = number_format((float) $itm->quantity, 2);
            $sub = number_format($rawSub, 2);
        @endphp
        <tr>
            <td class="center">{{ $index + 1 }}</td>
            <td>
                {{ $descLabel }}
                @if($lineDesc !== '')<br><span style="font-size:9pt;color:#555;">{{ $lineDesc }}</span>@endif
                @if($index === 0)
                    <br><br>
                    <strong>{{ $pdfLanguage === 'ms-MY' ? 'Nama Penyedia' : 'Provider Name' }}:</strong> AMIOSH RESOURCES SDN. BHD.<br>
                    <strong>{{ $pdfLanguage === 'ms-MY' ? 'Nama Majikan' : 'Employer Name' }}:</strong> {{ $companyName }}{{ $companySsm !== '' ? ' (' . $companySsm . ')' : '' }}<br>
                    @if($isHrdGrant && $grantApproval !== '')
                        <strong>Grant ID:</strong> {{ $grantApproval }}<br>
                    @endif
                    @if($loaNo !== '')
                        <strong>LOA/PO Number:</strong> {{ $loaNo }}<br>
                    @endif
                    <strong>{{ $pdfLanguage === 'ms-MY' ? 'Tajuk Latihan' : 'Training Title' }}:</strong> {{ $projectTitle }}<br>
                    <strong>{{ $pdfLanguage === 'ms-MY' ? 'Tarikh Latihan' : 'Training Date' }}:</strong> Start - {{ $startFmt }} ; End - {{ $endFmt }}<br><br>
                    <strong>{{ $pdfLanguage === 'ms-MY' ? 'Catatan Invois' : 'Invoice Remarks' }}:</strong> {{ $remarksVal !== '' ? $remarksVal : 'N/A' }}
                @endif
            </td>
            <td class="center">{{ $up }}</td>
            <td class="center">{{ $qty }}</td>
            <td class="center">{{ $sub }}</td>
        </tr>
    @endforeach
    <tr class="muted-row">
        <td colspan="4" style="text-align:right;"><strong>{{ $L('subtotal_rm', 'Subtotal (RM)') }}</strong></td>
        <td class="center"><strong>{{ number_format($runningSubtotal, 2) }}</strong></td>
    </tr>
    @if((float)($inv->sst_amount ?? 0) > 0)
        <tr>
            <td colspan="4" style="text-align:right;"><strong>SST 8% (RM)</strong></td>
            <td class="center"><strong>{{ number_format((float) $inv->sst_amount, 2) }}</strong></td>
        </tr>
    @endif
    <tr>
        <td colspan="4" style="text-align:right;"><strong>{{ $L('grand_total_rm', 'Grand Total (RM)') }}</strong></td>
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
                <img src="{{ $signDataUri }}" style="max-width:22mm;height:auto;margin-right:2mm;" alt="Signature">
            @endif
            @if($stampDataUri)
                <img src="{{ $stampDataUri }}" style="max-width:40mm;height:auto;" alt="Stamp">
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
        @foreach(\App\Support\PdfLegalTerms::get($pdfLanguage, 'invoice') as $index => $term)
            ({{ $index + 1 }}) {{ $term }}
        @endforeach
    </p>
</div>

</body>
</html>
