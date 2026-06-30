<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookIncomingPost extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'facebook_post_id',
        'message',
        'image_url',
        'permalink_url',
        'posted_at',
        'status',
        'photo_id',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'photo_id' => 'integer',
    ];
}
