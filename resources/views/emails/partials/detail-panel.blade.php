<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f2ff; border:1px solid #dcdbf8; border-radius:10px; margin:18px 0 20px;">
    <tr>
        <td style="padding:16px 18px;">
            <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#5856d6; font-weight:700;">{{ $heading }}</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:10px;">
                @foreach ($rows as $row)
                    <tr>
                        <td style="width:150px; padding:5px 12px 5px 0; font-size:13px; line-height:1.45; color:#6f6c8f; vertical-align:top;">{{ $row['label'] }}</td>
                        <td style="padding:5px 0; font-size:14px; line-height:1.45; color:#111827; font-weight:600; vertical-align:top;">{!! nl2br(e($row['value'])) !!}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>
