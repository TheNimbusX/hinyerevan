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
    public static function serializeFlat(iterable $comments): array
    {
        $collection = $comments instanceof Collection ? $comments : collect($comments);
        $authors = self::resolveAuthors($collection);

        return $collection
            ->map(fn (Comment $comment) => self::serializeOne($comment, $authors))
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
}
