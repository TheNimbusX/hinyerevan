<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model
{
    protected $table = 'news';

    public $timestamps = false;

    protected $fillable = [
        'title',
        'content',
        'date',
        'published',
    ];

    protected $casts = [
        'id' => 'integer',
        'published' => 'boolean',
        'date' => 'datetime',
    ];

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('id', '>', 0);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->alive()->where('published', 1);
    }
}
