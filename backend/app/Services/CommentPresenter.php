<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Collection;

class CommentPresenter
{
    /**
     * @param  iterable<Comment>  $comments
     * @return list<array<string, mixed>>
     */
    public static function serializeFlat(iterable $comments, ?TranslationService $translator = null, ?string $lang = null): array
    {
        $collection = $comments instanceof Collection ? $comments : collect($comments);
        $authors = self::resolveAuthors($collection);
        $rows = $collection
            ->map(fn (Comment $comment) => self::serializeOne($comment, $authors))
            ->values()
            ->all();

        if (! $translator || ! $lang) {
            return $rows;
        }

        return $translator->translateItems($rows, ['body'], $lang);
    }

    /**
     * @param  iterable<Comment>  $comments
     * @return array{roots: list<array<string, mixed>>, pendingFacebookReplies: array<string, list<array<string, mixed>>>}
     */
    public static function serializeSiteTree(iterable $comments, ?TranslationService $translator = null, ?string $lang = null): array
    {
        $collection = $comments instanceof Collection ? $comments : collect($comments);

        if ($collection->isEmpty()) {
            return ['roots' => [], 'pendingFacebookReplies' => []];
        }

        $authors = self::resolveAuthors($collection);
        $nodes = [];

        foreach ($collection as $comment) {
            $nodes[$comment->id] = array_merge(self::serializeOne($comment, $authors), [
                'source' => 'site',
                'replies' => [],
            ]);
        }

        $roots = [];
        $pendingFacebookReplies = [];

        foreach ($collection as $comment) {
            $parentId = (int) $comment->to;
            $fbParent = trim((string) ($comment->reply_to_facebook_comment_id ?? ''));

            if ($parentId > 0 && isset($nodes[$parentId])) {
                $nodes[$parentId]['replies'][] = &$nodes[$comment->id];
            } elseif ($fbParent !== '') {
                $pendingFacebookReplies[$fbParent][] = &$nodes[$comment->id];
            } else {
                $roots[] = &$nodes[$comment->id];
            }
        }

        self::sortRepliesRecursive($roots);

        if ($translator && $lang) {
            self::translateTree($roots, $translator, $lang);
            foreach ($pendingFacebookReplies as &$items) {
                self::translateTree($items, $translator, $lang);
            }
        }

        return [
            'roots' => array_values($roots),
            'pendingFacebookReplies' => $pendingFacebookReplies,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $facebookRoots
     * @param  array<string, list<array<string, mixed>>>  $pendingFacebookReplies
     */
    public static function attachPendingFacebookReplies(array &$facebookRoots, array $pendingFacebookReplies): void
    {
        if ($pendingFacebookReplies === []) {
            return;
        }

        $index = [];
        $walk = function (array &$nodes) use (&$walk, &$index): void {
            foreach ($nodes as &$node) {
                if (($node['source'] ?? '') === 'facebook' && ! empty($node['facebook_comment_id'])) {
                    $index[$node['facebook_comment_id']] = &$node;
                }
                if (! empty($node['replies'])) {
                    $walk($node['replies']);
                }
            }
        };
        $walk($facebookRoots);

        foreach ($pendingFacebookReplies as $fbId => $replies) {
            if (isset($index[$fbId])) {
                foreach ($replies as $reply) {
                    $index[$fbId]['replies'][] = $reply;
                }
                self::sortRepliesRecursive($index[$fbId]['replies']);
            }
        }
    }

    /**
     * @param  iterable<Comment>  $siteComments
     * @param  callable(): list<array<string, mixed>>  $facebookTreeFactory
     * @return list<array<string, mixed>>
     */
    public static function mergePhotoThreads(
        iterable $siteComments,
        callable $facebookTreeFactory,
        ?TranslationService $translator = null,
        ?string $lang = null,
    ): array {
        $site = self::serializeSiteTree($siteComments, $translator, $lang);
        $facebook = $facebookTreeFactory();
        self::attachPendingFacebookReplies($facebook, $site['pendingFacebookReplies']);

        return collect($site['roots'])
            ->concat($facebook)
            ->sortBy(fn (array $row) => $row['datetime'] ?? '')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Comment>  $comments
     * @return Collection<string, User>
     */
    private static function resolveAuthors(Collection $comments): Collection
    {
        $keys = $comments
            ->pluck('user_unique')
            ->filter()
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        $byUnique = User::query()
            ->whereIn('unique', $keys)
            ->get()
            ->keyBy('unique');

        $missing = $keys->diff($byUnique->keys());

        $byUid = $missing->isEmpty()
            ? collect()
            : User::query()->whereIn('uid', $missing)->get()->keyBy('uid');

        return $keys->mapWithKeys(function (string $key) use ($byUnique, $byUid) {
            $user = $byUnique->get($key) ?? $byUid->get($key);

            return $user ? [$key => $user] : [];
        });
    }

    /**
     * @param  Collection<string, User>  $authors
     * @return array<string, mixed>
     */
    private static function serializeOne(Comment $comment, Collection $authors): array
    {
        $author = $comment->author
            ?? ($comment->user_unique ? $authors->get($comment->user_unique) : null);

        return [
            'id' => $comment->id,
            'body' => CommentBodyFormatter::display((string) $comment->body),
            'datetime' => optional($comment->datetime)->toISOString(),
            'user_unique' => $comment->user_unique,
            'to' => $comment->to,
            'reply_to_facebook_comment_id' => $comment->reply_to_facebook_comment_id,
            'author' => $author ? self::serializeAuthor($author) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeAuthor(User $user): array
    {
        return [
            'id' => $user->id,
            'unique' => $user->unique,
            'uid' => $user->uid,
            'name' => $user->name,
            'display_name' => $user->display_name,
            'photo' => $user->photo,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function sortRepliesRecursive(array &$items): void
    {
        usort($items, fn (array $a, array $b) => strcmp($a['datetime'] ?? '', $b['datetime'] ?? ''));

        foreach ($items as &$item) {
            if (! empty($item['replies'])) {
                self::sortRepliesRecursive($item['replies']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $roots
     */
    private static function translateTree(array &$roots, TranslationService $translator, string $lang): void
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
}
