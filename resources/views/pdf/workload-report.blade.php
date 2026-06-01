<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Workload Tracking' }}</title>
    <style>
        {!! $fontFaceCss ?? '' !!}
        @page { margin: 10mm 13mm 14mm 13mm; }
        * {
            font-family: WorkloadArial, Arial, Helvetica, sans-serif !important;
        }
        body {
            margin: 0;
            color: #1f2937;
            background: #fff;
            font-family: WorkloadArial, Arial, Helvetica, sans-serif;
            font-size: 8.6pt;
            line-height: 1.35;
        }
        h1, h2, h3, h4 { margin: 0; color: #111827; letter-spacing: 0; }
        h1 { font-size: 19pt; }
        h2 { font-size: 12.5pt; }
        h3 { font-size: 10.5pt; }
        h4 { font-size: 8.6pt; text-transform: uppercase; }
        .muted { color: #6b7280; }
        .header-table {
            width: 100%;
            margin-bottom: 6mm;
            padding-bottom: 5mm;
            border-bottom: 1.4px solid #111827;
            border-collapse: collapse;
        }
        .header-table td { padding: 0; vertical-align: top; }
        .header-meta {
            width: 44%;
            text-align: right;
            font-size: 8.4pt;
        }
        .staff-section {
            margin-bottom: 5mm;
            border: 0;
            page-break-inside: auto;
        }
        .staff-header {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            page-break-after: avoid;
        }
        .staff-header td {
            padding: 0 0 3.2mm;
            vertical-align: middle;
            border-bottom: 0.6px solid #d1d5db;
        }
        .staff-name-cell { width: 68%; }
        .staff-score-cell {
            width: 32%;
            text-align: right;
            white-space: nowrap;
        }
        .staff-code {
            display: inline;
            color: #0d6efd;
            font-size: 13pt;
            font-weight: bold;
        }
        .staff-full-name {
            display: inline;
            color: #0d6efd;
            font-size: 10.5pt;
            font-weight: bold;
        }
        .score {
            color: #0d6efd;
            font-size: 19pt;
            font-weight: bold;
            border-bottom: 0.6px dotted currentColor;
        }
        .score.success { color: #198754; }
        .score.warning { color: #b58105; }
        .score.danger { color: #dc3545; }
        .score.low { color: #198754; }
        .score.moderate { color: #f9b115; }
        .score.high { color: #dc3545; }
        .score.extreme { color: #8b0000; }
        .chip {
            display: inline-block;
            margin-top: 2mm;
            margin-right: 1.2mm;
            padding: 1.2mm 2mm;
            border: 0.5px solid #d1d5db;
            border-radius: 7mm;
            font-size: 7.6pt;
            font-weight: bold;
            white-space: nowrap;
        }
        .chip.primary { color: #084298; background: #cfe2ff; }
        .chip.info { color: #055160; background: #cff4fc; }
        .chip.danger { color: #842029; background: #f8d7da; }
        .chip.muted { color: #6b7280; background: #f3f4f6; }
        .section-body { padding: 3.2mm 0 0; }
        .subheading {
            margin: 3mm 0 1.8mm;
            color: #6b7280;
            font-size: 7.8pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .project {
            margin-bottom: 3mm;
            page-break-inside: auto;
        }
        .table-start-guard {
            page-break-inside: avoid;
        }
        .table-start-guard .list-table,
        .table-start-guard .score-table,
        .table-start-guard .project-title-table {
            page-break-inside: avoid;
        }
        .table-continuation {
            margin-top: 0;
            page-break-inside: auto;
        }
        .project-title-table {
            width: 100%;
            margin-bottom: 2mm;
            border-collapse: collapse;
            page-break-after: avoid;
        }
        .project-title-table td { padding: 0; vertical-align: top; }
        .project-title {
            color: #6b7280;
            font-size: 9.2pt;
            font-weight: bold;
        }
        .project-value {
            width: 30mm;
            color: #198754;
            font-weight: bold;
            text-align: right;
            white-space: nowrap;
        }
        .list-table {
            width: 100%;
            border: 0.5px solid #d1d5db;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: auto;
        }
        .list-table td {
            padding: 1.8mm 2.3mm;
            vertical-align: top;
            border-bottom: 0.5px solid #e5e7eb;
            word-wrap: break-word;
        }
        .list-table tr:last-child td { border-bottom: 0; }
        .list-text { width: 72%; }
        .list-badge-cell {
            width: 28%;
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 1mm 1.8mm;
            border-radius: 7mm;
            font-size: 7.3pt;
            font-weight: bold;
            white-space: normal;
        }
        .badge.info { color: #055160; background: #cff4fc; }
        .badge.success { color: #0f5132; background: #d1e7dd; }
        .badge.danger { color: #842029; background: #f8d7da; }
        .empty {
            color: #6b7280;
            font-style: italic;
        }
        .score-table {
            width: 100%;
            margin-top: 2mm;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 7.8pt;
            page-break-inside: auto;
        }
        .score-table th,
        .score-table td {
            padding: 1.8mm;
            vertical-align: top;
            border: 0.5px solid #d1d5db;
            word-wrap: break-word;
        }
        .score-table th {
            color: #6b7280;
            background: #f3f4f6;
            font-weight: bold;
            text-align: left;
        }
        .score-table .section-row th,
        .score-table .section-row td {
            color: #1f2937;
            background: #e5e7eb;
            font-weight: bold;
            page-break-after: avoid;
        }
        .score-table .total-row th,
        .score-table .total-row td {
            color: #1f2937;
            background: #f3f4f6;
            font-weight: bold;
        }
        .work-type-table {
            width: 100%;
            margin: 1.8mm 0 2.2mm;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 7.6pt;
        }
        .work-type-table th,
        .work-type-table td {
            padding: 1.4mm 1.8mm;
            border: 0.5px solid #d1d5db;
            word-wrap: break-word;
        }
        .work-type-table th {
            color: #6b7280;
            background: #f3f4f6;
            text-align: left;
        }
        .work-type-number { width: 16mm; text-align: right; }
        .score-item { font-weight: bold; }
        .score-detail { color: #6b7280; }
        .points { width: 17mm; text-align: right; }
        thead { display: table-header-group; }
        tbody { display: table-row-group; }
        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        .subheading {
            page-break-after: avoid;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td>
                <h1>{{ $title ?? 'Workload Tracking' }}</h1>
            </td>
            <td class="header-meta">
                <div>Period: {{ $periodLabel ?? 'All available records' }}</div>
                <div>Snapshot as of: {{ $asOfDate ?? '-' }}</div>
                <div>Completed window: {{ $completedWindowLabel ?? 'All available completed work' }}</div>
            </td>
        </tr>
    </table>

    @forelse($staffRows as $staff)
        <section class="staff-section">
            <table class="staff-header">
                <tr>
                    <td class="staff-name-cell">
                        <span class="staff-code">{{ $staff['staffCode'] }}</span>
                        @if($staff['staffName'] !== '')
                            <span class="staff-full-name"> - {{ $staff['staffName'] }}</span>
                        @endif
                        <div>
                            @foreach($staff['chips'] as $chip)
                                <span class="chip {{ $chip['tone'] }}">{{ $chip['label'] }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="staff-score-cell">
                        <span class="score {{ $staff['scoreLevelKey'] ?? $staff['tone'] }}">{{ $staff['score'] }}</span>
                        <span class="muted"> workload score</span>
                    </td>
                </tr>
            </table>

            <div class="section-body">
                @if(count($staff['workTypeBreakdown']))
                    <div class="table-start-guard">
                        <div class="subheading">Work Type Mix</div>
                        <table class="work-type-table">
                            <thead>
                                <tr>
                                    <th>Work type</th>
                                    <th class="work-type-number">Active</th>
                                    <th class="work-type-number">Done</th>
                                    <th class="work-type-number">Effort</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($staff['workTypeBreakdown'] as $line)
                                    <tr>
                                        <td>{{ $line['label'] }}</td>
                                        <td class="work-type-number">{{ $line['activeCount'] }}</td>
                                        <td class="work-type-number">{{ $line['completedCount'] }}</td>
                                        <td class="work-type-number">{{ $line['effortPoints'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @forelse($staff['projects'] as $project)
                    <div class="project">
                        @php
                            $activityStartRows = array_slice($project['activityRows'], 0, 3);
                            $activityRemainingRows = array_slice($project['activityRows'], 3);
                        @endphp
                        @if(count($project['activityRows']))
                            <div class="table-start-guard">
                                <table class="project-title-table">
                                    <tr>
                                        <td class="project-title">{{ $project['title'] }}</td>
                                        <td class="project-value">{{ $project['value'] }}</td>
                                    </tr>
                                </table>
                                @include('pdf.partials.workload-activity-table', ['activities' => $activityStartRows])
                            </div>
                            @if(count($activityRemainingRows))
                                @include('pdf.partials.workload-activity-table', ['activities' => $activityRemainingRows, 'className' => 'table-continuation'])
                            @endif
                        @else
                            <div class="table-start-guard">
                                <table class="project-title-table">
                                    <tr>
                                        <td class="project-title">{{ $project['title'] }}</td>
                                        <td class="project-value">{{ $project['value'] }}</td>
                                    </tr>
                                </table>
                                <div class="empty">No project activity to show.</div>
                            </div>
                        @endif

                    </div>
                @empty
                    <div class="empty">No project workload in this snapshot.</div>
                @endforelse

                @php
                    $otherStartRows = array_slice($staff['otherTasks'], 0, 3);
                    $otherRemainingRows = array_slice($staff['otherTasks'], 3);
                @endphp
                @if(count($staff['otherTasks']))
                    <div class="table-start-guard">
                        <div class="subheading">Other 5MM Tasks</div>
                        @include('pdf.partials.workload-task-table', ['tasks' => $otherStartRows])
                    </div>
                    @if(count($otherRemainingRows))
                        @include('pdf.partials.workload-task-table', ['tasks' => $otherRemainingRows, 'className' => 'table-continuation'])
                    @endif
                @else
                    <div class="table-start-guard">
                        <div class="subheading">Other 5MM Tasks</div>
                        <div class="empty">No non-project workload in this snapshot.</div>
                    </div>
                @endif

                @php
                    $completedStartRows = array_slice($staff['completedTasks'], 0, 3);
                    $completedRemainingRows = array_slice($staff['completedTasks'], 3);
                    $scoreStartRows = array_slice($staff['scoreRows'], 0, 4);
                    $scoreRemainingRows = array_slice($staff['scoreRows'], 4);
                @endphp
                @if(count($staff['completedTasks']))
                    <div class="table-start-guard">
                        <div class="subheading">Completed 5MM Tasks</div>
                        @include('pdf.partials.workload-task-table', ['tasks' => $completedStartRows])
                    </div>
                    @if(count($completedRemainingRows))
                        @include('pdf.partials.workload-task-table', ['tasks' => $completedRemainingRows, 'className' => 'table-continuation'])
                    @endif
                @else
                    <div class="table-start-guard">
                        <div class="subheading">Completed 5MM Tasks</div>
                        <div class="empty">No completed non-project tasks in this period.</div>
                    </div>
                @endif

                <div class="table-start-guard">
                    <div class="subheading">Score Calculation</div>
                    @include('pdf.partials.workload-score-table', ['rows' => $scoreStartRows])
                </div>
                @if(count($scoreRemainingRows))
                    @include('pdf.partials.workload-score-table', ['rows' => $scoreRemainingRows, 'className' => 'table-continuation', 'showHeader' => false])
                @endif
            </div>
        </section>
    @empty
        <div class="empty">No workload data found.</div>
    @endforelse
</body>
</html>
