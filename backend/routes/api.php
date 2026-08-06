<?php

use App\Http\Controllers\Api\DevAuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\FacebookController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/', fn () => [
    'name' => 'HinYerevan API',
    'status' => 'ok',
    'endpoints' => [
        'health' => '/api/health',
        'photos' => '/api/photos',
        'markers' => '/api/photos/markers',
        'news' => '/api/news',
        'pages' => '/api/pages',
        'ratings' => '/api/ratings',
    ],
]);
Route::get('/health', fn () => ['status' => 'ok']);

Route::get('/dev-auth/status', [DevAuthController::class, 'status']);
Route::post('/dev-auth/login', [DevAuthController::class, 'login'])->middleware('throttle:12,1');

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register/send-code', [AuthController::class, 'registerSendCode'])->middleware('throttle:6,1');
Route::post('/auth/register/confirm', [AuthController::class, 'registerConfirm'])->middleware('throttle:12,1');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:12,1');

Route::get('/auth/social/providers', [SocialAuthController::class, 'providers']);
Route::post('/auth/social/ulogin', [SocialAuthController::class, 'ulogin']);

Route::middleware([
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
])->group(function () {
    Route::get('/auth/social/{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('/auth/social/{provider}/callback', [SocialAuthController::class, 'callback']);
});

Route::get('/photos/site-stats', [PhotoController::class, 'siteStats']);
Route::get('/photos/random', [PhotoController::class, 'random']);
Route::get('/photos/markers', [PhotoController::class, 'markers']);
Route::get('/photos', [PhotoController::class, 'index']);
Route::get('/photos/{photo}', [PhotoController::class, 'show'])->whereNumber('photo');
Route::get('/photos/file/{variant}/{fileId}', [PhotoController::class, 'serve'])
    ->whereIn('variant', ['original', 'large', 'thumb', 'users'])
    ->name('legacy.photos.large');
Route::get('/news', [ContentController::class, 'newsIndex']);
Route::get('/news/{news}', [ContentController::class, 'newsShow'])->whereNumber('news');
Route::get('/news/{news}/comments', [CommentController::class, 'newsIndex'])->whereNumber('news');
Route::get('/pages', [ContentController::class, 'pagesIndex']);
Route::get('/pages/{alias}', [ContentController::class, 'pageShow']);
Route::post('/feedback', [FeedbackController::class, 'store']);
Route::get('/ratings', [RatingController::class, 'index']);
Route::get('/facebook/page', [FacebookController::class, 'page']);
Route::get('/facebook/plugin-config', [FacebookController::class, 'pluginConfig']);
Route::get('/facebook-incoming/{post}/image', [AdminController::class, 'facebookIncomingImage'])->whereNumber('post');
Route::get('/users/search', [UserController::class, 'search']);
Route::get('/users/{unique}', [UserController::class, 'show']);
Route::get('/photos/{photo}/comments', [CommentController::class, 'index'])->whereNumber('photo');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'changePassword']);
    Route::get('/auth/stats', [AuthController::class, 'stats']);
    Route::get('/auth/photos', [AuthController::class, 'myPhotos']);
    Route::get('/auth/comments', [AuthController::class, 'myComments']);
    Route::get('/auth/favorites', [FavoriteController::class, 'index']);
    Route::post('/auth/avatar', [AuthController::class, 'uploadAvatar']);
    Route::post('/auth/social/link/{provider}/start', [SocialAuthController::class, 'startLink']);
    Route::post('/photos/{photo}/favorite', [FavoriteController::class, 'store'])->whereNumber('photo');
    Route::delete('/photos/{photo}/favorite', [FavoriteController::class, 'destroy'])->whereNumber('photo');
    Route::post('/photos', [PhotoController::class, 'store']);
    Route::post('/photos/{photo}/comments', [CommentController::class, 'store'])->whereNumber('photo');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroyOwn'])->whereNumber('comment');
    Route::post('/comments/{comment}/like', [CommentController::class, 'toggleLike'])->whereNumber('comment');
    Route::post('/photos/{photo}/facebook-comments/{fbComment}/like', [CommentController::class, 'toggleFacebookLike'])
        ->whereNumber('photo');
    Route::post('/news/{news}/comments', [CommentController::class, 'newsStore'])->whereNumber('news');

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/photos', [AdminController::class, 'photos']);
        Route::get('/photos/{photo}', [AdminController::class, 'showPhoto'])->whereNumber('photo');
        Route::put('/photos/{photo}', [AdminController::class, 'updatePhoto'])->whereNumber('photo');
        Route::post('/photos/{photo}', [AdminController::class, 'updatePhoto'])->whereNumber('photo');
        Route::delete('/photos/{photo}', [AdminController::class, 'deletePhoto'])->whereNumber('photo');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->whereNumber('comment');

        Route::get('/users', [AdminController::class, 'users']);
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->whereNumber('user');
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->whereNumber('user');

        Route::get('/news', [AdminController::class, 'news']);
        Route::post('/news', [AdminController::class, 'storeNews']);
        Route::put('/news/{news}', [AdminController::class, 'updateNews'])->whereNumber('news');
        Route::delete('/news/{news}', [AdminController::class, 'deleteNews'])->whereNumber('news');

        Route::get('/pages', [AdminController::class, 'pages']);
        Route::post('/pages', [AdminController::class, 'storePage']);
        Route::put('/pages/{page}', [AdminController::class, 'updatePage'])->whereNumber('page');

        Route::get('/facebook-incoming', [AdminController::class, 'facebookIncoming']);
        Route::post('/facebook-incoming/{post}/import', [AdminController::class, 'importFacebookIncoming'])->whereNumber('post');
        Route::post('/facebook-incoming/{post}/dismiss', [AdminController::class, 'dismissFacebookIncoming'])->whereNumber('post');
        Route::post('/facebook-incoming/{post}/restore', [AdminController::class, 'restoreFacebookIncoming'])->whereNumber('post');

        Route::get('/feedback', [AdminController::class, 'feedback']);
        Route::put('/feedback/{feedback}', [AdminController::class, 'markFeedbackRead'])->whereNumber('feedback');
        Route::delete('/feedback/{feedback}', [AdminController::class, 'deleteFeedback'])->whereNumber('feedback');
    });
});
