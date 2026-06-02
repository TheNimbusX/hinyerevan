<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\Facebook\FacebookPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFacebookPostStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FacebookPublishService $facebook): void
    {
        if (! $facebook->isConfigured()) {
            return;
        }

        Photo::query()
            ->published()
            ->whereNotNull('facebook_post_id')
            ->where('facebook_post_id', '!=', '')
            ->orderBy('id')
            ->chunkById(25, function ($photos) use ($facebook) {
                foreach ($photos as $photo) {
                    $facebook->syncPostStats($photo);
                }
            });
    }
}
