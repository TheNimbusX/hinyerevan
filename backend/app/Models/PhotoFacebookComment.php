<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoFacebookComment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'photo_id',
        'facebook_comment_id',
        'parent_facebook_comment_id',
        'author_name',
        'author_picture',
        'body',
        'commented_at',
        'synced_at',
    ];

    protected $casts = [
        'photo_id' => 'integer',
        'commented_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }
}
