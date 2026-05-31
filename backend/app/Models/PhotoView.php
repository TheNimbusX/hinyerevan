<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoView extends Model
{
    protected $table = 'views';

    protected $primaryKey = 'photo_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'photo_id',
        'count',
    ];

    protected $casts = [
        'photo_id' => 'integer',
        'count' => 'integer',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'photo_id');
    }
}
