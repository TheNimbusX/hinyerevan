<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommentLike extends Model
{
    protected $fillable = [
        'photo_id',
        'comment_id',
        'facebook_comment_id',
        'user_unique',
    ];

    protected $casts = [
        'photo_id' => 'integer',
        'comment_id' => 'integer',
    ];
}
