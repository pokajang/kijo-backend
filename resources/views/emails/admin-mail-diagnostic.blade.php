<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #172033; line-height: 1.5;">
    <h2 style="margin: 0 0 12px;">{{ $title }}</h2>
    <p style="margin: 0 0 14px;">{!! nl2br(e($body), false) !!}</p>
    <table cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 4px 12px 4px 0; color: #5f6b7a;">From</td>
            <td style="padding: 4px 0;">{{ $fromAddress }}</td>
        </tr>
        <tr>
            <td style="padding: 4px 12px 4px 0; color: #5f6b7a;">Sent</td>
            <td style="padding: 4px 0;">{{ $sentAt }}</td>
        </tr>
    </table>
</body>
</html>
