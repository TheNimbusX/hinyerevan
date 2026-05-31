<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $table = 'comments';

    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'body',
        'user_unique',
        'datetime',
        'to',
    ];

    protected $casts = [
        'id' => 'integer',
        'to' => 'integer',
        'datetime' => 'datetime',
    ];

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('id', '>', 0);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_unique', 'unique');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'to')->alive()->oldest('datetime');
    }
}
