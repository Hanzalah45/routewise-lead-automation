<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retries for leads whose CRM delivery failed. Run `php artisan schedule:work` (or a cron entry) alongside the app.
Schedule::command('leads:sync-pending')->everyMinute()->withoutOverlapping();

if (config('routewise.public_demo')) {
    Schedule::command('routewise:demo-reset')->dailyAt('03:00');
}
