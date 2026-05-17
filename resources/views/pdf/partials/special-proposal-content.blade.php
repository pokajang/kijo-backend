@php($partialLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en'))
<div class="title-box">{{ $proposalTitle ?? ($partialLanguage === 'ms-MY' ? 'Cadangan Perkhidmatan' : 'Service Proposal') }}</div>
<div class="proposal-content">{!! $contentHtml ?? '' !!}</div>
