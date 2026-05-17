@php($partialLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en'))
<p class="section-title">{{ $partialLanguage === 'ms-MY' ? 'Maklumat Tambahan' : 'Additional Information' }}</p>
<div class="section-body">{!! $additionalInfoHtml ?? '' !!}</div>
