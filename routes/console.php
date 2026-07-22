<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:expire-listings')->hourly();
Schedule::command('equipment-market:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shops:expire-eggs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('market:generate-npc-listings --limit=6')->everySixHours()->withoutOverlapping();
Schedule::command('npc-requests:expire')->hourly();
Schedule::command('npc-requests:generate')->dailyAt('05:00');
Schedule::command('arena:npc-auto-battles --battles=2')->dailyAt('07:20')->withoutOverlapping();
Schedule::command('arena:npc-auto-battles --battles=1')->dailyAt('15:20')->withoutOverlapping();
Schedule::command('arena:npc-auto-battles --battles=2')->dailyAt('22:20')->withoutOverlapping();
Schedule::command('portal:send-online-count')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('note:rss-sync')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('security:detect-anomalies')->everyFiveMinutes()->withoutOverlapping(10);
