<?php

namespace App\Services;

use App\Models\SixHeroSeason;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SixHeroSeasonService
{
    public function assertMatchesCalendarMonth(SixHeroSeason $season): SixHeroSeason
    {
        return $this->assertMatchesPeriod(
            $season,
            $this->periodForKey((string) $season->season_key),
        );
    }

    public function currentPeriod(?CarbonInterface $at = null): SixHeroSeasonPeriod
    {
        return $this->periodFor($this->inAppTimezone($at));
    }

    public function findCurrentSeason(?CarbonInterface $at = null): ?SixHeroSeason
    {
        $current = $this->inAppTimezone($at);
        $period = $this->periodFor($current);
        $season = SixHeroSeason::query()
            ->where('season_key', $period->key)
            ->first();

        return $season === null
            ? null
            : $this->assertMatchesPeriod($season, $period);
    }

    public function currentSeason(?CarbonInterface $at = null): SixHeroSeason
    {
        $current = $this->inAppTimezone($at);
        $period = $this->periodFor($current);
        $season = SixHeroSeason::query()
            ->where('season_key', $period->key)
            ->first();
        if ($season !== null) {
            return $this->assertMatchesPeriod($season, $period);
        }

        DB::table('six_hero_seasons')->insertOrIgnore([
            'season_key' => $period->key,
            'starts_at' => $period->startsAt,
            'ends_at' => $period->endsAt,
            'finalized_at' => null,
            'ranking_initialized_at' => null,
            'created_at' => $current,
            'updated_at' => $current,
        ]);

        $season = SixHeroSeason::query()
            ->where('season_key', $period->key)
            ->firstOrFail();

        return $this->assertMatchesPeriod($season, $period);
    }

    private function periodFor(CarbonImmutable $current): SixHeroSeasonPeriod
    {
        $startsAt = $current->startOfMonth()->startOfDay();

        return new SixHeroSeasonPeriod(
            key: $startsAt->format('Y-m'),
            startsAt: $startsAt,
            endsAt: $startsAt->addMonth(),
        );
    }

    private function periodForKey(string $key): SixHeroSeasonPeriod
    {
        if (preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/D', $key) !== 1) {
            throw new LogicException(
                "Six Heroes Season {$key} has an invalid season_key.",
            );
        }

        $startsAt = CarbonImmutable::create(
            (int) substr($key, 0, 4),
            (int) substr($key, 5, 2),
            1,
            0,
            0,
            0,
            $this->timezone(),
        );

        return new SixHeroSeasonPeriod(
            key: $key,
            startsAt: $startsAt,
            endsAt: $startsAt->addMonth(),
        );
    }

    private function assertMatchesPeriod(
        SixHeroSeason $season,
        SixHeroSeasonPeriod $period,
    ): SixHeroSeason {
        $startsAt = CarbonImmutable::instance($season->starts_at)
            ->setTimezone($this->timezone());
        $endsAt = CarbonImmutable::instance($season->ends_at)
            ->setTimezone($this->timezone());
        if (! $startsAt->equalTo($period->startsAt)
            || ! $endsAt->equalTo($period->endsAt)
        ) {
            throw new LogicException(
                "Six Heroes Season {$period->key} has invalid month boundaries.",
            );
        }

        return $season;
    }

    private function inAppTimezone(?CarbonInterface $at): CarbonImmutable
    {
        return $at === null
            ? CarbonImmutable::now($this->timezone())
            : CarbonImmutable::instance($at)->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
