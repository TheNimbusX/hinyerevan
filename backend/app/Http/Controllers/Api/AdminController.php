<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedbackMessage;
use App\Models\NewsItem;
use App\Models\Page;
use App\Models\Photo;
use App\Models\User;
use App\Jobs\PublishPhotoToFacebookJob;
use App\Services\Facebook\FacebookPublishService;
use App\Services\LegacySchema;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(
        private readonly FacebookPublishService $facebookPublish,
    ) {}
    public function dashboard()
    {
        if (! LegacySchema::photosReady()) {
            return [
                'photos_pending' => 0,
                'photos_published' => 0,
                'photos_total' => 0,
                'users_total' => 0,
                'news_total' => 0,
                'feedback_unread' => 0,
            ];
        }

        $photoTotals = Photo::query()
            ->alive()
            ->selectRaw('COUNT(*) as total, SUM(published = 1) as published_count, SUM(published = 0) as pending_count')
            ->first();

        return [
            'photos_pending' => (int) ($photoTotals?->pending_count ?? 0),
            'photos_published' => (int) ($photoTotals?->published_count ?? 0),
            'photos_total' => (int) ($photoTotals?->total ?? 0),
            'users_total' => LegacySchema::usersReady()
                ? (int) User::query()->where('id', '>', 0)->count()
                : 0,
            'news_total' => LegacySchema::newsReady()
                ? (int) NewsItem::query()->alive()->count()
                : 0,
            'feedback_unread' => LegacySchema::feedbackReady()
                ? (int) FeedbackMessage::query()->unread()->count()
                : 0,
        ];
    }

    public function photos(Request $request)
    {
        if (! LegacySchema::photosReady()) {
            return LegacySchema::emptyPaginator($request, min((int) $request->integer('per_page', 30), 100));
        }

        $photos = Photo::query()
            ->with(['author:id,unique,uid,first_name,last_name,email', 'viewCounter'])
            ->alive()
            ->when($request->filled('status'), function ($query) use ($request) {
                if ($request->status === 'pending') {
                    $query->where('published', 0);
                } elseif ($request->status === 'published') {
                    $query->where('published', 1);
                } elseif ($request->status === 'review') {
                    $query->where('needs_location_review', 1);
                }
            })
            ->when(
                ! $request->filled('status') && array_key_exists('published', $request->query()),
                function ($query) use ($request) {
                    $published = $request->query('published');
                    $query->where('published', in_array((string) $published, ['1', 'true', 'yes'], true) ? 1 : 0);
                }
            )
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return $photos->through(fn (Photo $photo) => $this->serializeAdminPhoto($photo));
    }

    public function updatePhoto(Request $request, Photo $photo)
    {
        abort_unless($photo->id > 0, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'year' => ['sometimes', 'required', 'integer', 'min:1', 'max:2100'],
            'lat' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'direction' => ['sometimes', 'required', 'integer', 'between:0,8'],
            'published' => ['sometimes'],
            'needs_location_review' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('published', $data)) {
            $data['published'] = (int) filter_var($data['published'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (array_key_exists('needs_location_review', $data)) {
            $data['needs_location_review'] = (int) filter_var($data['needs_location_review'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        $wasPublished = (bool) $photo->published;

        $photo->fill($data)->save();

        if (! $wasPublished && (bool) $photo->published && $photo->facebook_publish_pending) {
            PublishPhotoToFacebookJob::dispatchAfterResponse($photo->id);
        }

        PhotoController::flushMarkersCache();

        $payload = $this->serializeAdminPhoto($photo->fresh(['author', 'viewCounter']));
        if (! $wasPublished && (bool) $photo->published && $photo->facebook_publish_pending) {
            $payload['facebook_publish_queued'] = true;
        }

        return $payload;
    }

    public function deletePhoto(Photo $photo)
    {
        abort_unless($photo->id > 0, 404);
        $photo->id = -abs($photo->id);
        $photo->save();

        PhotoController::flushMarkersCache();

        return response()->noContent();
    }

    public function users(Request $request)
    {
        if (! LegacySchema::usersReady()) {
            return LegacySchema::emptyPaginator($request, min((int) $request->integer('per_page', 30), 100));
        }

        return User::query()
            ->where('id', '>', 0)
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%' . $request->search . '%';
                $query->where(fn ($inner) => $inner
                    ->where('uid', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 30), 100));
    }

    public function updateUser(Request $request, User $user)
    {
        abort_unless($user->id > 0, 404);

        $data = $request->validate([
            'type' => ['sometimes', 'integer', 'in:0,1,5'],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email', 'max:190', 'unique:users,email,' . $user->id],
            'identity' => ['nullable', 'string', 'max:80'],
            'sex' => ['sometimes', 'integer', 'in:0,1'],
            'birth_day' => ['sometimes', 'integer', 'between:1,31'],
            'birth_month' => ['sometimes', 'integer', 'between:1,12'],
            'birth_year' => ['sometimes', 'integer', 'between:1900,2026'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (isset($data['password'])) {
            $data['password'] = md5($data['password']);
        }

        if (isset($data['birth_year'], $data['birth_month'], $data['birth_day'])) {
            $data['bdate'] = sprintf(
                '%04d-%02d-%02d',
                (int) $data['birth_year'],
                (int) $data['birth_month'],
                (int) $data['birth_day'],
            );
            unset($data['birth_day'], $data['birth_month'], $data['birth_year']);
        }

        $user->fill($data)->save();

        return $user->fresh();
    }

    public function news(Request $request)
    {
        if (! LegacySchema::newsReady()) {
            return LegacySchema::emptyPaginator($request, min((int) $request->integer('per_page', 30), 100));
        }

        return NewsItem::query()
            ->alive()
            ->latest('date')
            ->paginate(min((int) $request->integer('per_page', 30), 100));
    }

    public function storeNews(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'date' => ['nullable', 'date'],
            'published' => ['nullable', 'boolean'],
        ]);

        return response()->json(NewsItem::query()->create([
            'title' => $data['title'],
            'content' => $data['content'],
            'date' => $data['date'] ?? now(),
            'published' => array_key_exists('published', $data)
                ? (int) filter_var($data['published'], FILTER_VALIDATE_BOOLEAN)
                : 1,
        ]), 201);
    }

    public function updateNews(Request $request, NewsItem $news)
    {
        abort_unless($news->id > 0, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'required', 'string'],
            'date' => ['sometimes', 'required', 'date'],
            'published' => ['sometimes', 'boolean'],
        ]);

        $news->fill($data)->save();

        return $news->fresh();
    }

    public function deleteNews(NewsItem $news)
    {
        abort_unless($news->id > 0, 404);
        $news->published = 0;
        $news->id = -abs($news->id);
        $news->save();

        return response()->noContent();
    }

    public function pages()
    {
        if (! LegacySchema::pagesReady()) {
            return [];
        }

        return Page::query()->alive()->orderBy('title')->get();
    }

    public function feedback(Request $request)
    {
        if (! LegacySchema::feedbackReady()) {
            return LegacySchema::emptyPaginator($request, min((int) $request->integer('per_page', 30), 100));
        }

        return FeedbackMessage::query()
            ->when($request->filled('unread'), function ($query) use ($request) {
                if (filter_var($request->query('unread'), FILTER_VALIDATE_BOOLEAN)) {
                    $query->unread();
                }
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 30), 100))
            ->through(fn (FeedbackMessage $message) => $this->serializeFeedback($message));
    }

    public function markFeedbackRead(FeedbackMessage $feedback)
    {
        $feedback->markRead();

        return $this->serializeFeedback($feedback->fresh());
    }

    public function deleteFeedback(FeedbackMessage $feedback)
    {
        $feedback->delete();

        return response()->noContent();
    }

    public function storePage(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'alias' => ['required', 'string', 'max:190', 'unique:pages,alias'],
            'content' => ['required', 'string'],
        ]);

        return response()->json(Page::query()->create($data), 201);
    }

    public function updatePage(Request $request, Page $page)
    {
        abort_unless($page->id > 0, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'alias' => ['sometimes', 'required', 'string', 'max:190', 'unique:pages,alias,' . $page->id],
            'content' => ['sometimes', 'required', 'string'],
        ]);

        $page->fill($data)->save();

        return $page->fresh();
    }

    private function serializeFeedback(FeedbackMessage $message): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'content' => $message->content,
            'read' => (bool) $message->read_at,
            'created_at' => optional($message->created_at)->toISOString(),
        ];
    }

    private function serializeAdminPhoto(Photo $photo): array
    {
        return [
            'id' => $photo->id,
            'title' => $photo->title,
            'year' => $photo->year,
            'lat' => $photo->lat,
            'lng' => $photo->lng,
            'direction' => $photo->direction,
            'published' => (bool) $photo->published,
            'needs_location_review' => (bool) $photo->needs_location_review,
            'datetime' => optional($photo->datetime)->toISOString(),
            'user' => $photo->user,
            'views' => $photo->viewCounter?->count ?? 0,
            'images' => $photo->image_urls,
            'author' => $photo->author ? [
                'id' => $photo->author->id,
                'uid' => $photo->author->uid,
                'name' => $photo->author->name,
                'email' => $photo->author->email,
            ] : null,
        ];
    }
}
