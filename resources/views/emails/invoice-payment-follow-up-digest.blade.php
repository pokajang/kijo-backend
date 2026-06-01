@extends('emails.layouts.standard')

@section('title'){{ $subject }}@endsection
@section('emailWidthAttribute', '760')
@section('emailWidth', '760px')
@section('preheader')Internal invoice follow-up reminder. No email has been sent to the client.@endsection
@section('headerLabel', 'Invoice Follow-up')
@section('headerTitle', 'Invoice Follow-up Reminder')
@section('headerSubtitle')Internal reminder only. No email has been sent to the client.@endsection

@section('content')
    <p style="margin:0 0 14px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 14px;">The invoice records below are still marked as pending, unpaid, or overdue. Please review the latest payment status and follow up manually with the client when appropriate.</p>
    <p style="margin:0 0 14px;">If payment has already been received, please update the invoice status so future reminders are skipped.</p>

    <table cellpadding="0" cellspacing="0" role="presentation" style="width:100%; border-collapse:collapse; margin:18px 0; border:1px solid #dcdbf8; font-size:13px;">
        <thead>
            <tr>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Stage</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Invoice</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Client</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Client PIC</th>
                <th align="right" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Amount</th>
                <th align="left" style="padding:8px; border-bottom:1px solid #dcdbf8; background:#f3f2ff; color:#5856d6;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoiceRows as $row)
                <tr>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['stage'] }}</td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">
                        <div style="font-weight:700; color:#111827;">{{ $row['invoice_ref_no'] }}</div>
                        <div style="color:#6b7280;">Invoice: {{ $row['invoice_date'] }} &middot; Terms: {{ $row['payment_terms_days'] }} days</div>
                        <div style="color:#6b7280;">Due: {{ $row['due_date'] }} &middot; Overdue: {{ $row['overdue_days'] }} days</div>
                    </td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['client_name'] }}</td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['client_pic'] }}</td>
                    <td align="right" style="padding:8px; border-bottom:1px solid #e6e4fb;">RM {{ $row['amount'] }}</td>
                    <td style="padding:8px; border-bottom:1px solid #e6e4fb;">{{ $row['status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin:14px 0 0;">Thank you.</p>
    <p style="margin:14px 0 0;">Best regards,<br>AMIOSH Admin</p>
@endsection
