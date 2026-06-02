<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Password reset</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.5; color: #1a1a1a;">
    <p>Hello{{ $user->name ? ', ' . e($user->name) : '' }}!</p>
    <p>You requested a password reset for your HinYerevan.com account.</p>
    <p>
        <a href="{{ $resetUrl }}" style="display:inline-block;padding:10px 18px;background:#2949b3;color:#fff;text-decoration:none;border-radius:6px;">
            Reset password
        </a>
    </p>
    <p>Or copy this link into your browser:</p>
    <p style="word-break:break-all;"><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p style="color:#666;font-size:13px;">This link expires in 60 minutes. If you did not request a reset, you can ignore this email.</p>
</body>
</html>
