<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Photo;
use App\Services\LegacySchema;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! LegacySchema::photosReady()) {
            return LegacySchema::emptyPaginator($request, (int) $request->integer('per_page', 12));
        }

        $favorites = Favorite::query()
            ->where('user_unique', $user->unique)
            ->whereHas('photo', fn ($query) => $query->where('id', '>', 0)->where('published', 1))
            ->with(['photo.viewCounter', 'photo.author:id,unique,uid,first_name,last_name,photo'])
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 12), 60));

        return $favorites->through(function (Favorite $favorite) {
            $photo = $favorite->photo;

            return [
                'id' => $favorite->id,
                'favorited_at' => optional($favorite->created_at)->toISOString(),
                'photo' => $photo ? [
                    'id' => $photo->id,
                    'title' => $photo->title,
                    'year' => $photo->year,
                    'views' => $photo->viewCounter?->count ?? 0,
                    'images' => $photo->image_urls,
                    'author' => $photo->author ? [
                        'unique' => $photo->author->unique,
                        'uid' => $photo->author->uid,
                        'name' => $photo->author->name,
                        'photo' => $photo->author->photo,
                    ] : null,
                ] : null,
            ];
        });
    }

    public function store(Request $request, int $photo)
    {
        abort_unless(LegacySchema::photosReady(), 503, 'Legacy database is not connected yet.');

        $photoModel = Photo::query()->findOrFail($photo);
        abort_unless($photoModel->id > 0 && $photoModel->published, 404);

        $favorite = Favorite::query()->firstOrCreate(
            [
                'user_unique' => $request->user()->unique,
                'photo_id' => $photoModel->id,
            ],
            [
                'created_at' => Carbon::now(),
            ]
        );

        return response()->json([
            'is_favorite' => true,
            'favorited_at' => optional($favorite->created_at)->toISOString(),
        ], 201);
    }

    public function destroy(Request $request, int $photo)
    {
        Favorite::query()
            ->where('user_unique', $request->user()->unique)
            ->where('photo_id', $photo)
            ->delete();

        return response()->json(['is_favorite' => false]);
    }
}
