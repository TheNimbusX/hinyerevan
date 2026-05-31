<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\NewsItem;
use App\Models\Photo;
use App\Services\CommentPresenter;
use App\Services\DemoData;
use App\Services\LegacySchema;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    private function newsPostId(int $news): string
    {
        return 'news-' . $news;
    }

    public function index(int $photo)
    {
        if (! LegacySchema::commentsReady()) {
            return DemoData::findPhoto($photo)['comments'] ?? [];
        }

        $photo = Photo::query()->findOrFail($photo);
        abort_unless($photo->id > 0 && $photo->published, 404);

        $comments = Comment::query()
            ->with(['author:id,unique,uid,first_name,last_name,photo,identity,email', 'replies.author:id,unique,uid,first_name,last_name,photo,identity,email'])
            ->alive()
            ->where('post_id', $photo->id)
            ->where(function ($query) {
                $query->whereNull('to')->orWhere('to', 0);
            })
            ->oldest('datetime')
            ->get();

        return CommentPresenter::serializeFlat($comments);
    }

    public function store(Request $request, int $photo)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        $photo = Photo::query()->findOrFail($photo);
        abort_unless($photo->id > 0 && $photo->published, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'to' => ['nullable', 'integer', 'min:0'],
        ]);

        $comment = Comment::query()->create([
            'post_id' => $photo->id,
            'body' => $data['body'],
            'user_unique' => $request->user()->unique,
            'datetime' => now(),
            'to' => $data['to'] ?? 0,
        ]);

        $comment->load('author:id,unique,uid,first_name,last_name,photo,identity,email');

        return response()->json(CommentPresenter::serializeFlat(collect([$comment]))[0], 201);
    }

    public function newsIndex(int $news)
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

        return CommentPresenter::serializeFlat($comments);
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

        return response()->json(CommentPresenter::serializeFlat(collect([$comment]))[0], 201);
    }

    public function destroy(Comment $comment)
    {
        abort_unless(LegacySchema::commentsReady(), 503, 'Legacy comments table is not connected yet.');
        abort_unless($comment->id > 0, 404);

        $comment->id = -abs($comment->id);
        $comment->save();

        return response()->noContent();
    }
}
