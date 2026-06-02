<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves social/OAuth logins against the legacy HinYerevan users table.
 *
 * Legacy uLogin stored unique = md5(provider_uid) without a network prefix.
 * Matching order: network+uid → email → legacy unique → create.
 */
class SocialAuthService
{
    public const DEFAULT_PHOTO = 'http://www.hinyerevan.com/photos/user.png';

    public const LEGACY_USER_PHOTO_PREFIX = 'http://www.hinyerevan.com/photos/users/';

    public function __construct(
        private readonly LegacyPhotoStorage $photoStorage,
    ) {}

    public const NETWORK_ALIASES = [
        'google' => ['google', 'googleplus'],
        'facebook' => ['facebook'],
        'yandex' => ['yandex'],
        'vkontakte' => ['vkontakte', 'vk'],
        'odnoklassniki' => ['odnoklassniki', 'ok'],
        'instagram' => ['instagram'],
        'apple' => ['apple'], // legacy DB only — OAuth removed
        'mailru' => ['mailru', 'mail'],
        'twitter' => ['twitter'],
        'linkedin' => ['linkedin'],
    ];

    /** Canonical value written to users.network for each OAuth driver id. */
    public const STORAGE_NETWORK = [
        'google' => 'google',
        'facebook' => 'facebook',
        'yandex' => 'yandex',
        'vkontakte' => 'vkontakte',
        'odnoklassniki' => 'odnoklassniki',
        'instagram' => 'instagram',
        'mailru' => 'mailru',
    ];

    public function findExisting(string $driver, string $providerId, ?string $email): ?User
    {
        $networks = $this->networkCandidates($driver);

        $user = User::query()
            ->whereIn('network', $networks)
            ->where('uid', $providerId)
            ->first();

        if (! $user && $email !== null && $email !== '') {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();
        }

        if (! $user) {
            $user = User::query()
                ->where('unique', md5($providerId))
                ->first();
        }

        return $user;
    }

    public function touchExisting(User $user, ?string $email, ?string $photo): User
    {
        $fill = [];

        if (! $user->email && $email) {
            $fill['email'] = $email;
        }

        if ($photo && $this->shouldRefreshPhoto($user->photo)) {
            $fill['photo'] = $this->normalizePhoto($this->upgradeAvatarUrl($photo));
        }

        if ($fill !== []) {
            $user->forceFill($fill)->save();
        }

        return $user;
    }

    public function createFromOAuth(
        string $driver,
        string $providerId,
        ?string $email,
        string $firstName,
        string $lastName,
        ?string $photo,
        ?string $identity = null,
    ): User {
        $network = self::STORAGE_NETWORK[$driver] ?? $driver;

        return User::query()->create([
            'uid' => $providerId,
            'network' => $network,
            'unique' => md5($providerId),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email ?: '',
            'identity' => $identity ?: trim($firstName . ' ' . $lastName),
            'bdate' => '1970-01-01',
            'sex' => 0,
            'photo' => $this->normalizePhoto($photo),
            'type' => User::TYPE_USER,
            'password' => md5(Str::random(40)),
            'last_ip' => request()->ip(),
        ]);
    }

    /** Match uLogin token payload (same rules as OAuth). */
    public function resolveFromUlogin(array $data): User
    {
        $network = mb_strtolower((string) $data['network']);
        $uid = (string) $data['uid'];
        $email = isset($data['email']) ? (string) $data['email'] : null;
        $photo = $data['photo_big'] ?? $data['photo'] ?? null;

        $user = User::query()
            ->whereIn('network', $this->networkCandidates($network))
            ->where('uid', $uid)
            ->first();

        if (! $user && $email) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();
        }

        if (! $user) {
            $user = User::query()->where('unique', md5($uid))->first();
        }

        if ($user) {
            return $this->touchExisting($user, $email ?: null, $photo ? (string) $photo : null);
        }

