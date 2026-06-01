@extends('emails.layouts.standard')

@section('title'){{ $subject }}@endsection
@section('preheader')Quotation {{ $quoteRefNo }} is attached as a PDF document.@endsection
@section('headerLabel', 'Quotation')
@section('headerTitle', 'AMIOSH Admin')
@section('headerSubtitle'){{ $fromAddress }}@endsection

@section('content')
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f3f2ff; border:1px solid #dcdbf8; border-radius:10px;">
    <tr>
      <td style="padding:16px 18px;">
        <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#5856d6; font-weight:700;">Quotation Details</div>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:10px;">
          <tr>
            <td style="width:120px; padding:4px 0; font-size:13px; color:#6f6c8f;">Prepared for</td>
            <td style="padding:4px 0; font-size:14px; color:#111827; font-weight:600;">{{ $recipientDisplay }}</td>
          </tr>
          <tr>
            <td style="width:120px; padding:4px 0; font-size:13px; color:#6f6c8f;">Service</td>
            <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $serviceLabel }}</td>
          </tr>
          <tr>
            <td style="width:120px; padding:4px 0; font-size:13px; color:#6f6c8f;">Reference</td>
            <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $quoteRefNo }}</td>
          </tr>
          <tr>
            <td style="width:120px; padding:4px 0; font-size:13px; color:#6f6c8f;">Attachment</td>
            <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $attachmentName }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <div style="margin-top:24px; font-size:15px; line-height:1.75; color:#111827;">
    @foreach ($bodyParagraphs as $paragraph)
      @if ($paragraph['type'] === 'note')
        <div style="margin:18px 0 0; padding:12px 14px; background-color:#fff7ed; border:1px solid #fed7aa; border-radius:8px; font-size:14px; line-height:1.65; color:#9a3412;">
          <strong>Note:</strong> {!! $paragraph['html'] !!}
        </div>
      @else
        <p style="margin:0 0 16px;">{!! $paragraph['html'] !!}</p>
      @endif
    @endforeach
  </div>
@endsection

@section('footer')
  This quotation is attached to this email as a PDF document.
@endsection
