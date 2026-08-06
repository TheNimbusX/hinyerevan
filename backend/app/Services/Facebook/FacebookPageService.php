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

    /** @return array{name: string, followers_count: int, fan_count: int, page_url: string, picture_url: string, cover_url: string, recent_posts: array<int, array{message: string, picture_url: string, permalink_url: string, created_time: string}>, configured: bool} */
    public function publicStats(): array
    {
        $base = [
            'name' => 'HinYerevan',
            'followers_count' => 0,
            'fan_count' => 0,
            'page_url' => $this->pageUrl(),
            'picture_url' => '',
            'cover_url' => '',
            'recent_posts' => [],
            'configured' => $this->isConfigured(),
        ];

        if (! $this->isConfigured()) {
            return $base;
        }

        try {
            return Cache::remember('facebook:page:stats:v3', now()->addMinutes(45), function () use ($base) {
                $stats = $this->fetchStats($base);
                $stats['recent_posts'] = $this->fetchRecentPosts();

                return $stats;
            });
        } catch (\Throwable) {
            $stats = $this->fetchStats($base);
            $stats['recent_posts'] = $this->fetchRecentPosts();

            return $stats;
        }
    }

    /**
     * @param  array{name: string, followers_count: int, fan_count: int, page_url: string, picture_url: string, cover_url: string, configured: bool}  $base
     * @return array{name: string, followers_count: int, fan_count: int, page_url: string, picture_url: string, cover_url: string, configured: bool}
     */
    private function fetchStats(array $base): array
    {
        try {
            $response = $this->graph->get($this->pageId(), [
                'fields' => 'name,followers_count,fan_count,link,picture.type(large),cover',
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
                'picture_url' => (string) ($data['picture']['data']['url'] ?? ''),
                'cover_url' => (string) ($data['cover']['source'] ?? ''),
                'configured' => true,
            ];
        } catch (\Throwable) {
            return $base;
        }
    }

    /** @return array<int, array{message: string, picture_url: string, permalink_url: string, created_time: string}> */
    private function fetchRecentPosts(): array
    {
        try {
            $response = $this->graph->get($this->pageId() . '/posts', [
                'fields' => 'message,full_picture,permalink_url,created_time',
                'limit' => 8,
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                return [];
            }

            $posts = [];
            foreach ($response->json('data') ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $message = trim((string) ($item['message'] ?? ''));
                $picture = trim((string) ($item['full_picture'] ?? ''));
                $link = trim((string) ($item['permalink_url'] ?? ''));

                if ($message === '' && $picture === '') {
                    continue;
                }

                $posts[] = [
                    'message' => $message,
                    'picture_url' => $picture,
                    'permalink_url' => $link,
                    'created_time' => (string) ($item['created_time'] ?? ''),
                ];

                if (count($posts) >= 5) {
                    break;
                }
            }

            return $posts;
        } catch (\Throwable) {
            return [];
        }
    }

    public function pluginAppId(): string
    {
        return (string) (config('services.facebook.plugin_app_id') ?: config('services.facebook.client_id') ?: '');
    }

    public function pluginConfig(): array
    {
        $pageUrl = $this->pageUrl();

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
