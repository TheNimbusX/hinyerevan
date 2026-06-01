<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LegacySchema;
use App\Services\SocialAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * OAuth drivers exposed to the SPA. Only providers with valid .env keys
     * are returned from providers().
     */
    private const PROVIDERS = [
        'google' => ['label' => 'Google', 'color' => '#ea4335'],
        'facebook' => ['label' => 'Facebook', 'color' => '#1877f2'],
        'vkontakte' => ['label' => 'VK', 'color' => '#0077ff'],
        'odnoklassniki' => ['label' => 'OK', 'color' => '#ee8208'],
        'yandex' => ['label' => 'Yandex', 'color' => '#fc3f1d'],
        'apple' => ['label' => 'Apple', 'color' => '#000000'],
        'instagram' => ['label' => 'Instagram', 'color' => '#e4405f'],
    ];

    public function __construct(
        private readonly SocialAuthService $socialAuth,
    ) {}

    public function providers()
    {
        $enabled = [];
        foreach (self::PROVIDERS as $key => $meta) {
            if ($this->isConfigured($key)) {
                $enabled[] = [
                    'id' => $key,
                    'label' => $meta['label'],
                    'color' => $meta['color'],
                ];
            }
        }

        return $enabled;
    }

    public function redirect(string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);
        abort_unless($this->isConfigured($provider), 404, 'This provider is not configured.');

        $driver = $this->socialiteDriver($provider)->stateless();

        return match ($provider) {
            'facebook' => $driver->scopes(['email', 'public_profile'])->redirect(),
            default => $driver->redirect(),
        };
    }

    public function callback(string $provider)
    {
        $frontend = $this->frontendUrl();

        if (! isset(self::PROVIDERS[$provider]) || ! $this->isConfigured($provider)) {
            return redirect()->away($frontend . '/?social_error=' . urlencode('Unknown provider.'));
        }

        if (! LegacySchema::usersReady()) {
            return redirect()->away($frontend . '/?social_error=' . urlencode('User database is not connected yet.'));
        }

        try {
            $oauthUser = $this->socialiteDriver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            \Log::error('Social login failed', [
                'provider' => $provider,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->away(
                $frontend . '/?social_error=' . urlencode('Could not sign in with ' . self::PROVIDERS[$provider]['label'] . '.'),
            );
        }

        $user = $this->resolveOAuthUser($provider, $oauthUser);

        if ($user->isBlocked()) {
            return redirect()->away($frontend . '/?social_error=' . urlencode('This account is blocked.'));
        }

        $user->forceFill(['last_ip' => request()->ip()])->save();
        $token = $user->createToken('spa-' . $provider)->plainTextToken;

        return redirect()->away($frontend . '/?social_token=' . urlencode($token));
    }

    /**
     * uLogin fallback — matches legacy users by network+uid / email / md5(uid).
     */
    public function ulogin(Request $request)
    {
        if (! LegacySchema::usersReady()) {
            return response()->json(['message' => 'User database is not connected yet.'], 503);
        }

        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            return response()->json(['message' => 'Missing uLogin token.'], 422);
        }

        try {
            $client = Http::timeout(12);
            $proxy = trim((string) config('services.ulogin.proxy', ''));
            if ($proxy !== '') {
                $client = $client->withOptions(['proxy' => $proxy]);
            }

            $data = $client
                ->get('https://ulogin.ru/token.php', [
                    'token' => $token,
                    'host' => $this->uloginHost($request),
                ])
                ->json();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not reach uLogin. Check server internet access (ULOGIN_PROXY if blocked).',
            ], 502);
        }

        if (! is_array($data) || ! empty($data['error']) || empty($data['network']) || empty($data['uid'])) {
            $message = is_array($data) && ! empty($data['error'])
                ? 'uLogin: ' . $data['error']
                : 'uLogin verification failed.';

            return response()->json(['message' => $message], 422);
        }

        $user = $this->socialAuth->resolveFromUlogin($data);

        if ($user->isBlocked()) {
            return response()->json(['message' => 'This account is blocked.'], 403);
        }

        $user->forceFill(['last_ip' => $request->ip()])->save();

        return response()->json([
            'token' => $user->createToken('spa-ulogin')->plainTextToken,
        ]);
    }

    private function resolveOAuthUser(string $provider, $oauthUser): User
    {
        $providerId = (string) $oauthUser->getId();
        $email = $oauthUser->getEmail() ?: null;
        $avatar = $oauthUser->getAvatar() ?: null;

        $existing = $this->socialAuth->findExisting($provider, $providerId, $email);

        if ($existing) {
            return $this->socialAuth->touchExisting($existing, $email, $avatar);
        }

        [$firstName, $lastName] = $this->socialAuth->splitName(
            $oauthUser->getName() ?: $oauthUser->getNickname() ?: '',
        );

        return $this->socialAuth->createFromOAuth(
            $provider,
            $providerId,
            $email,
            $firstName,
            $lastName,
            $avatar,
            $oauthUser->getName() ?: null,
        );
    }

    private function socialiteDriver(string $provider)
    {
        $driver = Socialite::driver($provider);
        $proxy = trim((string) config('services.oauth.proxy', ''));
        if ($proxy !== '') {
            $driver->setHttpClient(new \GuzzleHttp\Client([
                'proxy' => $proxy,
                'timeout' => 20,
            ]));
        }

        return $driver;
    }

    private function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'odnoklassniki' => ! empty(config('services.odnoklassniki.client_id'))
                && ! empty(config('services.odnoklassniki.client_secret'))
                && ! empty(config('services.odnoklassniki.client_public')),
            'apple' => ! empty(config('services.apple.client_id'))
                && (
                    ! empty(config('services.apple.client_secret'))
                    || (
                        ! empty(config('services.apple.team_id'))
                        && ! empty(config('services.apple.key_id'))
                        && (! empty(config('services.apple.private_key')) || ! empty(config('services.apple.private_key_path')))
                    )
                ),
            default => ! empty(config("services.$provider.client_id"))
                && ! empty(config("services.$provider.client_secret")),
        };
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://127.0.0.1:5173')), '/');
    }

    private function uloginHost(Request $request): string
    {
        $configured = trim((string) config('services.ulogin.host', ''));
        if ($configured !== '') {
            return $configured;
        }

        $origin = $request->headers->get('origin') ?: $request->headers->get('referer');
        $source = $origin ?: $this->frontendUrl();
        $host = parse_url($source, PHP_URL_HOST);
        $port = parse_url($source, PHP_URL_PORT);

        return ($host ?: '127.0.0.1') . ($port ? ':' . $port : '');
    }
}
