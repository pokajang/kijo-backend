@php($partialLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en'))
<div class="title-box">{{ $proposalTitle ?? ($partialLanguage === 'ms-MY' ? 'Cadangan Perkhidmatan' : 'Service Proposal') }}</div>

@include('pdf.partials.proposal-company-services')
<div class="proposal-content">{!! $contentHtml ?? '' !!}</div>
