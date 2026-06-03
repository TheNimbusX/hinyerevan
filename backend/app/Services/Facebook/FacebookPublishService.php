<?php

namespace App\Services\Facebook;

use App\Models\Photo;
use Illuminate\Support\Facades\Log;

class FacebookPublishService
{
    public function __construct(
        private readonly FacebookGraphClient $graph,
        private readonly FacebookPageService $pages,
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

        $message = trim((string) $photo->facebook_comment);
        if ($message === '') {
            $message = trim($photo->title . ' (' . $photo->year . ')');
        }

        $siteUrl = rtrim((string) (config('services.facebook.site_url') ?: config('app.frontend_url', config('app.url'))), '/');
        $message .= "\n\n" . $siteUrl . '/photos/' . $photo->id;

        if ($photo->video) {
            $message .= "\n\nYouTube: " . trim((string) $photo->video);
        }

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
                'fields' => 'likes.summary(true),comments.summary(true),permalink_url',
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                return;
            }

            $data = $response->json();
            $photo->forceFill([
                'facebook_likes' => (int) ($data['likes']['summary']['total_count'] ?? $photo->facebook_likes ?? 0),
                'facebook_comments_count' => (int) ($data['comments']['summary']['total_count'] ?? $photo->facebook_comments_count ?? 0),
                'facebook_post_url' => (string) ($data['permalink_url'] ?? $photo->facebook_post_url),
                'facebook_synced_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Facebook stats sync failed', ['photo_id' => $photo->id, 'message' => $e->getMessage()]);
        }
    }

    public function publishIfPending(Photo $photo): ?string
    {
        if (! $photo->facebook_publish_pending || ! $photo->published) {
            return null;
        }

        return $this->publishPhoto($photo);
    }

    private function resolvePermalink(string $mediaId): ?string
    {
        $response = $this->graph->get($mediaId, [
            'fields' => 'permalink_url,link',
            'access_token' => $this->pageAccessToken(),
        ]);

        if (! $response->ok()) {
            return null;
        }

        return $response->json('permalink_url') ?: $response->json('link');
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