        return User::query()->create([
            'uid' => $uid,
            'network' => $network,
            'unique' => md5($uid),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'email' => (string) ($email ?? ''),
            'identity' => (string) ($data['identity'] ?? ($data['first_name'] ?? '')),
            'bdate' => $this->uloginBdate($data['bdate'] ?? null),
            'sex' => $this->uloginSex($data['sex'] ?? null),
            'photo' => $this->normalizePhoto($photo ? (string) $photo : null),
            'type' => User::TYPE_USER,
            'password' => md5(Str::random(40)),
            'last_ip' => request()->ip(),
        ]);
    }

    public function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }

    public function networkCandidates(string $network): array
    {
        $network = mb_strtolower($network);
        $set = [$network];

        foreach (self::NETWORK_ALIASES as $aliases) {
            if (in_array($network, $aliases, true)) {
                $set = array_merge($set, $aliases);
            }
        }

        return array_values(array_unique($set));
    }

    private function shouldRefreshPhoto(?string $photo): bool
    {
        if (! $photo) {
            return true;
        }

        return str_contains($photo, '/user.png');
    }

    /**
     * Legacy users.photo is a short varchar — store OAuth avatars locally, not full URLs.
     */
    /** Prefer high-resolution OAuth avatar URLs before downloading. */
    public function upgradeAvatarUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        if (str_contains($url, 'graph.facebook.com') || str_contains($url, 'fbcdn.net')) {
            $parts = parse_url($url);
            if (! is_array($parts)) {
                return $url;
            }

            parse_str($parts['query'] ?? '', $query);
            $query['width'] = '320';
            $query['height'] = '320';

            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            $path = $parts['path'] ?? '';

            return $base . $path . '?' . http_build_query($query);
        }

        if (str_contains($url, 'googleusercontent.com')) {
            if (preg_match('/=s\d+-c/', $url)) {
                return preg_replace('/=s\d+-c/', '=s256-c', $url) ?? $url;
            }

            return str_contains($url, '?') ? $url . '&sz=256' : $url . '?sz=256';
        }

        if (str_contains($url, 'userapi.com') || str_contains($url, 'vk-cdn')) {
            return preg_replace('/photo_\d+/', 'photo_200', $url) ?? $url;
        }

        return $url;
    }

    /**
     * Attach a social provider to a local (hinyerevan) account. Keeps users.unique
     * so legacy photo ownership (photos.user) stays valid; email+password login still works.
     */
    public function linkProviderToUser(
        User $user,
        string $driver,
        string $providerId,
        ?string $email,
        ?string $photo,
    ): User {
        $conflict = User::query()
            ->where('id', '!=', $user->id)
            ->whereIn('network', $this->networkCandidates($driver))
            ->where('uid', $providerId)
            ->exists();

        if ($conflict) {
            throw new \RuntimeException('This social account is already linked to another user.');
        }

        $fill = [
            'network' => self::STORAGE_NETWORK[$driver] ?? $driver,
            'uid' => $providerId,
        ];

        if (! $user->email && $email) {
            $fill['email'] = $email;
        }

        if ($photo) {
            $fill['photo'] = $this->normalizePhoto($photo);
        }

        $user->forceFill($fill)->save();

        return $user;
    }

    public function canLinkSocialAccount(User $user): bool
    {
        return mb_strtolower((string) $user->network) === 'hinyerevan';
    }

    private function normalizePhoto(?string $photo): string
    {
        $photo = trim((string) $photo);
        if ($photo === '' || str_contains($photo, '/user.png')) {
            return self::DEFAULT_PHOTO;
        }

        if (str_starts_with($photo, self::LEGACY_USER_PHOTO_PREFIX)) {
            return substr($photo, strlen(self::LEGACY_USER_PHOTO_PREFIX));
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $photo)) {
            return strtolower($photo);
        }

        if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
            $fileId = $this->photoStorage->storeUserPhotoFromUrl(
                $this->upgradeAvatarUrl($photo),
                (string) config('app.key'),
            );

            if ($fileId !== null) {
                // Legacy column is short; store md5 filename only (frontend resolves via /api/photos/file/users/).
                return $fileId;
            }

            return self::DEFAULT_PHOTO;
        }

        return self::DEFAULT_PHOTO;
    }

    private function uloginBdate($value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return '1970-01-01';
    }

    private function uloginSex($value): int
    {
        return (string) $value === '2' ? 1 : 0;
    }
}
