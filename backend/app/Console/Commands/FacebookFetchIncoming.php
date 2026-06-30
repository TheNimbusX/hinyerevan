<?php

namespace App\Console\Commands;

use App\Services\Facebook\FacebookIncomingService;
use Illuminate\Console\Command;

class FacebookFetchIncoming extends Command
{
    protected $signature = 'facebook:fetch-incoming {--limit=25}';

    protected $description = 'Fetch posts made directly on the Facebook Page into the admin import inbox';

    public function handle(FacebookIncomingService $service): int
    {
        $stored = $service->fetchAndStore((int) $this->option('limit'));
        $this->info("Stored {$stored} new incoming Facebook post(s).");

        return self::SUCCESS;
    }
}
