<?php

namespace App\Services\Facebook;

use App\Models\Photo;
use App\Models\PhotoFacebookComment;
use App\Services\TranslationService;
use Illuminate\Support\Facades\Log;

class FacebookCommentSyncService
{
    private const COMMENT_FIELDS = 'id,message,created_time,from{name,picture},comments.limit(25){id,message,created_time,from{name,picture},comments.limit(15){id,message,created_time,from{name,picture}}}';

    public function __construct(
        private readonly FacebookGraphClient $graph,
    ) {}

    public function syncForPhoto(Photo $photo): void
    {
        $token = trim((string) config('services.facebook.page_access_token', ''));
        if (! $photo->facebook_post_id || $token === '') {
            return;
        }
        $postId = trim((string) $photo->facebook_post_id);

        try {
            $response = $this->graph->get($postId . '/comments', [
                'fields' => self::COMMENT_FIELDS,
                'limit' => 50,
                'access_token' => $token,
            ]);

            if (! $response->ok()) {
                Log::warning('Facebook comments sync failed', [
                    'photo_id' => $photo->id,
                    'body' => $response->body(),
                ]);

                return;
            }

            $rows = $response->json('data') ?? [];
            $seen = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->ingestCommentRow($photo, $row, null, $seen);
            }

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
    private function ingestCommentRow(Photo $photo, array $row, ?string $parentFbId, array &$seen): void
    {
        $fbId = (string) ($row['id'] ?? '');
        $message = trim((string) ($row['message'] ?? ''));
        if ($fbId === '' || $message === '') {
            return;
        }

        $seen[] = $fbId;
        $authorName = trim((string) ($row['from']['name'] ?? 'Facebook'));

        PhotoFacebookComment::query()->updateOrCreate(
            [
                'photo_id' => $photo->id,
                'facebook_comment_id' => $fbId,
            ],
            [
                'parent_facebook_comment_id' => $parentFbId,
                'author_name' => $authorName !== '' ? $authorName : 'Facebook',
                'author_picture' => $this->extractPictureUrl($row['from'] ?? null),
                'body' => $message,
                'commented_at' => isset($row['created_time']) ? $row['created_time'] : null,
                'synced_at' => now(),
            ],
        );

        foreach ($row['comments']['data'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }
            $this->ingestCommentRow($photo, $child, $fbId, $seen);
        }
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
                $nodes[$parentId]['replies'][] = $nodes[$row->facebook_comment_id];
            } else {
                $roots[] = $nodes[$row->facebook_comment_id];
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

        if ($flat === []) {
            return;
        }

        $translator->translateItems($flat, ['body'], $lang);
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
