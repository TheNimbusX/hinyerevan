<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeedbackMessage extends Model
{
    protected $table = 'feedback_messages';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'email',
        'content',
        'read_at',
        'created_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markRead(): void
    {
        if ($this->read_at) {
            return;
        }

        $this->read_at = now();
        $this->save();
    }
}
