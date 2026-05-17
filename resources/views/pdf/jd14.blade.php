<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>JD14 Declaration Form</title>
    <style>
        @page { margin: 10mm 15mm 32mm 15mm; }
        body {
            margin: 0;
            color: #000;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.28;
        }
        table { border-collapse: collapse; }
        .mycoid-label {
            width: 88mm;
            border: 0.5px solid #000;
            padding: 1.1mm 1mm;
            text-align: center;
            font-size: 10pt;
            font-weight: 700;
        }
        .mycoid-table {
            width: 88mm;
            border-collapse: collapse;
            margin-bottom: 3mm;
        }
        .mycoid-table td {
            width: 11mm;
            height: 5mm;
            border: 0.5px solid #000;
            text-align: center;
            vertical-align: middle;
            font-size: 10pt;
            font-weight: 700;
            padding: 0;
        }
        .form-ref {
            width: 60mm;
            border: 0.5px solid #000;
            padding: 1mm;
            text-align: center;
            font-size: 11pt;
            font-weight: 700;
            margin-bottom: 4mm;
        }
        .main-title {
            text-align: center;
            font-size: 11pt;
            font-weight: 700;
            line-height: 1.35;
            margin: 0 0 1mm 0;
        }
        .intro {
            text-align: center;
            font-size: 8pt;
            line-height: 1.35;
            margin: 0 0 4mm 0;
        }
        .section-title {
            font-size: 11pt;
            font-weight: 700;
            text-align: center;
            margin: 0 0 2mm 0;
        }
        .form-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-bottom: 4mm;
        }
        .form-table td {
            border: 0.5px solid #000;
            padding: 4px;
            vertical-align: top;
        }
        .claim-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-bottom: 4mm;
        }
        .claim-table td {
            border: 0.5px solid #000;
            padding: 4px;
            text-align: center;
            vertical-align: middle;
        }
        .part3 {
            width: 100%;
            border-collapse: collapse;
            border: 0.5px solid #000;
            font-size: 10pt;
        }
        .part3 > tbody > tr > td {
            border: none;
            padding: 6px 4px;
            vertical-align: top;
        }
        .inner {
            width: 100%;
            border-collapse: collapse;
        }
        .inner td {
            border: none;
            padding: 2px 3px;
            vertical-align: top;
        }
        .label { width: 35%; font-weight: 700; }
        .colon { width: 3%; text-align: center; }
        .value { width: 62%; }
        .signature-cell { height: 45px; }
        .stamp-cell { height: 55px; }
        .employer-stamp-hint {
            height: 40px;
            color: rgb(182, 182, 182);
            font-size: 8pt;
            line-height: 1.15;
        }
        .sign-img { max-width: 35mm; height: auto; }
        .stamp-img { max-width: 50mm; height: auto; }
        .reminder {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -22mm;
            font-size: 8pt;
            line-height: 1.25;
            text-align: justify;
        }
    </style>
