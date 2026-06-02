<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Salary Claim {{ $record['salaryMonth'] ?? '' }}</title>
    <style>
        @page { margin: 10mm 18mm 13mm 18mm; }
        body {
            margin: 0;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.75pt;
            line-height: 1.35;
        }
        .pdf-header { color: #5f6673; margin-bottom: 4.5mm; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name { color: #273142; font-size: 10.5pt; font-weight: 700; margin-bottom: 1.2mm; }
        .company-address { font-size: 8.5pt; line-height: 1.25; margin-bottom: 1.4mm; }
        .company-contact { color: #273142; font-size: 8.75pt; font-weight: 700; }
        .company-logo { width: 39mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type {
            color: #111827;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11pt;
            font-weight: 700;
            margin-top: 2mm;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .header-separator { margin-top: 2mm; border-bottom: 0.6px solid #d6dbe3; }
        .meta-table,
        .section-table,
        .signature-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }
        .meta-table {
            border-collapse: separate;
            border-spacing: 0;
            border: 0.6px solid #d8dee8;
            border-radius: 5px;
            color: #647081;
            margin-bottom: 4.5mm;
            background: #f8fafc;
        }
        .meta-table td {
            padding: 1.8mm 2.2mm;
            vertical-align: top;
        }
        .meta-label {
            color: #647081;
            font-weight: 700;
            text-transform: uppercase;
        }
        .meta-value {
            color: #111827;
            font-weight: 700;
        }
        .medical-balance-cell {
            white-space: nowrap;
        }
        .medical-balance-note {
            font-size: 8.25pt;
            white-space: nowrap;
        }
        .mileage-km-note {
            color: #647081;
            display: block;
            font-size: 7.25pt;
            line-height: 1.2;
            margin-top: 0.35mm;
        }
        h2 {
            color: #1f5f9f;
            font-size: 9.75pt;
            letter-spacing: 0.2px;
            margin: 4.5mm 0 1.6mm 0;
            text-transform: uppercase;
        }
        .section-table {
            border-collapse: separate;
            border-spacing: 0;
            border: 0.6px solid #d8dee8;
            border-radius: 6px;
            font-size: 8.35pt;
            margin-bottom: 3.5mm;
            overflow: hidden;
        }
        .section-table th {
            border-bottom: 0.6px solid #d8dee8;
            background: #f1f4f8;
            color: #4f5b6c;
            font-weight: 700;
            padding: 1.5mm 1.8mm;
            text-align: left;
            text-transform: uppercase;
        }
        .section-table td {
            border-bottom: 0.45px solid #e7ebf1;
            padding: 1.45mm 1.8mm;
            vertical-align: top;
            word-wrap: break-word;
        }
        .claim-master-table {
            margin-top: 4mm;
        }
        .claim-section-row td {
            background: #f8fafc;
            color: #647081;
            font-size: 8.75pt;
            font-weight: 700;
            letter-spacing: 0.15px;
            padding: 1.55mm 1.8mm;
            text-transform: uppercase;
        }
        .claim-section-empty-note {
            color: #647081;
            font-weight: 400;
            text-transform: none;
        }
        .claim-subheader-row th {
            background: #f1f4f8;
        }
        .amount-header {
            text-align: right !important;
            white-space: nowrap;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .muted { color: #647081; }
        .total-row td {
            border-top: 0.6px solid #d8dee8;
            border-bottom: 0.8px solid #cfd6e1;
            background: #f8fafc;
            color: #273142;
            font-weight: 700;
        }
        .empty-row td {
            color: #647081;
            padding: 2mm 1.2mm;
            text-align: center;
        }
        .pay-summary-label {
            color: #4f5b6c;
            font-weight: 700;
            text-transform: none;
        }
        .pay-summary-heading {
            background: #f1f4f8 !important;
            color: #1f5f9f;
            font-weight: 700;
            letter-spacing: 0.15px;
            text-transform: uppercase;
        }
        .pay-summary-value {
            color: #111827;
            font-weight: 700;
        }
        .claim-summary-row td {
            border-bottom: 0.45px solid #e7ebf1;
            background: #ffffff;
            padding: 1.35mm 1.8mm;
        }
        .claim-summary-start td {
            border-top: 0.6px solid #d8dee8;
        }
        .record-purpose-cell {
            border-left: 0.6px solid #d8dee8;
        }
        .record-purpose-heading {
            background: #f1f4f8 !important;
            color: #4f5b6c;
            font-weight: 700;
        }
        .record-purpose-notice {
            color: #647081;
            font-size: 8pt;
            text-align: left;
            text-transform: uppercase;
        }
        .record-purpose-label,
        .record-purpose-total {
            color: #4f5b6c;
            font-weight: 700;
            text-align: right;
        }
        .record-purpose-prior-note {
            color: #647081;
            font-size: 7.25pt;
            line-height: 1.15;
            text-align: center;
            vertical-align: middle !important;
        }
        .salary-payable-cell {
            border-top: 0.6px solid #d8dee8;
            background: #edf8ef;
            color: #0f9f4d;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .signature-table {
            margin-top: 6mm;
        }
        .signature-table td {
            color: #647081;
            font-size: 9pt;
            padding: 2.4mm 1.2mm 2mm 1.2mm;
            vertical-align: bottom;
            width: 50%;
        }
        .signature-image {
            display: block;
            max-height: 14mm;
            max-width: 44mm;
            margin: 1mm 0 0.6mm 0;
        }
        .digital-sign-note {
            color: #647081;
            font-size: 7.25pt;
            font-style: italic;
            margin-top: 0.3mm;
        }
        .signature-line {
            display: inline-block;
            min-width: 35mm;
        }
    </style>
</head>
<body>
    @php
        $plainMoney = static fn (mixed $value): string => number_format((float) $value, 2);
        $money = static fn (mixed $value): string => 'RM '.number_format((float) $value, 2);
        $trimDecimal = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
        $dateLabel = static function (mixed $value): string {
            if (!$value) {
                return '-';
            }
            try {
                return \Illuminate\Support\Carbon::parse($value)->format('d-M');
            } catch (\Throwable) {
                return (string) $value;
            }
        };
        $dateTimeLabel = static function (mixed $value): string {
            if (!$value) {
                return '-';
            }
            try {
                return \Illuminate\Support\Carbon::parse($value)->format('d-M-Y h:i A');
            } catch (\Throwable) {
                return (string) $value;
            }
        };
        $year = substr((string) ($record['salaryMonthValue'] ?? ''), 0, 4);
        if ($year === '') {
            $year = ($generatedAt ?? now())->format('Y');
        }
        $previousYear = (string) ((int) $year - 1);
        $previousYearReference = $previousYearReference ?? [
            'year' => $previousYear,
            'available' => false,
            'message' => $previousYear.' snapshot not configured. Set in Salary Settings.',
        ];
        $previousYear = (string) ($previousYearReference['year'] ?? $previousYear);
        $hasPreviousYearReference = !empty($previousYearReference['available']);
        $previousYearMissingMessage = (string) (
            $previousYearReference['message'] ?? $previousYear.' snapshot not configured. Set in Salary Settings.'
        );
        $claimDate = $dateTimeLabel($claimDate ?? $record['submittedAt'] ?? $generatedAt ?? now());
        $applicantSignature = $applicantSignature ?? [];
        $approverSignature = $approverSignature ?? [];
        $applicantSignatureDate = $dateTimeLabel($applicantSignature['signedAt'] ?? $claimDate);
        $approverSignatureDate = !empty($approverSignature['signedAt'])
            ? $dateTimeLabel($approverSignature['signedAt'])
            : '';
        $approverSignatureName = trim(implode(' ', array_filter([
            $approverSignature['name'] ?? '',
            !empty($approverSignature['code'] ?? '') ? '('.$approverSignature['code'].')' : '',
        ])));
        $vehicleLabel = trim((string) ($vehicle ?? '')) ?: '-';
        $profileMileageRate = (float) ($mileageRate ?? 0);
        $medicalCurrentLeft = (float) ($medicalBalance['currentLeft'] ?? 0);
        $medicalAfterClaim = (float) ($medicalBalance['afterClaim'] ?? $medicalCurrentLeft);
        $staffLabel = trim(implode(' ', array_filter([
            $record['staffName'] ?? '',
            !empty($record['staffCode']) ? '('.$record['staffCode'].')' : '',
        ]))) ?: '-';
        $claimRows = collect($claims ?? []);
        $mileageClaims = $claimRows->where('type', 'Mileage')->values();
        $allowanceClaims = $claimRows->where('type', 'Allowance')->values();
        $expenseClaims = $claimRows->where('type', 'Expense')->values();
        $medicalClaims = $claimRows->where('type', 'Medical')->values();
        $mileageTotal = $mileageClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $allowanceTotal = $allowanceClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $expenseTotal = $expenseClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $medicalTotal = $medicalClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $claimTotal = (float) ($record['claimsTotal'] ?? ($mileageTotal + $allowanceTotal + $expenseTotal + $medicalTotal));
        $deductions = $record['deductions'] ?? [];
        $employeeEpf = (float) ($deductions['employeeEpf'] ?? $deductions['epfEmployee'] ?? 0);
        $employeeSocso = (float) ($deductions['employeeSocso'] ?? $deductions['socsoEmployee'] ?? 0);
        $employeeEis = (float) ($deductions['employeeEis'] ?? $deductions['eisEmployee'] ?? 0);
        $employeeSocsoSip = $employeeSocso + $employeeEis;
        if ($employeeSocsoSip <= 0 && $employeeEpf <= 0) {
            $employeeSocsoSip = (float) ($record['employeeDeductions'] ?? 0);
        } elseif ($employeeSocsoSip <= 0) {
            $employeeSocsoSip = max(0, (float) ($record['employeeDeductions'] ?? 0) - $employeeEpf);
        }
        $basicSalary = (float) ($record['basicSalary'] ?? 0);
        $currentRecordTotal = $basicSalary + $allowanceTotal;
        $projectLabel = static function (array $claim): string {
            $value = trim((string) ($claim['sourceLabel'] ?? ''));
            if ($value !== '') {
                return $value;
            }
            $value = trim((string) ($claim['meta'] ?? ''));
            return $value !== '' ? $value : '-';
        };
    @endphp

    @include('pdf.partials.company-header', [
        'documentType' => 'SALARY AND CLAIM FORM',
        'pdfLanguage' => 'en',
        'logoDataUri' => $logoDataUri ?? null,
    ])

    <main>
        <table class="meta-table">
            <tr>
                <td style="width: 40%;">
                    <span class="meta-label">Staff Name:</span>
                    <span class="meta-value">{{ $staffLabel }}</span>
                </td>
                <td style="width: 22%;">
                    <span class="meta-label">Vehicle:</span>
                    <span class="meta-value">{{ $vehicleLabel }}</span>
                </td>
                <td class="medical-balance-cell" style="width: 38%;">
                    <span class="meta-label">Medical Claim Left</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Claim Date:</span>
                    <span class="meta-value">{{ $claimDate }}</span>
                </td>
                <td>
                    <span class="meta-label">Year :</span>
                    <span class="meta-value">{{ $year }}</span>
                </td>
                <td class="medical-balance-cell">
                    <span class="meta-value">{{ $money($medicalCurrentLeft) }}</span>
                    <span class="muted medical-balance-note"> | after this claim {{ $money($medicalAfterClaim) }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Salary Month:</span>
                    <span class="meta-value">{{ $record['salaryMonth'] ?? '-' }}</span>
                </td>
                <td>
                    <span class="meta-label">Status:</span>
                    <span class="meta-value">{{ $record['status'] ?? '-' }}</span>
                </td>
                <td>
                    <span class="meta-label">Claims Total:</span>
                    <span class="meta-value">{{ $money($claimTotal) }}</span>
                </td>
            </tr>
        </table>

        <table class="section-table claim-master-table">
            <tbody>
                @if($mileageTotal > 0)
                    <tr class="claim-section-row"><td colspan="9">Vehicle Mileage</td></tr>
                    <tr class="claim-subheader-row">
                        <th style="width: 10%;">Date</th>
                        <th style="width: 15%;">From</th>
                        <th style="width: 15%;">To</th>
                        <th colspan="3" style="width: 35%;">Purpose</th>
                        <th style="width: 8%;">KM</th>
                        <th colspan="2" style="width: 17%;" class="text-right amount-header">Amount (RM)</th>
                    </tr>
                    @foreach($mileageClaims as $claim)
                        @php
                            $oneWayKm = (float) ($claim['km'] ?? 0);
                            $returnKm = $oneWayKm * 2;
                            $claimAmount = (float) ($claim['amount'] ?? 0);
                            $rowMileageRate = $profileMileageRate > 0
                                ? $profileMileageRate
                                : ($returnKm > 0 ? $claimAmount / $returnKm : 0);
                        @endphp
                        <tr>
                            <td>{{ $dateLabel($claim['date'] ?? '') }}</td>
                            <td>{{ $claim['startLocation'] ?? '-' }}</td>
                            <td>{{ $claim['endLocation'] ?? '-' }}</td>
                            <td colspan="3">{{ $claim['description'] ?? '-' }}</td>
                            <td>
                                {{ $trimDecimal($oneWayKm) }} KM one-way
                                <span class="mileage-km-note">{{ $trimDecimal($returnKm) }} KM return</span>
                                <span class="mileage-km-note">{{ $trimDecimal($returnKm) }} KM x RM {{ $trimDecimal($rowMileageRate) }}</span>
                            </td>
                            <td colspan="2" class="text-right">{{ $plainMoney($claim['amount'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="7" class="text-right">MILEAGE TOTAL (RM)</td>
                        <td colspan="2" class="text-right">{{ $plainMoney($mileageTotal) }}</td>
                    </tr>
                @else
                    <tr class="claim-section-row"><td colspan="9">Vehicle Mileage <span class="claim-section-empty-note">- None this month.</span></td></tr>
                @endif

                @if($allowanceTotal > 0)
                    <tr class="claim-section-row"><td colspan="9">Job Allowance</td></tr>
                    <tr class="claim-subheader-row">
                        <th>Date</th>
                        <th colspan="2">Project</th>
                        <th colspan="4">Description</th>
                        <th colspan="2" class="text-right amount-header">Amount (RM)</th>
                    </tr>
                    @foreach($allowanceClaims as $claim)
                        <tr>
                            <td>{{ $dateLabel($claim['date'] ?? '') }}</td>
                            <td colspan="2">{{ $projectLabel($claim) }}</td>
                            <td colspan="4">{{ $claim['description'] ?? '-' }}</td>
                            <td colspan="2" class="text-right">{{ $plainMoney($claim['amount'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="7" class="text-right">ALLOWANCE TOTAL (RM)</td>
                        <td colspan="2" class="text-right">{{ $plainMoney($allowanceTotal) }}</td>
                    </tr>
                @else
                    <tr class="claim-section-row"><td colspan="9">Job Allowance <span class="claim-section-empty-note">- None this month.</span></td></tr>
                @endif

                @if($expenseTotal > 0)
                    <tr class="claim-section-row"><td colspan="9">Expenses Claim</td></tr>
                    <tr class="claim-subheader-row">
                        <th>Date</th>
                        <th colspan="2">Project</th>
                        <th colspan="4">Description</th>
                        <th colspan="2" class="text-right amount-header">Amount (RM)</th>
                    </tr>
                    @foreach($expenseClaims as $claim)
                        <tr>
                            <td>{{ $dateLabel($claim['date'] ?? '') }}</td>
                            <td colspan="2">{{ $projectLabel($claim) }}</td>
                            <td colspan="4">{{ $claim['description'] ?? '-' }}</td>
                            <td colspan="2" class="text-right">{{ $plainMoney($claim['amount'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="7" class="text-right">EXPENSE TOTAL (RM)</td>
                        <td colspan="2" class="text-right">{{ $plainMoney($expenseTotal) }}</td>
                    </tr>
                @else
                    <tr class="claim-section-row"><td colspan="9">Expenses Claim <span class="claim-section-empty-note">- None this month.</span></td></tr>
                @endif

                @if($medicalTotal > 0)
                    <tr class="claim-section-row"><td colspan="9">Medical Claim</td></tr>
                    <tr class="claim-subheader-row">
                        <th>Date</th>
                        <th colspan="2">Project</th>
                        <th colspan="4">Description</th>
                        <th colspan="2" class="text-right amount-header">Amount (RM)</th>
                    </tr>
                    @foreach($medicalClaims as $claim)
                        <tr>
                            <td>{{ $dateLabel($claim['date'] ?? '') }}</td>
                            <td colspan="2">{{ $projectLabel($claim) }}</td>
                            <td colspan="4">{{ $claim['description'] ?? '-' }}</td>
                            <td colspan="2" class="text-right">{{ $plainMoney($claim['amount'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="7" class="text-right">MEDICAL TOTAL (RM)</td>
                        <td colspan="2" class="text-right">{{ $plainMoney($medicalTotal) }}</td>
                    </tr>
                @else
                    <tr class="claim-section-row"><td colspan="9">Medical Claim <span class="claim-section-empty-note">- None this month.</span></td></tr>
                @endif

                <tr class="claim-summary-row claim-summary-start">
                    <td colspan="5" class="pay-summary-heading">Salary Summary</td>
                    <td colspan="2" class="record-purpose-cell record-purpose-heading record-purpose-notice">Record purpose</td>
                    <td class="text-center record-purpose-heading">{{ $previousYear }}</td>
                    <td class="text-right record-purpose-heading">{{ $year }}</td>
                </tr>
                <tr class="claim-summary-row">
                    <td colspan="3" class="pay-summary-label">Claims total</td>
                    <td colspan="2" class="text-right pay-summary-value">{{ $plainMoney($claimTotal) }}</td>
                    <td colspan="2" class="record-purpose-cell record-purpose-label">Basic</td>
                    @if($hasPreviousYearReference)
                        <td class="text-right">{{ $plainMoney($previousYearReference['basicSalary'] ?? 0) }}</td>
                    @else
                        <td rowspan="4" class="record-purpose-prior-note">{{ $previousYearMissingMessage }}</td>
                    @endif
                    <td class="text-right">{{ $plainMoney($basicSalary) }}</td>
                </tr>
                <tr class="claim-summary-row">
                    <td colspan="3" class="pay-summary-label">Basic salary</td>
                    <td colspan="2" class="text-right pay-summary-value">{{ $plainMoney($basicSalary) }}</td>
                    <td colspan="2" class="record-purpose-cell record-purpose-label">Allowance</td>
                    @if($hasPreviousYearReference)
                        <td class="text-right">{{ $plainMoney($previousYearReference['allowanceTotal'] ?? 0) }}</td>
                    @endif
                    <td class="text-right">{{ $plainMoney($allowanceTotal) }}</td>
                </tr>
                <tr class="claim-summary-row">
                    <td colspan="3" class="pay-summary-label">EPF</td>
                    <td colspan="2" class="text-right pay-summary-value">-{{ $plainMoney($employeeEpf) }}</td>
                    <td colspan="2" class="record-purpose-cell record-purpose-label">Increment</td>
                    @if($hasPreviousYearReference)
                        <td class="text-right">{{ $plainMoney($previousYearReference['incrementAmount'] ?? 0) }}</td>
                    @endif
                    <td class="text-right">{{ $plainMoney(0) }}</td>
                </tr>
                <tr class="claim-summary-row">
                    <td colspan="3" class="pay-summary-label">SOCSO & SIP</td>
                    <td colspan="2" class="text-right pay-summary-value">-{{ $plainMoney($employeeSocsoSip) }}</td>
                    <td colspan="2" class="record-purpose-cell record-purpose-total">Total</td>
                    @if($hasPreviousYearReference)
                        <td class="text-right record-purpose-total">{{ $plainMoney($previousYearReference['total'] ?? 0) }}</td>
                    @endif
                    <td class="text-right record-purpose-total">{{ $plainMoney($currentRecordTotal) }}</td>
                </tr>
                <tr class="claim-summary-row">
                    <td colspan="3" class="salary-payable-cell">Salary Payable</td>
                    <td colspan="2" class="salary-payable-cell text-right">{{ $money($record['payableSalary'] ?? 0) }}</td>
                    <td colspan="4" class="record-purpose-cell"></td>
                </tr>
            </tbody>
        </table>

        <table class="signature-table">
            <tr>
                <td>
                    <div>Applicant Signature: <span class="signature-line"></span></div>
                    @if(!empty($applicantSignature['dataUri']))
                        <img src="{{ $applicantSignature['dataUri'] }}" alt="Applicant signature" class="signature-image">
                        <div class="digital-sign-note">Digitally signed via KIJO</div>
                    @endif
                    <div>Date: {{ $applicantSignatureDate }}</div>
                </td>
                <td>
                    <div>Approved By: {{ $approverSignatureName ?: '' }} <span class="signature-line"></span></div>
                    @if(!empty($approverSignature['dataUri']))
                        <img src="{{ $approverSignature['dataUri'] }}" alt="Approver signature" class="signature-image">
                        <div class="digital-sign-note">Digitally signed via KIJO</div>
                    @endif
                    <div>Date: {{ $approverSignatureDate }}</div>
                </td>
            </tr>
        </table>
    </main>
</body>
</html>
