@extends('emails.layouts.standard')

@section('title', 'Security Action Required')
@section('preheader')Please update your KIJO password from Profile > Change Password.@endsection
@section('headerLabel', 'Security')
@section('headerTitle', 'Security Action Required')
@section('headerSubtitle', 'Default password check')

@section('content')
    <p style="margin:0 0 14px;">Dear {{ $recipientName }},</p>
    <p style="margin:0 0 14px;">Our security check found that your account is still using the default initial password.</p>
    <p style="margin:0 0 14px;">Please update your password immediately in <strong>Profile &gt; Change Password</strong>.</p>
    <p style="margin:0 0 22px;">Use a strong password with a mix of uppercase, lowercase, numbers, and symbols.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td bgcolor="#5856d6" style="border-radius:8px;">
                <a href="{{ $loginUrl }}" style="display:inline-block; padding:12px 18px; color:#ffffff; font-size:14px; line-height:1.2; font-weight:700; text-decoration:none;">Open KIJO</a>
            </td>
        </tr>
    </table>
@endsection
