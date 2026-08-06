<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Photo;
use App\Models\PhotoView;
use App\Services\CommentPresenter;
use App\Services\DemoData;
use App\Jobs\PublishPhotoToFacebookJob;
use App\Models\PhotoFacebookComment;
use App\Services\Facebook\FacebookCommentSyncService;
use App\Services\Facebook\FacebookPublishService;
use App\Services\LegacyPhotoStorage;
use App\Services\LegacySchema;
use App\Support\LegacyText;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;

class PhotoController extends Controller
{
    private const MARKERS_CACHE_VERSION_KEY = 'photo_markers_version';

    public function __construct(
        private TranslationService $translator,
        private FacebookPublishService $facebookPublish,
        private FacebookCommentSyncService $facebookComments,
    ) {
    }

    public function index(Request $request)
    {
        $lang = $this->translator->targetLanguage($request->query('lang'));

        if (! LegacySchema::photosReady()) {
            $payload = DemoData::paginatedPhotos($request, min((int) $request->integer('per_page', 20), 60));
            $payload['data'] = array_map(
                fn (array $photo) => $this->serializeDemoPhoto($photo, $lang),
                $payload['data']
            );

            return $payload;
        }

        $perPage = min((int) $request->integer('per_page', 20), 60);
        $isSimpleLatest = $perPage <= 10
            && ! $request->filled('user')
            && ! $request->filled('year_from')
            && ! $request->filled('year_to')
            && ! $request->filled('search')
            && ! $request->filled('direction')
            && ! $request->boolean('winter')
            && ! $request->query('media');

        if ($isSimpleLatest) {
            $cacheKey = "photos:latest:{$perPage}:{$lang}";
            $cached = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($perPage, $lang) {
                return Photo::query()
                    ->with(['author:id,unique,uid,first_name,last_name,photo,identity', 'viewCounter'])
                    ->withCount(['comments', 'favorites as likes_count'])
                    ->published()
                    ->latest('id')
                    ->paginate($perPage)
                    ->through(fn (Photo $photo) => $this->serialize($photo, false, $lang));
            });

            return $cached;
        }

        $photos = Photo::query()
            ->with(['author:id,unique,uid,first_name,last_name,photo,identity', 'viewCounter'])
            ->withCount(['comments', 'favorites as likes_count'])
            ->published()
            ->when($request->filled('user'), fn ($query) => $query->where('user', (string) $request->string('user')))
            ->when($request->filled('year_from'), fn ($query) => $query->where('year', '>=', (int) $request->year_from))
            ->when($request->filled('year_to'), fn ($query) => $query->where('year', '<=', (int) $request->year_to))
            ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%' . $request->search . '%'))
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', (int) $request->direction))
            ->when($request->boolean('winter'), fn ($query) => $query->where('is_winter', 1))
            ->when($request->query('media') === 'video', fn ($query) => $query->whereNotNull('video')->where('video', '!=', ''))
            ->when($request->query('media') === 'photo', fn ($query) => $query->where(fn ($q) => $q->whereNull('video')->orWhere('video', '')))
            ->latest('id')
            ->paginate($perPage);

