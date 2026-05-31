<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LegacySchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Providers we know how to drive. Each entry carries the label/brand colour
     * shown on the sign-in screen; a provider is only offered when its client
     * credentials are present in config/services.php.
     */
    private const PROVIDERS = [
        'google' => ['label' => 'Google', 'color' => '#ea4335'],
        'facebook' => ['label' => 'Facebook', 'color' => '#1877f2'],
        'yandex' => ['label' => 'Yandex', 'color' => '#fc3f1d'],
        'vkontakte' => ['label' => 'VK', 'color' => '#0077ff'],
        'odnoklassniki' => ['label' => 'OK', 'color' => '#ee8208'],
    ];

    /**
     * Legacy `network` values that should be treated as the same provider when
     * matching returning users (the old site stored some under different names).
     */
    private const NETWORK_ALIASES = [
        'google' => ['google', 'googleplus'],
        'facebook' => ['facebook'],
        'yandex' => ['yandex'],
        'vkontakte' => ['vkontakte', 'vk'],
        'odnoklassniki' => ['odnoklassniki', 'ok'],
        'mailru' => ['mailru', 'mail'],
        'twitter' => ['twitter'],
        'linkedin' => ['linkedin'],
        'instagram' => ['instagram'],
    ];

    /** Public list of providers that are actually configured and ready to use. */
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

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider)
    {
        $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://127.0.0.1:5173')), '/');

        if (! isset(self::PROVIDERS[$provider]) || ! $this->isConfigured($provider)) {
            return redirect()->away($frontend . '/auth?social_error=' . urlencode('Unknown provider.'));
        }

        if (! LegacySchema::usersReady()) {
            return redirect()->away($frontend . '/auth?social_error=' . urlencode('User database is not connected yet.'));
        }

        try {
            $driver = Socialite::driver($provider)->stateless();

            // Route the token/userinfo calls through a proxy when configured —
            // needed when the backend network blocks the provider (dev/VPN).
            $proxy = trim((string) config('services.oauth.proxy', ''));
            if ($proxy !== '') {
                $driver->setHttpClient(new \GuzzleHttp\Client([
                    'proxy' => $proxy,
                    'timeout' => 20,
                ]));
            }

            $oauthUser = $driver->user();
        } catch (\Throwable $e) {
            \Log::error('Social login failed', [
                'provider' => $provider,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return redirect()->away($frontend . '/auth?social_error=' . urlencode('Could not sign in with ' . self::PROVIDERS[$provider]['label'] . '.'));
        }

        $user = $this->findOrCreateUser($provider, $oauthUser);

        if ($user->isBlocked()) {
            return redirect()->away($frontend . '/auth?social_error=' . urlencode('This account is blocked.'));
        }

        $user->forceFill(['last_ip' => request()->ip()])->save();
        $token = $user->createToken('spa-' . $provider)->plainTextToken;

        return redirect()->away($frontend . '/auth?social_token=' . urlencode($token));
    }

    /**
     * uLogin sign-in. The widget hands the SPA a one-time token; we exchange it
     * for the user profile via uLogin's token endpoint and log the user in.
     *
     * uLogin returns the provider's native `network`+`uid`, which is exactly what
     * the legacy site stored — so returning social users are matched seamlessly.
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
            // Optional outbound proxy — handy when ulogin.ru is unreachable
            // directly (e.g. network/geo filtering during local development).
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
                'message' => 'Could not reach uLogin. Check the server\'s internet access (set ULOGIN_PROXY if ulogin.ru is blocked).',
            ], 502);
        }

        if (! is_array($data) || ! empty($data['error']) || empty($data['network']) || empty($data['uid'])) {
            $message = is_array($data) && ! empty($data['error'])
                ? 'uLogin: ' . $data['error']
                : 'uLogin verification failed.';

            return response()->json(['message' => $message], 422);
        }

        $user = $this->resolveUloginUser($data);

        if ($user->isBlocked()) {
            return response()->json(['message' => 'This account is blocked.'], 403);
        }

        $user->forceFill(['last_ip' => $request->ip()])->save();
        $authToken = $user->createToken('spa-ulogin')->plainTextToken;

        return response()->json(['token' => $authToken]);
    }

    private function resolveUloginUser(array $d): User
    {
        $network = mb_strtolower((string) $d['network']);
        $uid = (string) $d['uid'];
        $email = $d['email'] ?? null;
        $photo = $d['photo_big'] ?? $d['photo'] ?? null;

        // 1) Same provider (incl. legacy aliases) + uid — the common case for
        //    returning users, since the old site used uLogin too.
        $user = User::query()
            ->whereIn('network', $this->networkCandidates($network))
            ->where('uid', $uid)
            ->first();

        // 2) Fall back to a shared email so changed/legacy ids still reconnect.
        if (! $user && $email) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $email)])->first();
        }

        if ($user) {
            $fill = [];
            if (! $user->email && $email) {
                $fill['email'] = $email;
            }
            if ((! $user->photo || str_contains((string) $user->photo, '/user.png')) && $photo) {
                $fill['photo'] = $photo;
            }
            if ($fill) {
                $user->forceFill($fill)->save();
            }

            return $user;
        }

        return User::query()->create([
            'uid' => $uid,
            'network' => $network,
            'unique' => md5($network . ':' . $uid),
            'first_name' => (string) ($d['first_name'] ?? ''),
            'last_name' => (string) ($d['last_name'] ?? ''),
            'email' => (string) ($email ?? ''),
            'identity' => (string) ($d['identity'] ?? ($d['first_name'] ?? '')),
            'bdate' => $this->uloginBdate($d['bdate'] ?? null),
            'sex' => $this->uloginSex($d['sex'] ?? null),
            'photo' => (string) ($photo ?: 'http://www.hinyerevan.com/photos/user.png'),
            'type' => User::TYPE_USER,
            'password' => md5(Str::random(40)),
            'last_ip' => request()->ip(),
        ]);
    }

    /** Network values to match against, expanding any known legacy aliases. */
    private function networkCandidates(string $network): array
    {
        $set = [$network];
        foreach (self::NETWORK_ALIASES as $aliases) {
            if (in_array($network, $aliases, true)) {
                $set = array_merge($set, $aliases);
            }
        }

        return array_values(array_unique($set));
    }

    /** uLogin sends `dd.mm.yyyy`; store as `Y-m-d`. */
    private function uloginBdate($value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return '1970-01-01';
    }

    /** uLogin sex: 1 = female, 2 = male. Our schema uses 1 = male, 0 = female. */
    private function uloginSex($value): int
    {
        return (string) $value === '2' ? 1 : 0;
    }

    /** Host passed to uLogin's token endpoint — must match where the widget ran. */
    private function uloginHost(Request $request): string
    {
        $configured = trim((string) config('services.ulogin.host', ''));
        if ($configured !== '') {
            return $configured;
        }

        $origin = $request->headers->get('origin') ?: $request->headers->get('referer');
        $source = $origin ?: (string) config('app.frontend_url', 'http://127.0.0.1:5173');
        $host = parse_url($source, PHP_URL_HOST);
        $port = parse_url($source, PHP_URL_PORT);

        return ($host ?: '127.0.0.1') . ($port ? ':' . $port : '');
    }

    private function isConfigured(string $provider): bool
    {
        return ! empty(config("services.$provider.client_id"))
            && ! empty(config("services.$provider.client_secret"));
    }

    private function findOrCreateUser(string $provider, $oauthUser): User
    {
        $providerId = (string) $oauthUser->getId();
        $email = $oauthUser->getEmail();
        $networks = self::NETWORK_ALIASES[$provider] ?? [$provider];

        // 1) Returning social user — same provider (incl. legacy aliases) + id.
        //    Works for providers whose user id is stable across OAuth apps
        //    (Google, VK, Odnoklassniki). Facebook now issues app-scoped ids
        //    and old Yandex used OpenID ids, so those rely on the email match.
        $user = User::query()
            ->whereIn('network', $networks)
            ->where('uid', $providerId)
            ->first();

        // 2) Otherwise link to any existing account that shares the email
        //    (case-insensitive) — this is what reconnects legacy Facebook /
        //    Yandex users whose provider id changed.
        if (! $user && $email) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        }

        if ($user) {
            // Refresh avatar/email if the legacy record was missing them.
            $fill = [];
            if (! $user->email && $email) {
                $fill['email'] = $email;
            }
            if ((! $user->photo || str_contains((string) $user->photo, '/user.png')) && $oauthUser->getAvatar()) {
                $fill['photo'] = $oauthUser->getAvatar();
            }
            if ($fill) {
                $user->forceFill($fill)->save();
            }

            return $user;
        }

        [$firstName, $lastName] = $this->splitName($oauthUser->getName() ?: $oauthUser->getNickname() ?: '');

        return User::query()->create([
            'uid' => $providerId,
            'network' => $provider,
            'unique' => md5($provider . ':' . $providerId),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email ?: '',
            'identity' => $oauthUser->getName() ?: '',
            'bdate' => '1970-01-01',
            'sex' => 0,
            'photo' => $oauthUser->getAvatar() ?: 'http://www.hinyerevan.com/photos/user.png',
            'type' => User::TYPE_USER,
            // Social accounts have no local password; a random hash blocks password login.
            'password' => md5(Str::random(40)),
            'last_ip' => request()->ip(),
        ]);
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
