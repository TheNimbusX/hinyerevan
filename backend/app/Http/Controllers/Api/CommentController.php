<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\NewsItem;
use App\Models\Photo;
use App\Models\PhotoFacebookComment;
use App\Services\CommentPresenter;
use App\Services\DemoData;
use App\Services\Facebook\FacebookCommentSyncService;
use App\Services\LegacySchema;
use App\Services\TranslationService;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(
        private TranslationService $translator,
        private FacebookCommentSyncService $facebookComments,
    ) {
    }

    private function newsPostId(int $news): string
    {
        return 'news-' . $news;
    }

    private function lang(Request $request): ?string
    {
        return $this->translator->targetLanguage($request->query('lang'));
    }

    public function index(Request $request, int $photo)
    {
        if (! LegacySchema::commentsReady()) {
            return DemoData::findPhoto($photo)['comments'] ?? [];
        }

        $photoModel = Photo::query()->findOrFail($photo);
        abort_unless($photoModel->id > 0 && $photoModel->published, 404);

        if ($photoModel->facebook_post_id) {
            try {
                $this->facebookComments->syncForPhoto($photoModel);
            } catch (\Throwable) {
                // non-fatal
            }
        }

        $comments = Comment::query()
            ->with('author:id,unique,uid,first_name,last_name,photo,identity,email')
            ->alive()
            ->where('post_id', $photoModel->id)
            ->oldest('datetime')
            ->get();

        $lang = $this->lang($request);

        return CommentPresenter::mergePhotoThreads(
            $comments,
            fn () => $this->facebookComments->serializedTreeForPhoto($photoModel->id, $this->translator, $lang),
            $this->translator,
            $lang,
        );
    }

    public function store(Request $request, int $photo)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        $photo = Photo::query()->findOrFail($photo);
        abort_unless($photo->id > 0 && $photo->published, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'to' => ['nullable', 'integer', 'min:0'],
            'reply_to_facebook_comment_id' => ['nullable', 'string', 'max:64'],
        ]);

        $parentId = (int) ($data['to'] ?? 0);
        $replyToFacebook = trim((string) ($data['reply_to_facebook_comment_id'] ?? ''));

        if ($replyToFacebook !== '') {
            abort_unless(
                PhotoFacebookComment::query()
                    ->where('photo_id', $photo->id)
                    ->where('facebook_comment_id', $replyToFacebook)
                    ->exists(),
                422,
                'Facebook comment not found for this photo.',
            );
            $parentId = 0;
        } elseif ($parentId > 0) {
            abort_unless(
                Comment::query()
                    ->alive()
                    ->where('post_id', $photo->id)
                    ->where('id', $parentId)
                    ->exists(),
                422,
                'Parent comment not found.',
            );
        }

        $comment = Comment::query()->create([
            'post_id' => $photo->id,
            'body' => $data['body'],
            'user_unique' => $request->user()->unique,
            'datetime' => now(),
            'to' => $parentId,
            'reply_to_facebook_comment_id' => $replyToFacebook !== '' ? $replyToFacebook : null,
        ]);

        $comment->load('author:id,unique,uid,first_name,last_name,photo,identity,email');

        $payload = CommentPresenter::serializeFlat(collect([$comment]), $this->translator, $this->lang($request))[0];
        $payload['source'] = 'site';
        $payload['replies'] = [];

        return response()->json($payload, 201);
    }

    public function newsIndex(Request $request, int $news)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        $newsItem = NewsItem::query()->findOrFail($news);
        abort_unless($newsItem->id > 0 && $newsItem->published, 404);

        $comments = Comment::query()
            ->with('author:id,unique,uid,first_name,last_name,photo,identity,email')
            ->alive()
            ->where('post_id', $this->newsPostId($newsItem->id))
            ->oldest('datetime')
            ->get();

        return CommentPresenter::serializeFlat($comments, $this->translator, $this->lang($request));
    }

    public function newsStore(Request $request, int $news)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        $newsItem = NewsItem::query()->findOrFail($news);
        abort_unless($newsItem->id > 0 && $newsItem->published, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $comment = Comment::query()->create([
            'post_id' => $this->newsPostId($newsItem->id),
            'body' => $data['body'],
            'user_unique' => $request->user()->unique,
            'datetime' => now(),
            'to' => 0,
        ]);

        $comment->load('author:id,unique,uid,first_name,last_name,photo,identity,email');

        return response()->json(
            CommentPresenter::serializeFlat(collect([$comment]), $this->translator, $this->lang($request))[0],
            201,
        );
    }

    public function destroy(Comment $comment)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        abort_unless($comment->id > 0, 404);

        $this->softDelete($comment);

        return response()->noContent();
    }

    /** Authenticated users may delete their own comments (admins delete via the admin route). */
    public function destroyOwn(Request $request, Comment $comment)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        abort_unless($comment->id > 0, 404);

        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $comment->user_unique === $user->unique), 403);

        $this->softDelete($comment);

        return response()->noContent();
    }

    private function softDelete(Comment $comment): void
    {
        // Legacy soft-delete convention: negative id rows are treated as removed.
        $comment->id = -abs($comment->id);
        $comment->save();
    }
}
