@php
    // docs/design/email.md: one centred column of 600 px on the canvas colour, built with
    // tables, every colour inline (the values of web/src/styles/tokens.css), one <style> block
    // for the phone width and dark mode only. No image, nothing loaded from another address.
    $font = "font-family:-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;mso-line-height-rule:exactly;";
    $buttonWidth = max(240, mb_strlen($button) * 10 + 72);
@endphp<!doctype html>
<html lang="{{ app()->getLocale() }}" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title }}</title>
<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
<style>
@media (max-width: 480px) {
  .col { width: 100% !important; }
  .pad { padding-left: 20px !important; padding-right: 20px !important; }
  .btn-cell { width: 100% !important; }
}
@media (prefers-color-scheme: dark) {
  .dm-canvas { background-color: #111b33 !important; }
  .dm-card { background-color: #1b2747 !important; }
  .dm-ink { color: #f3f5f9 !important; }
  .dm-soft { color: #b9c2d3 !important; }
  .dm-link { color: #c9d3ea !important; }
}
</style>
</head>
<body class="dm-canvas" bgcolor="#f3f5f9" style="margin:0;background-color:#f3f5f9;">
<div style="display:none;mso-hide:all;font-size:1px;line-height:1px;color:#f3f5f9;">{{ $preheader }}@for ($i = 0; $i < 90; $i++)&zwnj;&nbsp;@endfor</div>
<table role="presentation" class="dm-canvas" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f5f9" style="width:100%;background-color:#f3f5f9;">
<tr><td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" class="col" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
<tr><td bgcolor="#061a3d" class="pad" style="background-color:#061a3d;padding:20px 32px;{!! $font !!}font-size:20px;line-height:26px;font-weight:bold;color:#ffffff;">{{ config('app.name') }}</td></tr>
<tr><td bgcolor="#0d3a7a" class="pad" style="background-color:#0d3a7a;background-image:linear-gradient(135deg,#09295b,#0d3a7a 60%,#1a4a8a);padding:36px 32px;">
<h1 style="margin:0 0 8px;{!! $font !!}font-size:26px;line-height:32px;font-weight:bold;color:#ffffff;">{{ $title }}</h1>
<div style="{!! $font !!}font-size:16px;line-height:24px;color:#c9d3ea;">{{ $line }}</div>
</td></tr>
<tr><td bgcolor="#ffffff" class="pad dm-card" style="background-color:#ffffff;padding:32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td class="dm-ink" style="padding-bottom:12px;{!! $font !!}font-size:16px;line-height:24px;color:#111b33;">{{ __('mail.greeting') }}</td></tr>
<tr><td class="dm-ink" style="padding-bottom:28px;{!! $font !!}font-size:16px;line-height:24px;color:#111b33;">{{ $intro }}</td></tr>
<tr><td style="padding-bottom:28px;">
<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:48px;v-text-anchor:middle;width:{{ $buttonWidth }}px;" arcsize="17%" stroke="f" fillcolor="#ff8603"><w:anchorlock/><center style="font-family:Arial,sans-serif;font-size:16px;font-weight:bold;color:#061a3d;">{{ $button }}</center></v:roundrect><![endif]-->
<!--[if !mso]><!-->
<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-cell">
<tr><td bgcolor="#ff8603" align="center" style="background-color:#ff8603;border-radius:8px;padding:0 28px;"><a href="{{ $url }}" style="display:block;{!! $font !!}font-size:16px;line-height:48px;font-weight:bold;color:#061a3d;text-decoration:none;">{{ $button }}</a></td></tr>
</table>
<!--<![endif]-->
</td></tr>
<tr><td class="dm-soft" style="padding-bottom:4px;{!! $font !!}font-size:14px;line-height:20px;color:#4a556b;">{{ __('mail.link_fallback') }}</td></tr>
<tr><td style="padding-bottom:24px;{!! $font !!}font-size:14px;line-height:22px;"><a class="dm-link" href="{{ $url }}" style="color:#1e3a8a;text-decoration:underline;word-break:break-all;">{{ $url }}</a></td></tr>
<tr><td class="dm-soft" style="padding-bottom:4px;{!! $font !!}font-size:14px;line-height:20px;color:#4a556b;">{{ $validity }}</td></tr>
<tr><td class="dm-soft" style="{!! $font !!}font-size:14px;line-height:20px;color:#4a556b;">{{ $ignore }}</td></tr>
</table>
</td></tr>
<tr><td class="pad dm-soft" style="padding:20px 32px;{!! $font !!}font-size:12px;line-height:18px;color:#4a556b;">{{ __('mail.footer') }}</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
