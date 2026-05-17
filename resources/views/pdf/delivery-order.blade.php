@php
    $pdfLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? (isset($order) ? ($order->document_language ?? 'en') : 'en'));
    $L = static fn(string $key, ?string $fallback = null): string => \App\Support\PdfLabels::get($pdfLanguage, $key, $fallback);
@endphp
<!doctype html>
<html lang="{{ $pdfLanguage === 'ms-MY' ? 'ms' : 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $L('DELIVERY ORDER', 'Delivery Order') }} {{ $order->do_number ?? '' }}</title>
    <style>
        @page {
            margin: 10mm 20mm 10mm 20mm;
        }
        body {
            margin: 0;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.35;
            text-align: justify;
        }
        .pdf-header {
            color: #696969;
            margin-bottom: 4mm;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .header-table td {
            vertical-align: top;
            padding: 0;
        }
        .header-left {
            width: 68%;
            text-align: left;
        }
        .company-name {
            font-size: 10pt;
            font-weight: 700;
            margin-bottom: 1.5mm;
        }
        .company-address {
            font-size: 10pt;
            line-height: 1.2;
            margin-bottom: 1.5mm;
        }
        .company-contact {
            font-size: 10pt;
            font-weight: 700;
        }
        .header-right {
            width: 32%;
            text-align: right;
        }
        .company-logo {
            width: 42mm;
            height: auto;
            display: inline-block;
            margin-top: -1mm;
        }
        .document-type {
            font-size: 10pt;
            font-weight: 700;
            margin-top: 2.2mm;
            letter-spacing: 0.3px;
        }
        .header-separator {
            margin-top: 1.3mm;
            border-bottom: 0.7px solid #696969;
        }
        main {
            display: block;
            margin-top: 0;
        }
        p {
            margin: 0 0 2mm 0;
        }
        .ref-line {
            width: 100%;
            border-collapse: collapse;
            margin: 1mm 0 3mm 0;
        }
        .ref-line td {
            padding: 0;
            vertical-align: top;
        }
        .two-col {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 4mm;
        }
        .two-col td {
            vertical-align: top;
            width: 49%;
            padding: 0;
        }
        .two-col td.gap {
            width: 2%;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-top: 3mm;
            margin-bottom: 4mm;
        }
        .items-table th,
        .items-table td {
            border: 0.5px solid #000;
            padding: 4px 5px;
            vertical-align: top;
        }
        .items-table thead th {
            background-color: #f2f2f2;
            font-weight: 700;
        }
        .text-center {
            text-align: center;
        }
        .accept-box {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-top: 3mm;
        }
        .accept-box td {
            border: 0.5px solid #000;
            width: 50%;
            height: 30mm;
            vertical-align: top;
            padding: 4px;
        }
        .small-italic {
            font-size: 10pt;
            font-style: italic;
        }
    </style>
</head>
<body>
    @php
        $clientName = trim((string) ($order->client_name ?? '')) !== '' ? $order->client_name : '-';
        $clientAddressRaw = trim((string) ($order->client_address ?? '')) !== '' ? $order->client_address : '-';
        $clientContactName = trim((string) ($order->client_contact_name ?? '')) !== '' ? $order->client_contact_name : '-';
        $clientContactPosition = trim((string) ($order->client_contact_position ?? '')) !== '' ? $order->client_contact_position : '-';
        $clientContactEmail = trim((string) ($order->client_contact_email ?? '')) !== '' ? $order->client_contact_email : '-';
        $clientContactPhone = trim((string) ($order->client_contact_phone ?? '')) !== '' ? $order->client_contact_phone : '-';
        $issuerPic = trim((string) ($order->company_contact_name ?? '')) !== '' ? $order->company_contact_name : '-';
        $projectName = trim((string) ($order->project_name ?? ''));
        $projectDesc = trim((string) ($order->project_description ?? ''));
        $projectServicePeriod = trim((string) ($order->project_service_period ?? ''));
        $projectLine = trim($projectName . ($projectName !== '' && $projectDesc !== '' ? ' - ' : '') . $projectDesc);
    @endphp

    @include('pdf.partials.company-header', [
        'documentType' => $documentType ?? 'DELIVERY ORDER',
        'pdfLanguage' => $pdfLanguage,
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <table class="ref-line">
            <tr>
                <td>{{ $L('delivery_order_no', 'Delivery Order No') }}: {{ $order->do_number ?? '-' }} &nbsp;&nbsp;&nbsp;&nbsp; {{ $L('date', 'Date') }}: {{ $createdDate ?? '-' }}</td>
            </tr>
        </table>

        <table class="two-col">
            <tr>
                <td>
                    <strong>{{ $L('delivered_to', 'Delivered To') }}:</strong><br>
                    {{ $clientName }}<br>
                    {!! nl2br(e($clientAddressRaw)) !!}<br>
                    {{ $L('in_charge', 'In Charge') }}: {{ $clientContactName }} ({{ $clientContactPosition }})<br>
                    {{ $L('contact', 'Contact') }}: {{ $clientContactEmail }} ({{ $clientContactPhone }})
                </td>
                <td class="gap"></td>
                <td>
                    <strong>{{ $L('delivered_by', 'Delivered By') }}:</strong><br>
                    AMIOSH Resources Sdn Bhd<br>
                    No.5-2, Jalan Seri Putra 1/5, Bandar Seri Putra, 43000 Kajang, Selangor<br>
                    {{ $L('in_charge', 'In Charge') }}: {{ $issuerPic }}<br>
                    {{ $L('contact', 'Contact') }}: 03-8210 8726
                </td>
            </tr>
        </table>

        <p>
            {{ $pdfLanguage === 'ms-MY' ? 'Sila semak butiran penghantaran dan tandatangan di bawah sebagai pengakuan penerimaan. Sebarang isu hendaklah dilaporkan dalam tempoh lima (5) hari selepas penghantaran.' : 'Kindly review the delivery details and sign below as acknowledgement. Any issues should be reported within five (5) days of delivery.' }}
        </p>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%;" class="text-center">#</th>
                    <th style="width:30%;">Item</th>
                    <th style="width:45%;">{{ $L('description', 'Description') }}</th>
                    <th style="width:20%;" class="text-center">{{ $L('qty', 'Quantity') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="4">
                        <strong>{{ $pdfLanguage === 'ms-MY' ? 'Untuk Projek' : 'For Project' }}:</strong>
                        {{ $projectLine !== '' ? $projectLine : '-' }}
                        @if($projectServicePeriod !== '')
                            <br><strong>{{ $pdfLanguage === 'ms-MY' ? 'Tempoh Perkhidmatan' : 'Service Period' }}:</strong> {{ $projectServicePeriod }}
                        @endif
                    </td>
                </tr>
                @forelse($items as $index => $item)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $item->item_name ?? '-' }}</td>
                        <td>{{ $item->description ?? '-' }}</td>
                        <td class="text-center">{{ $item->quantity ?? '-' }} {{ $item->unit ?? '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">{{ $pdfLanguage === 'ms-MY' ? 'Tiada pecahan item tersedia.' : 'No item breakdown available.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <p>
            {{ $pdfLanguage === 'ms-MY' ? 'Sila kembalikan salinan pesanan penghantaran yang telah ditandatangani sebagai pengesahan penerimaan anda.' : 'Kindly return a duly signed copy of this delivery order as confirmation of your acceptance.' }}
        </p>
        <p class="small-italic">
            {{ $L('computer_generated', '[This is a computer-generated document. No signature is required from us.]') }}
        </p>

        <p><strong>{{ $L('customer_acceptance', 'Customer Acceptance') }}</strong></p>
        <p>{{ $pdfLanguage === 'ms-MY' ? 'Kami dengan ini menerima item yang dihantar dan telah diperiksa dalam keadaan baik.' : 'We hereby accept the delivered items which have been checked to be in good order.' }}</p>

        <table class="accept-box">
            <tr>
                <td>
                    <br>
                    {{ $L('name', 'Name') }}:<br><br>
                    {{ $L('position', 'Position') }}:<br><br>
                    {{ $L('signature', 'Signature') }}:
                </td>
                <td>
                    <br>
                    {{ $L('company_stamp', 'Company Stamp') }}:<br><br>
                    {{ $L('date', 'Date') }}:
                </td>
            </tr>
        </table>
    </main>
</body>
</html>
