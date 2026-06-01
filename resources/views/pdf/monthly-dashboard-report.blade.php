<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        {!! $fontFaceCss ?? '' !!}
        @page { margin: 36mm 18mm 16mm 18mm; }
        body {
            color: #111827;
            font-family: ReportArial, Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.35;
            margin: 0;
        }
        h1, h2, h3 { margin: 0; }
        h1 { font-size: 17px; }
        h2 {
            border-bottom: 1px solid #d1d5db;
            color: #111827;
            font-size: 15px;
            margin-top: 18px;
            padding-bottom: 5px;
        }
        h3 { color: #374151; font-size: 12px; margin: 12px 0 6px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 6px; vertical-align: top; }
        th { background: #f6f7f9; color: #374151; font-weight: bold; text-align: left; }
        .pdf-header {
            color: #696969;
            height: 24mm;
            left: 0;
            position: fixed;
            right: 0;
            top: -26mm;
        }
        .header-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }
        .header-table td {
            border: 0;
            padding: 0;
            vertical-align: top;
        }
        .header-left {
            text-align: left;
            width: 68%;
        }
        .company-name {
            font-size: 10pt;
            font-weight: 700;
            margin-bottom: 1.5mm;
        }
        .company-address {
            font-size: 10pt;
            line-height: 1.2;
            margin-bottom: 1.5mm;
        }
        .company-contact {
            font-size: 10pt;
            font-weight: 700;
        }
        .header-right {
            text-align: right;
            width: 32%;
        }
        .company-logo {
            display: inline-block;
            height: auto;
            margin-top: -1mm;
            width: 42mm;
        }
        .document-type {
            font-size: 10pt;
            font-weight: 700;
            letter-spacing: 0.3px;
            margin-top: 2.2mm;
        }
        .header-separator {
            border-bottom: 0.7px solid #696969;
            margin-top: 1.3mm;
        }
        .report-title { margin-bottom: 8px; }
        .report-title h1 { margin-bottom: 4px; }
        .report-meta {
            border-bottom: 1px solid #d1d5db;
            border-top: 1px solid #d1d5db;
            color: #4b5563;
            font-size: 10px;
            margin-bottom: 14px;
            padding: 6px 0;
            white-space: nowrap;
        }
        .report-meta strong {
            color: #374151;
            font-weight: bold;
        }
        .report-meta .separator {
            color: #9ca3af;
            padding: 0 12px;
        }
        .muted { color: #6b7280; }
        .section-intro {
            color: #4b5563;
            font-size: 10px;
            margin: 5px 0 8px;
        }
        .kpi-grid {
            border: 1px solid #d1d5db;
            border-collapse: separate;
            border-radius: 6px;
            border-spacing: 0;
            margin-bottom: 14px;
            table-layout: fixed;
            width: 100%;
        }
        .kpi-grid td {
            border: 0;
            border-right: 1px solid #d1d5db;
            padding: 8px;
        }
        .kpi-grid td:last-child { border-right: 0; }
        .kpi-label {
            color: #6b7280;
            font-size: 8.5px;
            letter-spacing: .2px;
        }
        .kpi-value {
            color: #111827;
            font-size: 15px;
            font-weight: bold;
            margin-top: 4px;
        }
        .kpi-detail {
            color: #4b5563;
            font-size: 9.5px;
            margin-top: 2px;
        }
        .decision-grid {
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 12px;
            table-layout: fixed;
            width: 100%;
        }
        .decision-grid > tbody > tr > td {
            border: 0;
            padding: 0 6px 0 0;
            width: 33.333%;
        }
        .decision-grid > tbody > tr > td:last-child { padding-right: 0; }
        .decision-panel {
            border: 1px solid #d1d5db;
            border-collapse: separate;
            border-radius: 6px;
            border-spacing: 0;
            width: 100%;
        }
        .decision-panel td {
            border: 0;
            border-bottom: 1px solid #e5e7eb;
            padding: 6px;
        }
        .decision-panel tr:last-child td { border-bottom: 0; }
        .decision-title {
            background: #f6f7f9;
            color: #374151;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .2px;
            text-transform: uppercase;
        }
        .decision-label {
            color: #6b7280;
            font-size: 8.5px;
            margin-bottom: 2px;
        }
        .decision-value {
            color: #111827;
            font-size: 12px;
            font-weight: bold;
        }
        .decision-detail {
            color: #4b5563;
            font-size: 9px;
            margin-top: 2px;
        }
        .decision-list {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            margin-bottom: 10px;
            padding: 7px 10px;
        }
        .decision-list-title {
            color: #374151;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: .2px;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .decision-list ol,
        .decision-list ul {
            margin: 0 0 0 15px;
            padding: 0;
        }
        .decision-list li {
            margin-bottom: 3px;
        }
        .report-table {
            border: 1px solid #d1d5db;
            border-collapse: separate;
            border-radius: 6px;
            border-spacing: 0;
            margin-bottom: 8px;
        }
        .report-table th,
        .report-table td {
            border: 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .report-table tr:last-child td {
            border-bottom: 0;
        }
        .rank {
            color: #6b7280;
            text-align: center;
            white-space: nowrap;
            width: 28px;
        }
        .two-col { width: 100%; }
        .two-col > tbody > tr > td { border: 0; padding: 0 8px 0 0; width: 50%; }
        .two-col > tbody > tr > td:last-child { padding-right: 0; }
        .num { text-align: right; white-space: nowrap; }
        .empty { color: #6b7280; font-style: italic; text-align: center; }
        .keep-together { page-break-inside: avoid; }
        .staff-matrix-section { page-break-before: always; }
        .staff-subpage { page-break-before: always; }
        .wide-table th,
        .wide-table td { font-size: 9.5px; padding: 4px; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'MANAGEMENT REPORT',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <section class="report-title">
        <h1>{{ $title }}</h1>
    </section>

    <div class="report-meta">
        <strong>Report month:</strong> {{ $reportMonth }}
        <span class="separator">|</span>
        <strong>Reporting period:</strong> {{ $periodLabel }}
        <span class="separator">|</span>
        <strong>Generated:</strong> {{ $generatedAtLabel }}
    </div>

    @php
        $emptyRow = function (int $colspan) {
            return '<tr><td class="empty" colspan="'.$colspan.'">No records for this reporting period.</td></tr>';
        };
        $decisionSummary = $decisionSummary ?? [
            'cashSignals' => [],
            'pipelineSignals' => [],
            'driverSignals' => [],
            'decisionPoints' => [],
            'opportunities' => [],
        ];
    @endphp

    <h2>Executive Decision Summary</h2>
    <div class="section-intro">A front-page view of cash position, pipeline strength, performance drivers, and near-term decisions for the selected year-to-date period.</div>
    <table class="decision-grid">
        <tr>
            <td>
                <table class="decision-panel">
                    <tr><td class="decision-title">1. Grow Profitably</td></tr>
                    @forelse (($decisionSummary['cashSignals'] ?? []) as $row)
                        <tr>
                            <td>
                                <div class="decision-label">{{ $row['label'] }}</div>
                                <div class="decision-value">{{ $row['value'] }}</div>
                                <div class="decision-detail">{{ $row['detail'] }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="empty">No financial signal available.</td></tr>
                    @endforelse
                </table>
            </td>
            <td>
                <table class="decision-panel">
                    <tr><td class="decision-title">2. Pipeline Strength</td></tr>
                    @forelse (($decisionSummary['pipelineSignals'] ?? []) as $row)
                        <tr>
                            <td>
                                <div class="decision-label">{{ $row['label'] }}</div>
                                <div class="decision-value">{{ $row['value'] }}</div>
                                <div class="decision-detail">{{ $row['detail'] }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="empty">No pipeline signal available.</td></tr>
                    @endforelse
                </table>
            </td>
            <td>
                <table class="decision-panel">
                    <tr><td class="decision-title">3. Performance Drivers</td></tr>
                    @forelse (($decisionSummary['driverSignals'] ?? []) as $row)
                        <tr>
                            <td>
                                <div class="decision-label">{{ $row['label'] }}</div>
                                <div class="decision-value">{{ $row['value'] }}</div>
                                <div class="decision-detail">{{ $row['detail'] }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="empty">No driver signal available.</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
    <div class="decision-list">
        <div class="decision-list-title">4. Decision Points</div>
        <ol>
            @forelse (($decisionSummary['decisionPoints'] ?? []) as $point)
                <li>{{ $point }}</li>
            @empty
                <li>No decision point is available for this reporting period.</li>
            @endforelse
        </ol>
    </div>
    <div class="decision-list">
        <div class="decision-list-title">Opportunities to Develop</div>
        <ul>
            @forelse (($decisionSummary['opportunities'] ?? []) as $point)
                <li>{{ $point }}</li>
            @empty
                <li>No opportunity signal is available for this reporting period.</li>
            @endforelse
        </ul>
    </div>

    <h2>Company-Wide Snapshot</h2>
    <div class="section-intro">Company-wide figures for the selected year-to-date reporting period.</div>
    <table class="kpi-grid">
        <tr>
            @foreach ($summaryCards as $card)
                <td>
                    <div class="kpi-label">{{ $card['label'] }}</div>
                    <div class="kpi-value">{{ $card['value'] }}</div>
                    <div class="kpi-detail">{{ $card['detail'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <h2>Sales Performance and Conversion</h2>
    <div class="section-intro">Awarded sales ranked by value for the selected year-to-date reporting period.</div>
    <table class="two-col">
        <tr>
            <td>
                <h3>Top Awarded Services</h3>
                <table class="report-table">
                    <tr><th class="rank">Rank</th><th>Service</th><th class="num">Awarded Value</th></tr>
                    @forelse ($sales['byService'] as $row)
                        <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                    @empty
                        {!! $emptyRow(3) !!}
                    @endforelse
                </table>
            </td>
            <td>
                <h3>Top Awarded Staff</h3>
                <table class="report-table">
                    <tr><th class="rank">Rank</th><th>Staff</th><th class="num">Awarded Value</th></tr>
                    @forelse ($sales['byPerson'] as $row)
                        <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                    @empty
                        {!! $emptyRow(3) !!}
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
    <table class="two-col">
        <tr>
            <td>
                <h3>Top Awarded Sources</h3>
                <table class="report-table">
                    <tr><th class="rank">Rank</th><th>Source</th><th class="num">Awarded Value</th></tr>
                    @forelse ($sales['bySource'] as $row)
                        <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                    @empty
                        {!! $emptyRow(3) !!}
                    @endforelse
                </table>
            </td>
            <td>
                <h3>Sales Conversion by Staff</h3>
                <table class="report-table">
                    <tr><th class="rank">Rank</th><th>Staff</th><th class="num">Converted Quotes</th><th class="num">Total Quotes</th><th class="num">Conversion Rate</th></tr>
                    @forelse ($sales['conversionStaff'] as $row)
                        <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['convertedCount'] }}</td><td class="num">{{ $row['totalQuotes'] }}</td><td class="num">{{ $row['rate'] }}</td></tr>
                    @empty
                        {!! $emptyRow(5) !!}
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
    <div class="keep-together">
        <h3>Conversion by Source</h3>
        <table class="report-table">
            <tr><th class="rank">Rank</th><th>Source</th><th class="num">Converted Quotes</th><th class="num">Total Quotes</th><th class="num">Conversion Rate</th></tr>
            @forelse (($sales['conversionSource'] ?? []) as $row)
                <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['convertedCount'] }}</td><td class="num">{{ $row['totalQuotes'] }}</td><td class="num">{{ $row['rate'] }}</td></tr>
            @empty
                {!! $emptyRow(5) !!}
            @endforelse
        </table>
    </div>
    <div class="keep-together">
        <h3>Conversion by Service</h3>
        <table class="report-table">
            <tr><th class="rank">Rank</th><th>Service</th><th class="num">Converted Quotes</th><th class="num">Total Quotes</th><th class="num">Conversion Rate</th></tr>
            @forelse (($sales['conversionService'] ?? []) as $row)
                <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['convertedCount'] }}</td><td class="num">{{ $row['totalQuotes'] }}</td><td class="num">{{ $row['rate'] }}</td></tr>
            @empty
                {!! $emptyRow(5) !!}
            @endforelse
        </table>
    </div>

    <h2>CRM Quotation and Inquiry Activity</h2>
    <div class="section-intro">Quotation and inquiry totals for the selected year-to-date reporting period.</div>
    <h3>Monthly Quotation Trend</h3>
    <table class="report-table">
        <tr><th>Month</th><th class="num">Quotation Count</th><th class="num">Quotation Value</th></tr>
        @forelse (($crm['monthlyQuoteTrend'] ?? []) as $row)
            <tr><td>{{ $row['month'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
        @empty
            {!! $emptyRow(3) !!}
        @endforelse
    </table>
    <table class="two-col">
        <tr>
            <td>
                <h3>Quotation Activity by Staff</h3>
                <table class="report-table">
                    <tr><th class="rank">Rank</th><th>Staff</th><th class="num">Quotation Count</th><th class="num">Quotation Value</th></tr>
                    @forelse ($crm['quoteActivityByStaff'] as $row)
                        <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                    @empty
                        {!! $emptyRow(4) !!}
                    @endforelse
                </table>
            </td>
            <td>
                <h3>Quotation Value by Service</h3>
                <table class="report-table">
                    <tr><th class="rank">Rank</th><th>Service</th><th class="num">Quotation Value</th></tr>
                    @forelse ($crm['quoteValueByService'] as $row)
                        <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
                    @empty
                        {!! $emptyRow(3) !!}
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
    <h3>Service Mix Over Time</h3>
    <table class="report-table">
        <tr><th class="rank">Rank</th><th>Service</th><th class="num">Months With Quotes</th><th class="num">Quotation Value</th></tr>
        @forelse (($crm['monthlyQuoteServiceRows'] ?? []) as $row)
            <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['service'] }}</td><td class="num">{{ $row['monthsWithQuotes'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
        @empty
            {!! $emptyRow(4) !!}
        @endforelse
    </table>
    <h3>Inquiry Source Mix</h3>
    <table class="report-table">
        <tr><th class="rank">Rank</th><th>Inquiry Source</th><th class="num">Inquiry Count</th><th class="num">Quotation Value</th></tr>
        @forelse ($crm['inquirySourceMix'] as $row)
            <tr><td class="rank">{{ $row['rank'] }}</td><td>{{ $row['label'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $row['value'] }}</td></tr>
        @empty
            {!! $emptyRow(4) !!}
        @endforelse
    </table>

    <h2>Financial Position and Collections</h2>
    <div class="section-intro">Billing, collection, and receivable totals for the selected year-to-date reporting period.</div>
    <h3>YTD Financial Position</h3>
    <table class="report-table">
        <tr>
            <th class="num">Invoiced Amount</th>
            <th class="num">Received Amount</th>
            <th class="num">Outstanding Receivables</th>
            <th class="num">Uninvoiced Awarded Sales</th>
        </tr>
        <tr>
            <td class="num">RM {{ number_format($financial['totalInvoiced'], 2) }}</td>
            <td class="num">RM {{ number_format($financial['totalReceived'], 2) }}</td>
            <td class="num">{{ $financial['outstandingSummary'] }}</td>
            <td class="num">{{ $financial['uninvoicedAwardedSummary'] }}</td>
        </tr>
    </table>
    <h3>YTD Billing and Collection Trend</h3>
    <table class="report-table">
        <tr><th>Month</th><th class="num">Invoiced Amount</th><th class="num">Received Amount</th><th class="num">Net Movement</th></tr>
        @forelse ($financial['trend'] as $row)
            <tr><td>{{ $row['month'] }}</td><td class="num">{{ $row['invoiced'] }}</td><td class="num">{{ $row['received'] }}</td><td class="num">{{ $row['netMovement'] }}</td></tr>
        @empty
            {!! $emptyRow(4) !!}
        @endforelse
    </table>

    <h3>Top Outstanding Debtors</h3>
    <table class="report-table">
        <tr><th>Client</th><th>Invoice Reference</th><th>Invoice Date</th><th class="num">Outstanding Amount</th><th>Internal PIC</th></tr>
        @forelse ($financial['debtors'] as $row)
            <tr><td>{{ $row['client'] }}</td><td>{{ $row['invoice'] }}</td><td>{{ $row['date'] }}</td><td class="num">{{ $row['amount'] }}</td><td>{{ $row['pic'] }}</td></tr>
        @empty
            {!! $emptyRow(5) !!}
        @endforelse
    </table>

    <h2>Monitoring Pipeline and Realized Revenue</h2>
    <div class="section-intro">Monitoring stage distribution, realized service revenue, and staff pipeline totals for the selected year-to-date reporting period.</div>
    <h3>Monthly Monitoring Trend</h3>
    <table class="report-table">
        <tr><th>Month</th><th class="num">Proposal Items</th><th class="num">Proposal Value</th><th class="num">Closed Items</th><th class="num">Realized Revenue</th><th class="num">Win Rate</th></tr>
        @forelse (($monitoring['trendRows'] ?? []) as $row)
            <tr><td>{{ $row['month'] }}</td><td class="num">{{ $row['proposalQty'] }}</td><td class="num">{{ $row['proposalRm'] }}</td><td class="num">{{ $row['revenueQty'] }}</td><td class="num">{{ $row['revenueRm'] }}</td><td class="num">{{ $row['winRate'] }}</td></tr>
        @empty
            {!! $emptyRow(6) !!}
        @endforelse
    </table>
    <table class="two-col">
        <tr>
            <td>
                <h3>Pipeline Stage Summary</h3>
                <table class="report-table">
                    <tr><th>Pipeline Stage</th><th class="num">Total Items</th><th class="num">Special Project Items</th><th class="num">Tender Items</th></tr>
                    @forelse ($monitoring['pipelineStages'] as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['total'] }}</td><td class="num">{{ $row['specialProjectQty'] }}</td><td class="num">{{ $row['tenderQty'] }}</td></tr>
                    @empty
                        {!! $emptyRow(4) !!}
                    @endforelse
                </table>
            </td>
            <td>
                <h3>Realized Service Revenue</h3>
                <table class="report-table">
                    <tr><th>Service</th><th class="num">Service Items</th><th class="num">Realized Revenue</th></tr>
                    @forelse ($monitoring['serviceRevenue'] as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['qty'] }}</td><td class="num">{{ $row['rm'] }}</td></tr>
                    @empty
                        {!! $emptyRow(3) !!}
                    @endforelse
                </table>
            </td>
        </tr>
    </table>
    <h3>Staff Pipeline Stage Matrix</h3>
    <table class="report-table wide-table">
        <tr><th>Staff</th><th class="num">Leads</th><th class="num">Qualified</th><th class="num">Meeting/Pitching</th><th class="num">Proposal</th><th class="num">Negotiation</th><th class="num">Closed</th></tr>
        @forelse (($monitoring['staffMatrix'] ?? []) as $row)
            <tr><td>{{ $row['staff'] }}</td><td class="num">{{ $row['leads'] }}</td><td class="num">{{ $row['qualified'] }}</td><td class="num">{{ $row['meetingPitching'] }}</td><td class="num">{{ $row['proposals'] }}</td><td class="num">{{ $row['negotiation'] }}</td><td class="num">{{ $row['closed'] }}</td></tr>
        @empty
            {!! $emptyRow(7) !!}
        @endforelse
    </table>
    <h3>Staff Segment Revenue Matrix</h3>
    <table class="report-table wide-table">
        <tr><th>Staff</th><th class="num">Individual Items</th><th class="num">Individual RM</th><th class="num">Special Project Items</th><th class="num">Special Project RM</th><th class="num">Tender Items</th><th class="num">Tender RM</th><th class="num">Total Revenue</th></tr>
        @forelse (($monitoring['staffMatrix'] ?? []) as $row)
            <tr><td>{{ $row['staff'] }}</td><td class="num">{{ $row['individualQty'] }}</td><td class="num">{{ $row['individualRm'] }}</td><td class="num">{{ $row['specialProjectQty'] }}</td><td class="num">{{ $row['specialProjectRm'] }}</td><td class="num">{{ $row['tenderQty'] }}</td><td class="num">{{ $row['tenderRm'] }}</td><td class="num">{{ $row['revenue'] }}</td></tr>
        @empty
            {!! $emptyRow(8) !!}
        @endforelse
    </table>

    <h2>Workload Pressure by Staff</h2>
    <div class="section-intro">Snapshot as of {{ $workload['asOfDate'] ?: '-' }}. This combined management report excludes detailed task evidence; detailed task evidence remains in the standalone workload PDF.</div>
    <table class="report-table">
        <tr><th>Staff</th><th class="num">Workload Score</th><th class="num">Active Items</th><th class="num">Overdue Items</th><th class="num">Due Soon Items</th></tr>
        @forelse ($workload['topStaff'] as $row)
            <tr><td>{{ $row['staff'] }}</td><td class="num">{{ $row['score'] }}</td><td class="num">{{ $row['activeTasks'] }}</td><td class="num">{{ $row['overdueTasks'] }}</td><td class="num">{{ $row['dueSoonTasks'] }}</td></tr>
        @empty
            {!! $emptyRow(5) !!}
        @endforelse
    </table>
    <div class="keep-together">
        <h3>Workload Score Trend</h3>
        <table class="report-table">
            <tr><th>Staff</th><th class="num">Snapshots</th><th class="num">First Score</th><th class="num">Latest Score</th><th class="num">Average Score</th><th class="num">Peak Score</th></tr>
            @forelse (($workload['historyRows'] ?? []) as $row)
                <tr><td>{{ $row['staff'] }}</td><td class="num">{{ $row['snapshots'] }}</td><td class="num">{{ $row['firstScore'] }}</td><td class="num">{{ $row['latestScore'] }}</td><td class="num">{{ $row['averageScore'] }}</td><td class="num">{{ $row['peakScore'] }}</td></tr>
            @empty
                {!! $emptyRow(6) !!}
            @endforelse
        </table>
    </div>

    <div class="staff-matrix-section">
        <h2>Staff-by-Staff YTD Performance Matrix</h2>
        <div class="section-intro">Staff rows combine available year-to-date commercial, monitoring, and workload metrics. A dash indicates that the metric is not available for that staff row.</div>

        <h3>Staff Sales and Quotation Metrics</h3>
        <table class="report-table">
            <tr>
                <th>Staff</th>
                <th class="num">YTD Awarded Sales Value</th>
                <th class="num">Awarded Sales Count</th>
                <th class="num">Quotation Count</th>
                <th class="num">Quotation Value</th>
            </tr>
            @forelse ($staffPerformanceRows as $row)
                <tr>
                    <td>{{ $row['staff'] }}</td>
                    <td class="num">{{ $row['awardedSalesValue'] }}</td>
                    <td class="num">{{ $row['awardedSalesCount'] }}</td>
                    <td class="num">{{ $row['quotationCount'] }}</td>
                    <td class="num">{{ $row['quotationValue'] }}</td>
                </tr>
            @empty
                {!! $emptyRow(5) !!}
            @endforelse
        </table>

        <h3>Staff Conversion Metrics</h3>
        <table class="report-table">
            <tr>
                <th>Staff</th>
                <th class="num">Converted Quotes</th>
                <th class="num">Total Quotes</th>
                <th class="num">Conversion Rate</th>
            </tr>
            @forelse ($staffPerformanceRows as $row)
                <tr>
                    <td>{{ $row['staff'] }}</td>
                    <td class="num">{{ $row['convertedQuotes'] }}</td>
                    <td class="num">{{ $row['totalQuotes'] }}</td>
                    <td class="num">{{ $row['conversionRate'] }}</td>
                </tr>
            @empty
                {!! $emptyRow(4) !!}
            @endforelse
        </table>

        <div class="staff-subpage">
            <h3>Staff Monitoring Metrics</h3>
            <table class="report-table">
                <tr>
                    <th>Staff</th>
                    <th class="num">Lead Items</th>
                    <th class="num">Proposal Items</th>
                    <th class="num">Closed Items</th>
                    <th class="num">Realized Revenue</th>
                </tr>
                @forelse ($staffPerformanceRows as $row)
                    <tr>
                        <td>{{ $row['staff'] }}</td>
                        <td class="num">{{ $row['leadItems'] }}</td>
                        <td class="num">{{ $row['proposalItems'] }}</td>
                        <td class="num">{{ $row['closedItems'] }}</td>
                        <td class="num">{{ $row['realizedRevenue'] }}</td>
                    </tr>
                @empty
                    {!! $emptyRow(5) !!}
                @endforelse
            </table>

            <h3>Staff Workload Metrics</h3>
            <table class="report-table">
                <tr>
                    <th>Staff</th>
                    <th class="num">Workload Score</th>
                    <th class="num">Active Items</th>
                    <th class="num">Overdue Items</th>
                    <th class="num">Due Soon Items</th>
                </tr>
                @forelse ($staffPerformanceRows as $row)
                    <tr>
                        <td>{{ $row['staff'] }}</td>
                        <td class="num">{{ $row['workloadScore'] }}</td>
                        <td class="num">{{ $row['activeItems'] }}</td>
                        <td class="num">{{ $row['overdueItems'] }}</td>
                        <td class="num">{{ $row['dueSoonItems'] }}</td>
                    </tr>
                @empty
                    {!! $emptyRow(5) !!}
                @endforelse
            </table>
        </div>
    </div>
</body>
</html>
