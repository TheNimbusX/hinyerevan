<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Photo;
use App\Models\User;
use App\Services\LegacyPhotoStorage;
use App\Services\LegacySchema;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\PasswordResetMail;
use App\Support\UiLocale;

class AuthController extends Controller
{
    public function __construct(private TranslationService $translator)
    {
    }

    public function login(Request $request)
    {
        abort_unless(LegacySchema::usersReady(), 503, 'Legacy users table is not connected yet.');

        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('uid', $credentials['login'])
            ->orWhere('email', $credentials['login'])
            ->first();

        if (! $user || ! $this->passwordMatches($credentials['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Invalid credentials.',
            ]);
        }

        abort_if($user->isBlocked(), 403, 'This account is blocked.');

        $user->forceFill(['last_ip' => $request->ip()])->save();

        return [
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $this->serializeUser($user),
        ];
    }

    public function register(Request $request, LegacyPhotoStorage $storage)
    {
        abort_unless(LegacySchema::usersReady(), 503, 'Legacy users table is not connected yet.');

        UiLocale::apply($request);

        $data = $request->validate([
            'uid' => ['required', 'alpha_num', 'min:3', 'max:32', 'unique:users,uid'],
            'first_name' => ['required', 'string', 'min:3', 'max:80'],
            'last_name' => ['required', 'string', 'min:3', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'sex' => ['required', 'integer', 'in:0,1'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1900,2026'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        $this->verifyRecaptcha((string) ($data['recaptcha_token'] ?? ''));

        $photo = 'http://www.hinyerevan.com/photos/user.png';
        if ($request->hasFile('photo')) {
            $fileId = $storage->storeUserPhoto($request->file('photo'), config('app.key'));
            $photo = 'http://www.hinyerevan.com/photos/users/' . $fileId;
        }

        $attributes = [
            'uid' => $data['uid'],
            'network' => 'hinyerevan',
            'unique' => md5($data['uid']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'identity' => '',
            'sex' => $data['sex'],
            'photo' => $photo,
            'type' => User::TYPE_USER,
            // Keep the first-write format compatible with the legacy varchar(32) password column.
            'password' => md5($data['password']),
            'last_ip' => $request->ip(),
        ];

        // Birthday is optional; only store it when all three parts are provided.
        if (! empty($data['birth_year']) && ! empty($data['birth_month']) && ! empty($data['birth_day'])) {
            $attributes['bdate'] = sprintf('%04d-%02d-%02d', $data['birth_year'], $data['birth_month'], $data['birth_day']);
        }

        $user = User::query()->create($attributes);

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $this->serializeUser($user),
        ], 201);
    }

    public function me(Request $request)
    {
        return $this->serializeUser($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email,' . $user->id],
            'identity' => ['nullable', 'string', 'max:80'],
            'sex' => ['nullable', 'integer', 'in:0,1'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1900,2026'],
        ]);

        $data['last_name'] ??= '';
        $data['identity'] ??= '';
        $data['sex'] = isset($data['sex']) ? (int) $data['sex'] : (int) ($user->sex ?? 0);

        if (! empty($data['birth_year']) && ! empty($data['birth_month']) && ! empty($data['birth_day'])) {
            $data['bdate'] = sprintf(
                '%04d-%02d-%02d',
                (int) $data['birth_year'],
                (int) $data['birth_month'],
                (int) $data['birth_day'],
            );
        }

        unset($data['birth_day'], $data['birth_month'], $data['birth_year']);

        $user->fill($data)->save();

        return $this->serializeUser($user);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! $this->passwordMatches($data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is invalid.',
            ]);
        }

        $user->forceFill(['password' => md5($data['password'])])->save();

        return response()->noContent();
    }

    public function forgotPassword(Request $request)
    {
        abort_unless(LegacySchema::usersReady(), 503, 'Legacy users table is not connected yet.');

        $lang = UiLocale::apply($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ], [
            'email.required' => __('password.validation_email_required'),
            'email.email' => __('password.validation_email_invalid'),
        ]);

        $message = __('password.forgot_sent');

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || $user->isBlocked()) {
            return ['message' => $message, 'lang' => $lang];
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => hash('sha256', $token), 'created_at' => now()],
        );

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $resetUrl = $frontendUrl . '/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $user->email,
            'lang' => $lang,
        ]);

        try {
            Mail::to($user->email)->send(new PasswordResetMail($user, $resetUrl, $lang));
        } catch (\Throwable $exception) {
            Log::error('Password reset mail failed', [
                'email' => $user->email,
                'message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => __('password.mail_send_failed'),
            ]);
        }

        return ['message' => $message, 'lang' => $lang];
    }

    public function resetPassword(Request $request)
    {
        abort_unless(LegacySchema::usersReady(), 503, 'Legacy users table is not connected yet.');

        $lang = UiLocale::apply($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string', 'min:32'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'email.required' => __('password.validation_email_required'),
            'email.email' => __('password.validation_email_invalid'),
            'token.required' => __('password.validation_token_required'),
            'password.required' => __('password.validation_password_min'),
            'password.min' => __('password.validation_password_min'),
            'password.confirmed' => __('password.validation_password_confirmed'),
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();
        $tokenValid = $record
            && hash_equals((string) $record->token, hash('sha256', $data['token']));
        $expired = ! $record?->created_at
            || now()->diffInMinutes($record->created_at) > 60;

        if (! $tokenValid || $expired) {
            if ($record && $expired) {
                DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
            }

            throw ValidationException::withMessages([
                'email' => __('password.reset_invalid_link'),
            ]);
        }

        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || $user->isBlocked()) {
            throw ValidationException::withMessages([
                'email' => __('password.reset_invalid_link'),
            ]);
        }

        $user->forceFill(['password' => md5($data['password'])])->save();
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return ['message' => __('password.reset_success'), 'lang' => $lang];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function stats(Request $request)
    {
        $user = $request->user();

        $stats = [
            'photos_total' => 0,
            'photos_published' => 0,
            'photos_pending' => 0,
            'comments_total' => 0,
            'views_total' => 0,
            'member_since' => null,
        ];

        if (LegacySchema::photosReady()) {
            $totals = Photo::query()
                ->where('id', '>', 0)
                ->where('user', $user->unique)
                ->selectRaw('COUNT(*) as total, SUM(published = 1) as published_count, SUM(published = 0) as pending_count')
                ->first();

            $stats['photos_total'] = (int) ($totals?->total ?? 0);
            $stats['photos_published'] = (int) ($totals?->published_count ?? 0);
            $stats['photos_pending'] = (int) ($totals?->pending_count ?? 0);

            if (LegacySchema::viewsReady()) {
                $stats['views_total'] = (int) DB::table('views')
                    ->join('photos', 'photos.id', '=', 'views.photo_id')
                    ->where('photos.user', $user->unique)
                    ->where('photos.id', '>', 0)
                    ->sum('views.count');
            }

            $firstPhotoAt = Photo::query()
                ->where('user', $user->unique)
                ->where('id', '>', 0)
                ->min('datetime');

            if ($firstPhotoAt) {
                $stats['member_since'] = $firstPhotoAt;
            }
        }

        if (LegacySchema::commentsReady()) {
            $stats['comments_total'] = (int) Comment::query()
                ->where('id', '>', 0)
                ->where('user_unique', $user->unique)
                ->count();

            if (! $stats['member_since']) {
                $firstCommentAt = Comment::query()
                    ->where('user_unique', $user->unique)
                    ->where('id', '>', 0)
                    ->min('datetime');

                if ($firstCommentAt) {
                    $stats['member_since'] = $firstCommentAt;
                }
            }
        }

        return $stats;
    }

    public function myPhotos(Request $request)
    {
        $user = $request->user();

        if (! LegacySchema::photosReady()) {
            return LegacySchema::emptyPaginator($request, (int) $request->integer('per_page', 12));
        }

        $photos = Photo::query()
            ->with('viewCounter')
            ->withCount('comments')
            ->where('id', '>', 0)
            ->where('user', $user->unique)
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 12), 60));

        $lang = $this->translator->targetLanguage($request->query('lang'));

        return $photos->through(function (Photo $photo) use ($lang) {
            $row = [
                'id' => $photo->id,
                'title' => $photo->title,
                'year' => $photo->year,
                'direction' => $photo->direction,
                'direction_label' => $photo->direction_label,
                'published' => (bool) $photo->published,
                'datetime' => optional($photo->datetime)->toISOString(),
                'views' => $photo->viewCounter?->count ?? 0,
                'comments_count' => $photo->comments_count ?? 0,
                'images' => $photo->image_urls,
            ];

            if ($lang) {
                $row['title'] = $this->translator->translate($photo->title, $lang);
            }

            return $row;
        });
    }

    public function myComments(Request $request)
    {
        $user = $request->user();
        $lang = $this->translator->targetLanguage($request->query('lang'));

        if (! LegacySchema::commentsReady()) {
            return LegacySchema::emptyPaginator($request, (int) $request->integer('per_page', 12));
        }

        $comments = Comment::query()
            ->alive()
            ->where('user_unique', $user->unique)
            ->whereRaw("post_id REGEXP '^[0-9]+$'")
            ->with(['photo:id,title,year,file_id,published'])
            ->orderByDesc('datetime')
            ->paginate(min((int) $request->integer('per_page', 12), 60));

        return $comments->through(function (Comment $comment) use ($lang) {
            $photo = $comment->photo;
            $title = $photo?->title;

            if ($lang && $title) {
                $title = $this->translator->translate($title, $lang);
            }

            return [
                'id' => $comment->id,
                'body' => $lang
                    ? ($this->translator->translate($comment->body, $lang) ?? $comment->body)
                    : $comment->body,
                'datetime' => optional($comment->datetime)->toISOString(),
                'photo' => $photo && $photo->id > 0 ? [
                    'id' => $photo->id,
                    'title' => $title,
                    'year' => $photo->year,
                    'thumb_url' => "/api/photos/file/thumb/{$photo->file_id}",
                ] : null,
            ];
        });
    }

    public function uploadAvatar(Request $request, LegacyPhotoStorage $storage)
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $user = $request->user();
        $fileId = $storage->storeUserPhoto($request->file('photo'), config('app.key'));
        $user->forceFill([
            'photo' => 'http://www.hinyerevan.com/photos/users/' . $fileId,
        ])->save();

        return $this->serializeUser($user);
    }

    private function passwordMatches(string $plain, string $stored): bool
    {
        if (strlen($stored) === 32 && hash_equals($stored, md5($plain))) {
            return true;
        }

        return str_starts_with($stored, '$') && Hash::check($plain, $stored);
    }

    private function verifyRecaptcha(string $token): void
    {
        $secret = (string) config('services.recaptcha.secret');
        if ($secret === '') {
            return;
        }

        if ($token === '') {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('password.captcha_required'),
            ]);
        }

        try {
            $client = Http::asForm();
            $proxy = trim((string) config('services.oauth.proxy', ''));
            if ($proxy !== '') {
                $client = $client->withOptions(['proxy' => $proxy]);
            }

            $response = $client->post('https://www.recaptcha.net/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);
        } catch (\Throwable $e) {
            // Google unreachable from the backend (e.g. local network blocks it).
            // Don't punish the user for our connectivity — log and let it pass.
            \Log::warning('reCAPTCHA verify unreachable, skipping check', ['message' => $e->getMessage()]);

            return;
        }

        if (! $response->json('success')) {
            throw ValidationException::withMessages([
                'recaptcha_token' => __('password.captcha_failed'),
            ]);
        }
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'uid' => $user->uid,
            'unique' => $user->unique,
            'network' => $user->network,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'identity' => $user->identity,
            'bdate' => $user->bdate,
            'sex' => $user->sex,
            'photo' => $user->photo,
            'type' => $user->type,
            'is_admin' => (bool) $user->isAdmin(),
        ];
    }
}
