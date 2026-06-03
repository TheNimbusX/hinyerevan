<?php

namespace App\Services\Facebook;

use Illuminate\Support\Facades\Cache;

class FacebookPageService
{
    public function __construct(
        private readonly FacebookGraphClient $graph,
    ) {}

    public function isConfigured(): bool
    {
        return $this->pageId() !== '' && $this->pageAccessToken() !== '';
    }

    public function pageUrl(): string
    {
        return rtrim((string) config('services.facebook.page_url', 'https://www.facebook.com/HinYerevanCom/'), '/');
    }

    public function appId(): string
    {
        return (string) (config('services.facebook.app_id') ?: config('services.facebook.client_id') ?: '');
    }

    /** @return array{name: string, followers_count: int, fan_count: int, page_url: string, configured: bool} */
    public function publicStats(): array
    {
        $base = [
            'name' => 'HinYerevan',
            'followers_count' => 0,
            'fan_count' => 0,
            'page_url' => $this->pageUrl(),
            'configured' => $this->isConfigured(),
        ];

        if (! $this->isConfigured()) {
            return $base;
        }

        // Cache layer must never take down the endpoint (e.g. a read-only cache dir).
        try {
            return Cache::remember('facebook:page:stats', now()->addMinutes(45), fn () => $this->fetchStats($base));
        } catch (\Throwable) {
            return $this->fetchStats($base);
        }
    }

    /**
     * @param  array{name: string, followers_count: int, fan_count: int, page_url: string, configured: bool}  $base
     * @return array{name: string, followers_count: int, fan_count: int, page_url: string, configured: bool}
     */
    private function fetchStats(array $base): array
    {
        try {
            $response = $this->graph->get($this->pageId(), [
                'fields' => 'name,followers_count,fan_count,link',
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                return $base;
            }

            $data = $response->json();

            return [
                'name' => (string) ($data['name'] ?? $base['name']),
                'followers_count' => (int) ($data['followers_count'] ?? $data['fan_count'] ?? 0),
                'fan_count' => (int) ($data['fan_count'] ?? 0),
                'page_url' => (string) ($data['link'] ?? $base['page_url']),
                'configured' => true,
            ];
        } catch (\Throwable) {
            return $base;
        }
    }

    public function pluginAppId(): string
    {
        return (string) (config('services.facebook.plugin_app_id') ?: config('services.facebook.client_id') ?: '');
    }

    public function pluginConfig(): array
    {
        $pageUrl = $this->pageUrl();
        if ($this->isConfigured()) {
            $stats = $this->publicStats();
            if (! empty($stats['page_url'])) {
                $pageUrl = (string) $stats['page_url'];
            }
        }

        return [
            'app_id' => $this->pluginAppId(),
            'page_url' => $pageUrl,
            'configured' => $this->pluginAppId() !== '' && $pageUrl !== '',
        ];
    }

    private function pageId(): string
    {
        return trim((string) config('services.facebook.page_id', ''));
    }

    private function pageAccessToken(): string
    {
        return trim((string) config('services.facebook.page_access_token', ''));
    }
}
