<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdBanner extends Model
{
    protected $fillable = ['title', 'link_url', 'image_path', 'sort', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    public function imageUrl(): string
    {
        // Version in the URL: a replaced image is never served from browser cache.
        return '/api/ads/' . $this->id . '/image?v=' . ($this->updated_at?->timestamp ?? 0);
    }
}
