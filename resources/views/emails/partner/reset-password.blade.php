<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('partner.password_reset_email_subject') }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:Arial, sans-serif; color:#111827;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:30px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden;">
                    <tr>
                        <td style="background:#111827; padding:22px 30px; color:#ffffff; font-size:20px; font-weight:bold;">
                            Heavy Tools Előrendelés
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px;">
                            <h1 style="margin:0 0 20px; font-size:22px; color:#111827;">
                                {{ __('partner.password_reset_email_subject') }}
                            </h1>

                            <p style="font-size:15px; line-height:1.6; margin:0 0 16px;">
                                {{ __('partner.password_reset_email_greeting') }}
                            </p>

                            <p style="font-size:15px; line-height:1.6; margin:0 0 24px;">
                                {{ __('partner.password_reset_email_line_1') }}
                            </p>

                            <p style="text-align:center; margin:30px 0;">
                                <a href="{{ $url }}"
                                   style="display:inline-block; background:#111827; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold;">
                                    {{ __('partner.password_reset_email_button') }}
                                </a>
                            </p>

                            <p style="font-size:14px; line-height:1.6; color:#4b5563; margin:0 0 20px;">
                                {{ __('partner.password_reset_email_line_2') }}
                            </p>

                            <p style="font-size:13px; line-height:1.6; color:#6b7280; margin:24px 0 0;">
                                {{ __('partner.password_reset_email_copy_link') }}
                            </p>

                            <p style="font-size:12px; line-height:1.6; word-break:break-all; color:#2563eb; margin:8px 0 0;">
                                <a href="{{ $url }}" style="color:#2563eb;">
                                    {{ $url }}
                                </a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#f9fafb; padding:18px 30px; font-size:12px; color:#6b7280; text-align:center;">
                            © {{ date('Y') }} Heavy Tools
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>