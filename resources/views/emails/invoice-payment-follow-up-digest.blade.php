<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f6f7f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table width="760" cellpadding="0" cellspacing="0" role="presentation" style="max-width:760px;width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="padding:20px 24px;border-bottom:1px solid #e5e7eb;">
                            <div style="font-size:16px;font-weight:700;color:#111827;">Invoice Follow-up Reminder</div>
                            <div style="font-size:13px;color:#6b7280;margin-top:4px;">Internal reminder only. No email has been sent to the client.</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 24px;font-size:14px;line-height:1.6;">
                            <p style="margin:0 0 14px;">Hi {{ $recipientName }},</p>
                            <p style="margin:0 0 14px;">The invoice records below are still marked as pending, unpaid, or overdue. Please review the latest payment status and follow up manually with the client when appropriate.</p>
                            <p style="margin:0 0 14px;">If payment has already been received, please update the invoice status so future reminders are skipped.</p>

                            <table cellpadding="0" cellspacing="0" role="presentation" style="width:100%;border-collapse:collapse;margin:18px 0;border:1px solid #e5e7eb;font-size:13px;">
                                <thead>
                                    <tr>
                                        <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Stage</th>
                                        <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Invoice</th>
                                        <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Client</th>
                                        <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Client PIC</th>
                                        <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Amount</th>
                                        <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;background:#f9fafb;color:#374151;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($invoiceRows as $row)
                                        <tr>
                                            <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $row['stage'] }}</td>
                                            <td style="padding:8px;border-bottom:1px solid #e5e7eb;">
                                                <div style="font-weight:700;color:#111827;">{{ $row['invoice_ref_no'] }}</div>
                                                <div style="color:#6b7280;">Invoice: {{ $row['invoice_date'] }} &middot; Terms: {{ $row['payment_terms_days'] }} days</div>
                                                <div style="color:#6b7280;">Due: {{ $row['due_date'] }} &middot; Overdue: {{ $row['overdue_days'] }} days</div>
                                            </td>
                                            <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $row['client_name'] }}</td>
                                            <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $row['client_pic'] }}</td>
                                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;">RM {{ $row['amount'] }}</td>
                                            <td style="padding:8px;border-bottom:1px solid #e5e7eb;">{{ $row['status'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                            <p style="margin:14px 0 0;">Thank you.</p>
                            <p style="margin:14px 0 0;">Best regards,<br>AMIOSH Admin</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
