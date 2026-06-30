<?php

namespace App\Services\Facebook;

use App\Models\FacebookIncomingPost;
use App\Models\Photo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls posts that were created directly on the Facebook Page (manually, not by this
 * site) into a "pending" inbox so an admin can turn them into site photos.
 */
class FacebookIncomingService
{
    public function __construct(
        private readonly FacebookGraphClient $graph,
    ) {}

    public function isConfigured(): bool
    {
        return $this->pageId() !== '' && $this->pageAccessToken() !== '';
    }

    /**
     * Fetch recent page posts and store the ones with a photo that did not originate
     * from this site. Returns the number of newly stored pending posts.
     */
    public function fetchAndStore(int $limit = 25): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        try {
            $response = $this->graph->get($this->pageId() . '/feed', [
                'fields' => 'id,message,permalink_url,created_time,full_picture,status_type',
                'limit' => $limit,
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                Log::warning('Facebook incoming fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return 0;
            }

            $rows = $response->json('data');
            if (! is_array($rows)) {
                return 0;
            }

            // Post ids we created from the site — never re-import our own posts.
            // Post ids that already exist as a site photo — either published by us from the
            // site, or already imported from this inbox. Never re-import these.
            $ourPostIds = Photo::query()
                ->whereNotNull('facebook_post_id')
                ->pluck('facebook_post_id')
                ->map(fn ($v) => (string) $v)
                ->all();
            $ourPostIds = array_flip($ourPostIds);

            // Post ids we have already seen in this inbox (pending, imported or dismissed) —
            // skip them so imported/hidden posts never resurface.
            $knownIncomingIds = FacebookIncomingPost::query()
                ->pluck('facebook_post_id')
                ->map(fn ($v) => (string) $v)
                ->all();
            $knownIncomingIds = array_flip($knownIncomingIds);

            // Our site posts always carry a link back to the photo page; use that as a
            // robust signal even when the stored post id format differs.
            $siteHost = $this->siteHost();
            $ownMarker = $siteHost !== '' ? $siteHost . '/photos/' : '';

            // Clean up any of our own posts that were stored before this filter existed.
            if ($ownMarker !== '') {
                FacebookIncomingPost::query()
                    ->where('status', FacebookIncomingPost::STATUS_PENDING)
                    ->where('message', 'like', '%' . $ownMarker . '%')
                    ->delete();
            }

            $stored = 0;
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
                    continue; // already in the inbox (pending, imported or dismissed)
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
