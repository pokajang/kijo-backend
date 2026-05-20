<!doctype html>
<html>
<body style="font-family:Arial,sans-serif;color:#111827;line-height:1.45;">
    <div style="max-width:760px;margin:0 auto;">
        <h2 style="margin:0 0 8px;">{{ $subject }}</h2>
        <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>
        <p style="margin:0 0 16px;">The following client vendor registration records need attention.</p>

        <table style="border-collapse:collapse;width:100%;font-size:13px;">
            <thead>
                <tr>
                    <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f3f4f6;">Stage</th>
                    <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f3f4f6;">Client</th>
                    <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f3f4f6;">Valid From</th>
                    <th style="text-align:left;border:1px solid #d1d5db;padding:8px;background:#f3f4f6;">Valid Until</th>
                    <th style="text-align:right;border:1px solid #d1d5db;padding:8px;background:#f3f4f6;">Days Left</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td style="border:1px solid #d1d5db;padding:8px;">{{ $row['stage_label'] }}</td>
                        <td style="border:1px solid #d1d5db;padding:8px;">{{ $row['client_name'] }}</td>
                        <td style="border:1px solid #d1d5db;padding:8px;">{{ $row['valid_from'] }}</td>
                        <td style="border:1px solid #d1d5db;padding:8px;">{{ $row['valid_until'] }}</td>
                        <td style="border:1px solid #d1d5db;padding:8px;text-align:right;">{{ $row['days_left'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin:16px 0 0;color:#6b7280;font-size:12px;">Internal reminder only. No email has been sent to the client.</p>
    </div>
</body>
</html>
