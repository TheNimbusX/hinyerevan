<?php

namespace App\Services\Facebook;

use App\Models\Comment;
use App\Models\Photo;
use App\Models\PhotoFacebookComment;
use App\Services\LegacyPhotoStorage;
use App\Services\TranslationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FacebookCommentSyncService
{
    /** Stream returns flat list; parent must be requested as `parent` (not parent{id}). */
    private const COMMENT_FIELDS = 'id,message,created_time,from{id,name,picture},parent';

    /** Avoid hammering Graph when one page view touches several endpoints. */
    private const THROTTLE_SECONDS = 15;

    /** @var list<string> FB comment ids already represented by site comments. */
    private array $linkedFacebookIds = [];

    public function __construct(
        private readonly FacebookGraphClient $graph,
        private readonly LegacyPhotoStorage $photoStorage,
    ) {}

    /**
     * Cross-post a site comment to the photo's Facebook post via the Page token.
     * Appears as the Page; author is attributed in the message text.
     *
     * @return string|null  The created Facebook comment id, or null on failure.
     */
    public function publishComment(Photo $photo, string $message, ?string $replyToFacebookCommentId = null): ?string
    {
        $token = trim((string) config('services.facebook.page_access_token', ''));
        $postId = trim((string) $photo->facebook_post_id);
        if ($postId === '' || $token === '' || trim($message) === '') {
            return null;
        }

        $target = $replyToFacebookCommentId !== null && $replyToFacebookCommentId !== ''
            ? $replyToFacebookCommentId
            : $postId;

        try {
            $response = $this->graph->post($target . '/comments', [
                'message' => $message,
                'access_token' => $token,
            ]);

            if (! $response->ok()) {
                Log::warning('Facebook comment cross-post failed', [
                    'photo_id' => $photo->id,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            $id = (string) ($response->json('id') ?? '');

            return $id !== '' ? $id : null;
        } catch (\Throwable $e) {
            Log::warning('Facebook comment cross-post exception', [
                'photo_id' => $photo->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function syncForPhoto(Photo $photo, bool $force = false): void
    {
        $token = trim((string) config('services.facebook.page_access_token', ''));
        if (! $photo->facebook_post_id || $token === '') {
            return;
        }

        $throttleKey = 'fb_comments_sync_' . $photo->id;
        if (! $force) {
            try {
                if (Cache::has($throttleKey)) {
                    return;
                }
                Cache::put($throttleKey, true, now()->addSeconds(self::THROTTLE_SECONDS));
            } catch (\Throwable) {
                // Cache unavailable — proceed without throttling rather than skip the sync.
            }
        }

        $postId = trim((string) $photo->facebook_post_id);

        // FB comment ids that originated from site comments (cross-posted): they are
        // already shown as the site comment, so don't ingest them as duplicates.
        $this->linkedFacebookIds = Comment::query()
            ->where('post_id', $photo->id)
            ->whereNotNull('facebook_comment_id')
            ->pluck('facebook_comment_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        try {
            $response = $this->graph->get($postId . '/comments', [
                'fields' => self::COMMENT_FIELDS,
                'filter' => 'stream',
                'limit' => 100,
                'access_token' => $token,
            ]);

            if (! $response->ok()) {
                Log::warning('Facebook comments sync failed', [
                    'photo_id' => $photo->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return;
            }

            $seen = [];
            $this->ingestStreamPages($photo, $postId, $token, $response, $seen);

            if ($seen !== []) {
                PhotoFacebookComment::query()
                    ->where('photo_id', $photo->id)
                    ->whereNotIn('facebook_comment_id', $seen)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Facebook comments sync exception', [
                'photo_id' => $photo->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<string>  $seen
     */
    private function ingestStreamPages(Photo $photo, string $postId, string $token, $response, array &$seen): void
    {
        $guard = 0;

        while ($response && $guard < 8) {
            $guard++;
            foreach ($response->json('data') ?? [] as $row) {
                if (is_array($row)) {
                    $this->ingestCommentRow($photo, $row, $seen);
                }
            }

            $next = $response->json('paging.next');
            if (! is_string($next) || $next === '') {
                break;
            }

            $response = $this->graph->getUrl($next);
            if (! $response->ok()) {
                break;
            }
        }
    }

    /**
     * @param  list<string>  $seen
     */
    private function ingestCommentRow(Photo $photo, array $row, array &$seen, ?string $fallbackParentId = null): void
    {
        $fbId = (string) ($row['id'] ?? '');
        $message = trim((string) ($row['message'] ?? ''));
        if ($fbId === '' || $message === '') {
            return;
        }

        // Skip comments that we cross-posted from the site; the site comment is the source of truth.
        if (in_array($fbId, $this->linkedFacebookIds, true)) {
            return;
        }

        if (! in_array($fbId, $seen, true)) {
            $seen[] = $fbId;
        }

        $authorName = trim((string) ($row['from']['name'] ?? 'Facebook'));
        $parentId = $this->extractParentCommentId($row['parent'] ?? null);
        $postId = trim((string) $photo->facebook_post_id);
        if ($parentId === '' && $fallbackParentId !== null && $fallbackParentId !== $fbId) {
            $parentId = $fallbackParentId;
        }
        if ($parentId === $fbId || $parentId === $postId) {
            $parentId = '';
        }

        try {
            PhotoFacebookComment::query()->updateOrCreate(
                [
                    'photo_id' => $photo->id,
                    'facebook_comment_id' => $fbId,
                ],
                [
                    'parent_facebook_comment_id' => $parentId !== '' ? $parentId : null,
                    'author_name' => $authorName !== '' ? $authorName : 'Facebook',
                    'author_picture' => $this->resolveAuthorPicture($row['from'] ?? null),
                    'body' => $message,
                    'commented_at' => isset($row['created_time']) ? $row['created_time'] : null,
                    'synced_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            // One malformed row must never abort the whole batch.
            Log::warning('Facebook comment row ingest failed', [
                'photo_id' => $photo->id,
                'facebook_comment_id' => $fbId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $parent
     */
    private function extractParentCommentId(?array $parent): string
    {
        if (! is_array($parent)) {
            return '';
        }

        return trim((string) ($parent['id'] ?? ''));
    }

    /**
     * Resolve the avatar to persist: prefer a locally cached copy (so it survives
     * the lookaside URL expiry), falling back to the raw remote URL if the
     * download fails. Returns null for default silhouettes (frontend shows initials).
     *
     * @param  array<string, mixed>|null  $from
     */
    private function resolveAuthorPicture(?array $from): ?string
    {
        $remote = $this->extractPictureUrl($from);
        if ($remote === null) {
            return null;
        }

        $facebookUserId = is_array($from) ? trim((string) ($from['id'] ?? '')) : '';
        if ($facebookUserId !== '') {
            $fileId = $this->photoStorage->storeFacebookAvatar($remote, $facebookUserId);
            if ($fileId !== null) {
                return '/api/photos/file/users/' . $fileId;
            }
        }

        return $remote;
    }

    /**
     * @param  array<string, mixed>|null  $from
     */
    private function extractPictureUrl(?array $from): ?string
    {
        if (! is_array($from)) {
            return null;
        }

        $picture = $from['picture'] ?? null;
        if (is_string($picture) && $picture !== '') {
            return $picture;
        }

        if (! is_array($picture)) {
            return null;
        }

        // A default silhouette is not worth caching — let the UI render initials.
        if (($picture['data']['is_silhouette'] ?? false) === true) {
            return null;
        }

        $url = $picture['data']['url'] ?? $picture['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializedTreeForPhoto(int $photoId, ?TranslationService $translator = null, ?string $lang = null): array
    {
        $rows = PhotoFacebookComment::query()
            ->where('photo_id', $photoId)
            ->orderBy('commented_at')
            ->orderBy('id')
            ->get();

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[$row->facebook_comment_id] = $this->serializeFacebookRow($row);
        }

        $roots = [];
        foreach ($rows as $row) {
            $parentId = trim((string) ($row->parent_facebook_comment_id ?? ''));
            if ($parentId !== '' && isset($nodes[$parentId])) {
                $nodes[$parentId]['replies'][] = &$nodes[$row->facebook_comment_id];
            } else {
                $roots[] = &$nodes[$row->facebook_comment_id];
            }
        }

        $this->sortRepliesRecursive($roots);

        if ($translator && $lang) {
            $this->translateTree($roots, $translator, $lang);
        }

        return $roots;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFacebookRow(PhotoFacebookComment $row): array
    {
        $name = $row->author_name ?: 'Facebook';

        return [
            'id' => 'fb_' . $row->facebook_comment_id,
            'body' => $row->body,
            'datetime' => optional($row->commented_at)->toISOString(),
            'user_unique' => null,
            'to' => null,
            'source' => 'facebook',
            'facebook_comment_id' => $row->facebook_comment_id,
            'author' => [
                'name' => $name,
                'display_name' => $name,
                'picture' => $row->author_picture,
            ],
            'replies' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sortRepliesRecursive(array &$items): void
    {
        usort($items, fn (array $a, array $b) => strcmp($a['datetime'] ?? '', $b['datetime'] ?? ''));

        foreach ($items as &$item) {
            if (! empty($item['replies'])) {
                $this->sortRepliesRecursive($item['replies']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $roots
     */
    private function translateTree(array &$roots, TranslationService $translator, string $lang): void
    {
        $flat = [];
        $walk = function (array &$node) use (&$walk, &$flat): void {
            $flat[] = &$node;
            foreach ($node['replies'] as &$reply) {
                $walk($reply);
            }
        };

        foreach ($roots as &$root) {
            $walk($root);
        }

        if ($flat !== []) {
            $translator->translateItems($flat, ['body'], $lang);
        }
    }

    /**
     * @deprecated Use serializedTreeForPhoto()
     * @return list<array<string, mixed>>
     */
    public function serializedForPhoto(int $photoId, ?TranslationService $translator = null, ?string $lang = null): array
    {
        return $this->serializedTreeForPhoto($photoId, $translator, $lang);
    }
}
