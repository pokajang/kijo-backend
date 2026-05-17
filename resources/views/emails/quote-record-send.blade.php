<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
  </head>
  <body style="margin:0; padding:0; background-color:#f4f6fb; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f6fb; margin:0; padding:24px 0;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="640" style="width:640px; max-width:640px;">
            <tr>
              <td style="padding:0 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#ffffff; border:1px solid #dbe2ea; border-radius:14px; overflow:hidden;">
                  <tr>
                    <td style="padding:18px 24px; background-color:#0f2e4d; border-bottom:4px solid #4f7fb3;">
                      <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#c6d7eb; font-weight:700;">Quotation</div>
                      <div style="margin-top:6px; font-size:24px; line-height:1.25; color:#ffffff; font-weight:700;">AMIOSH Admin</div>
                      <div style="margin-top:4px; font-size:13px; line-height:1.5; color:#d8e4f2;">
                        {{ $fromAddress }}
                      </div>
                    </td>
                  </tr>

                  <tr>
                    <td style="padding:24px;">
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;">
                        <tr>
                          <td style="padding:16px 18px;">
                            <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#64748b; font-weight:700;">Quotation Details</div>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:10px;">
                              <tr>
                                <td style="width:120px; padding:4px 0; font-size:13px; color:#64748b;">Prepared for</td>
                                <td style="padding:4px 0; font-size:14px; color:#111827; font-weight:600;">{{ $recipientDisplay }}</td>
                              </tr>
                              <tr>
                                <td style="width:120px; padding:4px 0; font-size:13px; color:#64748b;">Service</td>
                                <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $serviceLabel }}</td>
                              </tr>
                              <tr>
                                <td style="width:120px; padding:4px 0; font-size:13px; color:#64748b;">Reference</td>
                                <td style="padding:4px 0; font-size:14px; color:#111827;">{{ $quoteRefNo }}</td>
                              </tr>
                              <tr>
                                <td style="width:120px; padding:4px 0; font-size:13px; color:#64748b;">Attachment</td>
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

                      <div style="margin-top:24px; padding-top:16px; border-top:1px solid #e5e7eb; font-size:13px; line-height:1.65; color:#6b7280;">
                        This quotation is attached to this email as a PDF document.
                      </div>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
