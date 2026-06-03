<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookGraphClient;
use App\Services\Facebook\FacebookPageService;
use App\Services\Facebook\FacebookPublishService;
use Illuminate\Console\Command;

class FacebookDiagnose extends Command
{
    protected $signature = 'facebook:diagnose';

    protected $description = 'Check Facebook Page API configuration and fetch page stats';

    public function handle(FacebookPageService $pages, FacebookGraphClient $graph, FacebookPublishService $publish): int
    {
        $pageId = trim((string) config('services.facebook.page_id', ''));
        $token = trim((string) config('services.facebook.page_access_token', ''));
        $pageUrl = $pages->pageUrl();
        $appId = $pages->appId();

        $this->info('Facebook Page integration');
        $this->table(
            ['Key', 'Value'],
            [
                ['FACEBOOK_CLIENT_ID', config('services.facebook.client_id') ?: '—'],
                ['FACEBOOK_APP_ID', $appId ?: '—'],
                ['FACEBOOK_PAGE_ID', $pageId ?: '—'],
                ['FACEBOOK_PAGE_URL', $pageUrl],
                ['FACEBOOK_PAGE_ACCESS_TOKEN', $token ? (substr($token, 0, 12) . '…') : '—'],
                ['OAUTH_PROXY', config('services.oauth.proxy') ? 'set' : '—'],
                ['site_url (publish links)', config('services.facebook.site_url') ?: '—'],
            ],
        );

        if (! $pages->isConfigured()) {
            $this->error('Page is NOT configured. Set FACEBOOK_PAGE_ID and FACEBOOK_PAGE_ACCESS_TOKEN in .env');
            $this->line('Guide: deploy/FACEBOOK-TEST-PAGE.md');

            return self::FAILURE;
        }

        if ($tokenError = $publish->assertPageAccessToken()) {
            $this->error('Token problem: ' . $tokenError);

            return self::FAILURE;
        }

        $this->info('Calling Graph API…');
        $response = $graph->get($pageId, [
            'fields' => 'name,id,link,followers_count,fan_count',
            'access_token' => $token,
        ]);

        if (! $response->ok()) {
            $this->error('Graph API error: HTTP ' . $response->status());
            $this->line($response->body());

            return self::FAILURE;
        }

        $data = $response->json();
        $this->info('Page OK: ' . ($data['name'] ?? '?'));
        $this->table(['Field', 'Value'], [
            ['id', (string) ($data['id'] ?? '')],
            ['link', (string) ($data['link'] ?? '')],
            ['followers_count', (string) ($data['followers_count'] ?? $data['fan_count'] ?? 0)],
            ['fan_count', (string) ($data['fan_count'] ?? 0)],
        ]);

        $stats = $pages->publicStats();
        $this->info('publicStats() (cached): followers=' . $stats['followers_count']);

        $this->newLine();
        $this->comment('Next: open /facebook on the site, publish a photo with "Facebook" checked (HTTPS public image URL).');

        return self::SUCCESS;
    }
}
