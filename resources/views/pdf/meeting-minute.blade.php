<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Meeting Minutes - {{ $meetingTitle ?? '' }}</title>
    <style>
        @page { margin: 10mm 20mm 14mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }
        p { margin: 0 0 2mm 0; }

        .pdf-header { color: #696969; margin-bottom: 4mm; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; }
        .company-logo { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }

        .meeting-title { font-size: 12pt; font-weight: 700; margin: 0 0 2mm 0; }
        .section-title { font-size: 10.5pt; font-weight: 700; margin: 4mm 0 1.5mm 0; }
        .meta-table { width: 100%; border-collapse: collapse; margin: 2mm 0 3mm 0; font-size: 10pt; }
        .meta-table td { border: 0; padding: 1.2mm 0; vertical-align: top; }
        .meta-label { width: 30%; font-weight: 700; }
        .simple-list { margin: 0 0 2mm 0; padding-left: 5mm; }
        .simple-list li { margin-bottom: 1mm; }
        .content-block { margin-bottom: 2mm; }
        .content-block p { margin-bottom: 1.5mm; }
        .content-block ul,
        .content-block ol { margin: 0 0 2mm 0; padding-left: 5mm; }
        .content-block li { margin-bottom: 1mm; }

        .items-table { width: 100%; border-collapse: collapse; margin-top: 2mm; margin-bottom: 2mm; font-size: 10pt; }
        .items-table th { background: #f4f4f4; font-weight: 700; border: 0.5px solid #999; padding: 3px 5px; text-align: center; font-size: 9.5pt; }
        .items-table td { border: 0.5px solid #999; padding: 3px 5px; vertical-align: top; }
        .items-table td.num { text-align: center; }
        .items-table td.center { text-align: center; }
        .muted { color: #696969; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'MEETING MINUTES',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <h1 class="meeting-title">{{ $meetingTitle }}</h1>

        <table class="meta-table">
            <tr>
                <td class="meta-label">Meeting Type</td>
                <td>{{ $meetingType }}</td>
            </tr>
            <tr>
                <td class="meta-label">Meeting Date &amp; Time</td>
                <td>{{ $meetingDateTime }}</td>
            </tr>
            <tr>
                <td class="meta-label">Venue</td>
                <td>{{ $venue }}</td>
            </tr>
            <tr>
                <td class="meta-label">Created By</td>
                <td>{{ $createdBy }}</td>
            </tr>
            <tr>
                <td class="meta-label">Updated By</td>
                <td>{{ $updatedBy }}</td>
            </tr>
            <tr>
                <td class="meta-label">Approval Status</td>
                <td>{{ $verificationStatus }}</td>
            </tr>
            <tr>
                <td class="meta-label">Verified By</td>
                <td>{{ $verifiedBy }}</td>
            </tr>
            <tr>
                <td class="meta-label">Concurred By</td>
                <td>{{ $concurredBy }}</td>
            </tr>
        </table>

        <div class="section-title">Staff Attendees</div>
        @if(!empty($attendees))
            <ol class="simple-list">
                @foreach($attendees as $attendee)
                    <li>{{ $attendee }}</li>
                @endforeach
            </ol>
        @else
            <p>-</p>
        @endif

        <div class="section-title">Guest Attendees</div>
        @if(!empty($guestLines))
            <ol class="simple-list">
                @foreach($guestLines as $guest)
                    <li>{{ $guest }}</li>
                @endforeach
            </ol>
        @else
            <p>-</p>
        @endif

        <div class="section-title">Agenda</div>
        <div class="content-block">{!! $agendaHtml !!}</div>

        <div class="section-title">Minutes</div>
        <div class="content-block">{!! $minutesHtml !!}</div>

        <div class="section-title">Action Items</div>
        @if(!empty($actionItems))
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width:6%;">#</th>
                        <th style="width:42%; text-align:left;">Action</th>
                        <th style="width:24%;">PIC</th>
                        <th style="width:14%;">Due Date</th>
                        <th style="width:14%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($actionItems as $i => $item)
                        <tr>
                            <td class="num">{{ $i + 1 }}</td>
                            <td>{{ $item['actionText'] }}</td>
                            <td>{{ $item['picLabel'] }}</td>
                            <td class="center">{{ $item['dueDate'] }}</td>
                            <td class="center">{{ $item['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>-</p>
        @endif

        <p class="muted">
            This is a computer-generated meeting minute document.
        </p>
    </main>
</body>
</html>
