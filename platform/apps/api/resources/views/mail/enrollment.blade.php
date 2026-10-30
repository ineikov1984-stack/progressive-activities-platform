{{-- One layout for every enrollment email; the words come from MailContent. Email-safe: tables and inline styles. --}}
@php($b = $mail->brand)
<!doctype html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $mail->subject }}</title>
</head>
<body style="margin:0;padding:0;background:#F6F4EF;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F4EF;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border:1px solid #E2E6EA;border-radius:20px;font-family:Onest,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#344054;">
@if ($mail->team)
<tr><td style="padding:16px 32px;background:{{ $b->accent }};color:{{ $b->onAccent }};font-size:14px;font-weight:700;border-radius:20px 20px 0 0;">{{ $b->name }}</td></tr>
@else
<tr><td style="height:6px;line-height:6px;font-size:0;background:{{ $b->accent }};border-radius:20px 20px 0 0;">&nbsp;</td></tr>
<tr><td style="padding:24px 32px 0;">
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<td style="padding-right:12px;vertical-align:middle;">
@if ($b->logoUrl)
{{-- When the client blocks images, the initials show on the colour instead of a broken picture. --}}
<img src="{{ $b->logoUrl }}" width="40" height="40" alt="{{ $b->initials }}" style="display:block;width:40px;height:40px;line-height:40px;border-radius:999px;object-fit:cover;background:{{ $b->accent }};color:{{ $b->onAccent }};font-size:15px;font-weight:700;text-align:center;">
@else
<div style="width:40px;height:40px;line-height:40px;border-radius:999px;background:{{ $b->accent }};color:{{ $b->onAccent }};font-size:15px;font-weight:700;text-align:center;">{{ $b->initials }}</div>
@endif
</td>
<td style="vertical-align:middle;font-size:16px;font-weight:700;color:#173F5F;">{{ $b->name }}</td>
</tr></table>
</td></tr>
@endif
<tr><td style="padding:24px 32px 28px;">
@if ($mail->greeting)
<p style="margin:0 0 16px;font-size:16px;line-height:24px;">{{ $mail->greeting }}</p>
@endif
<h1 style="margin:0 0 16px;font-size:24px;line-height:30px;font-weight:700;color:#173F5F;">{{ $mail->heading }}</h1>
@foreach ($mail->paragraphs as $paragraph)
<p style="margin:0 0 16px;font-size:16px;line-height:24px;">{{ $paragraph }}</p>
@endforeach
@if ($mail->callout)
<div style="margin:0 0 16px;padding:14px 18px;border-radius:12px;background:#FCF1D8;">
<div style="font-size:14px;line-height:20px;font-weight:700;color:#8A5A0B;">{{ $mail->callout['title'] }}</div>
<div style="font-size:16px;line-height:24px;color:#344054;">{{ $mail->callout['text'] }}</div>
</div>
@endif
@if ($mail->facts !== [])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #E2E6EA;border-radius:12px;">
@foreach ($mail->facts as $fact)
<tr><td style="padding:{{ $loop->first ? '14px' : '6px' }} 18px {{ $loop->last ? '14px' : '6px' }};">
<div style="font-size:14px;line-height:20px;font-weight:600;color:#5B6878;">{{ $fact['label'] }}</div>
<div style="font-size:16px;line-height:24px;font-weight:600;color:#173F5F;">{{ $fact['value'] }}</div>
@if (! empty($fact['hint']))
<div style="font-size:14px;line-height:20px;color:#5B6878;">{{ $fact['hint'] }}</div>
@endif
</td></tr>
@endforeach
</table>
@endif
@if ($mail->quote)
<div style="margin:0 0 16px;">
<div style="font-size:14px;line-height:20px;font-weight:600;color:#5B6878;">{{ $mail->quote['label'] }}</div>
<div style="margin-top:6px;padding:12px 16px;border-radius:8px;background:#F2F4F5;font-size:16px;line-height:24px;color:#173F5F;white-space:pre-line;">{{ $mail->quote['text'] }}</div>
</div>
@endif
@if ($mail->button)
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr>
<td style="border-radius:12px;background:{{ $mail->team ? '#B8561A' : $b->accent }};">
<a href="{{ $mail->button['url'] }}" style="display:inline-block;padding:12px 22px;font-size:16px;font-weight:600;color:{{ $mail->team ? '#FFFFFF' : $b->onAccent }};text-decoration:none;">{{ $mail->button['label'] }}</a>
</td></tr></table>
@endif
@foreach ($mail->after as $line)
<p style="margin:0 0 12px;font-size:15px;line-height:22px;">{{ $line }}</p>
@endforeach
</td></tr>
<tr><td style="padding:18px 32px;background:#F2F4F5;font-size:13px;line-height:19px;color:#5B6878;border-radius:0 0 20px 20px;">{{ $mail->footer }}</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
