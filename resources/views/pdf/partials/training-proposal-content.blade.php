@php
    $partialLanguage = \App\Support\PdfLabels::normalize($pdfLanguage ?? 'en');
    $sectionLabels = [
        'HRDC Training Programme No.' => 'No. Program Latihan HRDC',
        'Introduction' => 'Pengenalan',
        'Objectives' => 'Objektif',
        'Modules' => 'Modul',
        'Training Requirements' => 'Keperluan Latihan',
        'Additional Requirements' => 'Keperluan Tambahan',
        'Training Materials' => 'Bahan Latihan',
        'Lecture Medium' => 'Medium Penyampaian',
        'Theory Method' => 'Kaedah Teori',
        'Practical Method' => 'Kaedah Praktikal',
        'Duration' => 'Tempoh',
    ];
@endphp
<div class="title-box">{{ $proposalTitle ?? '' }} {{ $partialLanguage === 'ms-MY' ? 'Brosur Latihan' : 'Training Brochure' }}</div>

@foreach(($sections ?? []) as $section)
    @if(!empty(trim((string) ($section['content'] ?? ''))))
        @php($sectionTitle = (string) ($section['title'] ?? ''))
        <p class="section-title">{{ $partialLanguage === 'ms-MY' ? ($sectionLabels[$sectionTitle] ?? $sectionTitle) : $sectionTitle }}</p>
        <div class="section-body">{!! $section['contentHtml'] ?? '' !!}</div>
    @endif
@endforeach

@if(!empty($agendaByDay))
    <p class="agenda-heading">{{ $partialLanguage === 'ms-MY' ? 'Tentatif Program' : 'Program Tentative' }}</p>
    @php($multiDay = count($agendaByDay) > 1)
    @foreach($agendaByDay as $day => $items)
        @if($multiDay)
            <p class="day-heading">{{ $partialLanguage === 'ms-MY' ? 'Hari' : 'Day' }} {{ $day }}</p>
        @endif
        <table class="agenda-table">
            <thead>
                <tr>
                    <th class="time-cell">{{ $partialLanguage === 'ms-MY' ? 'Masa' : 'Time' }}</th>
                    <th>Agenda</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td class="time-cell">{{ $item['timeRange'] }}</td>
                        <td>{!! $item['topicHtml'] !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
@endif

<p class="terms-title">{{ $partialLanguage === 'ms-MY' ? 'Terma dan Syarat Tentatif' : 'Tentative Terms and Conditions' }}</p>
<ol class="terms-list">
    @if($partialLanguage === 'ms-MY')
        <li>Program tentatif ini bertujuan sebagai panduan umum sahaja dan tidak mewakili agenda tetap atau muktamad.</li>
        <li>Pelarasan jadual boleh dibuat di tapak berdasarkan keadaan semasa seperti cuaca (bagi program luar), respons dan tahap interaksi peserta, atau kekangan logistik.</li>
        <li>Semasa sesi latihan sebenar, turutan dan masa modul atau sesi boleh dilaraskan sewajarnya bagi memastikan penyampaian dan keberkesanan pembelajaran yang optimum.</li>
        <li>Waktu rehat dan tempoh sesi boleh diubah bagi menampung kelewatan yang tidak dijangka atau menyesuaikan dinamik kumpulan latihan.</li>
        <li>Bagi program yang boleh dituntut melalui HRD Corp, jumlah jam latihan hendaklah mematuhi geran yang diluluskan.</li>
    @else
        <li>This tentative program is intended solely as a general guide and does not represent a fixed or final agenda.</li>
        <li>Adjustments to the schedule may be made on-site based on real-time conditions such as weather (for outdoor programs), participant response and interaction levels, or logistical constraints.</li>
        <li>During actual training session, the sequence and timing of modules or sessions may be adjusted accordingly to ensure optimal delivery and learning effectiveness.</li>
        <li>Break times and session durations may be modified to accommodate unforeseen delays or to suit the dynamics of the training group.</li>
        <li>For HRD Corp claimable programs, the total training hours shall comply with the approved grant.</li>
    @endif
</ol>
