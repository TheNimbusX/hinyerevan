<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookGraphClient;
use Illuminate\Console\Command;

class FacebookExchangeToken extends Command
{
    protected $signature = 'facebook:exchange-token
                            {token : Short-lived page or user token from Graph API Explorer}
                            {--app-secret= : HinYerevanPage app secret (overrides FACEBOOK_APP_SECRET)}
                            {--write-env : Save long-lived token to .env as FACEBOOK_PAGE_ACCESS_TOKEN}';

    protected $description = 'Exchange a short-lived Facebook token for a long-lived token (~60 days)';

    public function handle(FacebookGraphClient $graph): int
    {
        $appId = trim((string) (config('services.facebook.app_id') ?: config('services.facebook.client_id', '')));
        $appSecret = trim((string) ($this->option('app-secret') ?: config('services.facebook.app_secret') ?: config('services.facebook.client_secret', '')));
        $short = trim($this->argument('token'));

        if ($appId === '' || $appSecret === '') {
            $this->error('Set FACEBOOK_APP_ID + FACEBOOK_APP_SECRET in .env (secret from HinYerevanPage app 4435294110080579 → Settings → Basic), or pass --app-secret=');

            return self::FAILURE;
        }

        $response = $graph->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $short,
        ]);

        if (! $response->ok()) {
            $this->error('Exchange failed: HTTP ' . $response->status());
            $this->line($response->body());
            $this->comment('Use App ID + App Secret of the same Meta app that issued this token (HinYerevanPage 4435294110080579, not Consumer 802992039416856).');

            return self::FAILURE;
        }

        $data = $response->json();
        $long = (string) ($data['access_token'] ?? '');

        if ($long === '') {
            $this->error('No access_token in response');
            $this->line($response->body());

            return self::FAILURE;
        }

        if ($this->option('write-env')) {
            $this->writeEnv($long, $appSecret);
            $this->info('Updated .env: FACEBOOK_PAGE_ACCESS_TOKEN (long-lived)');
            if ($this->option('app-secret')) {
                $this->info('Updated .env: FACEBOOK_APP_SECRET');
            }
        } else {
            $this->info('Long-lived token (add to .env as FACEBOOK_PAGE_ACCESS_TOKEN):');
            $this->line($long);
        }

        if (isset($data['expires_in'])) {
            $this->comment('expires_in seconds: ' . $data['expires_in']);
        }

        return self::SUCCESS;
    }

    private function writeEnv(string $longToken, string $appSecret): void
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            throw new \RuntimeException('.env not found at ' . $path);
        }

        $contents = file_get_contents($path);
        $contents = $this->replaceEnvLine($contents, 'FACEBOOK_PAGE_ACCESS_TOKEN', $longToken);
        if ($appSecret !== '') {
            $contents = $this->replaceEnvLine($contents, 'FACEBOOK_APP_SECRET', $appSecret);
        }
        file_put_contents($path, $contents);
    }

    private function replaceEnvLine(string $contents, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $contents)) {
            return (string) preg_replace($pattern, $line, $contents, 1);
        }

        return rtrim($contents) . PHP_EOL . $line . PHP_EOL;
    }
}
