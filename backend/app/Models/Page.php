<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table = 'pages';

    public $timestamps = false;

    protected $fillable = [
        'title',
        'alias',
        'content',
    ];

    protected $casts = [
        'id' => 'integer',
    ];

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('id', '>', 0);
    }
}
