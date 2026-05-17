<p>Hello,</p>
<p>The system detected <strong>{{ $count }}</strong> active user(s) still using the default initial password.</p>
<p>User notification result: <strong>Sent {{ $noticeSent }}</strong>, <strong>Failed {{ $noticeFailed }}</strong>.</p>
<p>Please follow up with users that failed to receive notification and ensure they update their password in <strong>Profile &gt; Change Password</strong>.</p>
<table style="border-collapse:collapse;border:1px solid #ddd;">
    <thead>
        <tr>
            <th style="padding:6px 10px;border:1px solid #ddd;text-align:left;">Full Name</th>
            <th style="padding:6px 10px;border:1px solid #ddd;text-align:left;">Email</th>
            <th style="padding:6px 10px;border:1px solid #ddd;text-align:left;">Staff ID</th>
            <th style="padding:6px 10px;border:1px solid #ddd;text-align:left;">User Notice</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
        <tr>
            <td style="padding:6px 10px;border:1px solid #ddd;">{{ $row['full_name'] }}</td>
            <td style="padding:6px 10px;border:1px solid #ddd;">{{ $row['email'] }}</td>
            <td style="padding:6px 10px;border:1px solid #ddd;">{{ $row['staff_id'] }}</td>
            <td style="padding:6px 10px;border:1px solid #ddd;">{{ $row['notice_status'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
