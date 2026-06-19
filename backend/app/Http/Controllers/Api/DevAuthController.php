<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\RecaptchaVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DevAuthController extends Controller
{
    public function status(Request $request)
    {
        if (! $this->enabled($request)) {
            return ['required' => false, 'ok' => true];
        }

        return [
            'required' => true,
            'ok' => $this->tokenIsValid($this->readToken($request)),
        ];
    }

    public function login(Request $request)
    {
        abort_unless($this->enabled($request), 404);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:120'],
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        RecaptchaVerifier::verify((string) ($data['recaptcha_token'] ?? ''));

        $user = (string) config('services.dev_auth.user', '');
        $pass = (string) config('services.dev_auth.password', '');

        if (
            $user === ''
            || $pass === ''
            || ! hash_equals($user, (string) $data['username'])
            || ! hash_equals($pass, (string) $data['password'])
        ) {
            throw ValidationException::withMessages([
                'username' => __('dev_auth.invalid_credentials'),
            ]);
        }

        $token = Str::random(64);
        Cache::put($this->cacheKey($token), true, now()->addHours(24));

        return [
            'token' => $token,
            'expires_in' => 86400,
        ];
    }

    private function enabled(Request $request): bool
    {
        if (! filter_var(config('services.dev_auth.enabled', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->isDevHost((string) $request->getHost());
    }

    private function isDevHost(string $host): bool
    {
        return in_array($host, [
            'dev.hinyerevan.com',
            'www.dev.hinyerevan.com',
            '45.138.25.76',
        ], true);
    }

    private function readToken(Request $request): string
    {
        $header = trim((string) $request->header('X-Dev-Auth', ''));

        return $header !== '' ? $header : trim((string) $request->bearerToken());
    }

    private function tokenIsValid(string $token): bool
    {
        return $token !== '' && Cache::has($this->cacheKey($token));
    }

    private function cacheKey(string $token): string
    {
        return 'dev_auth:' . $token;
    }
}
