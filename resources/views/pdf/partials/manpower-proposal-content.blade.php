@php
    $partialLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en');
    $sectionLabels = [
        'Introduction' => 'Pengenalan',
        'Service Deliverables' => 'Serahan Perkhidmatan',
        'Supplied Manpower Deliverables' => 'Serahan Tenaga Kerja Dibekalkan',
        'Additional Information' => 'Maklumat Tambahan',
    ];
@endphp
<div class="title-box">{{ $proposalTitle ?? '' }}{{ $partialLanguage === 'ms-MY' ? ' Cadangan Perkhidmatan Pembekalan Tenaga Kerja' : ' Manpower Supply Service Proposal' }}</div>

@foreach(($sections ?? []) as $section)
    @php($sectionContentHtml = (string) ($section['contentHtml'] ?? $section['content'] ?? ''))
    @if(!empty(trim(strip_tags($sectionContentHtml))))
        @php($sectionTitle = (string) ($section['title'] ?? ''))
        <p class="section-title">{{ $partialLanguage === 'ms-MY' ? ($sectionLabels[$sectionTitle] ?? $sectionTitle) : $sectionTitle }}</p>
        <div class="section-body">{!! $sectionContentHtml !!}</div>
    @endif
@endforeach
