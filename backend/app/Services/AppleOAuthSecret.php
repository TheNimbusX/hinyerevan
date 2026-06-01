<?php

namespace App\Services;

use Firebase\JWT\JWT;

/**
 * Builds the Sign in with Apple client_secret JWT from Team ID, Key ID and .p8 key.
 * Users only need APPLE_CLIENT_ID, APPLE_TEAM_ID, APPLE_KEY_ID and APPLE_PRIVATE_KEY in .env.
 */
class AppleOAuthSecret
{
    public static function applyToConfig(): void
    {
        if (! empty(config('services.apple.client_secret'))) {
            return;
        }

        $secret = self::generate();
        if ($secret !== null) {
            config(['services.apple.client_secret' => $secret]);
        }
    }

    public static function generate(): ?string
    {
        $teamId = (string) config('services.apple.team_id', '');
        $keyId = (string) config('services.apple.key_id', '');
        $clientId = (string) config('services.apple.client_id', '');
        $privateKey = self::privateKeyMaterial();

        if ($teamId === '' || $keyId === '' || $clientId === '' || $privateKey === null) {
            return null;
        }

        $now = time();

        return JWT::encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + (86400 * 180),
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ], $privateKey, 'ES256', $keyId);
    }

    private static function privateKeyMaterial(): ?string
    {
        $inline = (string) config('services.apple.private_key', '');
        if ($inline !== '') {
            return str_replace('\\n', "\n", $inline);
        }

        $path = (string) config('services.apple.private_key_path', '');
        if ($path !== '' && is_readable($path)) {
            return file_get_contents($path);
        }

        return null;
    }
}
