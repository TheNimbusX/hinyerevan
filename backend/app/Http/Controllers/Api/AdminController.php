<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacebookIncomingPost;
use App\Models\FeedbackMessage;
use App\Models\NewsItem;
use App\Models\Page;
use App\Models\Photo;
use App\Models\PhotoView;
use App\Models\User;
use App\Jobs\PublishPhotoToFacebookJob;
use App\Services\Facebook\FacebookIncomingService;
use App\Services\Facebook\FacebookPublishService;
use App\Services\LegacyPhotoStorage;
use App\Services\LegacySchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = trim((string) $request->search);
                $query->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', '%' . $term . '%');
                    if (ctype_digit($term)) {
                        $inner->orWhere('id', (int) $term);
                    }
                });
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 30), 100));

        return $photos->through(fn (Photo $photo) => $this->serializeAdminPhoto($photo));
    }

    public function showPhoto(Photo $photo)
    {
        abort_unless($photo->id > 0, 404);

        return $this->serializeAdminPhoto($photo->load(['author', 'viewCounter']));
    }

    public function updatePhoto(Request $request, Photo $photo, LegacyPhotoStorage $storage)
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
            'is_winter' => ['sometimes', 'boolean'],
            'media_type' => ['sometimes', 'in:photo,video'],
            'file' => ['nullable', 'image', 'max:10240'],
            'video' => ['nullable', 'string', 'max:512', 'regex:#^https?://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)[\w\-]{6,}#i'],
            'publish_to_facebook' => ['nullable', 'boolean'],
            'facebook_comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'video.regex' => 'The video link must be a valid YouTube URL.',
        ]);

        if (array_key_exists('published', $data)) {
            $data['published'] = (int) filter_var($data['published'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        foreach (['needs_location_review', 'is_winter'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (int) filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        $hasMediaChange = $request->hasFile('file')
            || $request->filled('video')
            || $request->has('media_type');

        if ($hasMediaChange) {
            $mediaType = $data['media_type'] ?? ($photo->video ? 'video' : 'photo');
            unset($data['media_type']);

            if ($mediaType === 'video') {
                $video = trim((string) ($data['video'] ?? $photo->video ?? ''));
                abort_if($video === '', 422, 'A YouTube link is required for video posts.');

                if ($video !== $photo->video || ! $photo->video) {
                    $data['file_id'] = $storage->storeYoutubeThumbnail($video, config('app.key'), $photo->file_id);
                }
                $data['video'] = $video;
            } else {
                $data['video'] = null;
                if ($request->hasFile('file')) {
                    $storage->replaceUpload($request->file('file'), $photo->file_id);
                } elseif ($photo->video) {
                    abort(422, 'Upload a photo file when switching from video.');
                }
            }
        } else {
            unset($data['media_type'], $data['video']);
        }

        unset($data['file']);

        $publishToFacebook = filter_var($request->input('publish_to_facebook', false), FILTER_VALIDATE_BOOLEAN);
        if ($publishToFacebook && ! $photo->facebook_post_id) {
            $data['facebook_publish_pending'] = 1;
            $data['facebook_comment'] = trim((string) $request->input('facebook_comment', '')) ?: null;
        }

        $wasPublished = (bool) $photo->published;

        $photo->fill($data)->save();

        $facebookPublishError = null;
        if ((bool) $photo->published && $photo->facebook_publish_pending) {
            PublishPhotoToFacebookJob::dispatchSync($photo->id);
            $photo->refresh();
            if ($photo->facebook_publish_pending && ! $photo->facebook_post_id) {
                $facebookPublishError = $this->facebookPublish->publishIfPending($photo);
            }
        }

        PhotoController::flushMarkersCache();

        $payload = $this->serializeAdminPhoto($photo->fresh(['author', 'viewCounter']));
        if ($facebookPublishError) {
            $payload['facebook_publish_error'] = $facebookPublishError;
        } elseif ($photo->facebook_post_id || $photo->facebook_post_url) {
            $payload['facebook_publish_done'] = true;
        }

        return $payload;
    }

    public function deletePhoto(Photo $photo)
    {
        abort_unless($photo->id > 0, 404);
        $originalId = $photo->id;
        $photo->id = -abs($photo->id);
        $photo->save();

        // If this photo came from a Facebook import, put that post back in the queue so a
        // rejected/deleted import can be handled again (low cost of a mistake).
        if (Schema::hasTable('facebook_incoming_posts')) {
            FacebookIncomingPost::query()
                ->where('photo_id', $originalId)
                ->update([
                    'status' => FacebookIncomingPost::STATUS_PENDING,
                    'photo_id' => null,
                ]);
        }

        PhotoController::flushMarkersCache();

        return response()->noContent();
    }

    /** Inbox of posts made directly on the Facebook Page, awaiting admin import. */
    public function facebookIncoming(Request $request, FacebookIncomingService $incoming)
    {
        if (! Schema::hasTable('facebook_incoming_posts')) {
            return ['data' => [], 'configured' => false, 'counts' => ['pending' => 0, 'imported' => 0, 'dismissed' => 0]];
        }

        // Best-effort live refresh so the admin sees fresh posts on open.
        if ($request->boolean('refresh')) {
            $incoming->fetchAndStore();
        }

        $allowed = [
            FacebookIncomingPost::STATUS_PENDING,
            FacebookIncomingPost::STATUS_IMPORTED,
            FacebookIncomingPost::STATUS_DISMISSED,
        ];
        $status = (string) $request->query('status', FacebookIncomingPost::STATUS_PENDING);
        if (! in_array($status, $allowed, true)) {
            $status = FacebookIncomingPost::STATUS_PENDING;
        }

        $rows = FacebookIncomingPost::query()
            ->where('status', $status)
            ->orderByDesc('posted_at')
            ->limit(100)
            ->get();

        $counts = FacebookIncomingPost::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'configured' => $incoming->isConfigured(),
            'status' => $status,
            'counts' => [
                'pending' => (int) ($counts[FacebookIncomingPost::STATUS_PENDING] ?? 0),
                'imported' => (int) ($counts[FacebookIncomingPost::STATUS_IMPORTED] ?? 0),
                'dismissed' => (int) ($counts[FacebookIncomingPost::STATUS_DISMISSED] ?? 0),
            ],
            'data' => $rows->map(fn (FacebookIncomingPost $p) => [
                'id' => $p->id,
                'facebook_post_id' => $p->facebook_post_id,
                'message' => $p->message,
                'image_url' => $p->image_url,
                'permalink_url' => $p->permalink_url,
                'posted_at' => optional($p->posted_at)->toISOString(),
                'photo_id' => $p->photo_id,
                'status' => $p->status,
            ])->all(),
        ];
    }

    public function dismissFacebookIncoming(FacebookIncomingPost $post)
    {
        $post->forceFill(['status' => FacebookIncomingPost::STATUS_DISMISSED])->save();

        return response()->noContent();
    }

    /** Return an imported/dismissed post back to the pending queue so it can be handled again. */
    public function restoreFacebookIncoming(FacebookIncomingPost $post)
    {
        $post->forceFill([
            'status' => FacebookIncomingPost::STATUS_PENDING,
            'photo_id' => null,
        ])->save();

        return response()->noContent();
    }

    /**
     * Turn an incoming Facebook post into an unpublished draft photo. The admin then
     * fills in year / location / direction in the normal photo editor and publishes.
     */
    public function importFacebookIncoming(Request $request, FacebookIncomingPost $post, LegacyPhotoStorage $storage)
    {
        abort_unless(LegacySchema::photosReady(), 503, 'Legacy database is not connected yet.');

        if ($post->status === FacebookIncomingPost::STATUS_IMPORTED && $post->photo_id) {
            return ['photo_id' => $post->photo_id, 'already' => true];
        }

        $fileId = $storage->storeImageFromUrl((string) $post->image_url, (string) config('app.key'));
        abort_if($fileId === null, 422, 'Could not download the Facebook image.');

        $title = trim((string) ($post->message ?? ''));
        $title = $title !== '' ? mb_substr(preg_split('/\r\n|\r|\n/', $title)[0], 0, 255) : 'Импорт из Facebook';

        $photo = Photo::query()->create([
            'title' => $title,
            'year' => 0, // admin must set a real year before publishing
            'lat' => 40.179136,
            'lng' => 44.511623,
            'direction' => 0,
            'datetime' => $post->posted_at ?? now(),
            'user' => $this->siteAuthorUnique(), // author shows as "HinYerevan.com"
            'published' => 0, // draft — appears in the pending/unpublished list
            'file_id' => $fileId,
            'needs_location_review' => true,
            // Already lives on Facebook — link it so likes/comments sync and the editor
            // hides the "publish to Facebook" option for this photo.
            'facebook_post_id' => $post->facebook_post_id,
            'facebook_post_url' => $post->permalink_url,
            'facebook_publish_pending' => 0,
        ]);

        PhotoView::query()->create(['photo_id' => $photo->id, 'count' => 0]);

        $post->forceFill([
            'status' => FacebookIncomingPost::STATUS_IMPORTED,
            'photo_id' => $photo->id,
        ])->save();

        // Pull the post's likes/comments right away so they show on the site.
        try {
            $this->facebookPublish->syncPostStats($photo->fresh());
        } catch (\Throwable) {
            // best-effort; the scheduled job will retry
        }

        return ['photo_id' => $photo->id, 'already' => false];
    }

    /** A shared "HinYerevan.com" author used for posts imported from the Facebook Page. */
    private function siteAuthorUnique(): string
    {
        $unique = 'hinyerevan-site';

        User::query()->firstOrCreate(
            ['unique' => $unique],
            [
                'uid' => 'hinyerevan-site',
                'network' => 'hinyerevan',
                'first_name' => 'HinYerevan.com',
                'last_name' => '',
                'email' => '',
                'identity' => 'HinYerevan.com',
                'bdate' => '1970-01-01',
                'sex' => User::SEX_UNSET,
                'photo' => 'http://www.hinyerevan.com/photos/user.png',
                'type' => User::TYPE_USER,
                'password' => md5(uniqid('hin-site', true)),
                'last_ip' => $this->ipOrNull(),
            ],
        );

        return $unique;
    }

    private function ipOrNull(): ?string
    {
        return request()?->ip();
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
            ->when(
                $request->filled('type') && in_array((int) $request->input('type'), [User::TYPE_USER, User::TYPE_BLOCKED, User::TYPE_ADMIN], true),
                fn ($query) => $query->where('type', (int) $request->input('type')),
            )
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
            'sex' => ['sometimes', 'integer', 'in:0,1,2'],
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

    public function deleteUser(Request $request, User $user)
    {
        abort_unless($user->id > 0, 404);

        $actor = $request->user();
        if ($actor && (int) $actor->id === (int) $user->id) {
            throw ValidationException::withMessages([
                'user' => __('Cannot delete your own account.'),
            ]);
        }

        if ($user->isAdmin()) {
            $adminCount = User::query()
                ->where('type', User::TYPE_ADMIN)
                ->where('id', '>', 0)
                ->count();

            if ($adminCount <= 1) {
                throw ValidationException::withMessages([
                    'user' => __('Cannot delete the last administrator.'),
                ]);
            }
        }

        $user->tokens()->delete();

        if (Schema::hasTable('favorites')) {
            DB::table('favorites')->where('user_unique', $user->unique)->delete();
        }

        // Photos and comments stay on the site; ownership is stored by users.unique string.
        $user->delete();

        return response()->noContent();
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
            'is_winter' => (bool) $photo->is_winter,
            'video' => $photo->video,
            'has_video' => (bool) $photo->video,
            'datetime' => optional($photo->datetime)->toISOString(),
            'user' => $photo->user,
            'views' => $photo->viewCounter?->count ?? 0,
            'images' => $photo->image_urls,
            'facebook_post_id' => $photo->facebook_post_id,
            'facebook_post_url' => $photo->facebook_post_url,
            'facebook_publish_pending' => (bool) $photo->facebook_publish_pending,
            'facebook_comment' => $photo->facebook_comment,
            'author' => $photo->author ? [
                'id' => $photo->author->id,
                'uid' => $photo->author->uid,
                'name' => $photo->author->name,
                'email' => $photo->author->email,
            ] : null,
        ];
    }
}
