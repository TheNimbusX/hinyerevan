<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DemoData;
use App\Services\LegacySchema;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RatingController extends Controller
{
    public function __construct(private TranslationService $translator)
    {
    }

    public function index(Request $request)
    {
        $lang = $this->translator->targetLanguage($request->query('lang'));

        if (! LegacySchema::photosReady()) {
            $ratings = DemoData::ratings();
            $ratings['photos_by_views'] = $this->translator->translateItems($ratings['photos_by_views'], ['title'], $lang);
            $ratings['photos_by_likes'] = $this->translator->translateItems($ratings['photos_by_likes'], ['title'], $lang);
            $ratings['photos_by_comments'] = $this->translator->translateItems($ratings['photos_by_comments'], ['title'], $lang);

            return $ratings;
        }

        $commentCounts = DB::table('comments')
            ->select('post_id', DB::raw('COUNT(*) as comments_count'))
            ->where('id', '>', 0)
            ->groupBy('post_id');

        $photosByViews = LegacySchema::viewsReady()
            ? DB::table('photos')
                ->leftJoin('views', 'views.photo_id', '=', 'photos.id')
                ->where('photos.id', '>', 0)
                ->where('photos.published', 1)
                ->orderByDesc('views.count')
                ->limit(10)
                ->get(['photos.id', 'photos.title', 'photos.file_id', 'photos.year', DB::raw('COALESCE(views.count, 0) as views')])
            : [];

        $hasFbCount = Schema::hasColumn('photos', 'facebook_comments_count');
        $fbExpr = $hasFbCount ? 'COALESCE(photos.facebook_comments_count, 0)' : '0';
        $totalCommentsExpr = "COALESCE(comment_counts.comments_count, 0) + {$fbExpr}";

        $photosByComments = LegacySchema::commentsReady()
            ? DB::table('photos')
                ->leftJoinSub($commentCounts, 'comment_counts', fn ($join) => $join->on('comment_counts.post_id', '=', 'photos.id'))
                ->where('photos.id', '>', 0)
                ->where('photos.published', 1)
                ->orderByDesc(DB::raw($totalCommentsExpr))
                ->limit(10)
                ->get(['photos.id', 'photos.title', 'photos.file_id', 'photos.year', DB::raw("{$totalCommentsExpr} as comments_count")])
            : [];

        $favoriteCounts = Schema::hasTable('favorites')
            ? DB::table('favorites')
                ->select('photo_id', DB::raw('COUNT(*) as favorites_count'))
                ->groupBy('photo_id')
            : null;

        $legacyLikesExpr = Schema::hasColumn('photos', 'legacy_likes_count')
            ? 'COALESCE(photos.legacy_likes_count, 0)'
            : '0';
        $facebookLikesExpr = Schema::hasColumn('photos', 'facebook_likes')
            ? 'COALESCE(photos.facebook_likes, 0)'
            : '0';
        $totalLikesExpr = "COALESCE(favorite_counts.favorites_count, 0) + {$legacyLikesExpr} + {$facebookLikesExpr}";

        $photosByLikes = $favoriteCounts !== null
            ? DB::table('photos')
                ->leftJoinSub($favoriteCounts, 'favorite_counts', fn ($join) => $join->on('favorite_counts.photo_id', '=', 'photos.id'))
                ->where('photos.id', '>', 0)
                ->where('photos.published', 1)
                ->orderByDesc(DB::raw($totalLikesExpr))
                ->limit(10)
                ->get(['photos.id', 'photos.title', 'photos.file_id', 'photos.year', DB::raw("{$totalLikesExpr} as likes_count")])
            : [];

        return [
            'photos_by_views' => $this->translator->translateItems($photosByViews, ['title'], $lang),
            'photos_by_likes' => $this->translator->translateItems($photosByLikes, ['title'], $lang),
            'photos_by_comments' => $this->translator->translateItems($photosByComments, ['title'], $lang),
            'users_by_photos' => LegacySchema::usersReady()
                ? DB::table('users')
                    ->leftJoin('photos', function ($join) {
                        $join->on('photos.user', '=', 'users.unique')
                            ->where('photos.id', '>', 0)
                            ->where('photos.published', 1);
                    })
                    ->where('users.id', '>', 0)
                    ->groupBy('users.id', 'users.unique', 'users.uid', 'users.first_name', 'users.last_name', 'users.photo', 'users.network')
                    ->orderByDesc('photos_count')
                    ->limit(10)
                    ->get(['users.id', 'users.unique', 'users.uid', 'users.first_name', 'users.last_name', 'users.photo', 'users.network', DB::raw('COUNT(photos.id) as photos_count')])
                : [],
            'users_by_comments' => LegacySchema::usersReady() && LegacySchema::commentsReady()
                ? DB::table('users')
                    ->leftJoin('comments', function ($join) {
                        $join->on('comments.user_unique', '=', 'users.unique')
                            ->where('comments.id', '>', 0);
                    })
                    ->where('users.id', '>', 0)
                    ->groupBy('users.id', 'users.unique', 'users.uid', 'users.first_name', 'users.last_name', 'users.photo', 'users.network')
                    ->orderByDesc('comments_count')
                    ->limit(10)
                    ->get(['users.id', 'users.unique', 'users.uid', 'users.first_name', 'users.last_name', 'users.photo', 'users.network', DB::raw('COUNT(comments.id) as comments_count')])
                : [],
        ];
    }

}
