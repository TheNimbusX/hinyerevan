<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Photo extends Model
{
    public const DIRECTIONS = [
        0 => 'Նկար բարձրությունից',
        1 => 'Հյուսիս',
        2 => 'Հյուսիս-Արևելք',
        3 => 'Արևելք',
        4 => 'Հարավ-Արևելք',
        5 => 'Հարավ',
        6 => 'Հարավ-Արևմուտք',
        7 => 'Արևմուտք',
        8 => 'Հյուսիս-Արևմուտք',
    ];

    protected $table = 'photos';

    public $timestamps = false;

    protected $fillable = [
        'title',
        'lat',
        'lng',
        'datetime',
        'user',
        'direction',
        'year',
        'published',
        'file_id',
        'video',
        'needs_location_review',
        'facebook_post_id',
        'facebook_post_url',
        'facebook_publish_pending',
        'facebook_comment',
        'facebook_likes',
        'facebook_comments_count',
        'facebook_synced_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'direction' => 'integer',
        'year' => 'integer',
        'published' => 'boolean',
        'needs_location_review' => 'boolean',
        'facebook_publish_pending' => 'boolean',
        'facebook_likes' => 'integer',
        'facebook_comments_count' => 'integer',
        'facebook_synced_at' => 'datetime',
        'datetime' => 'datetime',
    ];

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('id', '>', 0);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->alive()->where('published', 1);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user', 'unique');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')->alive();
    }

    public function viewCounter(): HasOne
    {
        return $this->hasOne(PhotoView::class, 'photo_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class, 'photo_id');
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTIONS[(int) $this->direction] ?? self::DIRECTIONS[0];
    }

    public function getImageUrlsAttribute(): array
    {
        return [
            'original' => "/api/photos/file/original/{$this->file_id}",
            'large' => "/api/photos/file/large/{$this->file_id}",
            'thumb' => "/api/photos/file/thumb/{$this->file_id}",
        ];
    }
}
