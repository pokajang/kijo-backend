@extends('emails.layouts.standard')

@section('title'){{ $subject }}@endsection
@section('emailWidthAttribute', '760')
@section('emailWidth', '760px')
@section('preheader')Client vendor registration records need attention.@endsection
@section('headerLabel', 'Client Vendor Registration')
@section('headerTitle'){{ $subject }}@endsection
@section('headerSubtitle')Internal reminder only. No email has been sent to the client.@endsection

@section('content')
    <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 16px;">The following client vendor registration records need attention.</p>

    <table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse; width:100%; font-size:13px; border:1px solid #dcdbf8; margin:18px 0;">
        <thead>
            <tr>
                <th align="left" style="border-bottom:1px solid #dcdbf8; padding:8px; background:#f3f2ff; color:#5856d6;">Stage</th>
                <th align="left" style="border-bottom:1px solid #dcdbf8; padding:8px; background:#f3f2ff; color:#5856d6;">Client</th>
                <th align="left" style="border-bottom:1px solid #dcdbf8; padding:8px; background:#f3f2ff; color:#5856d6;">Valid From</th>
                <th align="left" style="border-bottom:1px solid #dcdbf8; padding:8px; background:#f3f2ff; color:#5856d6;">Valid Until</th>
                <th align="right" style="border-bottom:1px solid #dcdbf8; padding:8px; background:#f3f2ff; color:#5856d6;">Days Left</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td style="border-bottom:1px solid #e6e4fb; padding:8px;">{{ $row['stage_label'] }}</td>
                    <td style="border-bottom:1px solid #e6e4fb; padding:8px;">{{ $row['client_name'] }}</td>
                    <td style="border-bottom:1px solid #e6e4fb; padding:8px;">{{ $row['valid_from'] }}</td>
                    <td style="border-bottom:1px solid #e6e4fb; padding:8px;">{{ $row['valid_until'] }}</td>
                    <td align="right" style="border-bottom:1px solid #e6e4fb; padding:8px;">{{ $row['days_left'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection

@section('footer')
    Internal reminder only. No email has been sent to the client.
@endsection
