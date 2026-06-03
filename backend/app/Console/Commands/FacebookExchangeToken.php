<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookGraphClient;
use Illuminate\Console\Command;

class FacebookExchangeToken extends Command
{
    protected $signature = 'facebook:exchange-token {token : Short-lived page or user token from Graph API Explorer}';

    protected $description = 'Exchange a short-lived Facebook token for a long-lived token (60 days)';

    public function handle(FacebookGraphClient $graph): int
    {
        $clientId = trim((string) config('services.facebook.client_id', ''));
        $clientSecret = trim((string) config('services.facebook.client_secret', ''));
        $short = trim($this->argument('token'));

        if ($clientId === '' || $clientSecret === '') {
            $this->error('Set FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET in .env');

            return self::FAILURE;
        }

        $response = $graph->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'fb_exchange_token' => $short,
        ]);

        if (! $response->ok()) {
            $this->error('Exchange failed: HTTP ' . $response->status());
            $this->line($response->body());

            return self::FAILURE;
        }

        $data = $response->json();
        $long = (string) ($data['access_token'] ?? '');

        if ($long === '') {
            $this->error('No access_token in response');
            $this->line($response->body());

            return self::FAILURE;
        }

        $this->info('Long-lived token (add to .env as FACEBOOK_PAGE_ACCESS_TOKEN):');
        $this->line($long);

        if (isset($data['expires_in'])) {
            $this->comment('expires_in seconds: ' . $data['expires_in']);
        }

        return self::SUCCESS;
    }
}
