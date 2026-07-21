<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Other Claim {{ $record['claimMonth'] ?? '' }}</title>
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
        .meta-nowrap {
            white-space: nowrap;
        }
        .mileage-km-note {
            color: #647081;
            display: block;
            font-size: 7.25pt;
            line-height: 1.2;
            margin-top: 0.35mm;
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
        .claim-master-table { margin-top: 4mm; }
        .claim-section-row td {
            background: #f8fafc;
            color: #647081;
            font-size: 8.75pt;
            font-weight: 700;
            letter-spacing: 0.15px;
            padding: 1.55mm 1.8mm;
            text-transform: uppercase;
        }
        .claim-subheader-row th { background: #f1f4f8; }
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
        .claim-summary-row td {
            border-bottom: 0.45px solid #e7ebf1;
            background: #ffffff;
            padding: 1.35mm 1.8mm;
        }
        .claim-summary-start td { border-top: 0.6px solid #d8dee8; }
        .pay-summary-heading {
            background: #f1f4f8 !important;
            color: #1f5f9f;
            font-weight: 700;
            letter-spacing: 0.15px;
            text-transform: uppercase;
        }
        .pay-summary-label {
            color: #4f5b6c;
            font-weight: 700;
        }
        .pay-summary-value {
            color: #111827;
            font-weight: 700;
        }
        .total-claim-cell {
            border-top: 0.6px solid #d8dee8;
            background: #edf8ef;
            color: #0f9f4d;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .signature-table { margin-top: 6mm; }
        .signature-table td {
            color: #647081;
            font-size: 9pt;
            padding: 2.4mm 1.2mm 2mm 1.2mm;
            vertical-align: bottom;
            width: 33.333%;
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
        $year = substr((string) ($record['claimMonthValue'] ?? ''), 0, 4);
        if ($year === '') {
            $year = ($generatedAt ?? now())->format('Y');
        }
        $claimDate = $dateTimeLabel($claimDate ?? $record['submittedAt'] ?? $generatedAt ?? now());
        $profileMileageRate = (float) ($mileageRate ?? 0);
        $applicantSignature = $applicantSignature ?? [];
        $checkerSignature = $checkerSignature ?? [];
        $approverSignature = $approverSignature ?? [];
        $applicantSignatureDate = $dateTimeLabel($applicantSignature['signedAt'] ?? $claimDate);
        $checkerSignatureDate = !empty($checkerSignature['signedAt'])
            ? $dateTimeLabel($checkerSignature['signedAt'])
            : '';
        $approverSignatureDate = !empty($approverSignature['signedAt'])
            ? $dateTimeLabel($approverSignature['signedAt'])
            : '';
        $approverSignatureName = trim(implode(' ', array_filter([
            $approverSignature['name'] ?? '',
            !empty($approverSignature['code'] ?? '') ? '('.$approverSignature['code'].')' : '',
        ])));
        $checkerSignatureName = trim(implode(' ', array_filter([
            $checkerSignature['name'] ?? '',
            !empty($checkerSignature['code'] ?? '') ? '('.$checkerSignature['code'].')' : '',
        ])));
        $staffLabel = trim(implode(' ', array_filter([
            $record['staffName'] ?? '',
            !empty($record['staffCode']) ? '('.$record['staffCode'].')' : '',
        ]))) ?: '-';
        $staffPosition = trim((string) ($record['staffPosition'] ?? ''));
        $staffDepartment = trim((string) ($record['staffDepartment'] ?? ''));
        $claimRows = collect($claims ?? []);
        $mileageClaims = $claimRows->where('type', 'Mileage')->values();
        $allowanceClaims = $claimRows->where('type', 'Allowance')->values();
        $expenseClaims = $claimRows->where('type', 'Expense')->values();
        $mileageTravelGroupIds = $mileageClaims
            ->pluck('travelGroupId')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
        $travelExpenseClaims = $expenseClaims
            ->filter(fn ($claim) => $mileageTravelGroupIds->contains(trim((string) ($claim['travelGroupId'] ?? ''))))
            ->groupBy(fn ($claim) => (string) $claim['travelGroupId']);
        $standaloneExpenseClaims = $expenseClaims
            ->reject(fn ($claim) => $mileageTravelGroupIds->contains(trim((string) ($claim['travelGroupId'] ?? ''))))
            ->values();
        $medicalClaims = $claimRows->where('type', 'Medical')->values();
        $mileageTotal = $mileageClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $allowanceTotal = $allowanceClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $expenseTotal = $expenseClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $travelExpenseTotal = $travelExpenseClaims->flatten(1)->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $standaloneExpenseTotal = $standaloneExpenseClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $medicalTotal = $medicalClaims->sum(fn ($claim) => (float) ($claim['amount'] ?? 0));
        $claimTotal = (float) ($record['claimsTotal'] ?? ($mileageTotal + $allowanceTotal + $expenseTotal + $medicalTotal));
        $projectLabel = static function (array $claim): string {
            $value = trim((string) ($claim['sourceLabel'] ?? ''));
            if ($value !== '') {
                return $value;
            }
            $value = trim((string) ($claim['meta'] ?? ''));
            return $value !== '' ? $value : '-';
        };
        $chargeAllocations = $claimRows
            ->filter(fn ($claim) => ($claim['source'] ?? '') === 'manual-allocation' && trim((string) ($claim['sourceLabel'] ?? '')) !== '')
            ->groupBy(fn ($claim) => trim((string) $claim['sourceLabel']))
            ->map(fn ($rows) => $rows->sum(fn ($claim) => (float) ($claim['amount'] ?? 0)));
    @endphp

    @include('pdf.partials.company-header', [
        'documentType' => 'OTHER CLAIM FORM',
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
                <td class="meta-nowrap" style="width: 60%;">
                    <span class="meta-label">Claim Date:</span>
                    <span class="meta-value">{{ $claimDate }}</span>
                </td>
            </tr>
             <tr>
                 <td>
                     <span class="meta-label">Status:</span>
                     <span class="meta-value">{{ $record['status'] ?? '-' }}</span>
                 </td>
                 <td>
                     <span class="meta-label">Claim Month:</span>
                     <span class="meta-value">{{ $record['claimMonth'] ?? '-' }}</span>
                     @if(!empty($vehicle ?? ''))
                         <span class="meta-label" style="padding-left: 4mm;">Vehicle:</span>
                         <span class="meta-value">{{ $vehicle }}</span>
                     @endif
                  </td>
             </tr>
             <tr>
                 <td>
                     <span class="meta-label">Designation:</span>
                     <span class="meta-value">{{ $staffPosition ?: '-' }}</span>
                 </td>
                 <td>
                     <span class="meta-label">Department / Division:</span>
                     <span class="meta-value">{{ $staffDepartment ?: '-' }}</span>
                 </td>
             </tr>
         </table>

        <table class="section-table claim-master-table">
            <tbody>
                 @if(($mileageTotal + $travelExpenseTotal) > 0)
                     <tr class="claim-section-row"><td colspan="9">Travel &amp; Mileage</td></tr>
                    <tr class="claim-subheader-row">
                        <th style="width: 10%;">Date</th>
                        <th style="width: 15%;">From</th>
                        <th style="width: 15%;">To</th>
                        <th colspan="2" style="width: 25%;">Purpose / Charge To</th>
                        <th style="width: 10%;">KM</th>
                        <th style="width: 10%;" class="text-right amount-header">Travel Expense</th>
                        <th style="width: 10%;" class="text-right amount-header">Mileage</th>
                        <th style="width: 10%;" class="text-right amount-header">Total (RM)</th>
                    </tr>
                    @foreach($mileageClaims as $claim)
                        @php
                            $oneWayKm = (float) ($claim['km'] ?? 0);
                            $tripMode = ($claim['tripMode'] ?? null) === 'one_way' ? 'one_way' : 'return';
                            $claimableKm = $oneWayKm * ($tripMode === 'one_way' ? 1 : 2);
                            $claimAmount = (float) ($claim['amount'] ?? 0);
                            $linkedExpenses = $travelExpenseClaims->get((string) ($claim['travelGroupId'] ?? ''), collect());
                            $linkedExpenseAmount = $linkedExpenses->sum(fn ($expense) => (float) ($expense['amount'] ?? 0));
                             $linkedExpenseLabel = $linkedExpenses->map(function ($expense) {
                                 return match ($expense['expenseCategory'] ?? '') {
                                     'combined' => 'Parking / taxi / toll / others',
                                     'parking' => 'Parking',
                                    'toll' => 'Toll',
                                    'taxi' => 'Taxi',
                                    default => 'Other',
                                };
                            })->unique()->implode(', ');
                            $rowMileageRate = $profileMileageRate > 0
                                ? $profileMileageRate
                                : ($claimableKm > 0 ? $claimAmount / $claimableKm : 0);
                        @endphp
                        <tr>
                            <td>{{ $dateLabel($claim['date'] ?? '') }}</td>
                            <td>{{ $claim['startLocation'] ?? '-' }}</td>
                            <td>{{ $claim['endLocation'] ?? '-' }}</td>
                            <td colspan="2">
                                {{ $claim['description'] ?? '-' }}
                                @if(!empty($claim['sourceLabel']))
                                    <span class="mileage-km-note">Charge to: {{ $claim['sourceLabel'] }}</span>
                                @endif
                             </td>
                             <td>
                                 @if($claimableKm > 0)
                                     {{ $trimDecimal($claimableKm) }} KM {{ $tripMode === 'one_way' ? 'one-way' : 'return' }}
                                     <span class="mileage-km-note">{{ $trimDecimal($claimableKm) }} KM x RM {{ $trimDecimal($rowMileageRate) }}</span>
                                 @else
                                     -
                                 @endif
                             </td>
                            <td class="text-right">
                                {{ $plainMoney($linkedExpenseAmount) }}
                                @if($linkedExpenseLabel !== '')<span class="mileage-km-note">{{ $linkedExpenseLabel }}</span>@endif
                            </td>
                            <td class="text-right">{{ $plainMoney($claimAmount) }}</td>
                            <td class="text-right">{{ $plainMoney($claimAmount + $linkedExpenseAmount) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="6" class="text-right">TRAVEL &amp; MILEAGE TOTAL (RM)</td>
                        <td class="text-right">{{ $plainMoney($travelExpenseTotal) }}</td>
                        <td class="text-right">{{ $plainMoney($mileageTotal) }}</td>
                        <td class="text-right">{{ $plainMoney($mileageTotal + $travelExpenseTotal) }}</td>
                    </tr>
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
                @endif

                @if($standaloneExpenseTotal > 0)
                    <tr class="claim-section-row"><td colspan="9">Expenses Claim</td></tr>
                    <tr class="claim-subheader-row">
                        <th>Date</th>
                        <th colspan="2">Project</th>
                        <th colspan="4">Description</th>
                        <th colspan="2" class="text-right amount-header">Amount (RM)</th>
                    </tr>
                    @foreach($standaloneExpenseClaims as $claim)
                        <tr>
                            <td>{{ $dateLabel($claim['date'] ?? '') }}</td>
                            <td colspan="2">{{ $projectLabel($claim) }}</td>
                            <td colspan="4">{{ $claim['description'] ?? '-' }}</td>
                            <td colspan="2" class="text-right">{{ $plainMoney($claim['amount'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td colspan="7" class="text-right">EXPENSE TOTAL (RM)</td>
                        <td colspan="2" class="text-right">{{ $plainMoney($standaloneExpenseTotal) }}</td>
                    </tr>
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
                @endif

                <tr class="claim-summary-row claim-summary-start">
                    <td colspan="7" class="pay-summary-heading">Claim Summary</td>
                    <td colspan="2" class="text-right pay-summary-heading">Amount (RM)</td>
                </tr>
                @foreach($chargeAllocations as $allocationLabel => $allocationAmount)
                    <tr class="claim-summary-row">
                        <td colspan="7" class="pay-summary-label">Charge to: {{ $allocationLabel }}</td>
                        <td colspan="2" class="text-right pay-summary-value">{{ $plainMoney($allocationAmount) }}</td>
                    </tr>
                @endforeach
                <tr class="claim-summary-row">
                    <td colspan="7" class="pay-summary-label">Claims total</td>
                    <td colspan="2" class="text-right pay-summary-value">{{ $plainMoney($claimTotal) }}</td>
                </tr>
                <tr class="claim-summary-row">
                    <td colspan="7" class="total-claim-cell">Total Claim</td>
                    <td colspan="2" class="total-claim-cell text-right">{{ $money($claimTotal) }}</td>
                </tr>
            </tbody>
        </table>

        <table class="signature-table">
            <tr>
                <td>
                    <div>Applicant: {{ $staffLabel }} <span class="signature-line"></span></div>
                    @if(!empty($applicantSignature['dataUri']))
                        <img src="{{ $applicantSignature['dataUri'] }}" alt="Applicant signature" class="signature-image">
                        <div class="digital-sign-note">Digitally signed via KIJO</div>
                    @endif
                    <div>Date: {{ $applicantSignatureDate }}</div>
                </td>
                <td>
                    <div>Checked By: {{ $checkerSignatureName ?: '' }} <span class="signature-line"></span></div>
                    @if(!empty($checkerSignature['dataUri']))
                        <img src="{{ $checkerSignature['dataUri'] }}" alt="Checker signature" class="signature-image">
                        <div class="digital-sign-note">Digitally signed via KIJO</div>
                    @endif
                    <div>Date: {{ $checkerSignatureDate }}</div>
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
