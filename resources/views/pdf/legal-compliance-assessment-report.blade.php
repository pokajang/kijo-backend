@php
    $statusMeta = static function ($value): array {
        return match ($value) {
            'comply' => ['label' => 'Comply', 'class' => 'status-comply'],
            'not_comply' => ['label' => 'Not comply', 'class' => 'status-not-comply'],
            default => ['label' => 'Not selected', 'class' => 'status-empty'],
        };
    };
    $display = static fn($value): string => trim((string) ($value ?? '')) !== '' ? (string) $value : '-';
    $fieldValue = static function (array $field, array $response) use ($display): string {
        $key = (string) ($field['key'] ?? '');
        $rawValue = $response[$key] ?? '';

        if (($field['type'] ?? '') === 'radio') {
            foreach (($field['options'] ?? []) as $option) {
                if ((string) ($option['value'] ?? '') === (string) $rawValue) {
                    return $display($option['label'] ?? $rawValue);
                }
            }
        }

        if (is_array($rawValue)) {
            return $display(implode(', ', array_filter(array_map('strval', $rawValue))));
        }

        return $display($rawValue);
    };
    $groupLetter = static function (int $index): string {
        $value = $index + 1;
        $label = '';

        while ($value > 0) {
            $value--;
            $label = chr(65 + ($value % 26)).$label;
            $value = intdiv($value, 26);
        }

        return $label !== '' ? $label : 'A';
    };
    $conductedByName = trim((string) ($record->assessor_name ?? ''));
    $conductedByEmail = trim((string) ($record->assessor_email ?? ''));
    $conductedBy = $conductedByName !== '' && $conductedByEmail !== ''
        ? "{$conductedByName} ({$conductedByEmail})"
        : ($conductedByName !== '' ? $conductedByName : $conductedByEmail);
    $reportTitle = trim((string) ($templateSnapshot['report_title'] ?? '')) ?: 'Free Legal Compliance Assessment Report';
    $disclaimerText = trim((string) ($templateSnapshot['disclaimer_text'] ?? ''));
    if ($disclaimerText === '') {
        $disclaimerText = 'This free assessment report is provided as a preliminary compliance review based on the information available during the assessment. It does not constitute legal advice or a full statutory audit. Further verification may be required before relying on this report for regulatory, contractual, or enforcement purposes.';
    }
    $legalCount = count($groups ?? []);
    $totalClauses = 0;
    $complyClauses = 0;

    foreach (($groups ?? []) as $group) {
        foreach (($group['clauses'] ?? []) as $clause) {
            $totalClauses++;
            $clauseId = (string) ($clause['id'] ?? '');
            if (($clauseResponses[$clauseId]['complianceStatus'] ?? '') === 'comply') {
                $complyClauses++;
            }
        }
    }

    $compliancePercent = $totalClauses > 0 ? round(($complyClauses / $totalClauses) * 100) : 0;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle }} - {{ $record->company_name ?? $record->id }}</title>
    <style>
        @page { margin: 36mm 20mm 16mm 20mm; }
        body { margin: 0; color: #111; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }
        .pdf-header { position: fixed; top: -26mm; left: 0; right: 0; height: 24mm; color: #696969; margin-bottom: 0; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .company-name { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; margin-top: 0.8mm; }
        .header-right { width: 32%; text-align: right; }
        .company-logo { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }
        .report-title { margin: 0 0 4mm 0; text-align: center; font-size: 14pt; font-weight: 700; color: #003c00; }
        .disclaimer-card { margin: 0 0 4mm 0; border: 0.5px solid #d8dee5; border-radius: 1.5mm; padding: 2mm 2.5mm; background: #fafafa; }
        .disclaimer-title { margin: 0 0 0.8mm 0; color: #333; font-size: 9pt; font-weight: 700; }
        .disclaimer-text { margin: 0; color: #666; font-size: 8pt; font-style: italic; line-height: 1.3; }
        .snapshot-warning { margin: 0 0 4mm 0; border: 0.5px solid #f0b429; border-radius: 1.5mm; padding: 2mm 2.5mm; background: #fff8e5; color: #7a4d00; font-size: 8.5pt; line-height: 1.35; }
        .snapshot-warning-title { margin: 0 0 0.8mm 0; font-size: 9pt; font-weight: 700; }
        .snapshot-warning-text { margin: 0; }
        .section-title { margin: 0 0 2mm 0; color: #006400; font-size: 11pt; font-weight: 700; page-break-after: avoid; }
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 5mm; table-layout: fixed; }
        .details-table th { color: #555; font-size: 8.5pt; font-weight: 400; text-align: left; padding: 1.2mm 1.5mm 0.4mm 0; }
        .details-table td { font-size: 10pt; padding: 0 1.5mm 2.2mm 0; vertical-align: top; }
        .stats-table { width: 100%; border-collapse: separate; border-spacing: 2.5mm 0; margin: -1mm -2.5mm 5mm -2.5mm; table-layout: fixed; }
        .stat-card { border: 0.6px solid #c8f0c8; background: #f7fff7; border-radius: 2.5mm; padding: 3mm 3.5mm; text-align: center; }
        .stat-label { color: #555; font-size: 8.2pt; margin-bottom: 1mm; white-space: nowrap; }
        .stat-value { color: #003c00; font-size: 15pt; font-weight: 700; }
        .group { margin-bottom: 5mm; page-break-inside: auto; }
        .group-title { color: #555; font-size: 11pt; font-weight: 700; margin: 0 0 2mm 0; page-break-after: avoid; }
        .clause { margin-bottom: 6mm; page-break-inside: avoid; }
        .clause-title-row { margin-bottom: 1mm; }
        .status-text { font-weight: 700; }
        .status-comply { color: #00802b; }
        .status-not-comply { color: #9b1c1c; }
        .status-empty { color: #555; }
        .clause-title { font-weight: 700; font-size: 10pt; }
        .clause-excerpt { margin: 0 0 1.5mm 0; }
        .finding-text { margin: 0; white-space: pre-line; }
        .extra-field { margin: 1mm 0 0 0; white-space: pre-line; }
        .extra-field-label { color: #555; font-weight: 700; }
        .empty-state { color: #666; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header', [
        'documentType' => 'REPORT',
        'pdfLanguage' => 'en',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <h1 class="report-title">{{ $reportTitle }}</h1>

        @if($disclaimerText !== '')
            <div class="disclaimer-card">
                <p class="disclaimer-title">Disclaimer</p>
                <p class="disclaimer-text">{{ $disclaimerText }}</p>
            </div>
        @endif

        @if(! empty($templateSnapshotUnresolved))
            <div class="snapshot-warning">
                <p class="snapshot-warning-title">Template Snapshot Unresolved</p>
                <p class="snapshot-warning-text">The original assessment template could not be resolved from a stored snapshot or historical template version. Findings are shown only where saved responses exist; legal groups and clauses are not inferred from the current active template.</p>
            </div>
        @endif

        <div class="section-title">Assessment Details</div>
        <table class="details-table">
            <tr>
                <th>Company</th>
                <th>Address</th>
                <th>Assessment Date</th>
            </tr>
            <tr>
                <td>{{ $display($record->company_name ?? '') }}</td>
                <td>{{ $display($record->site_location ?? '') }}</td>
                <td>{{ $display($record->assessment_date ?? '') }}</td>
            </tr>
            <tr>
                <th>Client PIC Name</th>
                <th>Client PIC Email</th>
                <th>Nature of Company</th>
            </tr>
            <tr>
                <td>{{ $display($record->client_pic_name ?? '') }}</td>
                <td>{{ $display($record->client_pic_email ?? '') }}</td>
                <td>{{ $display($record->nature_of_company ?? '') }}</td>
            </tr>
            <tr>
                <th>Assessment Conducted By</th>
                <th>Project</th>
                <th>Revision</th>
            </tr>
            <tr>
                <td>{{ $display($conductedBy) }}</td>
                <td>{{ $display($record->project_name ?? '') }}</td>
                <td>Rev. {{ (int) ($record->revision_number ?? 1) }}</td>
            </tr>
            <tr>
                <th>Submitted By</th>
                <th>Submitted At</th>
                <th></th>
            </tr>
            <tr>
                <td>{{ $display($record->submitted_by_name ?? '') }}</td>
                <td>{{ $display($record->submitted_at ?? '') }}</td>
                <td></td>
            </tr>
        </table>

        <table class="stats-table">
            <tr>
                <td>
                    <div class="stat-card">
                        <div class="stat-label">Legals Assessed</div>
                        <div class="stat-value">{{ $legalCount }}</div>
                    </div>
                </td>
                <td>
                    <div class="stat-card">
                        <div class="stat-label">Clauses Assessed</div>
                        <div class="stat-value">{{ $totalClauses }}</div>
                    </div>
                </td>
                <td>
                    <div class="stat-card">
                        <div class="stat-label">Compliance Rate</div>
                        <div class="stat-value">{{ $compliancePercent }}%</div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="section-title">Assessment Findings</div>
        @forelse($groups as $groupIndex => $group)
            <section class="group">
                <h2 class="group-title">{{ $groupLetter($groupIndex) }}. {{ $display($group['title'] ?? 'Legislation name not set') }}</h2>
                @forelse(($group['clauses'] ?? []) as $clause)
                    @php
                        $clauseId = (string) ($clause['id'] ?? '');
                        $response = $clauseResponses[$clauseId] ?? [];
                        $status = $statusMeta($response['complianceStatus'] ?? '');
                    @endphp
                    <div class="clause">
                        <div class="clause-title-row">
                            <span class="clause-title">{{ $display($clause['title'] ?? '') }}</span>
                        </div>
                        <p class="clause-excerpt">{{ $display($clause['excerpt'] ?? '') }}</p>
                        <p class="finding-text"><span class="status-text {{ $status['class'] }}">{{ $status['label'] }}</span> - {{ $display($response['finding'] ?? '') }}</p>
                        @foreach(($clause['fields'] ?? []) as $field)
                            @php
                                $fieldKey = (string) ($field['key'] ?? '');
                            @endphp
                            @if(! in_array($fieldKey, ['complianceStatus', 'finding'], true))
                                <p class="extra-field">
                                    <span class="extra-field-label">{{ $display($field['label'] ?? $fieldKey) }}:</span>
                                    {{ $fieldValue($field, $response) }}
                                </p>
                            @endif
                        @endforeach
                    </div>
                @empty
                    <p class="empty-state">No clauses found.</p>
                @endforelse
            </section>
        @empty
            <p class="empty-state">No assessment findings found.</p>
        @endforelse
    </main>
</body>
</html>
