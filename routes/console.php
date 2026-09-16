<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('discord:status')->everyFiveMinutes();
// Chat history is intentionally retained indefinitely. Keep the legacy
// command unscheduled as an additional safeguard against accidental purges.
Schedule::command('world:cleanup-deleted')->weekly();
Schedule::command('db:backup')->dailyAt('03:00');
