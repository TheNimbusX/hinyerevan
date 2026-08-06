<?php

namespace App\Services\Facebook;

use App\Models\FacebookIncomingPost;
use App\Models\Photo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookIncomingService
{
    public function __construct(
        private readonly FacebookGraphClient $graph,
    ) {}

    public function isConfigured(): bool
    {
        return $this->pageId() !== '' && $this->pageAccessToken() !== '';
    }

    public function cachedImagePath(FacebookIncomingPost $post): string
    {
        return storage_path('app/fb-incoming/' . md5((string) $post->facebook_post_id) . '.jpg');
    }

    public function hasCachedImage(FacebookIncomingPost $post): bool
    {
        $path = $this->cachedImagePath($post);

        return is_file($path) && filesize($path) > 0;
    }

    public function publicImageUrl(FacebookIncomingPost $post): ?string
    {
        if ($this->hasCachedImage($post)) {
            $base = rtrim((string) (config('services.facebook.site_url') ?: config('app.frontend_url', config('app.url'))), '/');

            return $base . '/api/facebook-incoming/' . $post->id . '/image?v=' . filemtime($this->cachedImagePath($post));
        }

        return $post->image_url ?: null;
    }

    private function downloadImage(string $url, string $target): bool
    {
        if (trim($url) === '') {
            return false;
        }

        try {
            $response = Http::timeout(20)->get($url);
            if (! $response->ok() || strlen($response->body()) < 1000) {
                return false;
            }

            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, $response->body());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function cacheMissingImages(int $limit = 40): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $done = 0;

        foreach (FacebookIncomingPost::query()->orderByDesc('posted_at')->get() as $row) {
            if ($done >= $limit) {
                break;
            }
            if ($this->hasCachedImage($row)) {
                continue;
            }

            $target = $this->cachedImagePath($row);
            $ok = $this->downloadImage((string) $row->image_url, $target);

            if (! $ok) {
                try {
                    $response = $this->graph->get($row->facebook_post_id, [
                        'fields' => 'full_picture',
                        'access_token' => $this->pageAccessToken(),
                    ]);
                    $fresh = $response->ok() ? trim((string) ($response->json('full_picture') ?? '')) : '';
                    if ($fresh !== '') {
                        $row->forceFill(['image_url' => $fresh])->save();
                        $ok = $this->downloadImage($fresh, $target);
                    }
                } catch (\Throwable) {
                    // leave for the next run
                }
            }

            if ($ok) {
                $done++;
            }
        }

        return $done;
    }

    public function fetchAndStore(int $limit = 25, ?string $since = null, ?string $until = null): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $hasRange = $since !== null || $until !== null;

        try {
            $query = [
                'fields' => 'id,message,permalink_url,created_time,full_picture,status_type',
                'limit' => $hasRange ? 100 : $limit,
                'access_token' => $this->pageAccessToken(),
            ];
            if ($since !== null) $query['since'] = $since;
            if ($until !== null) $query['until'] = $until;

            $response = $this->graph->get($this->pageId() . '/feed', $query);

            if (! $response->ok()) {
                Log::warning('Facebook incoming fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return 0;
            }

            $ourPostIds = Photo::query()
                ->whereNotNull('facebook_post_id')
                ->pluck('facebook_post_id')
                ->map(fn ($v) => (string) $v)
                ->all();
            $ourPostIds = array_flip($ourPostIds);

            $knownIncomingIds = FacebookIncomingPost::query()
                ->pluck('facebook_post_id')
                ->map(fn ($v) => (string) $v)
                ->all();
            $knownIncomingIds = array_flip($knownIncomingIds);

            $siteHost = $this->siteHost();
            $ownMarker = $siteHost !== '' ? $siteHost . '/photos/' : '';

            if ($ownMarker !== '') {
                FacebookIncomingPost::query()
                    ->where('status', FacebookIncomingPost::STATUS_PENDING)
                    ->where('message', 'like', '%' . $ownMarker . '%')
                    ->delete();
            }

            $stored = 0;
            $maxPages = $hasRange ? 20 : 1;

            for ($page = 0; $page < $maxPages; $page++) {
                $rows = $response->json('data');
                if (! is_array($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $postId = (string) ($row['id'] ?? '');
                    $picture = trim((string) ($row['full_picture'] ?? ''));
                    $message = (string) ($row['message'] ?? '');

                    if ($postId === '' || $picture === '') {
                        continue; // only posts that carry an image
                    }
                    if (isset($ourPostIds[$postId])) {
                        continue; // already a site photo (published or imported)
                    }
                    if (isset($knownIncomingIds[$postId])) {
                        FacebookIncomingPost::query()
                            ->where('facebook_post_id', $postId)
                            ->update([
                                'image_url' => $picture,
                                'permalink_url' => trim((string) ($row['permalink_url'] ?? '')) ?: null,
                            ]);

                        continue;
                    }
                    if ($ownMarker !== '' && str_contains($message, $ownMarker)) {
                        continue; // a site post (carries the photo page link)
                    }

                    $created = FacebookIncomingPost::query()->firstOrCreate(
                        ['facebook_post_id' => $postId],
                        [
                            'message' => trim((string) ($row['message'] ?? '')) ?: null,
                            'image_url' => $picture,
                            'permalink_url' => trim((string) ($row['permalink_url'] ?? '')) ?: null,
                            'posted_at' => $this->parseTime($row['created_time'] ?? null),
                            'status' => FacebookIncomingPost::STATUS_PENDING,
                        ],
                    );

                    if ($created->wasRecentlyCreated) {
                        $stored++;
                    }
                }

                $nextUrl = $response->json('paging.next');
                if (! $hasRange || ! $nextUrl || $page + 1 >= $maxPages) {
                    break;
                }

                $response = $this->graph->getUrl($nextUrl);
                if (! $response->ok()) {
                    break;
                }
            }

            return $stored;
        } catch (\Throwable $e) {
            Log::error('Facebook incoming fetch exception', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    private function parseTime($value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function siteHost(): string
    {
        $base = (string) (config('services.facebook.site_url') ?: config('app.frontend_url', config('app.url')));

        return (string) (parse_url($base, PHP_URL_HOST) ?: '');
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
