@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? (isset($inv) ? ($inv->document_language ?? 'en') : 'en'));
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $L('OFFICIAL RECEIPT', 'Official Receipt') }} {{ $inv->receipt_no ?? $inv->invoice_ref_no ?? '' }}</title>
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
        .receipt-meta { font-size: 11pt; margin: 3mm 0 2mm 0; }
        .to-block { font-size: 11pt; margin: 2mm 0; }
        table.breakdown { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        table.breakdown th, table.breakdown td { border: 0.5px solid #000; padding: 4px; vertical-align: top; }
        table.breakdown th { background: #f2f2f2; font-weight: 700; text-align: center; }
        table.breakdown td.center { text-align: center; }
        .muted-row { background: #f9f9f9; }
        .footer-note { font-size: 10pt; margin: 3mm 0; }
        .tagline { text-align: center; font-size: 12pt; font-style: italic; color: #cccccc; margin-top: 8mm; }
    </style>
</head>
<body>

@include('pdf.partials.company-header', [
    'documentType' => 'OFFICIAL RECEIPT',
    'pdfLanguage' => $pdfLanguage,
    'logoDataUri' => $logoDataUri ?? null,
])

{{-- Receipt No & Date --}}
@php
    $receiptRef = (string) ($inv->receipt_no ?? $inv->invoice_ref_no ?? 'RECEIPT');
    $paidDate   = (string) ($inv->paid_date ?? '');
    $dateStr    = ($paidDate !== '' && ($ts = strtotime($paidDate)) !== false) ? date('d M Y', $ts) : '-';
    $totalPaid  = number_format((float) ($inv->paid_amount ?? 0), 2);
    $sstAmt     = (float) ($inv->sst_amount ?? 0);
@endphp
<p class="receipt-meta">{{ $L('receipt_number', 'Receipt Number') }}: {{ $receiptRef }} &nbsp;&nbsp;&nbsp;&nbsp; {{ $L('date', 'Date') }}: {{ $dateStr }}</p>

{{-- Billed-to --}}
@php
    $companyName = (string) ($inv->invoice_client_name ?? '-');
    $companySsm  = (string) ($inv->invoice_client_ssm  ?? '');
    $companyTin  = (string) ($inv->invoice_client_tin  ?? '');
    $address     = (string) ($inv->invoice_client_address ?? '-');
    $city        = (string) ($inv->invoice_client_city  ?? '-');
    $state       = (string) ($inv->invoice_client_state ?? '-');
    $zip         = (string) ($inv->invoice_client_zip   ?? '-');
    $email       = (string) ($inv->invoice_pic_email ?? '-');
    $phone       = (string) ($inv->invoice_pic_phone ?? '-');
    $serviceType = (string) ($inv->service_type ?? '-');
    $purpose     = (string) ($inv->invoice_purpose ?? '-');
    $invoiceRef  = (string) ($inv->invoice_ref_no ?? '-');
    $remarksVal  = trim((string) ($inv->remarks ?? ''));
@endphp
<p class="to-block">
    <strong>{{ $L('billed_to', 'Billed To') }}:</strong><br>
    {{ $companyName }}<br>
    SSM No.: {{ $companySsm !== '' ? $companySsm : 'N/A' }}<br>
    Tax Identification Number (TIN): {{ $companyTin !== '' ? $companyTin : 'N/A' }}<br>
    {{ $address }}, {{ $city }}, {{ $state }} {{ $zip }}<br>
    {{ $L('email', 'Email') }}: {{ $email }} &nbsp;&nbsp;&nbsp; {{ $L('phone', 'Phone') }}: {{ $phone }}
</p>

{{-- Breakdown table --}}
<table class="breakdown">
    <tr>
        <th width="5%">#</th>
        <th width="40%">{{ $L('description', 'Description') }}</th>
        <th width="15%">{{ $L('unit_price_rm', 'Unit Price (RM)') }}</th>
        <th width="10%">{{ $L('qty', 'Qty') }}</th>
        <th width="10%">{{ $L('unit', 'Unit') }}</th>
        <th width="20%">{{ $L('subtotal_rm', 'Subtotal (RM)') }}</th>
    </tr>
    <tr>
        <td></td>
        <td colspan="5">
            {{ $serviceType }} - {{ $purpose }}<br>
            {{ $L('for_invoice', 'For invoice') }} {{ $invoiceRef }}
            @if($remarksVal !== '')<br>{{ $L('remarks', 'Remarks') }}: {{ $remarksVal }}@endif
        </td>
    </tr>
    @foreach($items as $i => $itm)
        @php
            $up  = $itm->unit_price ?? null;
            $qty = $itm->quantity   ?? null;
            $sub = $itm->subtotal   ?? null;
            if ($up === null || $qty === null || $sub === null) { continue; }
            $desc     = (string) ($itm->item_description ?? '');
            $lineDesc = trim((string) ($itm->description ?? ''));
            $unit     = (string) ($itm->unit ?? '');
        @endphp
        <tr>
            <td class="center">{{ $i + 1 }}</td>
            <td>
                {{ $desc }}
                @if($lineDesc !== '')<br><span style="font-size:9pt;color:#555;">{{ $lineDesc }}</span>@endif
            </td>
            <td class="center">{{ number_format((float) $up, 2) }}</td>
            <td class="center">{{ number_format((float) $qty, 2) }}</td>
            <td class="center">{{ $unit }}</td>
            <td class="center">{{ number_format((float) $sub, 2) }}</td>
        </tr>
    @endforeach
    @if($sstAmt > 0)
        <tr>
            <td colspan="5" style="text-align:right;"><strong>SST (8%) (RM)</strong></td>
            <td class="center"><strong>{{ number_format($sstAmt, 2) }}</strong></td>
        </tr>
    @endif
    <tr class="muted-row">
        <td colspan="5" style="text-align:right;"><strong>{{ $L('total_paid_rm', 'Total Paid (RM)') }}</strong></td>
        <td class="center"><strong>{{ $totalPaid }}</strong></td>
    </tr>
</table>

<p class="footer-note">
    {{ $L('receipt_thanks', 'Thank you for your payment. We are keen to serve you again.') }}<br>
    <span style="font-size:8pt;font-style:italic;">{{ $L('computer_generated', '[This is a computer-generated document. No signature is required from us.]') }}</span>
</p>

<p class="tagline"><strong>"An ounce of prevention is worth a pound of cure."</strong></p>

</body>
</html>
