<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new \App\Jobs\SyncFacebookPostStatsJob())->everyFiveMinutes();

        // Pull posts made directly on the Facebook Page into the admin import inbox.
        $schedule->command('facebook:fetch-incoming')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Keep the long-lived Page token healthy (data-access window ~90 days).
        $schedule->command('facebook:refresh-token')
            ->weekly()
            ->sundays()
            ->at('04:10')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
