@extends('emails.layouts.standard')

@section('title'){{ $title }}@endsection
@section('preheader')KIJO mail diagnostic message sent at {{ $sentAt }}.@endsection
@section('headerLabel', 'Mail Diagnostic')
@section('headerTitle'){{ $title }}@endsection
@section('headerSubtitle'){{ $fromAddress }}@endsection

@section('content')
    <p style="margin:0 0 18px;">{!! nl2br(e($body), false) !!}</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f2ff; border:1px solid #dcdbf8; border-radius:10px;">
        <tr>
            <td style="padding:16px 18px;">
                <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#5856d6; font-weight:700;">Delivery Details</div>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:10px;">
                    <tr>
                        <td style="width:90px; padding:4px 0; font-size:13px; color:#6f6c8f;">From</td>
                        <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $fromAddress }}</td>
                    </tr>
                    <tr>
                        <td style="width:90px; padding:4px 0; font-size:13px; color:#6f6c8f;">Sent</td>
                        <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $sentAt }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
