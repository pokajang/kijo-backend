<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quotation &mdash; {{ $quoteRefNo ?? 'Equipment Quote' }}</title>
    <style>
        @page { margin: 10mm 20mm 10mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }
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

        .items-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 2mm; font-size: 10pt; }
        .items-table th { background: #f4f4f4; font-weight: 700; border: 0.5px solid #999; padding: 3px 5px; text-align: center; font-size: 9.5pt; }
        .items-table td { border: 0.5px solid #999; padding: 3px 5px; vertical-align: top; }
        .items-table td.num { text-align: center; }
        .items-table td.right { text-align: right; }
        .items-table .subtotal-row td { text-align: right; font-weight: 400; }
        .items-table .total-row td { text-align: right; font-weight: 700; }
        .muted { font-size: 8.5pt; color: #6c757d; }
        .small-note { font-size: 8pt; color: #666; font-style: italic; }

        .page-break { page-break-before: always; }
        .accept-box { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        .accept-box td { border: 0.5px solid #000; width: 50%; height: 28mm; vertical-align: top; padding: 4px; }
        .terms-title { font-size: 11pt; font-weight: 700; margin: 0 0 1.5mm 0; }
        ol { margin: 0 0 2mm 0; padding-left: 5mm; font-size: 9pt; }
        li { margin-bottom: 1.2mm; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'QUOTATION',
        'logoDataUri'  => $logoDataUri ?? null,
    ])

    <main>
        <p>
            @if(!empty($revisionNo) && (int) $revisionNo > 0)
                Quote Number: {{ $quoteRefNo }} (Rev0{{ (int) $revisionNo }}) &nbsp;&nbsp;
                Rev. Date: {{ $updatedDateIso }} &nbsp;&nbsp;
                Ori. Date: {{ $createdDateIso }}
            @else
                Quote Number: {{ $quoteRefNo }} &nbsp;&nbsp; Date: {{ $createdDateLegacy }}
            @endif
        </p>

        <p>
            <strong>Attention To:</strong><br>
            {{ $picName }}<br>
            {{ $clientName }}<br>
            {!! nl2br(e($clientAddressBlock)) !!}<br>
            Email: {{ $picEmail }} &nbsp;&nbsp;&nbsp;&nbsp;
            Phone: {{ $picPhone }}
        </p>

        <p>Dear <strong>Valued Customer</strong>,</p>
        <p>Thank you for your interest in the following equipment. Please find below the quotation details.</p>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:40%; text-align:left;">Item Description</th>
                    <th style="width:10%;">Qty</th>
                    <th style="width:20%;">Unit Price (RM)</th>
                    <th style="width:25%;">Amount (RM)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $i => $item)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>
                            {{ $item['title'] }}<br>
                            @php
                                $shortDesc = mb_strimwidth(strip_tags($item['description'] ?? ''), 0, 50, '…', 'UTF-8');
                            @endphp
                            <span class="muted">{{ $shortDesc }}</span>
                        </td>
                        <td class="num">{{ (int) $item['quantity'] }}</td>
                        <td class="right">{{ number_format((float) $item['marked_up_price'], 2) }}</td>
                        <td class="right">{{ number_format((float) $item['line_total'], 2) }}</td>
                    </tr>
                @endforeach

                <tr class="subtotal-row">
                    <td colspan="4">Amount (RM)</td>
                    <td>RM {{ number_format($lineItemsTotal, 2) }}</td>
                </tr>

                @if($deliveryCharge > 0)
                    <tr class="subtotal-row">
                        <td colspan="4">Delivery Charge (RM)</td>
                        <td>RM {{ number_format($deliveryCharge, 2) }}</td>
                    </tr>
                @endif

                @if($miscCharge > 0)
                    <tr class="subtotal-row">
                        <td colspan="4">Miscellaneous Charge (RM)</td>
                        <td>RM {{ number_format($miscCharge, 2) }}</td>
                    </tr>
                @endif

                @if($discountAmount > 0)
                    <tr class="subtotal-row">
                        <td colspan="4">Discount (RM)</td>
                        <td>- RM {{ number_format($discountAmount, 2) }}</td>
                    </tr>
                @endif

                <tr class="subtotal-row">
                    <td colspan="4">Subtotal (RM)</td>
                    <td>RM {{ number_format($subTotalNet, 2) }}</td>
                </tr>

                @if($sstAmount > 0)
                    <tr class="subtotal-row">
                        <td colspan="4">{{ $sstPercentLabel }}% SST Charge (RM)</td>
                        <td>RM {{ number_format($sstAmount, 2) }}</td>
                    </tr>
                @endif

                <tr class="total-row">
                    <td colspan="4">Grand Total (RM)</td>
                    <td>RM {{ number_format($grandTotal, 2) }}</td>
                </tr>
            </tbody>
        </table>

        <p>
            Kindly review the terms and conditions outlined in the next page, and <strong>return a duly signed copy</strong> of this quotation as confirmation of your acceptance.
        </p>
        <p>
            Prepared by: <strong>{{ $preparedByName }}</strong><br>
            {{ $signOffTitle }}<br>
            AMIOSH RESOURCES SDN BHD<br>
            <span class="small-note">[This is a computer-generated document. No signature required.]</span>
        </p>

        <div class="page-break"></div>

        <p style="margin-bottom: 1mm;"><strong>Customer Acceptance</strong></p>
        <p style="font-size: 10pt;">
            I/We hereby accept the terms and conditions stated in this quotation and confirm our intention to proceed.
        </p>

        <table class="accept-box">
            <tr>
                <td>
                    <br>
                    Name:<br><br>
                    Position:<br><br>
                    Signature:<br>
                </td>
                <td>
                    <br>
                    Company Stamp:<br><br>
                    Date:
                </td>
            </tr>
        </table>

        <p class="terms-title" style="margin-top: 4mm;">Terms and Conditions</p>
        <ol>
            <li>This quotation is valid for thirty (30) calendar days from the date of issuance and is subject to equipment availability at the time of confirmation.</li>
            <li>All equipment prices are exclusive of SST unless expressly stated otherwise in this quotation.</li>
            <li>Payment terms are strictly thirty (30) days from the invoice date unless otherwise agreed in writing by both parties.</li>
            <li>Delivery and installation charges, where applicable, are not included unless explicitly specified in this quotation.</li>
            <li>All equipment supplied shall be covered under the respective manufacturer's warranty and maintenance conditions.</li>
            <li>The Client shall inspect all delivered equipment upon receipt and notify AMIOSH Resources Sdn. Bhd. in writing within three (3) days of any defects, damages, or discrepancies identified.</li>
            <li>Ownership of all equipment shall remain with AMIOSH Resources Sdn. Bhd. until full payment has been received and cleared.</li>
            <li>Requests for customization or special packaging may incur additional charges, subject to prior approval and written confirmation.</li>
            <li>Returns, exchanges, or cancellations by the Client are subject to prior written approval and may incur restocking or administrative fees.</li>
        </ol>
    </main>
</body>
</html>
