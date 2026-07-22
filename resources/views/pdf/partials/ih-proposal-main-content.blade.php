@php
    $partialLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en');
    $sectionLabels = [
        'Introduction' => 'Pengenalan',
        'Objectives' => 'Objektif',
        'Work Scope' => 'Skop Kerja',
        'Schedule' => 'Jadual',
        'References' => 'Rujukan',
        'Additional Information' => 'Maklumat Tambahan',
    ];
@endphp
<div class="title-box">{{ $proposalTitle ?? ($partialLanguage === 'ms-MY' ? 'Cadangan Perkhidmatan' : 'Service Proposal') }}</div>

@include('pdf.partials.proposal-company-services')

@foreach(($sections ?? []) as $section)
    @php($sectionTitle = (string) ($section['title'] ?? ''))
    <p class="section-title">{{ $partialLanguage === 'ms-MY' ? ($sectionLabels[$sectionTitle] ?? $sectionTitle) : $sectionTitle }}</p>
    <div class="section-body">{!! $section['contentHtml'] ?? '' !!}</div>
@endforeach
