<?php

namespace App\Services\Facebook;

use App\Models\Photo;
use App\Models\PhotoFacebookComment;
use Illuminate\Support\Facades\Log;

class FacebookPublishService
{
    public function __construct(
        private readonly FacebookGraphClient $graph,
        private readonly FacebookPageService $pages,
        private readonly FacebookCommentSyncService $commentSync,
    ) {}

    public function isConfigured(): bool
    {
        return $this->pages->isConfigured();
    }

    /**
     * Publish photo to the Facebook Page. Returns error message or null on success.
     */
    public function publishPhoto(Photo $photo): ?string
    {
        if (! $this->isConfigured()) {
            return 'Facebook Page is not configured on the server.';
        }

        if ($tokenError = $this->assertPageAccessToken()) {
            return $tokenError;
        }

        if ($photo->facebook_post_id) {
            return null;
        }

        $imageUrl = $this->publicImageUrl($photo);
        if ($imageUrl === null) {
            return 'Could not build a public image URL for Facebook.';
        }

        $message = $this->buildPostMessage($photo);

        try {
            $response = $this->graph->post($this->pageId() . '/photos', [
                'url' => $imageUrl,
                'message' => $message,
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                $body = $response->json();
                $error = is_array($body) ? ($body['error']['message'] ?? $response->body()) : $response->body();
                Log::warning('Facebook publish failed', ['photo_id' => $photo->id, 'error' => $error]);

                return is_string($error) ? $error : 'Facebook publish failed.';
            }

            $mediaId = (string) ($response->json('id') ?? '');
            if ($mediaId === '') {
                return 'Facebook returned an empty post id.';
            }

            $permalink = $this->resolvePermalink($mediaId);

            $photo->forceFill([
                'facebook_post_id' => $mediaId,
                'facebook_post_url' => $permalink,
                'facebook_publish_pending' => false,
                'facebook_synced_at' => now(),
            ])->save();

            $this->syncPostStats($photo->fresh());

            return null;
        } catch (\Throwable $e) {
            Log::error('Facebook publish exception', ['photo_id' => $photo->id, 'message' => $e->getMessage()]);

            return 'Facebook publish error: ' . $e->getMessage();
        }
    }

    public function syncPostStats(Photo $photo): void
    {
        if (! $photo->facebook_post_id || ! $this->isConfigured()) {
            return;
        }

        try {
            $response = $this->graph->get($photo->facebook_post_id, [
                'fields' => 'link,likes.summary(true)',
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                Log::warning('Facebook stats sync HTTP error', [
                    'photo_id' => $photo->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $data = $response->json();
            $likes = (int) ($data['likes']['summary']['total_count'] ?? 0);
            $postUrl = (string) ($data['link'] ?? $photo->facebook_post_url ?? '');
            $fbViews = $this->fetchPostImpressions($photo->facebook_post_id);

            $fill = [
                'facebook_likes' => $likes,
                'facebook_post_url' => $postUrl !== '' ? $postUrl : $photo->facebook_post_url,
                'facebook_synced_at' => now(),
            ];
            if ($fbViews !== null) {
                $fill['facebook_views'] = $fbViews;
            }
            $photo->forceFill($fill)->save();

            $this->commentSync->syncForPhoto($photo->fresh());

            $storedComments = PhotoFacebookComment::query()
                ->where('photo_id', $photo->id)
                ->count();
            $photo->forceFill(['facebook_comments_count' => $storedComments])->save();
        } catch (\Throwable $e) {
            Log::warning('Facebook stats sync failed', ['photo_id' => $photo->id, 'message' => $e->getMessage()]);
        }
    }

    private function fetchPostImpressions(string $objectId): ?int
    {
        try {
            $response = $this->graph->get($objectId . '/insights', [
                'metric' => 'post_impressions',
                'period' => 'lifetime',
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                return null;
            }

            $values = $response->json('data.0.values') ?? [];
            if (! is_array($values) || $values === []) {
                return null;
            }

            $last = end($values);
            $value = is_array($last) ? ($last['value'] ?? null) : null;

            return is_numeric($value) ? max(0, (int) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function publishIfPending(Photo $photo): ?string
    {
        if (! $photo->facebook_publish_pending || ! $photo->published) {
            return null;
        }

        return $this->publishPhoto($photo);
    }

    public function resolvePermalink(string $mediaId): ?string
    {
        $response = $this->graph->get($mediaId, [
            'fields' => 'link,from',
            'access_token' => $this->pageAccessToken(),
        ]);

        if (! $response->ok()) {
            return null;
        }

        $url = $response->json('link');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function publicPostUrl(Photo $photo): ?string
    {
        if ($photo->facebook_post_url) {
            return $photo->facebook_post_url;
        }

        if ($photo->facebook_post_id) {
            return $this->resolvePermalink((string) $photo->facebook_post_id);
        }

        return null;
    }

    private function buildPostMessage(Photo $photo): string
    {
        $siteUrl = rtrim((string) (config('services.facebook.site_url') ?: config('app.frontend_url', config('app.url'))), '/');
        $photoUrl = $siteUrl . '/photos/' . $photo->id;

        $headline = trim((string) $photo->facebook_comment);
        if ($headline === '') {
            $headline = trim((string) $photo->title);
        }

        // Strip raw URLs from comment so the photo link is not duplicated.
        $headline = trim(preg_replace('#https?://\S+#u', '', $headline) ?? $headline);

        $lines = [$headline, $photoUrl];

        if ($photo->video) {
            $lines[] = '';
            $lines[] = 'YouTube: ' . trim((string) $photo->video);
        }

        return implode("\n", $lines);
    }

    private function publicImageUrl(Photo $photo): ?string
    {
        if (! $photo->file_id) {
            return null;
        }

        $base = rtrim((string) (config('services.facebook.site_url') ?: config('app.frontend_url', config('app.url'))), '/');

        return $base . '/api/photos/file/large/' . rawurlencode($photo->file_id);
    }

    private function pageId(): string
    {
        return trim((string) config('services.facebook.page_id', ''));
    }

    private function pageAccessToken(): string
    {
        return trim((string) config('services.facebook.page_access_token', ''));
    }

    /** Page token from /me/accounts → access_token; User token from Explorer top field will not work. */
    public function assertPageAccessToken(): ?string
    {
        $token = $this->pageAccessToken();
        $pageId = $this->pageId();

        if ($token === '' || $pageId === '') {
            return 'Facebook Page is not configured on the server.';
        }

        try {
            $response = $this->graph->get('me', [
                'fields' => 'id',
                'access_token' => $token,
            ]);

            if (! $response->ok()) {
                $body = $response->json();
                $msg = is_array($body) ? ($body['error']['message'] ?? null) : null;

                return is_string($msg) ? $msg : 'Facebook Page access token is invalid.';
            }

            if ((string) ($response->json('id') ?? '') !== $pageId) {
                return 'FACEBOOK_PAGE_ACCESS_TOKEN must be the Page token from GET /me/accounts (field access_token for your page), not the User token from Graph API Explorer.';
            }
        } catch (\Throwable $e) {
            return 'Facebook token check failed: ' . $e->getMessage();
        }

        return null;
    }
}
