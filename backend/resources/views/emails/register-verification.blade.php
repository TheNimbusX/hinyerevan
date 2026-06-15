<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('register.mail_subject') }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #1a1a1a;">
    <p>{{ __('register.mail_greeting') }}{{ $firstName ? ', ' . e($firstName) : '' }}!</p>
    <p>{{ __('register.mail_intro') }}</p>
    <p style="font-size:28px;font-weight:bold;letter-spacing:4px;margin:24px 0;">{{ $code }}</p>
    <p style="color:#666;font-size:13px;">{{ __('register.mail_expiry') }}</p>
    <p style="margin-top:28px;color:#444;">{{ __('register.mail_signature') }}</p>
</body>
</html>
