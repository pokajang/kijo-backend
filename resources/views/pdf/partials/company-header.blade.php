@php
    $headerLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? $documentLanguage ?? 'en');
    $headerDocumentType = \App\Support\PdfLabels::documentType($headerLanguage, $documentType ?? 'DOCUMENT');
@endphp
<header class="pdf-header">
    <table class="header-table">
        <tr>
            <td class="header-left">
                <div class="company-name">AMIOSH RESOURCES SDN BHD (1062417W)</div>
                <div class="company-address">
                    No.5-2, Jalan Seri Putra 1/5, Bandar Seri Putra 1/5,<br>
                    Bandar Seri Putra Bangi, 43000 Kajang Selangor, Malaysia.
                </div>
                <div class="company-contact">amiosh.com&nbsp;&nbsp;03-8210 8726</div>
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
