<?php

namespace BikeShare\Console;

use BikeShare\Console\Commands\BuildApiDocs;
use BikeShare\Console\Commands\CheckLongRents;
use BikeShare\Console\Commands\CheckManyRents;
use BikeShare\Console\Commands\ComposerUpdateScript;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        BuildApiDocs::class,
        ComposerUpdateScript::class,
        CheckLongRents::class,
        CheckManyRents::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        // $schedule->command('bike-share:long-rents')->at('23:55');
        // $schedule->command('bike-share:many-rents')->hourly();
    }

    /**
     * Register the Closure based commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
