@extends('emails.layouts.standard')

@section('title', 'Reset Your KIJO Password')
@section('preheader')Use this secure link to reset your KIJO password. It expires in 60 minutes.@endsection
@section('headerLabel', 'Account Security')
@section('headerTitle', 'Reset Your KIJO Password')
@section('headerSubtitle', 'Password reset request')

@section('content')
    <p style="margin:0 0 14px;">Dear {{ $recipientName }},</p>
    <p style="margin:0 0 22px;">We received a request to reset your KIJO password.</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr>
            <td bgcolor="#5856d6" style="border-radius:8px;">
                <a href="{{ $resetUrl }}" style="display:inline-block; padding:12px 18px; color:#ffffff; font-size:14px; line-height:1.2; font-weight:700; text-decoration:none;">Reset your password</a>
            </td>
        </tr>
    </table>

    <p style="margin:0; color:#6b7280; font-size:13px; line-height:1.65;">This link expires in 60 minutes. If you did not request this reset, you can ignore this email.</p>
@endsection
