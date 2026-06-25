<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:expire-listings')->hourly();
Schedule::command('npc-requests:expire')->hourly();
Schedule::command('npc-requests:generate')->dailyAt('05:00');
Schedule::command('portal:send-online-count')->everyFiveMinutes()->withoutOverlapping();
