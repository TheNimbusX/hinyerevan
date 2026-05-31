<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\User;
use App\Services\LegacySchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function show(string $unique)
    {
        abort_unless(LegacySchema::usersReady(), 503, 'Legacy users table is not connected yet.');

        $user = User::query()
            ->where('unique', $unique)
            ->orWhere('uid', $unique)
            ->firstOrFail();

        $photosCount = 0;
        $viewsTotal = 0;
        $commentsTotal = 0;

        if (LegacySchema::photosReady()) {
            $photosCount = (int) Photo::query()
                ->where('id', '>', 0)
                ->where('published', 1)
                ->where('user', $user->unique)
                ->count();

            if (LegacySchema::viewsReady()) {
                $viewsTotal = (int) Cache::remember(
                    'user:views:' . $user->unique,
                    now()->addMinutes(30),
                    fn () => (int) DB::table('views')
                        ->join('photos', 'photos.id', '=', 'views.photo_id')
                        ->where('photos.user', $user->unique)
                        ->where('photos.id', '>', 0)
                        ->where('photos.published', 1)
                        ->sum('views.count'),
                );
            }
        }

        if (LegacySchema::commentsReady()) {
            $commentsTotal = (int) Comment::query()
                ->where('id', '>', 0)
                ->where('user_unique', $user->unique)
                ->count();
        }

        return [
            'id' => $user->id,
            'uid' => $user->uid,
            'unique' => $user->unique,
            'network' => $user->network,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'photo' => $user->photo,
            'identity' => $user->identity,
            'photos_count' => $photosCount,
            'views_total' => $viewsTotal,
            'comments_total' => $commentsTotal,
        ];
    }
}
