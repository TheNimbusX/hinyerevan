<?php

namespace App\Services\Facebook;

use App\Models\Photo;
use App\Models\PhotoFacebookComment;
use App\Services\TranslationService;
use Illuminate\Support\Facades\Log;

class FacebookCommentSyncService
{
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
                'fields' => 'id,message,created_time,from{name}',
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

                $fbId = (string) ($row['id'] ?? '');
                $message = trim((string) ($row['message'] ?? ''));
                if ($fbId === '' || $message === '') {
                    continue;
                }

                $seen[] = $fbId;
                $authorName = trim((string) ($row['from']['name'] ?? 'Facebook'));

                PhotoFacebookComment::query()->updateOrCreate(
                    [
                        'photo_id' => $photo->id,
                        'facebook_comment_id' => $fbId,
                    ],
                    [
                        'author_name' => $authorName !== '' ? $authorName : 'Facebook',
                        'body' => $message,
                        'commented_at' => isset($row['created_time']) ? $row['created_time'] : null,
                        'synced_at' => now(),
                    ],
                );
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
     * @return list<array<string, mixed>>
     */
    public function serializedForPhoto(int $photoId, ?TranslationService $translator = null, ?string $lang = null): array
    {
        $rows = PhotoFacebookComment::query()
            ->where('photo_id', $photoId)
            ->orderBy('commented_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PhotoFacebookComment $row) => [
                'id' => 'fb_' . $row->facebook_comment_id,
                'body' => $row->body,
                'datetime' => optional($row->commented_at)->toISOString(),
                'user_unique' => null,
                'to' => null,
                'source' => 'facebook',
                'facebook_comment_id' => $row->facebook_comment_id,
                'author' => [
                    'name' => $row->author_name ?: 'Facebook',
                    'display_name' => $row->author_name ?: 'Facebook',
                ],
            ])
            ->values()
            ->all();

        if ($translator && $lang) {
            return $translator->translateItems($rows, ['body'], $lang);
        }

        return $rows;
    }
}
