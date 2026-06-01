@extends('emails.layouts.standard')

@section('title', 'Year-to-Date Dashboard Management Report')
@section('preheader')Report {{ $reportMonth }} is ready for management review.@endsection
@section('headerLabel', 'Dashboard Report')
@section('headerTitle', 'Year-to-Date Dashboard Management Report')
@section('headerSubtitle')Report month: {{ $reportMonth }}@endsection

@section('content')
    <p style="margin:0 0 18px;">The dashboard management report for <strong>{{ $reportMonth }}</strong> is ready.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f2ff; border:1px solid #dcdbf8; border-radius:10px; margin:0 0 22px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#5856d6; font-weight:700;">Report Details</div>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:10px;">
                    <tr>
                        <td style="width:130px; padding:4px 0; font-size:13px; color:#6f6c8f;">Report month</td>
                        <td style="padding:4px 0; font-size:14px; color:#111827; font-weight:600;">{{ $reportMonth }}</td>
                    </tr>
                    <tr>
                        <td style="width:130px; padding:4px 0; font-size:13px; color:#6f6c8f;">Reporting period</td>
                        <td style="padding:4px 0; font-size:14px; color:#111827; font-weight:600;">{{ $periodLabel }}</td>
                    </tr>
                    <tr>
                        <td style="width:130px; padding:4px 0; font-size:13px; color:#6f6c8f;">Format</td>
                        <td style="padding:4px 0; font-size:14px; color:#111827;">PDF report</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr>
            <td bgcolor="#5856d6" style="border-radius:8px;">
                <a href="{{ $downloadUrl }}" style="display:inline-block; padding:12px 18px; color:#ffffff; font-size:14px; line-height:1.2; font-weight:700; text-decoration:none;">Open dashboard report</a>
            </td>
        </tr>
    </table>

    <p style="margin:0; color:#6b7280; font-size:13px; line-height:1.65;">
        This link is intended for management review and may expire based on system policy.
    </p>
@endsection
