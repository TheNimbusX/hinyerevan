<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /** Define the application's command schedule. */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new \App\Jobs\SyncFacebookPostStatsJob())->everyFiveMinutes();

        $schedule->command('facebook:fetch-incoming')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('facebook:refresh-token')
            ->weekly()
            ->sundays()
            ->at('04:10')
            ->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
