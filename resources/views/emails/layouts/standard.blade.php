<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'KIJO Notification')</title>
  </head>
  <body style="margin:0; padding:0; background-color:#f6f5ff; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    @hasSection('preheader')
      <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; mso-hide:all;">
        @yield('preheader')
      </div>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f6f5ff; margin:0; padding:24px 0;">
      <tr>
        <td align="center">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="@yield('emailWidthAttribute', '640')" style="width:@yield('emailWidth', '640px'); max-width:100%;">
            <tr>
              <td style="padding:0 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#ffffff; border:1px solid #deddf7; border-radius:14px; overflow:hidden;">
                  <tr>
                    <td style="padding:18px 24px; background-color:#5856d6; border-bottom:4px solid #c7c6f3;">
                      <div style="font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#ecebff; font-weight:700;">@yield('headerLabel', 'KIJO')</div>
                      <div style="margin-top:6px; font-size:24px; line-height:1.25; color:#ffffff; font-weight:700;">@yield('headerTitle', 'KIJO Notification')</div>
                      @hasSection('headerSubtitle')
                        <div style="margin-top:4px; font-size:13px; line-height:1.5; color:#f0efff;">
                          @yield('headerSubtitle')
                        </div>
                      @endif
                    </td>
                  </tr>

                  <tr>
                    <td style="padding:24px; font-size:15px; line-height:1.7; color:#111827;">
                      @yield('content')
                    </td>
                  </tr>

                  @hasSection('footer')
                    <tr>
                      <td style="padding:0 24px 24px;">
                        <div style="padding-top:16px; border-top:1px solid #e6e4fb; font-size:13px; line-height:1.65; color:#6b7280;">
                          @yield('footer')
                        </div>
                      </td>
                    </tr>
                  @endif
                </table>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
