<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'All Staff Tasks' }}</title>
    <style>
        @page { margin: 10mm 20mm 12mm 20mm; }
        body {
            margin: 0;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1.32;
        }
        .pdf-header { color: #696969; margin-bottom: 5mm; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 9pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 9pt; font-weight: 700; }
        .company-logo { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }
        h1 {
            margin: 0 0 2mm 0;
            font-size: 15pt;
            line-height: 1.2;
        }
        .meta {
            margin: 0 0 4mm 0;
            color: #555;
            font-size: 9pt;
        }
        .meta div { margin-bottom: 1mm; }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 7.6pt;
        }
        .report-table th,
        .report-table td {
            border: 0.5px solid #b8b8b8;
            padding: 4px 5px;
            vertical-align: top;
            text-align: left;
            word-wrap: break-word;
        }
        .report-table th {
            background: #f2f2f2;
            font-weight: 700;
        }
        .col-no { width: 24px; text-align: center; }
        .col-created { width: 88px; }
        .col-due { width: 70px; }
        .col-staff { width: 130px; }
        .col-task { width: 155px; }
        .col-status { width: 105px; }
        .col-lapsed { width: 78px; text-align: center; }
        .col-basis { width: 82px; }
        .col-completed { width: 82px; }
        .col-comments { width: 130px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .empty-row { text-align: center; color: #666; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'REPORT',
        'pdfLanguage' => 'en',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <h1>{{ $title ?? 'All Staff Tasks' }}</h1>
        <div class="meta">
            <div>{{ $periodLabel ?? 'All records' }}</div>
            <div>{{ $staffLabel ?? 'Staff: All Staff' }}</div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th class="col-no">#</th>
                    <th class="col-created">Created On</th>
                    <th class="col-due">Due Date</th>
                    <th class="col-staff">Staff</th>
                    <th class="col-task">Task</th>
                    <th class="col-status">Status</th>
                    <th class="col-lapsed">Days Lapsed</th>
                    <th class="col-basis">Lapsed Basis</th>
                    <th class="col-completed">Completed At</th>
                    <th class="col-comments">Comment Logs</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tasks as $index => $task)
                    <tr>
                        <td class="col-no">{{ $index + 1 }}</td>
                        <td>{{ $task['createdAt'] }}</td>
                        <td>{{ $task['dueDate'] }}</td>
                        <td>{{ $task['staff'] }}</td>
                        <td>{{ $task['title'] }}</td>
                        <td>{{ $task['statusText'] }}</td>
                        <td class="col-lapsed">{{ $task['daysLapsed'] }}</td>
                        <td>{{ $task['daysLapsedBasis'] ?? '-' }}</td>
                        <td>{{ $task['completedAt'] }}</td>
                        <td>{!! nl2br(e($task['commentSummary'])) !!}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="empty-row">No records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </main>
</body>
</html>
