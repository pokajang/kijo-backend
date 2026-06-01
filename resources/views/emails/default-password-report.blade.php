@extends('emails.layouts.standard')

@section('title', 'Action Required: Users Still Using Default Password')
@section('emailWidthAttribute', '760')
@section('emailWidth', '760px')
@section('preheader'){{ $count }} active user(s) are still using the default initial password.@endsection
@section('headerLabel', 'Security Report')
@section('headerTitle', 'Users Still Using Default Password')
@section('headerSubtitle')Sent {{ $noticeSent }} notification(s), failed {{ $noticeFailed }}.@endsection

@section('content')
    <p style="margin:0 0 14px;">Hello,</p>
    <p style="margin:0 0 14px;">The system detected <strong>{{ $count }}</strong> active user(s) still using the default initial password.</p>
    <p style="margin:0 0 14px;">User notification result: <strong>Sent {{ $noticeSent }}</strong>, <strong>Failed {{ $noticeFailed }}</strong>.</p>
    <p style="margin:0 0 18px;">Please follow up with users that failed to receive notification and ensure they update their password in <strong>Profile &gt; Change Password</strong>.</p>

    <table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse; width:100%; border:1px solid #dcdbf8; font-size:13px;">
        <thead>
            <tr>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Full Name</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Email</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Staff ID</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">User Notice</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['full_name'] }}</td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['email'] }}</td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['staff_id'] }}</td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['notice_status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
