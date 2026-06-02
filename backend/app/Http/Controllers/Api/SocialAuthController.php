<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LegacySchema;
use App\Services\SocialAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        'mailru' => ['label' => 'Mail', 'color' => '#005ff9'],
        'yandex' => ['label' => 'Yandex', 'color' => '#fc3f1d'],
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

        $this->consumeSocialLinkKey($provider);

        if ($provider === 'odnoklassniki' && $this->usesVkIdForOk()) {
            return $this->vkIdRedirect('ok_ru');
        }

        if ($provider === 'mailru' && $this->usesVkIdForMail()) {
            return $this->vkIdRedirect('mail_ru');
        }

        if ($provider === 'vkontakte') {
            return $this->vkIdRedirect('vkid');
        }

        $driver = $this->socialiteDriver($provider, $this->usesOAuthSession($provider));

        return match ($provider) {
            'facebook' => $driver->scopes(['email', 'public_profile'])->redirect(),
            'yandex' => $driver->scopes(['login:email', 'login:info'])->redirect(),
            default => $driver->redirect(),
        };
    }

    public function callback(string $provider)
    {
        set_time_limit(120);

        $frontend = $this->frontendUrl();

        if (! isset(self::PROVIDERS[$provider]) || ! $this->isConfigured($provider)) {
            return redirect()->away($frontend . '/?social_error=' . urlencode('Unknown provider.'));
        }

        if (! LegacySchema::usersReady()) {
            return redirect()->away($frontend . '/?social_error=' . urlencode('User database is not connected yet.'));
        }

        $storageNetwork = $provider;

        try {
            if ($provider === 'vkontakte') {
                $storageNetwork = (string) request()->session()->pull('social_oauth_network', 'vkontakte');
                if (! isset(self::PROVIDERS[$storageNetwork])) {
                    $storageNetwork = 'vkontakte';
                }
                $oauthUser = $this->socialiteDriver('vkontakte', true)->user();
            } else {
                $oauthUser = $this->socialiteDriver($provider, $this->usesOAuthSession($provider))->user();
            }
        } catch (\Throwable $e) {
            \Log::error('Social login failed', [
                'provider' => $provider,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->away(
                $frontend . '/?social_error=' . urlencode($this->socialErrorMessage($storageNetwork, $e)),
            );
        }

        $linkUserId = request()->session()->pull('social_link_user_id');

        try {
            if ($linkUserId) {
                $user = $this->resolveLinkUser((int) $linkUserId, $storageNetwork, $oauthUser);
            } else {
                $user = $this->resolveOAuthUser($storageNetwork, $oauthUser);
            }
        } catch (\RuntimeException $e) {
            return redirect()->away($frontend . '/?social_error=' . urlencode($e->getMessage()));
        }

        if ($user->isBlocked()) {
            return redirect()->away($frontend . '/?social_error=' . urlencode('This account is blocked.'));
        }

        $user->forceFill(['last_ip' => request()->ip()])->save();

        if ($linkUserId) {
            return redirect()->away($frontend . '/profile?tab=settings&social_linked=1');
        }

        $token = $user->createToken('spa-' . $storageNetwork)->plainTextToken;

        return redirect()->away($frontend . '/?social_token=' . urlencode($token));
    }

    /** Begin linking a provider to the logged-in local account (Bearer → one-time redirect key). */
    public function startLink(Request $request, string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);
        abort_unless($this->isConfigured($provider), 404, 'This provider is not configured.');

        $user = $request->user();
        abort_unless($this->socialAuth->canLinkSocialAccount($user), 403, 'Your account already uses social sign-in.');

        $key = Str::random(48);
        Cache::put('social_link:' . $key, $user->id, now()->addMinutes(15));

        return [
            'redirect_url' => url('/api/auth/social/' . $provider . '/redirect?link_key=' . urlencode($key)),
        ];
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

    private function resolveLinkUser(int $userId, string $provider, $oauthUser): User
    {
        $user = \App\Models\User::query()->findOrFail($userId);
        abort_unless($this->socialAuth->canLinkSocialAccount($user), 403);

        $avatar = $oauthUser->getAvatar() ?: null;
        if ($avatar) {
            $avatar = $this->socialAuth->upgradeAvatarUrl($avatar);
        }

        return $this->socialAuth->linkProviderToUser(
            $user,
            $provider,
            (string) $oauthUser->getId(),
            $oauthUser->getEmail() ?: null,
            $avatar,
        );
    }

    private function consumeSocialLinkKey(string $provider): void
    {
        $key = trim((string) request()->query('link_key', ''));
        if ($key === '') {
            return;
        }

        $userId = Cache::pull('social_link:' . $key);
        if ($userId) {
            request()->session()->put('social_link_user_id', (int) $userId);
        }
    }

    private function resolveOAuthUser(string $provider, $oauthUser): User
    {
        $providerId = (string) $oauthUser->getId();
        $email = $oauthUser->getEmail() ?: null;
        $avatar = $oauthUser->getAvatar() ?: null;
        if ($avatar) {
            $avatar = $this->socialAuth->upgradeAvatarUrl($avatar);
        }

        $existing = $this->socialAuth->findExisting($provider, $providerId, $email);

        if ($existing) {
            $net = mb_strtolower((string) $existing->network);
            $networks = $this->socialAuth->networkCandidates($provider);

            if (in_array($net, $networks, true) && (string) $existing->uid === $providerId) {
                return $this->socialAuth->touchExisting($existing, $email, $avatar, true);
            }

            if ($net === 'hinyerevan' || $net === '') {
                return $this->socialAuth->linkProviderToUser(
                    $existing,
                    $provider,
                    $providerId,
                    $email,
                    $avatar,
                );
            }

            \Log::warning('OAuth account mismatch; creating new user', [
                'provider' => $provider,
                'provider_id' => $providerId,
                'existing_id' => $existing->id,
                'existing_network' => $net,
            ]);
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

    private function usesOAuthSession(string $provider): bool
    {
        return $provider === 'vkontakte'
            || ($provider === 'odnoklassniki' && $this->usesVkIdForOk())
            || ($provider === 'mailru' && $this->usesVkIdForMail());
    }

    /** OK login via VK ID (provider=ok_ru), same app keys and callback as VK. */
    private function usesVkIdForOk(): bool
    {
        return $this->isVkIdConfigured() && ! $this->isLegacyOkConfigured();
    }

    /** Mail.ru login via VK ID (provider=mail_ru). */
    private function usesVkIdForMail(): bool
    {
        return $this->isVkIdConfigured();
    }

    private function isVkIdConfigured(): bool
    {
        return ! empty(config('services.vkontakte.client_id'))
            && ! empty(config('services.vkontakte.client_secret'));
    }

    private function isLegacyOkConfigured(): bool
    {
        return ! empty(config('services.odnoklassniki.client_id'))
            && ! empty(config('services.odnoklassniki.client_secret'))
            && ! empty(config('services.odnoklassniki.client_public'));
    }

    private const VK_ID_PROVIDER_NETWORK = [
        'vkid' => 'vkontakte',
        'ok_ru' => 'odnoklassniki',
        'mail_ru' => 'mailru',
    ];

    private function vkIdRedirect(string $providerParam): \Illuminate\Http\RedirectResponse
    {
        request()->session()->put(
            'social_oauth_network',
            self::VK_ID_PROVIDER_NETWORK[$providerParam] ?? 'vkontakte',
        );

        $driver = $this->socialiteDriver('vkontakte', true)
            ->scopes(['vkid.personal_info', 'email']);

        if ($providerParam !== 'vkid') {
            $driver = $driver->with(['provider' => $providerParam]);
        }

        return $driver->redirect();
    }

    private function socialiteDriver(string $provider, bool $withSession = false)
    {
        $driver = Socialite::driver($provider);

        $clientOptions = [
            'connect_timeout' => 8,
            'timeout' => 20,
        ];
        $proxy = trim((string) config('services.oauth.proxy', ''));
        if ($proxy !== '') {
            $clientOptions['proxy'] = $proxy;
        }
        $driver->setHttpClient(new \GuzzleHttp\Client($clientOptions));

        if (! $withSession) {
            $driver->stateless();
        }

        return $driver;
    }

    private function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'odnoklassniki' => $this->isLegacyOkConfigured() || $this->isVkIdConfigured(),
            'mailru' => $this->isVkIdConfigured(),
            default => ! empty(config("services.$provider.client_id"))
                && ! empty(config("services.$provider.client_secret")),
        };
    }

    private function socialErrorMessage(string $provider, \Throwable $e): string
    {
        $label = self::PROVIDERS[$provider]['label'] ?? $provider;
        $msg = $e->getMessage();

        if ($provider === 'yandex' && (
            str_contains($msg, 'login.yandex.ru')
            || str_contains($msg, 'OAUTH_PROXY')
            || str_contains($msg, 'Connection timed out')
            || str_contains($msg, 'Could not resolve host')
        )) {
            return 'Вход через Яндекс: сервер не может связаться с login.yandex.ru (часто блокируют IP датацентров). '
                .'Добавьте в backend/.env OAUTH_PROXY= socks5://… или http://… — прокси/VPN с доступом к Яндексу, затем php artisan config:cache.';
        }

        return 'Could not sign in with '.$label.'.';
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