</head>
<body>
    <div class="mycoid-label">TRAINING PROVIDER MYCOID(ROC/ROB/ROS)</div>
    <table class="mycoid-table">
        <tr>
            @foreach(str_split('1062417W') as $char)
                <td>{{ $char }}</td>
            @endforeach
        </tr>
    </table>

    <div class="form-ref">PSMB/SBL-KHAS /JD/14</div>

    <div class="main-title">
        EMPLOYER AND TRAINING PROVIDER JOINT DECLARATION FOR SBL-KHAS SCHEME CLAIMS
        (FEES) UNDER THE PEMBANGUNAN SUMBER MANUSIA BERHAD ACT 2001
    </div>
    <div class="intro">
        This declaration is to certify that employer involved in the training program had agreed with the
        training program conducted, fees charged and allow training provider to claim with PSMB. This
        declaration should only be signed by employers after the training completed. This form must be attached
        when submitting online SBL-KHAS claim. This form must be kept at training providers premises and
        available for future verification by PSMB.
    </div>

    <div class="section-title">PART 1 - EMPLOYER'S PARTICULAR</div>

    <table class="form-table">
        <tr>
            <td rowspan="4" width="50%">
                Registered Name and Address of Employer:<br>
                {{ $row->employer_name ?? '' }}<br>
                {{ $row->employer_address ?? '' }}
            </td>
            <td width="20%">Employer Code</td>
            <td width="30%">{{ $row->employer_code ?? '' }}</td>
        </tr>
        <tr>
            <td>Approval No</td>
            <td>{{ $row->approval_no ?? '' }}</td>
        </tr>
        <tr>
            <td>Group Approved</td>
            <td>{{ $row->group_approved ?? '' }}</td>
        </tr>
        <tr>
            <td>Group Claimed</td>
            <td>{{ $row->group_claimed ?? '' }}</td>
        </tr>
        <tr>
            <td width="20%">Course Title</td>
            <td width="80%" colspan="2">{{ $row->course_title ?? '' }}</td>
        </tr>
        <tr>
            <td>Training Dates</td>
            <td colspan="2">
                Commenced: {{ $row->commenced_date ?? '' }}
                &nbsp;&nbsp;&nbsp;&nbsp;
                Ended: {{ $row->end_date ?? '' }}
            </td>
        </tr>
        <tr>
            <td>Training Venue</td>
            <td colspan="2">{{ $row->training_venue ?? '' }}</td>
        </tr>
    </table>

    <div class="section-title">PART 2 - CLAIM FOR COURSE FEE</div>

    <table class="claim-table">
        <tr>
            <td width="33%"><strong>Number of Trainee(s)*</strong></td>
            <td width="33%"><strong>Total Fee Approved (RM)</strong></td>
            <td width="34%"><strong>Total Fee Claimed (RM)</strong></td>
        </tr>
        <tr>
            <td height="20"><strong>{{ $row->no_of_pax ?? '' }}</strong></td>
            <td><strong>{{ $row->total_fee_approved ?? '' }}</strong></td>
            <td><strong>{{ $row->total_fee_claimed ?? '' }}</strong></td>
        </tr>
    </table>

    <div class="section-title">PART 3 - JOINT DECLARATION OF THE TRAINING PROVIDER AND THE EMPLOYER</div>

    <table class="part3">
        <tr>
            <td colspan="2">
                (a) I certify that all information declared above is true and correct and the training program
                claimed above has been conducted with all terms and condition under this scheme has been complied.
                I also declared that apart from this claim, there is no other claim has been made for these expenses.
                All relevant documents pertaining to this claim are with us and can be inspected by the Secretariat
                of the Pembangunan Sumber Manusia Berhad. <strong>(Training Provider)</strong>
            </td>
        </tr>
        <tr>
            <td width="40%">
                <table class="inner">
                    <tr>
                        <td class="label">SIGNATURE</td>
                        <td class="colon">:</td>
                        <td class="value signature-cell">
                            @if($signDataUri)
                                <img src="{{ $signDataUri }}" class="sign-img" alt="Signature">
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="label">NAME</td>
                        <td class="colon">:</td>
                        <td class="value">MUHAMMAD AMIN ROZAK</td>
                    </tr>
                    <tr>
                        <td class="label">MYKAD NO</td>
                        <td class="colon">:</td>
                        <td class="value">760628-03-5981</td>
                    </tr>
                </table>
            </td>
            <td width="60%">
                <table class="inner">
                    <tr>
                        <td class="label">DESIGNATION</td>
                        <td class="colon">:</td>
                        <td class="value">MANAGING DIRECTOR</td>
                    </tr>
                    <tr>
                        <td class="label">COMPANY STAMP</td>
                        <td class="colon">:</td>
                        <td class="value stamp-cell">
                            @if($stampDataUri)
                                <img src="{{ $stampDataUri }}" class="stamp-img" alt="Stamp">
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="label">DATE</td>
                        <td class="colon">:</td>
                        <td class="value">{{ $todayDate }}</td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                (b) I certify that the training had been completed and agreed with the fees charged above.
                I am responsible to the claimed above and certify all information provided here is true and correct.
                <strong>(Employer)</strong>
            </td>
        </tr>
        <tr>
            <td width="40%">
                <table class="inner">
                    <tr>
                        <td class="label">SIGNATURE</td>
                        <td class="colon">:</td>
                        <td class="value signature-cell"></td>
                    </tr>
                    <tr>
                        <td class="label">NAME</td>
                        <td class="colon">:</td>
                        <td class="value"></td>
                    </tr>
                    <tr>
                        <td class="label">MYKAD NO</td>
                        <td class="colon">:</td>
                        <td class="value"></td>
                    </tr>
                </table>
            </td>
            <td width="60%">
                <table class="inner">
                    <tr>
                        <td class="label">DESIGNATION</td>
                        <td class="colon">:</td>
                        <td class="value"></td>
                    </tr>
                    <tr>
                        <td class="label">COMPANY STAMP</td>
                        <td class="colon">:</td>
                        <td class="value employer-stamp-hint">
                            (Shall only be certified by either<br>
                            Managing Director/General Manager/<br>
                            Financial Controller/Finance<br>
                            Director of Employer)
                        </td>
                    </tr>
                    <tr>
                        <td class="label">DATE</td>
                        <td class="colon">:</td>
                        <td class="value"></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="reminder">
        <strong>REMINDER</strong>: You are reminded that, if you should give false or misleading statements,
        or make in writing, or sign any declaration which is untrue or incorrect in any particular, you will be
        prosecuted under Section 40 and/or Section 41 of the Pembangunan Sumber Manusia Berhad Act 2001 and shall
        be liable to a fine not exceeding twenty thousand ringgit or to imprisonment for a term not exceeding two
        years or to both. Besides, Pembangunan Sumber Manusia Berhad may, at its discretion, withdraw the grant
        and recover immediately any amount of the grant that may have been disbursed.
    </div>
</body>
</html>
