@php
    $headerLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? $documentLanguage ?? 'en');
    $headerDocumentType = \App\Support\PdfLabels::documentType($headerLanguage, $documentType ?? 'DOCUMENT');
@endphp
<header class="pdf-header">
    <table class="header-table">
        <tr>
            <td class="header-left">
                <div class="company-name">{{ \App\Support\EquipmentQuotationLayout::COMPANY_NAME }}</div>
                <div class="company-address">
                    {{ \App\Support\EquipmentQuotationLayout::COMPANY_ADDRESS_LINE_1 }}<br>
                    {{ \App\Support\EquipmentQuotationLayout::COMPANY_ADDRESS_LINE_2 }}
                </div>
                <div class="company-contact">{{ \App\Support\EquipmentQuotationLayout::COMPANY_CONTACT }}</div>
            </td>
            <td class="header-right">
                @if(!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" alt="AMIOSH logo" class="company-logo">
                @endif
                <div class="document-type">{{ strtoupper($headerDocumentType) }}</div>
            </td>
        </tr>
    </table>
    <div class="header-separator"></div>
</header>
