<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    protected $table = 'favorites';

    public $timestamps = false;

    protected $fillable = [
        'user_unique',
        'photo_id',
        'created_at',
    ];

    protected $casts = [
        'photo_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'photo_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_unique', 'unique');
    }
}
