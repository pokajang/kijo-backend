<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $record['salaryMonth'] ?? '' }}</title>
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
        .pair-table,
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
        .section-title {
            color: #1f5f9f;
            font-size: 9.75pt;
            font-weight: 700;
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
        .section-table th,
        .section-table td {
            padding: 1.45mm 1.8mm;
            vertical-align: top;
        }
        .section-table th {
            border-bottom: 0.6px solid #d8dee8;
            background: #f1f4f8;
            color: #4f5b6c;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
        }
        .section-table td {
            border-bottom: 0.45px solid #e7ebf1;
        }
        .amount-header {
            text-align: right !important;
            white-space: nowrap;
        }
        .text-right { text-align: right; }
        .muted { color: #647081; }
        .total-row td {
            border-top: 0.6px solid #d8dee8;
            border-bottom: 0.8px solid #cfd6e1;
            background: #f8fafc;
            color: #273142;
            font-weight: 700;
        }
        .net-row td {
            background: #edf8ef;
            color: #0f9f4d;
            font-size: 9.5pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .pair-table td {
            padding: 0;
            vertical-align: top;
        }
        .pair-gap {
            width: 4mm;
        }
        .signature-table {
            border-collapse: separate;
            border-spacing: 0;
            border: 0.6px solid #d8dee8;
            border-radius: 6px;
            margin-top: 6mm;
            overflow: hidden;
        }
        .signature-table td {
            color: #4f5b6c;
            padding: 2.2mm;
            vertical-align: top;
        }
        .signature-heading {
            background: #f1f4f8;
            color: #1f5f9f;
            font-weight: 700;
            letter-spacing: 0.15px;
            text-transform: uppercase;
        }
        .signature-image {
            display: block;
            max-height: 15mm;
            max-width: 42mm;
            margin: 1mm 0;
        }
        .stamp-image {
            display: block;
            max-height: 22mm;
            max-width: 48mm;
            margin: 1mm 0;
        }
        .placeholder {
            color: #647081;
            font-size: 7.5pt;
            font-style: italic;
            padding-top: 2mm;
        }
        .digital-sign-note {
            color: #647081;
            font-size: 7.25pt;
            font-style: italic;
            margin-top: 0.3mm;
        }
    </style>
</head>
<body>
    @php
        $plainMoney = static fn (mixed $value): string => number_format((float) $value, 2);
        $money = static fn (mixed $value): string => 'RM '.number_format((float) $value, 2);
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
        $staffLabel = trim(implode(' ', array_filter([
            $record['staffName'] ?? '',
            !empty($record['staffCode']) ? '('.$record['staffCode'].')' : '',
        ]))) ?: '-';
        $claimRows = collect($claims ?? []);
        $mileageTotal = $claimRows->where('type', 'Mileage')->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $allowanceTotal = $claimRows->where('type', 'Allowance')->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $expenseTotal = $claimRows->where('type', 'Expense')->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $medicalTotal = $claimRows->where('type', 'Medical')->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $claimTotal = (float) ($record['claimsTotal'] ?? ($mileageTotal + $allowanceTotal + $expenseTotal + $medicalTotal));
        $basicSalary = (float) ($record['basicSalary'] ?? 0);
        $grossPay = $basicSalary + $claimTotal;
        $deductions = $record['deductions'] ?? [];
        $employeeEpf = (float) ($deductions['employeeEpf'] ?? $deductions['epfEmployee'] ?? 0);
        $employeeSocso = (float) ($deductions['employeeSocso'] ?? $deductions['socsoEmployee'] ?? 0);
        $employeeEis = (float) ($deductions['employeeEis'] ?? $deductions['eisEmployee'] ?? 0);
        $employeeTotal = (float) ($deductions['employeeTotal'] ?? $record['employeeDeductions'] ?? ($employeeEpf + $employeeSocso + $employeeEis));
        $employerEpf = (float) ($deductions['employerEpf'] ?? $deductions['epfEmployer'] ?? 0);
        $employerSocso = (float) ($deductions['employerSocso'] ?? $deductions['socsoEmployer'] ?? 0);
        $employerEis = (float) ($deductions['employerEis'] ?? $deductions['eisEmployer'] ?? 0);
        $employerTotal = (float) ($deductions['employerTotal'] ?? $record['employerContributions'] ?? ($employerEpf + $employerSocso + $employerEis));
        $netPayable = (float) ($record['payableSalary'] ?? ($grossPay - $employeeTotal));
        $totalEmployerCost = $grossPay + $employerTotal;
        $managementDate = $dateTimeLabel($record['approvedAt'] ?? $generatedAt ?? now());
    @endphp

    @include('pdf.partials.company-header', [
        'documentType' => 'PAYSLIP',
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
                <td style="width: 30%;">
                    <span class="meta-label">Salary Period:</span>
                    <span class="meta-value">{{ $record['salaryMonth'] ?? '-' }}</span>
                </td>
                <td style="width: 30%;">
                    <span class="meta-label">Status:</span>
                    <span class="meta-value">{{ $record['status'] ?? '-' }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Approval Date:</span>
                    <span class="meta-value">{{ $dateTimeLabel($record['approvedAt'] ?? '') }}</span>
                </td>
                <td>
                    <span class="meta-label">Generated Date:</span>
                    <span class="meta-value">{{ $dateTimeLabel($generatedAt ?? now()) }}</span>
                </td>
                <td>
                    <span class="meta-label">Net Payable:</span>
                    <span class="meta-value">{{ $money($netPayable) }}</span>
                </td>
            </tr>
        </table>

        <div class="section-title">Earnings & Claims</div>
        <table class="section-table">
            <tr>
                <th>Item</th>
                <th class="amount-header" style="width: 28%;">Amount (RM)</th>
            </tr>
            <tr><td>Basic Salary</td><td class="text-right">{{ $plainMoney($basicSalary) }}</td></tr>
            <tr><td>Mileage Claim</td><td class="text-right">{{ $plainMoney($mileageTotal) }}</td></tr>
            <tr><td>Allowance Claim</td><td class="text-right">{{ $plainMoney($allowanceTotal) }}</td></tr>
            <tr><td>Expense Claim</td><td class="text-right">{{ $plainMoney($expenseTotal) }}</td></tr>
            <tr><td>Medical Claim</td><td class="text-right">{{ $plainMoney($medicalTotal) }}</td></tr>
            <tr class="total-row"><td>Gross Pay</td><td class="text-right">{{ $plainMoney($grossPay) }}</td></tr>
        </table>

        <table class="pair-table">
            <tr>
                <td>
                    <div class="section-title">Employee Deductions</div>
                    <table class="section-table">
                        <tr>
                            <th>Item</th>
                            <th class="amount-header" style="width: 35%;">Amount (RM)</th>
                        </tr>
                        <tr><td>EPF Employee</td><td class="text-right">{{ $plainMoney($employeeEpf) }}</td></tr>
                        <tr><td>SOCSO Employee</td><td class="text-right">{{ $plainMoney($employeeSocso) }}</td></tr>
                        <tr><td>EIS/SIP Employee</td><td class="text-right">{{ $plainMoney($employeeEis) }}</td></tr>
                        <tr class="total-row"><td>Total Employee Deductions</td><td class="text-right">{{ $plainMoney($employeeTotal) }}</td></tr>
                    </table>
                </td>
                <td class="pair-gap"></td>
                <td>
                    <div class="section-title">Employer Contributions</div>
                    <table class="section-table">
                        <tr>
                            <th>Item</th>
                            <th class="amount-header" style="width: 35%;">Amount (RM)</th>
                        </tr>
                        <tr><td>EPF Employer</td><td class="text-right">{{ $plainMoney($employerEpf) }}</td></tr>
                        <tr><td>SOCSO Employer</td><td class="text-right">{{ $plainMoney($employerSocso) }}</td></tr>
                        <tr><td>EIS/SIP Employer</td><td class="text-right">{{ $plainMoney($employerEis) }}</td></tr>
                        <tr class="total-row"><td>Total Employer Contributions</td><td class="text-right">{{ $plainMoney($employerTotal) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="section-title">Pay Summary</div>
        <table class="section-table">
            <tr><td>Gross Pay</td><td class="text-right">{{ $plainMoney($grossPay) }}</td></tr>
            <tr><td>Less Employee Deductions</td><td class="text-right">-{{ $plainMoney($employeeTotal) }}</td></tr>
            <tr class="net-row"><td>Net Payable</td><td class="text-right">{{ $money($netPayable) }}</td></tr>
            <tr><td>Employer Contributions</td><td class="text-right">{{ $plainMoney($employerTotal) }}</td></tr>
            <tr class="total-row"><td>Total Employer Cost</td><td class="text-right">{{ $plainMoney($totalEmployerCost) }}</td></tr>
        </table>

        <table class="signature-table">
            <tr>
                <td colspan="2" class="signature-heading">Management Certification</td>
            </tr>
            <tr>
                <td style="width: 50%;">
                    <div><strong>Signature</strong></div>
                    @if(!empty($managementSignatureDataUri))
                        <img src="{{ $managementSignatureDataUri }}" alt="Management signature" class="signature-image">
                    @else
                        <div class="placeholder">Management signature not configured.</div>
                    @endif
                    <div><strong>Name:</strong> MUHAMMAD AMIN ROZAK</div>
                    <div><strong>Designation:</strong> MANAGING DIRECTOR</div>
                    <div><strong>Date:</strong> {{ $managementDate }}</div>
                    <div class="digital-sign-note">Digitally signed by management via KIJO</div>
                </td>
                <td style="width: 50%;">
                    <div><strong>Company Stamp</strong></div>
                    @if(!empty($companyStampDataUri))
                        <img src="{{ $companyStampDataUri }}" alt="Company stamp" class="stamp-image">
                    @else
                        <div class="placeholder">Company stamp not configured.</div>
                    @endif
                </td>
            </tr>
        </table>
    </main>
</body>
</html>
