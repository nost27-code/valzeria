<?php

use App\Services\WeeklyWinRankingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ranking:warm-weekly-win-cache', function (WeeklyWinRankingService $service) {
    $rowCount = $service->warmCurrentWidgetRowsCache();

    $this->info("週間勝利数番付キャッシュを更新しました（{$rowCount}件）");
})->purpose('週間勝利数番付のホーム表示用キャッシュを先回り更新する');

Schedule::command('market:expire-listings')->hourly();
Schedule::command('equipment-market:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shops:expire-eggs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('market:generate-npc-listings --limit=6')->everySixHours()->withoutOverlapping();
Schedule::command('npc-requests:expire')->hourly();
Schedule::command('npc-requests:generate')->dailyAt('05:00');
if (! (bool) config('features.six_hero_ui_enabled', false)) {
    Schedule::command('arena:npc-auto-battles --battles=2')->dailyAt('07:20')->withoutOverlapping();
    Schedule::command('arena:npc-auto-battles --battles=1')->dailyAt('15:20')->withoutOverlapping();
    Schedule::command('arena:npc-auto-battles --battles=2')->dailyAt('22:20')->withoutOverlapping();
}
Schedule::command('portal:send-online-count')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('note:rss-sync')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('contact-mail:import')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('web-push:dispatch')->everyMinute()->withoutOverlapping(10);
Schedule::command('security:detect-anomalies')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('ranking:finalize-weekly-wins --automatic')
    ->dailyAt(config('weekly_win_ranking.finalize_time', '09:05'))
    ->timezone(config('weekly_win_ranking.timezone', 'Asia/Tokyo'))
    ->withoutOverlapping(30);
Schedule::command('ranking:warm-weekly-win-cache')
    ->everyThirtyMinutes()
    ->timezone(config('weekly_win_ranking.timezone', 'Asia/Tokyo'))
    ->withoutOverlapping(10);
Schedule::command('six-heroes:ensure-current-season')
    ->dailyAt('00:05')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);
Schedule::command('six-heroes:finalize-ended-seasons')
    ->everyTenMinutes()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);
Schedule::command('six-heroes:initialize-current-rankings')
    ->everyTenMinutes()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);
Schedule::command('six-heroes:health-check --quiet')
    ->hourly()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10);
