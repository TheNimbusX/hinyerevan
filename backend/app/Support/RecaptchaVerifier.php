<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RecaptchaVerifier
{
    public static function verify(string $token): void
    {
        $secret = trim((string) config('services.recaptcha.secret', ''));
        if ($secret === '') {
            return;
        }

        if ($token === '') {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('password.captcha_required'),
            ]);
        }

        try {
            $response = Http::timeout(12)->asForm()->post('https://www.recaptcha.net/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('reCAPTCHA verify unreachable, skipping check', ['message' => $e->getMessage()]);

            return;
        }

        if (! $response->json('success')) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('password.captcha_failed'),
            ]);
        }
    }
}