        return $photos->through(fn (Photo $photo) => $this->serialize($photo, false, $lang));
    }

    public function siteStats()
    {
        if (! LegacySchema::photosReady()) {
            return ['photos_published' => 0];
        }

        return Cache::remember('site:photos_published_count:v1', now()->addMinutes(10), function () {
            $count = Photo::query()
                ->alive()
                ->where('published', 1)
                ->count();

            return ['photos_published' => $count];
        });
    }

    public function markers(Request $request)
    {
        if (! LegacySchema::photosReady()) {
            return DemoData::markers();
        }

        $version = (int) Cache::get(self::MARKERS_CACHE_VERSION_KEY, 1);
        $cacheKey = 'photo_markers:s3:v' . $version . ':' . md5(json_encode([
            'user' => (string) $request->query('user', ''),
            'review' => $request->boolean('review') ? 1 : 0,
            'direction' => (string) $request->query('direction', ''),
            'winter' => $request->boolean('winter') ? 1 : 0,
            'year_from' => (string) $request->query('year_from', ''),
            'year_to' => (string) $request->query('year_to', ''),
        ]));

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($request) {
            return Photo::query()
                ->published()
                ->when($request->filled('user'), fn ($query) => $query->where('user', (string) $request->string('user')))
                ->when($request->filled('year_from'), fn ($query) => $query->where('year', '>=', (int) $request->year_from))
                ->when($request->filled('year_to'), fn ($query) => $query->where('year', '<=', (int) $request->year_to))
                ->when($request->boolean('review'), fn ($query) => $query->where('needs_location_review', 1))
                ->when($request->filled('direction'), fn ($query) => $query->where('direction', (int) $request->direction))
                ->when($request->boolean('winter'), fn ($query) => $query->where('is_winter', 1))
                ->select(['id', 'title', 'lat', 'lng', 'direction', 'year', 'file_id', 'datetime', 'video', 'needs_location_review', 'is_winter'])
                ->get()
                ->map(function (Photo $photo) {
                    $row = [
                        'id' => $photo->id,
                        'title' => LegacyText::decode($photo->title),
                        'lat' => $photo->lat,
                        'lng' => $photo->lng,
                        'year' => $photo->year,
                        'direction' => $photo->direction,
                        'direction_label' => $photo->direction_label,
                        'thumb_url' => $photo->image_urls['thumb'],
                        'large_url' => $photo->image_urls['large'],
                        'datetime' => optional($photo->datetime)->toISOString(),
                    ];

                    if ($photo->video) {
                        $row['has_video'] = true;
                        $row['video'] = $photo->video;
                    }
                    if ($photo->needs_location_review) {
                        $row['needs_location_review'] = true;
                    }
                    if ($photo->is_winter) {
                        $row['is_winter'] = true;
                    }

                    return $row;
                })
                ->all();
        });
    }

    public static function flushMarkersCache(): void
    {
        $current = (int) Cache::get(self::MARKERS_CACHE_VERSION_KEY, 1);
        Cache::forever(self::MARKERS_CACHE_VERSION_KEY, $current + 1);

        foreach (['ru', 'en', 'hy', null] as $lang) {
            foreach ([8, 10, 12, 20] as $perPage) {
                Cache::forget("photos:latest:{$perPage}:{$lang}");
            }
        }
    }

    public function show(Request $request, int $photo)
    {
        if (! LegacySchema::photosReady()) {
            $data = DemoData::findPhoto($photo) ?? abort(404);
            $lang = $this->translator->targetLanguage($request->query('lang'));

            return $this->serializeDemoPhoto($data, $lang);
        }

        $photoModel = Photo::query()
            ->withCount(['comments', 'favorites as likes_count'])
            ->alive()
            ->find($photo);

        abort_unless($photoModel, 404, 'Photo not found.');

        $photo = $photoModel;

        $viewer = $request->user() ?: Auth::guard('sanctum')->user();
        $canView = $photo->published
            || ($viewer && ($viewer->isAdmin() || $viewer->unique === $photo->user));

        abort_unless($canView, 404);

        DB::transaction(function () use ($photo) {
            PhotoView::query()->firstOrCreate(['photo_id' => $photo->id], ['count' => 0])->increment('count');
        });

        $photo->load([
            'author:id,unique,uid,first_name,last_name,photo,identity',
            'viewCounter',
            'comments.author:id,unique,uid,first_name,last_name,photo,identity,email',
            'comments.replies.author:id,unique,uid,first_name,last_name,photo,identity,email',
        ]);

        $lang = $this->translator->targetLanguage($request->query('lang'));
        $lightTranslate = $request->query('translate') === 'main';
        $commentLang = ($lang && ! $lightTranslate) ? $lang : null;
        $relatedLang = $lightTranslate ? null : $lang;

        $this->scheduleFacebookSync($photo->id);

        $data = $this->serialize($photo, true, $lang, $commentLang);
        $data['author_other_photos'] = $this->otherPhotosByAuthor($photo, $relatedLang);
        $data['nearby_photos'] = $this->nearbyPhotos($photo, 6, 0.012, $relatedLang);
        $data['author_stats'] = $this->authorStats($photo);
        $data['is_favorite'] = $this->isFavorite($request, $photo->id);

        return $data;
    }

    private function otherPhotosByAuthor(Photo $photo, ?string $lang = null, int $limit = 6): array
    {
        if (! $photo->user) {
            return [];
        }

        $rows = Photo::query()
            ->published()
            ->where('user', $photo->user)
            ->where('id', '!=', $photo->id)
            ->with('viewCounter')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Photo $other) => [
                'id' => $other->id,
                'title' => LegacyText::decode($other->title),
                'year' => $other->year,
                'views' => $other->viewCounter?->count ?? 0,
                'video' => $other->video ?: null,
                'images' => $other->image_urls,
            ])
            ->all();

        return $this->translator->translateItems($rows, ['title'], $lang);
    }

    private function nearbyPhotos(Photo $photo, int $limit = 6, float $radiusDeg = 0.012, ?string $lang = null): array
    {
        if ($photo->lat === null || $photo->lng === null) {
            return [];
        }

        $lat = (float) $photo->lat;
        $lng = (float) $photo->lng;

        $rows = Photo::query()
            ->published()
            ->where('id', '!=', $photo->id)
            ->whereBetween('lat', [$lat - $radiusDeg, $lat + $radiusDeg])
            ->whereBetween('lng', [$lng - $radiusDeg, $lng + $radiusDeg])
            ->select(['id', 'title', 'year', 'file_id', 'lat', 'lng', 'direction', 'video'])
            ->orderByRaw('POWER(lat - ?, 2) + POWER(lng - ?, 2) ASC', [$lat, $lng])
            ->limit($limit)
            ->get()
            ->map(fn (Photo $near) => [
                'id' => $near->id,
                'title' => LegacyText::decode($near->title),
                'year' => $near->year,
                'lat' => $near->lat,
                'lng' => $near->lng,
                'direction' => $near->direction,
                'video' => $near->video ?: null,
                'images' => $near->image_urls,
            ])
            ->all();

        return $this->translator->translateItems($rows, ['title'], $lang);
    }

    private function authorStats(Photo $photo): ?array
    {
        if (! $photo->user) {
            return null;
        }

        $totals = Photo::query()
            ->where('user', $photo->user)
            ->where('id', '>', 0)
            ->where('published', 1)
            ->selectRaw('COUNT(*) as photos_count')
            ->first();

        $views = 0;
        if (LegacySchema::viewsReady()) {
            $views = (int) DB::table('views')
                ->join('photos', 'photos.id', '=', 'views.photo_id')
                ->where('photos.user', $photo->user)
                ->where('photos.id', '>', 0)
                ->where('photos.published', 1)
                ->sum('views.count');
        }

        return [
            'photos_count' => (int) ($totals?->photos_count ?? 0),
            'views_total' => $views,
        ];
    }

    private function isFavorite(Request $request, int $photoId): bool
    {
        $user = $request->user() ?: Auth::guard('sanctum')->user();
        if (! $user || ! Schema::hasTable('favorites')) {
            return false;
        }

        return Favorite::query()
            ->where('user_unique', $user->unique)
            ->where('photo_id', $photoId)
            ->exists();
    }

    public function random(Request $request)
    {
        $lang = $this->translator->targetLanguage($request->query('lang'));

        if (! LegacySchema::photosReady()) {
            $photo = DemoData::photos()[array_rand(DemoData::photos())];

            return $this->serializeDemoPhoto($photo, $lang);
        }

        $photo = Photo::query()->published()->inRandomOrder()->firstOrFail();

        return $this->serialize($photo, false, $lang);
    }

    public function store(Request $request, LegacyPhotoStorage $storage)
    {
        abort_unless(LegacySchema::photosReady() && LegacySchema::viewsReady(), 503, 'Legacy database is not connected yet.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1', 'max:2100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'direction' => ['nullable', 'integer', 'between:0,8'],
            'file' => ['required_without:video', 'nullable', 'image', 'max:10240'],
            'video' => ['required_without:file', 'nullable', 'string', 'max:512', 'regex:#^https?://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)[\w\-]{6,}#i'],
            'needs_location_review' => ['nullable', 'boolean'],
            'is_winter' => ['nullable', 'boolean'],
            'publish_to_facebook' => ['nullable', 'boolean'],
            'facebook_comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'video.regex' => 'The video link must be a valid YouTube URL.',
        ]);

        $video = $data['video'] ?? null;

        if ($request->hasFile('file')) {
            $fileId = $storage->storeUpload($request->file('file'), config('app.key'));
        } else {
            $fileId = $this->storeYoutubeThumbnail($storage, $video);
        }

        $isAdmin = (bool) $request->user()?->isAdmin();
        $publishToFacebook = filter_var($request->input('publish_to_facebook', false), FILTER_VALIDATE_BOOLEAN);

        $photo = Photo::query()->create([
            'title' => $data['title'],
            'year' => $data['year'],
            'lat' => $data['lat'] ?? 40.179136,
            'lng' => $data['lng'] ?? 44.511623,
            'direction' => $data['direction'] ?? 0,
            'datetime' => now(),
            'user' => $request->user()->unique,
            'published' => $isAdmin ? 1 : 0,
            'file_id' => $fileId,
            'video' => $video,
            'needs_location_review' => (bool) ($data['needs_location_review'] ?? false),
            'is_winter' => (bool) ($data['is_winter'] ?? false),
            'facebook_publish_pending' => $publishToFacebook,
            'facebook_comment' => $publishToFacebook ? trim((string) $request->input('facebook_comment', '')) : null,
        ]);

        PhotoView::query()->create(['photo_id' => $photo->id, 'count' => 0]);

        self::flushMarkersCache();

        $facebookPublishError = null;
        if ($publishToFacebook && $isAdmin && $photo->published) {
            try {
                PublishPhotoToFacebookJob::dispatchSync($photo->id);
            } catch (\Throwable $e) {
                $facebookPublishError = $e->getMessage();
            }
            $photo->refresh();
        }

        $fresh = $photo->fresh(['author:id,unique,uid,first_name,last_name,photo,identity']);
        $payload = $this->serialize($fresh);
        $payload['moderation_pending'] = ! $isAdmin;
        $payload['message'] = $isAdmin
            ? 'Photo published.'
            : 'Photo submitted for moderation.';
        if ($facebookPublishError) {
            $payload['facebook_publish_error'] = $facebookPublishError;
        }

        return response()->json($payload, 201);
    }

    private function youtubeId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([\w\-]{6,})#i', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function storeYoutubeThumbnail(LegacyPhotoStorage $storage, ?string $videoUrl): string
    {
        $videoId = $this->youtubeId($videoUrl);
        abort_if($videoId === null, 422, 'Could not read the YouTube video id.');

        $candidates = [
            "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg",
            "https://img.youtube.com/vi/{$videoId}/sddefault.jpg",
            "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg",
        ];

        $binary = null;
        foreach ($candidates as $url) {
            try {
                $response = Http::timeout(15)->get($url);
                if ($response->ok() && strlen($response->body()) > 2000) {
                    $binary = $response->body();
                    break;
                }
            } catch (\Throwable) {
                // try the next candidate
            }
        }

        abort_if($binary === null, 422, 'Could not fetch the video thumbnail.');

        $tmp = tempnam(sys_get_temp_dir(), 'ythumb_') . '.jpg';
        file_put_contents($tmp, $binary);

        try {
            return $storage->storeImageFile($tmp, config('app.key'));
        } finally {
            @unlink($tmp);
        }
    }

    public function serve(string $variant, string $fileId, LegacyPhotoStorage $storage)
    {
        abort_unless(in_array($variant, ['original', 'large', 'thumb', 'users'], true), 404);

        $order = match ($variant) {
            'large' => ['large', 'original', 'thumb'],
            'original' => ['original', 'large', 'thumb'],
            'thumb' => ['thumb', 'large', 'original'],
            default => ['users'],
        };

        foreach ($order as $candidate) {
            $path = $storage->absolutePath($candidate, $fileId);
            if (is_file($path) && filesize($path) > 0) {
                if ($variant === 'users') {
                    $target = min(768, max(128, (int) request()->integer('w', LegacyPhotoStorage::USER_AVATAR_TARGET)));
                    $display = $storage->userAvatarDisplayPath($path, $target);
                    $headers = [
                        'Content-Type' => mime_content_type($display) ?: 'image/jpeg',
                        'Cache-Control' => 'public, max-age=604800',
                    ];

                    return Response::file($display, $headers);
                }

                if (in_array($variant, ['large', 'original'], true)) {
                    $watermarked = $storage->watermarkedPath($path);
                    if ($watermarked !== null) {
                        return $this->fileImageResponse($watermarked, $fileId);
                    }
                }

                return $this->fileImageResponse($path, $fileId);
            }
        }

        $fallback = public_path('demo/photo-' . ((abs(crc32($fileId)) % 3) + 1) . '.svg');

        abort_unless(is_file($fallback), 404);

        return Response::file($fallback);
    }

    private function serializeDemoPhoto(array $photo, ?string $lang): array
    {
        $photo['title'] = $this->displayTitle($photo['title'] ?? '', $lang);

        return $photo;
    }

    private function displayTitle(?string $title, ?string $lang): string
    {
        $text = LegacyText::decode($title ?? '');

        return $lang ? $this->translator->translate($text, $lang) : $text;
    }

    private function serialize(Photo $photo, bool $includeComments = false, ?string $lang = null, ?string $commentLang = null): array
    {
        $title = $this->displayTitle($photo->title, $lang);

        $data = [
            'id' => $photo->id,
            'title' => $title,
            'lat' => $photo->lat,
            'lng' => $photo->lng,
            'year' => $photo->year,
            'direction' => $photo->direction,
            'direction_label' => $photo->direction_label,
            'published' => $photo->published,
            'needs_location_review' => (bool) $photo->needs_location_review,
            'is_winter' => (bool) ($photo->is_winter ?? false),
            'datetime' => optional($photo->datetime)->toISOString(),
            'video' => $photo->video ?: null,
            'views' => $photo->viewCounter?->count ?? 0,
            'comments_count' => $photo->comments_count ?? ($includeComments ? $photo->comments->count() : 0),
            'likes_count' => $photo->likes_count ?? 0,
            'author' => $this->serializeAuthor($photo->author),
            'images' => $photo->image_urls,
            'facebook' => $this->serializeFacebook($photo),
        ];

        $siteLikes = (int) ($photo->likes_count ?? 0);
        $legacyLikes = (int) ($photo->legacy_likes_count ?? 0);
        $fbLikes = (int) ($photo->facebook_likes ?? 0);
        $siteComments = (int) ($photo->comments_count ?? 0);
        $fbComments = $photo->facebook_post_id
            ? max(
                (int) ($photo->facebook_comments_count ?? 0),
                $includeComments
                    ? PhotoFacebookComment::query()->where('photo_id', $photo->id)->count()
                    : 0,
            )
            : 0;

        $siteViews = (int) ($photo->viewCounter?->count ?? 0);
        $fbViews = (int) ($photo->facebook_views ?? 0);

        $data['views'] = $siteViews + $fbViews;
        $data['site_views_count'] = $siteViews;
        $data['facebook_views_count'] = $fbViews;
        $data['likes_total'] = $siteLikes + $legacyLikes + $fbLikes;
        $data['site_likes_count'] = $siteLikes + $legacyLikes;
        $data['legacy_likes_count'] = $legacyLikes;
        $data['comments_count'] = $siteComments + $fbComments;

        if ($includeComments) {
            $effectiveCommentLang = $commentLang ?? $lang;
            $data['comments'] = $this->mergedComments($photo, $effectiveCommentLang);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleFacebookSync(int $photoId): void
    {
        app()->terminating(function () use ($photoId) {
            try {
                $photo = Photo::find($photoId);
                if ($photo) {
                    $this->syncFacebookIfStale($photo);
                }
            } catch (\Throwable) {
            }
        });
    }

    private function syncFacebookIfStale(Photo $photo): void
    {
        if (! $photo->facebook_post_id || ! $this->facebookPublish->isConfigured()) {
            return;
        }

        try {
            $fresh = $photo->fresh() ?? $photo;
            $this->facebookComments->syncForPhoto($fresh);

            $statsKey = 'facebook_stats_photo_' . $photo->id;
            if (! Cache::has($statsKey)) {
                $this->facebookPublish->syncPostStats($fresh);
                Cache::put($statsKey, true, now()->addSeconds(20));
            }
        } catch (\Throwable) {
            // non-fatal for page load
        }
    }

    private function mergedComments(Photo $photo, ?string $lang): array
    {
        return CommentPresenter::mergePhotoThreads(
            $photo->comments->sortBy('datetime')->values(),
            fn () => $this->facebookComments->serializedTreeForPhoto($photo->id, $this->translator, $lang),
            $this->translator,
            $lang,
        );
    }

    private function serializeAuthor($author): ?array
    {
        if (! $author) {
            return null;
        }

        return [
            'id' => $author->id,
            'unique' => $author->unique,
            'uid' => $author->uid,
            'first_name' => $author->first_name,
            'last_name' => $author->last_name,
            'name' => $author->name,
            'display_name' => $author->display_name,
            'photo' => $author->photo,
        ];
    }

    private function serializeFacebook(Photo $photo): ?array
    {
        if (! $photo->facebook_post_id && ! $photo->facebook_post_url && ! $photo->facebook_publish_pending) {
            return null;
        }

        $storedComments = PhotoFacebookComment::query()->where('photo_id', $photo->id)->count();

        $postUrl = $this->facebookPublish->publicPostUrl($photo);

        return [
            'pending' => (bool) $photo->facebook_publish_pending,
            'published' => (bool) $photo->facebook_post_id,
            'post_id' => $photo->facebook_post_id,
            'post_url' => $postUrl,
            'likes' => (int) ($photo->facebook_likes ?? 0),
            'views' => (int) ($photo->facebook_views ?? 0),
            'comments_count' => max((int) ($photo->facebook_comments_count ?? 0), $storedComments),
            'synced_at' => optional($photo->facebook_synced_at)->toISOString(),
        ];
    }

    private function fileImageResponse(string $path, string $fileId)
    {
        $mime = mime_content_type($path) ?: 'image/jpeg';
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=604800',
        ];

        if (request()->boolean('download')) {
            $rawName = (string) request()->query('filename', $fileId);
            $safeName = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $rawName) ?: $fileId;
            $headers['Content-Disposition'] = 'attachment; filename="' . $safeName . '.jpg"';
        }

        return Response::file($path, $headers);
    }
}
