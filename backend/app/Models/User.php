<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const TYPE_USER = 0;
    public const TYPE_BLOCKED = 1;
    public const TYPE_ADMIN = 5;

    public $timestamps = false;

    protected $fillable = [
        'uid',
        'network',
        'unique',
        'first_name',
        'last_name',
        'email',
        'identity',
        'bdate',
        'sex',
        'photo',
        'type',
        'password',
        'last_ip',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'type' => 'integer',
    ];

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'user', 'unique');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_unique', 'unique');
    }

    public function isAdmin(): bool
    {
        return (int) $this->type === self::TYPE_ADMIN;
    }

    public function isBlocked(): bool
    {
        return (int) $this->type === self::TYPE_BLOCKED;
    }

    public function getNameAttribute(): string
    {
        return $this->resolveDisplayName();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->resolveDisplayName();
    }

    public function looksLikeProviderId(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (bool) preg_match('/^\d{8,}$/', trim($value));
    }

    private function resolveDisplayName(): string
    {
        $full = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
        if ($full !== '') {
            return $full;
        }

        $identity = trim((string) ($this->identity ?? ''));
        if ($identity !== '' && ! $this->looksLikeProviderId($identity)) {
            return $identity;
        }

        $uid = trim((string) ($this->uid ?? ''));
        if ($uid !== '' && ! $this->looksLikeProviderId($uid)) {
            return $uid;
        }

        $email = trim((string) ($this->email ?? ''));
        if ($email !== '' && str_contains($email, '@')) {
            $local = strstr($email, '@', true) ?: '';
            if ($local !== '' && ! $this->looksLikeProviderId($local)) {
                return $local;
            }
        }

        return '';
    }
}
