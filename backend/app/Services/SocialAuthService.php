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
    public const NETWORK_ALIASES = [
        'google' => ['google', 'googleplus'],
        'facebook' => ['facebook'],
        'yandex' => ['yandex'],
        'vkontakte' => ['vkontakte', 'vk'],
        'odnoklassniki' => ['odnoklassniki', 'ok'],
        'instagram' => ['instagram'],
        'apple' => ['apple'],
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
        'apple' => 'apple',
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
            $fill['photo'] = $photo;
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
            'photo' => $photo ?: 'http://www.hinyerevan.com/photos/user.png',
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
            'photo' => (string) ($photo ?: 'http://www.hinyerevan.com/photos/user.png'),
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
