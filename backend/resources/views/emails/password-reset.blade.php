<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('password.mail_subject') }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #1a1a1a;">
    <p>{{ __('password.mail_greeting') }}{{ $user->name ? ', ' . e($user->name) : '' }}!</p>
    <p>{{ __('password.mail_intro') }}</p>
    <p>
        <a href="{{ $resetUrl }}" style="display:inline-block;padding:10px 18px;background:#2949b3;color:#fff;text-decoration:none;border-radius:6px;">
            {{ __('password.mail_button') }}
        </a>
    </p>
    <p>{{ __('password.mail_copy_hint') }}</p>
    <p style="word-break:break-all;"><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p style="color:#666;font-size:13px;">{{ __('password.mail_expiry') }}</p>
</body>
</html>
