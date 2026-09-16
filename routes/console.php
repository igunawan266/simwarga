<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:work --queue=default --max-time=3600')->everyMinute()->withoutOverlapping();

Schedule::command('schedule:run')->everyMinute();

Schedule::command('app:cleanup-old-logs')->daily()->at('02:00');

Schedule::command('backup:run')->daily()->at('03:00');

Schedule::command('app:generate-monthly-dues')->monthlyOn(1, '01:00');
