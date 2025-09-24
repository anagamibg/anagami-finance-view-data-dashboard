<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ruleName }}</title>
</head>
@php
    $accent = ['info' => '#1d82f5', 'warning' => '#e8a317', 'critical' => '#d6403a'][$event->severity] ?? '#1d82f5';
@endphp
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;color:#28313c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;">
                <tr>
                    <td style="background:#1d82f5;padding:18px 28px;color:#ffffff;font-size:16px;font-weight:bold;">
                        {{ $companyName ?: $appName }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <p style="margin:0 0 14px;font-size:15px;">@lang('databackd-gaps-alerts.mail.greeting')</p>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">
                            @lang('databackd-gaps-alerts.mail.intro', ['rule' => $ruleName])
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e8eef3;border-left:4px solid {{ $accent }};border-radius:4px;margin-bottom:20px;">
                            <tr>
                                <td style="padding:16px 18px;font-size:14px;line-height:1.6;">
                                    <div style="font-size:12px;font-weight:bold;color:{{ $accent }};text-transform:uppercase;margin-bottom:4px;">{{ $severityLabel }}</div>
                                    <div style="font-size:16px;font-weight:bold;margin-bottom:8px;">{{ $ruleName }}</div>
                                    <div style="margin-bottom:10px;">{{ $messageText }}</div>
                                    @if ($valueText !== '')
                                        <div><span style="color:#616e80;">@lang('databackd-gaps-alerts.mail.value'):</span> {{ $valueText }}</div>
                                    @endif
                                    @if ($thresholdText !== '')
                                        <div><span style="color:#616e80;">@lang('databackd-gaps-alerts.mail.threshold'):</span> {{ $thresholdText }}</div>
                                    @endif
                                    @if ($periodText !== '')
                                        <div><span style="color:#616e80;">@lang('databackd-gaps-alerts.mail.period'):</span> {{ $periodText }}</div>
                                    @endif
                                    @foreach ($lines as $line)
                                        <div style="color:#616e80;font-size:13px;">{{ $line }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        </table>

                        @if ($openUrl)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                <tr>
                                    <td style="background:#1d82f5;border-radius:4px;">
                                        <a href="{{ $openUrl }}" target="_blank"
                                           style="display:inline-block;padding:11px 22px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;">
                                            @lang('databackd-gaps-alerts.mail.open')
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        <p style="margin:0;font-size:12px;color:#8a95a5;">
                            @lang('databackd-gaps-alerts.mail.generated_at', ['date' => $checkedAt])
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 28px;background:#f9fafb;font-size:12px;color:#8a95a5;line-height:1.5;">
                        @lang('databackd-gaps-alerts.mail.footer', ['app' => $appName])
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
