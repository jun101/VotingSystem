<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $intro }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1b2540;">
<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
<p style="margin:0 0 16px;font-size:16px;">{{ __('mail.greeting') }}</p>
<p style="margin:0 0 24px;font-size:16px;line-height:1.5;">{{ $intro }}</p>
<p style="margin:0 0 24px;"><a href="{{ $url }}" style="display:inline-block;background:#e8a317;color:#1b2540;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;">{{ $button }}</a></p>
<p style="margin:0 0 8px;font-size:14px;line-height:1.5;">{{ $validity }}</p>
<p style="margin:0 0 24px;font-size:14px;line-height:1.5;">{{ $ignore }}</p>
<p style="margin:0 0 4px;font-size:12px;color:#5b6684;">{{ __('mail.link_fallback') }}</p>
<p style="margin:0 0 24px;font-size:12px;word-break:break-all;"><a href="{{ $url }}" style="color:#5b6684;">{{ $url }}</a></p>
<p style="margin:0;font-size:12px;color:#5b6684;">{{ __('mail.footer') }}</p>
</div>
</body>
</html>
