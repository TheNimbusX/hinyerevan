<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\Facebook\FacebookPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishPhotoToFacebookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $photoId) {}

    public function handle(FacebookPublishService $facebook): void
    {
        $photo = Photo::query()->find($this->photoId);
        if (! $photo || ! $photo->facebook_publish_pending || ! $photo->published) {
            return;
        }

        $error = $facebook->publishIfPending($photo);
        if ($error) {
            Log::warning('Facebook publish job failed', [
                'photo_id' => $this->photoId,
                'error' => $error,
            ]);
        }
    }
}
