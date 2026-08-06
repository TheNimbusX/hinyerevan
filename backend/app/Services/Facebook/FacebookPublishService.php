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

        $videoUrl = trim((string) $photo->video);
        if ($videoUrl !== '') {
            return $this->publishVideoLinkPost($photo, $videoUrl);
        }

        $imageUrl = $this->publicImageUrl($photo);
        if ($imageUrl === null) {
            return 'Could not build a public image URL for Facebook.';
        }

        $message = $this->buildPostMessage($photo);

        try {
            $uploadResponse = $this->graph->post($this->pageId() . '/photos', [
                'url' => $imageUrl,
                'published' => 'false',
                'access_token' => $this->pageAccessToken(),
            ]);

            $mediaId = $uploadResponse->ok() ? (string) ($uploadResponse->json('id') ?? '') : '';

            if ($mediaId !== '') {
                $feedResponse = $this->graph->post($this->pageId() . '/feed', [
                    'message' => $message,
                    'attached_media[0]' => json_encode(['media_fbid' => $mediaId]),
                    'access_token' => $this->pageAccessToken(),
                ]);

                if ($feedResponse->ok()) {
                    $postId = (string) ($feedResponse->json('id') ?? '');
                    if ($postId !== '') {
                        return $this->finishPublish($photo, $postId);
                    }
                }

                Log::warning('Facebook feed post failed, falling back to photo post', [
                    'photo_id' => $photo->id,
                    'media_id' => $mediaId,
                    'status' => $feedResponse->status(),
                    'body' => $feedResponse->body(),
                ]);
            } else {
                Log::warning('Facebook unpublished upload failed, falling back to photo post', [
                    'photo_id' => $photo->id,
                    'status' => $uploadResponse->status(),
                    'body' => $uploadResponse->body(),
                ]);
            }

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

            $postId = (string) ($response->json('post_id') ?? $response->json('id') ?? '');
            if ($postId === '') {
                return 'Facebook returned an empty post id.';
            }

            return $this->finishPublish($photo, $postId);
        } catch (\Throwable $e) {
            Log::error('Facebook publish exception', ['photo_id' => $photo->id, 'message' => $e->getMessage()]);

            return 'Facebook publish error: ' . $e->getMessage();
        }
    }

    private function publishVideoLinkPost(Photo $photo, string $videoUrl): ?string
    {
        try {
            $response = $this->graph->post($this->pageId() . '/feed', [
                'message' => $this->buildPostMessage($photo, includeVideoLine: false),
                'link' => $videoUrl,
                'access_token' => $this->pageAccessToken(),
            ]);

            if (! $response->ok()) {
                $body = $response->json();
                $error = is_array($body) ? ($body['error']['message'] ?? $response->body()) : $response->body();
                Log::warning('Facebook video link post failed', ['photo_id' => $photo->id, 'error' => $error]);

                return is_string($error) ? $error : 'Facebook publish failed.';
            }

            $postId = (string) ($response->json('id') ?? '');
            if ($postId === '') {
                return 'Facebook returned an empty post id.';
            }

            return $this->finishPublish($photo, $postId);
        } catch (\Throwable $e) {
            Log::error('Facebook video publish exception', ['photo_id' => $photo->id, 'message' => $e->getMessage()]);

            return 'Facebook publish error: ' . $e->getMessage();
        }
    }

    private function finishPublish(Photo $photo, string $postId): ?string
    {
        $permalink = $this->resolvePermalink($postId);

        $photo->forceFill([
            'facebook_post_id' => $postId,
            'facebook_post_url' => $permalink,
            'facebook_publish_pending' => false,
            'facebook_synced_at' => now(),
        ])->save();

        $this->syncPostStats($photo->fresh());

        return null;
    }

    public function syncPostStats(Photo $photo): void
    {
        if (! $photo->facebook_post_id || ! $this->isConfigured()) {
            return;
        }

        try {
            $response = $this->graph->get($photo->facebook_post_id, [
                'fields' => 'permalink_url,reactions.summary(total_count)',
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
            $likes = (int) ($data['reactions']['summary']['total_count'] ?? 0);
            $postUrl = (string) ($data['permalink_url'] ?? $photo->facebook_post_url ?? '');
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

            $this->commentSync->syncForPhoto($photo->fresh(), true);

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
        return null;

        try { // @phpstan-ignore-line (kept for if/when FB restores the metric)
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
            'fields' => 'permalink_url,link',
            'access_token' => $this->pageAccessToken(),
        ]);

        if ($response->ok()) {
            $url = $response->json('permalink_url') ?: $response->json('link');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (str_contains($mediaId, '_')) {
            [$pageId, $postId] = explode('_', $mediaId, 2);
            if ($pageId !== '' && $postId !== '') {
                return 'https://www.facebook.com/' . $pageId . '/posts/' . $postId;
            }
        }

        return null;
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

    private function buildPostMessage(Photo $photo, bool $includeVideoLine = true): string
    {
        $siteUrl = rtrim((string) (config('services.facebook.site_url') ?: config('app.frontend_url', config('app.url'))), '/');
        $photoUrl = $siteUrl . '/photos/' . $photo->id;

        $headline = trim((string) $photo->facebook_comment);
        if ($headline === '') {
            $headline = trim((string) $photo->title);
        }

        $headline = trim(preg_replace('#https?://\S+#u', '', $headline) ?? $headline);

        $lines = [$headline];

        $authorName = trim((string) ($photo->author?->name ?? ''));
        if ($authorName !== '') {
            $lines[] = '📷 ' . $authorName;
        }

        $lines[] = $photoUrl;

        if ($includeVideoLine && $photo->video) {
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

        return $base . '/api/photos/file/original/' . rawurlencode($photo->file_id);
    }

    private function pageId(): string
    {
        return trim((string) config('services.facebook.page_id', ''));
    }

    private function pageAccessToken(): string
    {
        return trim((string) config('services.facebook.page_access_token', ''));
    }

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
