<!DOCTYPE html>
<html lang="{{ $mailLocale }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $mailSubject }}</title>
<style>
    body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table { border-collapse: collapse; }
    img { border: 0; outline: none; text-decoration: none; }
    a { color: #326abd; }
    @media (max-width: 620px) {
        .bw-card { padding: 24px 20px !important; }
        .bw-outer { padding: 12px 8px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .bw-canvas { background-color: #15191e !important; }
        .bw-card { background-color: #1c2127 !important; border-color: #3c444f !important; }
        .bw-text, .bw-brand { color: #eaedf2 !important; }
        .bw-muted { color: #bac3cf !important; }
        .bw-link { color: #9bc5fe !important; }
        .bw-rule { border-color: #3c444f !important; }
        .bw-button-cell { background-color: #6aa4f6 !important; }
        .bw-button { color: #15191e !important; }
    }
    [data-ogsc] .bw-canvas { background-color: #15191e !important; }
    [data-ogsc] .bw-card { background-color: #1c2127 !important; }
    [data-ogsc] .bw-text, [data-ogsc] .bw-brand { color: #eaedf2 !important; }
    [data-ogsc] .bw-muted { color: #bac3cf !important; }
    [data-ogsc] .bw-link { color: #9bc5fe !important; }
</style>
</head>
<body class="bw-canvas" style="margin:0;padding:0;background-color:#f5f7f9;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">{{ $mailSubject }}</div>
<table role="presentation" class="bw-canvas" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f7f9;">
    <tr>
        <td class="bw-outer" align="center" style="padding:32px 16px;">
            <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                <tr>
                    <td style="padding:0 4px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                        <a href="{{ $siteUrl }}" style="text-decoration:none;">
                            <img src="{{ $logoUrl }}" width="32" height="32" alt="" style="display:inline-block;vertical-align:middle;width:32px;height:32px;">
                            <span class="bw-brand" style="display:inline-block;vertical-align:middle;margin-left:10px;font-size:18px;line-height:32px;font-weight:600;color:#1b417f;">Business Watchdog</span>
                        </a>
                    </td>
                </tr>
                <tr>
                    <td class="bw-card" style="background-color:#ffffff;border:1px solid #d5dbe3;border-radius:8px;padding:32px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                        @if ($greeting !== null)
                            <p class="bw-text" style="margin:0 0 16px;font-size:16px;line-height:24px;color:#1c2127;">{{ $greeting }}</p>
                        @endif
                        @foreach ($blocks as $block)
                            @if ($block['type'] === 'button')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 8px;">
                                    <tr>
                                        <td class="bw-button-cell" style="background-color:#24549f;border-radius:6px;">
                                            <a class="bw-button" href="{{ $block['url'] }}" target="_blank" rel="noopener" style="display:inline-block;padding:12px 22px;font-size:15px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">{{ $block['label'] }}</a>
                                        </td>
                                    </tr>
                                </table>
                                <p class="bw-muted" style="margin:0 0 16px;font-size:12px;line-height:18px;color:#626d7c;word-break:break-all;">{{ __('mail.button_fallback') }} <a class="bw-link" href="{{ $block['url'] }}" style="color:#326abd;">{{ $block['url'] }}</a></p>
                            @else
                                <p class="bw-text" style="margin:0 0 16px;font-size:16px;line-height:24px;color:#1c2127;">
                                    @foreach ($block['lines'] as $line)
                                        {!! implode('', array_map(fn (array $segment): string => isset($segment['url']) ? '<a class="bw-link" href="'.e($segment['url']).'" target="_blank" rel="noopener" style="color:#326abd;word-break:break-all;">'.e($segment['text']).'</a>' : e($segment['text']), $line)) !!}@if (! $loop->last)<br>@endif
                                    @endforeach
                                </p>
                            @endif
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:18px;">
                        @if ($reason !== '')
                            <p class="bw-muted" style="margin:0 0 8px;color:#626d7c;">{{ $reason }}@if ($settings !== null) <a class="bw-link" href="{{ $settings['url'] }}" style="color:#326abd;">{{ $settings['label'] }}</a>@endif</p>
                        @endif
                        <p class="bw-muted" style="margin:0 0 8px;color:#626d7c;">
                            @foreach ($links as $link)<a class="bw-link" href="{{ $link['url'] }}" style="color:#326abd;">{{ $link['label'] }}</a>@if (! $loop->last) &nbsp;·&nbsp; @endif @endforeach
                        </p>
                        @if ($provider !== '')
                            <p class="bw-muted" style="margin:0;color:#626d7c;">{{ $provider }}</p>
                        @endif
                    </td>
                </tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>
