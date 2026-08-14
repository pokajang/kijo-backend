<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quotation &mdash; {{ $quoteRefNo ?? 'Equipment Quote' }}</title>
    <style>
        @php
            $layout = \App\Support\EquipmentQuotationLayout::class;
            $itemColumnPercentages = $layout::ITEM_COLUMN_PERCENTAGES;
        @endphp
        @page { margin: {{ $layout::MARGIN_TOP_MM }}mm {{ $layout::MARGIN_SIDE_MM }}mm {{ $layout::MARGIN_BOTTOM_MM }}mm {{ $layout::MARGIN_SIDE_MM }}mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }
        p { margin: 0 0 2mm 0; }

        .pdf-header { position: fixed; top: -26mm; left: 0; right: 0; height: 24mm; color: #696969; margin-bottom: 0; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: {{ $layout::HEADER_LEFT_PERCENT }}%; text-align: left; }
        .header-right { width: {{ $layout::HEADER_RIGHT_PERCENT }}%; text-align: right; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; }
        .company-logo { width: {{ $layout::LOGO_WIDTH_MM }}mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }

        .items-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 2mm; font-size: 10pt; }
        .items-table th { background: #f4f4f4; font-weight: 700; border: 0.5px solid #999; padding: 3px 5px; text-align: center; font-size: 10pt; }
        .items-table td { border: 0.5px solid #999; padding: 3px 5px; vertical-align: top; }
        .items-table td.num { text-align: center; }
        .items-table td.right { text-align: right; }
        .items-table .subtotal-row td { text-align: right; font-weight: 400; }
        .items-table .total-row td { text-align: right; font-weight: 700; }
        .items-table .quotation-remarks-row td { text-align: left; white-space: pre-line; }
        .small-note { font-size: 8pt; color: #666; font-style: italic; }

        .page-break { page-break-before: always; height: 0; margin: 0; padding: 0; }
        .accept-box { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        .accept-box td { border: 0.5px solid #000; width: 50%; height: 28mm; vertical-align: top; padding: 4px; }
        .terms-title { font-size: 11pt; font-weight: 700; margin: 0 0 1.5mm 0; page-break-after: avoid; break-after: avoid; }
        ol { margin: 0 0 2mm 0; padding-left: 5mm; font-size: 10pt; line-height: 1.35; }
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
                    @foreach($itemColumnPercentages as $columnIndex => $columnWidth)
                        <th style="width:{{ $columnWidth }}%;{{ $columnIndex === 1 ? ' text-align:left;' : '' }}">
                            {{ ['#', 'Item Description', 'Qty', 'Unit Price (RM)', 'Amount (RM)'][$columnIndex] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($items as $i => $item)
                    @php
                        $itemCellSegments = \App\Support\PdfText::itemCellSegments(
                            $item['description'] ?? '',
                            $item['item_remarks'] ?? '',
                        );
                    @endphp
                    @foreach($itemCellSegments as $segmentIndex => $itemCellSegment)
                        <tr class="{{ $segmentIndex === 0 ? 'equipment-item-row' : 'equipment-item-continuation-row' }}">
                            <td class="num">{{ $segmentIndex === 0 ? $i + 1 : '' }}</td>
                            <td>
                                @include('pdf.partials.equipment-item-cell', [
                                    'itemName' => $item['title'] ?? '',
                                    'description' => $itemCellSegment['description'],
                                    'remarks' => $itemCellSegment['remarks'],
                                    'showItemName' => $segmentIndex === 0,
                                    'showDescriptionLabel' => $itemCellSegment['show_description_label'],
                                    'showRemarksLabel' => $itemCellSegment['show_remarks_label'],
                                ])
                            </td>
                            <td class="num">{{ $segmentIndex === 0 ? (int) $item['quantity'] : '' }}</td>
                            <td class="right">{{ $segmentIndex === 0 ? number_format((float) $item['marked_up_price'], 2) : '' }}</td>
                            <td class="right">{{ $segmentIndex === 0 ? number_format((float) $item['line_total'], 2) : '' }}</td>
                        </tr>
                    @endforeach
                @endforeach

                @foreach(\App\Support\PdfText::tableRowSegments($quotationRemarks) as $remarksIndex => $quotationRemarkSegment)
                    <tr class="quotation-remarks-row{{ $remarksIndex > 0 ? ' quotation-remarks-continuation-row' : '' }}">
                        <td colspan="5">
                            @if($remarksIndex === 0)<strong>Quotation Remarks:</strong> @endif{{ $quotationRemarkSegment }}
                        </td>
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
            @foreach($terms as $term)
                <li>{{ $term }}</li>
            @endforeach
        </ol>
    </main>
</body>
</html>
