<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Supplier PO {{ $po->po_ref_no ?? '' }}</title>
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
        p {
            margin: 0 0 2mm 0;
        }
        .ref-line {
            margin: 1mm 0 3mm 0;
        }
        .two-col {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 3mm;
        }
        .two-col td {
            vertical-align: top;
            padding: 0;
        }
        .supplier-col {
            width: 64%;
        }
        .issuer-col {
            width: 36%;
            text-align: right;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
            margin-bottom: 3mm;
        }
        .items-table th,
        .items-table td {
            border: 0.5px solid #000;
            padding: 4px 5px;
            vertical-align: top;
        }
        .items-table th {
            background: #f2f2f2;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .small-italic {
            font-size: 9pt;
            font-style: italic;
        }
        .accept-box {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3mm;
        }
        .accept-box td {
            border: 0.5px solid #000;
            width: 50%;
            height: 30mm;
            vertical-align: top;
            padding: 4px;
        }
        .terms-page {
            page-break-before: always;
        }
    </style>
</head>
<body>
    @php
        $supplierName = trim((string) ($po->supplier_name ?? '')) !== '' ? $po->supplier_name : '-';
        $supplierAddress = trim((string) ($po->supplier_address ?? '')) !== '' ? $po->supplier_address : '-';
        $supplierContactName = trim((string) ($po->supplier_contact_name ?? '')) !== '' ? $po->supplier_contact_name : '-';
        $supplierContactNumber = trim((string) ($po->supplier_contact_number ?? '')) !== '' ? $po->supplier_contact_number : '-';
        $supplierEmail = trim((string) ($po->supplier_email ?? '')) !== '' ? $po->supplier_email : '-';
        $issuerName = trim((string) ($po->full_name ?? '')) !== '' ? $po->full_name : '-';
        $issuerPosition = trim((string) ($po->position ?? ''));
        $issuerDept = trim((string) ($po->department ?? ''));
        $issuerRoleLine = implode(', ', array_filter([$issuerPosition, $issuerDept], static fn (string $part): bool => $part !== ''));
        $discount = (float) ($po->discount ?? 0);
        $delivery = (float) ($po->delivery_charge ?? 0);
        $sstPercent = (float) ($po->sst_percent ?? 0);
        $sstAmount = (float) ($po->sst_amount ?? 0);
        $grandTotal = (float) ($po->grand_total ?? 0);
    @endphp

    @include('pdf.partials.company-header', [
        'documentType' => $documentType ?? 'PURCHASE ORDER',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <p class="ref-line">
        Our Ref: {{ $po->po_ref_no ?? '-' }} &nbsp;&nbsp;&nbsp;&nbsp; Date: {{ $createdDate ?? '-' }}
    </p>

    <table class="two-col">
        <tr>
            <td class="supplier-col">
                <strong>Attention To:</strong><br>
                {{ $supplierName }}<br>
                {!! nl2br(e($supplierAddress)) !!}<br>
                Email: {{ $supplierEmail }}<br>
                Phone: {{ $supplierContactNumber }}
            </td>
            <td class="issuer-col"></td>
        </tr>
    </table>

    <p>Dear <strong>{{ $supplierContactName }}</strong>,</p>
    <p>
        We are pleased to issue this <strong>Purchase Order</strong> for the following items under the agreed terms and conditions.
    </p>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:5%;" class="text-center">#</th>
                <th style="width:35%;">Item Description</th>
                <th style="width:10%;" class="text-center">Unit</th>
                <th style="width:10%;" class="text-center">Qty</th>
                <th style="width:20%;" class="text-center">U/P (RM)</th>
                <th style="width:20%;" class="text-center">Total (RM)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $index => $item)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>
                        {{ $item->item_name ?? '-' }}
                        @if(!empty($item->description))
                            <br><small>{{ $item->description }}</small>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->unit ?? '-' }}</td>
                    <td class="text-center">{{ number_format((float) ($item->quantity ?? 0), 0) }}</td>
                    <td class="text-center">{{ number_format((float) ($item->unit_price ?? 0), 2) }}</td>
                    <td class="text-center">{{ number_format((float) ($item->line_total ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No PO items found.</td>
                </tr>
            @endforelse

            @if($discount > 0)
                <tr>
                    <td colspan="5" class="text-right"><strong>Discount (RM)</strong></td>
                    <td class="text-center">{{ number_format($discount, 2) }}</td>
                </tr>
            @endif
            @if($delivery > 0)
                <tr>
                    <td colspan="5" class="text-right"><strong>Delivery Charge (RM)</strong></td>
                    <td class="text-center">{{ number_format($delivery, 2) }}</td>
                </tr>
            @endif
            @if($sstAmount > 0)
                <tr>
                    <td colspan="5" class="text-right"><strong>SST ({{ number_format($sstPercent, 2) }}%)</strong></td>
                    <td class="text-center">{{ number_format($sstAmount, 2) }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="5" class="text-right"><strong>Grand Total (RM)</strong></td>
                <td class="text-center"><strong>{{ number_format($grandTotal, 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <p>
        Please review the terms and conditions on the next page and return us a signed copy of this Purchase Order.
    </p>
    <p>
        Authorized by,<br>
        <strong>{{ $issuerName }}</strong><br>
        @if($issuerRoleLine !== ''){{ $issuerRoleLine }}<br>@endif
        AMIOSH RESOURCES SDN BHD
    </p>

    <p class="small-italic">[This is a computer-generated document. No signature is required from us.]</p>

    <p><strong>Vendor Acknowledgement</strong></p>
    <p>
        I hereby acknowledge and accept the terms and conditions set forth in this Purchase Order and shall deliver
        the items indicated with full responsibility and professionalism.
    </p>

    <table class="accept-box">
        <tr>
            <td>
                <br>
                Signature:<br><br>
                Name:<br><br>
                Position:
            </td>
            <td>
                <br>
                Company Stamp:<br><br>
                Date:
            </td>
        </tr>
    </table>

    <div class="terms-page">
        <h3>Terms and Conditions</h3>
        <p><strong>A. Compliance Commitment</strong></p>
        <p>
            AMIOSH Resources Sdn. Bhd. is committed to occupational health and safety compliance. All equipment supplied
            must meet applicable legal and safety requirements.
        </p>

        <p><strong>B. Delivery and Acceptance</strong></p>
        <p>
            Delivery must be made within the agreed timeline. Items are subject to inspection and testing upon receipt.
            Non-conforming goods may be rejected at supplier expense.
        </p>

        <p><strong>C. E-Invoice and Documentation</strong></p>
        <p>
            Supplier shall provide complete invoices and supporting documents required for tax, e-invoicing, and audit compliance.
        </p>

        <p><strong>D. Warranty</strong></p>
        <p>
            Supplier warrants goods are free from defects in materials and workmanship for the agreed warranty period,
            or at least twelve (12) months if not specified.
        </p>

        <p><strong>E. General Commitments</strong></p>
        <ol>
            <li>Supplier must update AMIOSH on delivery progress and clarify outstanding matters promptly.</li>
            <li>All supplied items must be new, in good condition, and accompanied by required supporting documents.</li>
            <li>Where applicable, products must comply with relevant certification standards.</li>
            <li>Serious misconduct or breach may result in immediate PO cancellation and legal action.</li>
            <li>This Purchase Order is governed by the laws of Malaysia.</li>
        </ol>
    </div>
</body>
</html>
