<?php

namespace Tests\Feature;

use App\Models\SixHeroSeason;
use App\Services\SixHeroSeasonService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SixHeroSeasonServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);
        Carbon::setTestNow(Carbon::parse('2026-08-19 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('periodProvider')]
    public function test_current_period_uses_app_timezone_calendar_months(
        string $at,
        string $expectedKey,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $period = app(SixHeroSeasonService::class)->currentPeriod(
            CarbonImmutable::parse($at),
        );

        $this->assertSame($expectedKey, $period->key);
        $this->assertSame($expectedStart, $period->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame($expectedEnd, $period->endsAt->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tokyo', $period->startsAt->getTimezone()->getName());
        $this->assertSame('Asia/Tokyo', $period->endsAt->getTimezone()->getName());
    }

    public static function periodProvider(): array
    {
        return [
            'month start' => [
                '2026-08-01 00:00:00 Asia/Tokyo',
                '2026-08',
                '2026-08-01 00:00:00',
                '2026-09-01 00:00:00',
            ],
            'middle of month' => [
                '2026-08-19 10:31:00 Asia/Tokyo',
                '2026-08',
                '2026-08-01 00:00:00',
                '2026-09-01 00:00:00',
            ],
            'last second of month' => [
                '2026-08-31 23:59:59 Asia/Tokyo',
                '2026-08',
                '2026-08-01 00:00:00',
                '2026-09-01 00:00:00',
            ],
            'next month boundary' => [
                '2026-09-01 00:00:00 Asia/Tokyo',
                '2026-09',
                '2026-09-01 00:00:00',
                '2026-10-01 00:00:00',
            ],
            'thirty day month' => [
                '2026-04-30 23:59:59 Asia/Tokyo',
                '2026-04',
                '2026-04-01 00:00:00',
                '2026-05-01 00:00:00',
            ],
            'leap February' => [
                '2028-02-29 23:59:59 Asia/Tokyo',
                '2028-02',
                '2028-02-01 00:00:00',
                '2028-03-01 00:00:00',
            ],
            'year end' => [
                '2026-12-31 23:59:59 Asia/Tokyo',
                '2026-12',
                '2026-12-01 00:00:00',
                '2027-01-01 00:00:00',
            ],
            'new year boundary' => [
                '2027-01-01 00:00:00 Asia/Tokyo',
                '2027-01',
                '2027-01-01 00:00:00',
                '2027-02-01 00:00:00',
            ],
            'UTC before Tokyo month change' => [
                '2026-08-31 14:59:59 UTC',
                '2026-08',
                '2026-08-01 00:00:00',
                '2026-09-01 00:00:00',
            ],
            'UTC at Tokyo month change' => [
                '2026-08-31 15:00:00 UTC',
                '2026-09',
                '2026-09-01 00:00:00',
                '2026-10-01 00:00:00',
            ],
        ];
    }

    public function test_period_uses_the_configured_timezone_instead_of_a_hardcoded_zone(): void
    {
        config(['app.timezone' => 'America/Los_Angeles']);
        $service = app(SixHeroSeasonService::class);

        $beforeBoundary = $service->currentPeriod(
            CarbonImmutable::parse('2026-09-01 06:59:59 UTC'),
        );
        $atBoundary = $service->currentPeriod(
            CarbonImmutable::parse('2026-09-01 07:00:00 UTC'),
        );

        $this->assertSame('2026-08', $beforeBoundary->key);
        $this->assertSame('2026-09', $atBoundary->key);
        $this->assertSame(
            'America/Los_Angeles',
            $atBoundary->startsAt->getTimezone()->getName(),
        );
    }

    public function test_find_is_read_only_and_current_season_lazily_creates_only_one_season_row(): void
    {
        $service = app(SixHeroSeasonService::class);
        $at = CarbonImmutable::parse('2026-08-19 10:31:00 Asia/Tokyo');

        $this->assertNull($service->findCurrentSeason($at));
        $this->assertDatabaseCount('six_hero_seasons', 0);

        $season = $service->currentSeason($at);

        $this->assertSame('2026-08', $season->season_key);
        $this->assertSame(
            '2026-08-01 00:00:00',
            $season->starts_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-09-01 00:00:00',
            $season->ends_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($season->finalized_at);
        $this->assertDatabaseCount('six_hero_seasons', 1);
        $this->assertDatabaseCount('six_hero_rankings', 0);
        $this->assertDatabaseCount('six_hero_daily_usages', 0);
        $this->assertDatabaseCount('six_hero_battle_logs', 0);
    }

    public function test_current_season_is_idempotent_and_reuses_the_existing_row_one_hundred_times(): void
    {
        $service = app(SixHeroSeasonService::class);
        $at = CarbonImmutable::parse('2026-08-19 10:31:00 Asia/Tokyo');
        $first = $service->currentSeason($at);

        for ($call = 0; $call < 100; $call++) {
            $this->assertSame($first->id, $service->currentSeason($at)->id);
        }

        $this->assertSame($first->id, $service->findCurrentSeason($at)?->id);
        $this->assertDatabaseCount('six_hero_seasons', 1);
    }

    #[DataProvider('previousFinalizationProvider')]
    public function test_next_month_is_created_independently_of_previous_finalization(
        ?string $previousFinalizedAt,
    ): void {
        $previous = SixHeroSeason::query()->create([
            'season_key' => '2026-08',
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => '2026-09-01 00:00:00',
            'finalized_at' => $previousFinalizedAt,
            'ranking_initialized_at' => null,
        ]);
        $future = SixHeroSeason::query()->create([
            'season_key' => '2026-10',
            'starts_at' => '2026-10-01 00:00:00',
            'ends_at' => '2026-11-01 00:00:00',
            'finalized_at' => null,
            'ranking_initialized_at' => null,
        ]);
        $previousBefore = $previous->getRawOriginal();
        $futureBefore = $future->getRawOriginal();

        $current = app(SixHeroSeasonService::class)->currentSeason(
            CarbonImmutable::parse('2026-09-01 00:05:00 Asia/Tokyo'),
        );

        $this->assertSame('2026-09', $current->season_key);
        $this->assertNull($current->ranking_initialized_at);
        $this->assertEquals($previousBefore, $previous->fresh()->getRawOriginal());
        $this->assertEquals($futureBefore, $future->fresh()->getRawOriginal());
        $this->assertDatabaseCount('six_hero_seasons', 3);
        $this->assertDatabaseCount('six_hero_rankings', 0);
    }

    public static function previousFinalizationProvider(): array
    {
        return [
            'previous month unfinalized' => [null],
            'previous month finalized' => ['2026-09-01 00:03:00'],
        ];
    }

    public function test_only_the_current_gap_is_created_without_backfilling_missing_months(): void
    {
        SixHeroSeason::query()->create([
            'season_key' => '2026-07',
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-08-01 00:00:00',
            'finalized_at' => null,
        ]);

        $season = app(SixHeroSeasonService::class)->currentSeason(
            CarbonImmutable::parse('2026-09-15 12:00:00 Asia/Tokyo'),
        );

        $this->assertSame('2026-09', $season->season_key);
        $this->assertSame(
            ['2026-07', '2026-09'],
            SixHeroSeason::query()->orderBy('season_key')->pluck('season_key')->all(),
        );
    }

    #[DataProvider('boundaryMismatchProvider')]
    public function test_existing_boundary_mismatch_is_detected_without_repair(
        string $startsAt,
        string $endsAt,
    ): void {
        $season = SixHeroSeason::query()->create([
            'season_key' => '2026-08',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'finalized_at' => null,
            'ranking_initialized_at' => null,
        ]);
        $before = $season->getRawOriginal();
        $service = app(SixHeroSeasonService::class);
        $at = CarbonImmutable::parse('2026-08-19 12:00:00 Asia/Tokyo');

        foreach (['findCurrentSeason', 'currentSeason'] as $method) {
            try {
                $service->{$method}($at);
                $this->fail("{$method} did not detect the invalid Season boundary.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString('2026-08', $exception->getMessage());
            }
        }

        $this->assertEquals($before, $season->fresh()->getRawOriginal());
        $this->assertDatabaseCount('six_hero_seasons', 1);
    }

    public static function boundaryMismatchProvider(): array
    {
        return [
            'invalid start' => ['2026-08-02 00:00:00', '2026-09-01 00:00:00'],
            'invalid end' => ['2026-08-01 00:00:00', '2026-09-05 00:00:00'],
        ];
    }

    public function test_concurrency_design_uses_unique_insert_then_reselect_without_finalization(): void
    {
        $serviceSource = file_get_contents(app_path('Services/SixHeroSeasonService.php'));
        $migrationSource = file_get_contents(database_path(
            'migrations/2026_08_19_120000_create_six_hero_foundation_tables.php',
        ));

        $this->assertIsString($serviceSource);
        $this->assertStringContainsString('insertOrIgnore(', $serviceSource);
        $this->assertStringContainsString("where('season_key'", $serviceSource);
        $this->assertStringNotContainsString('firstOrCreate(', $serviceSource);
        $this->assertStringNotContainsString('finalizePrevious', $serviceSource);
        $this->assertStringNotContainsString('finalizeEnded', $serviceSource);
        $this->assertStringNotContainsString('SixHeroRanking', $serviceSource);
        $this->assertStringNotContainsString('SixHeroDailyUsage', $serviceSource);
        $this->assertStringNotContainsString('SixHeroBattleLog', $serviceSource);
        $this->assertIsString($migrationSource);
        $this->assertStringContainsString(
            "string('season_key', 7)->unique()",
            $migrationSource,
        );
    }
}
