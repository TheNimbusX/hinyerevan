<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\Facebook\FacebookPublishService;
use Illuminate\Console\Command;

class FacebookPublishPhoto extends Command
{
    protected $signature = 'facebook:publish {photo_id : Photo id to publish to the configured Page}';

    protected $description = 'Publish a photo to the Facebook Page (or retry a failed/pending publish)';

    public function handle(FacebookPublishService $publish): int
    {
        $photo = Photo::query()->find($this->argument('photo_id'));

        if (! $photo) {
            $this->error('Photo not found.');

            return self::FAILURE;
        }

        if (! $photo->published) {
            $this->warn('Photo is not published on the site yet. Approve it first or publish as admin.');

            return self::FAILURE;
        }

        if (! $photo->facebook_publish_pending && $photo->facebook_post_id) {
            $this->info('Already on Facebook: ' . $photo->facebook_post_url);

            return self::SUCCESS;
        }

        $photo->forceFill(['facebook_publish_pending' => true])->save();

        $error = $publish->publishPhoto($photo->fresh());

        if ($error) {
            $this->error($error);

            return self::FAILURE;
        }

        $photo->refresh();
        $this->info('Published: ' . ($photo->facebook_post_url ?: $photo->facebook_post_id));

        return self::SUCCESS;
    }
}
