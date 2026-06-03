<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\Facebook\FacebookPublishService;
use Illuminate\Console\Command;

class FacebookSyncStats extends Command
{
    protected $signature = 'facebook:sync-stats {photo? : Photo id (optional, sync all when omitted)}';

    protected $description = 'Sync Facebook likes, comments, permalink for published FB posts';

    public function handle(FacebookPublishService $facebook): int
    {
        if (! $facebook->isConfigured()) {
            $this->error('Facebook Page is not configured.');

            return self::FAILURE;
        }

        $photoId = $this->argument('photo');

        if ($photoId !== null) {
            $photo = Photo::query()->find($photoId);
            if (! $photo || ! $photo->facebook_post_id) {
                $this->error('Photo not found or not published to Facebook.');

                return self::FAILURE;
            }
            $facebook->syncPostStats($photo);
            $this->info("Synced photo {$photo->id}");

            return self::SUCCESS;
        }

        $count = 0;
        Photo::query()
            ->published()
            ->whereNotNull('facebook_post_id')
            ->where('facebook_post_id', '!=', '')
            ->orderBy('id')
            ->chunkById(25, function ($photos) use ($facebook, &$count) {
                foreach ($photos as $photo) {
                    $facebook->syncPostStats($photo);
                    $count++;
                }
            });

        $this->info("Synced {$count} photo(s).");

        return self::SUCCESS;
    }
}
