<?php

declare(strict_types=1);

use App\Enums\SixHeroRoomKey;
use App\Exceptions\SixHeroRankingNotReadyException;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroDailyUsage;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Services\Battle\BattleResult;
use App\Services\Battle\PvPBattleExecutionContext;
use App\Services\Battle\PvPBattleResolution;
use App\Services\PvPBattleService;
use App\Services\SixHeroHallScreenService;
use App\Services\SixHeroDailyUsageService;
use App\Services\SixHeroOfficialBattleService;
use App\Services\SixHeroPracticeBattleService;
use App\Services\SixHeroRankingInitializationService;
use App\Services\SixHeroRankingService;
use App\Services\SixHeroSeasonFinalizationService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

final class Phase6DDeterministicPvPBattleService extends PvPBattleService
{
    public function __construct(
        private readonly bool $attackerWon,
        private readonly ?string $resolveGate,
        private readonly ?CarbonImmutable $resolvedAt,
    ) {}

    public function resolveBattle(
        Character $attackerChar,
        Character $defenderChar,
        ?PvPBattleExecutionContext $context = null,
    ): PvPBattleResolution {
        if ($this->resolveGate !== null) {
            waitUntil(
                fn (): bool => is_file($this->resolveGate),
                30,
                'Timed out waiting for the deterministic battle release gate.',
            );
        }

        if ($this->resolvedAt !== null) {
            Carbon::setTestNow($this->resolvedAt);
        }

        $result = new BattleResult;
        $result->result = $this->attackerWon ? 'victory' : 'defeat';
        $result->logs = ['Phase 6D deterministic MySQL release battle.'];
        $result->turnCount = 1;

        return new PvPBattleResolution(
            result: $result,
            attackerWon: $this->attackerWon,
            turnCount: 1,
            attackerHp: $this->attackerWon ? 100 : 0,
            attackerMaxHp: 100,
            defenderHp: $this->attackerWon ? 0 : 100,
            defenderMaxHp: 100,
        );
    }
}

assertSafeReleaseDatabase();

$command = $argv[1] ?? '';

if ($command === 'worker') {
    runWorker($argv[2] ?? '');
    exit(0);
}

if ($command === 'trigger-probe') {
    printJson(runTriggerProbe());
    exit(0);
}

if ($command === 'race-suite') {
    printJson(runRaceSuite());
    exit(0);
}

if ($command === 'performance-suite') {
    printJson(runPerformanceSuite());
    exit(0);
}

if ($command === 'browser-fixture') {
    printJson(runBrowserFixture());
    exit(0);
}

fwrite(STDERR, "Usage: php scripts/verify/six-hero-mysql-release.php [trigger-probe|race-suite|performance-suite|browser-fixture]\n");
exit(2);

function assertSafeReleaseDatabase(): void
{
    $connection = DB::connection();
    $driver = $connection->getDriverName();
    $database = (string) $connection->getDatabaseName();

    if (! app()->environment('testing')) {
        throw new RuntimeException('The Six Heroes release harness requires APP_ENV=testing.');
    }
    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('The Six Heroes release harness requires MySQL or MariaDB.');
    }
    if (! str_starts_with($database, 'valzeria_sixhero_phase6d_')) {
        throw new RuntimeException('Refusing to run outside a Phase 6D isolated database.');
    }
}

/** @return array<string, mixed> */
function runTriggerProbe(): array
{
    $champion = DB::table('six_hero_champions')
        ->where('is_vacant', false)
        ->whereNotNull('character_id_snapshot')
        ->orderBy('id')
        ->first();
    releaseAssert($champion !== null, 'No non-vacant Champion is available for the trigger probe.');

    $id = (int) $champion->id;
    $snapshot = (int) $champion->character_id_snapshot;
    $attempted = $snapshot + 1000000;

    $instanceBlocked = catches(
        static fn () => SixHeroChampion::query()
            ->findOrFail($id)
            ->update(['character_id_snapshot' => $attempted]),
        LogicException::class,
    );
    $eloquentBuilderBlocked = catches(
        static fn () => SixHeroChampion::query()
            ->whereKey($id)
            ->update(['character_id_snapshot' => $attempted]),
        QueryException::class,
    );
    $queryBuilderBlocked = catches(
        static fn () => DB::table('six_hero_champions')
            ->where('id', $id)
            ->update(['character_id_snapshot' => $attempted]),
        QueryException::class,
    );

    $current = DB::table('six_hero_champions')->where('id', $id)->first();
    releaseAssert($instanceBlocked, 'Eloquent instance update bypassed Champion identity immutability.');
    releaseAssert($eloquentBuilderBlocked, 'Eloquent builder update bypassed Champion identity immutability.');
    releaseAssert($queryBuilderBlocked, 'Query Builder update bypassed Champion identity immutability.');
    releaseAssert((int) $current->character_id_snapshot === $snapshot, 'Champion snapshot changed.');

    return [
        'instance_update_blocked' => $instanceBlocked,
        'eloquent_builder_update_blocked' => $eloquentBuilderBlocked,
        'query_builder_update_blocked' => $queryBuilderBlocked,
        'snapshot_unchanged' => (int) $current->character_id_snapshot === $snapshot,
    ];
}

/** @return array<string, mixed> */
function runRaceSuite(): array
{
    return [
        'database' => [
            'driver' => DB::connection()->getDriverName(),
            'version' => (string) DB::scalar('SELECT VERSION()'),
            'isolation' => databaseIsolationLevel(),
        ],
        'concurrent_register' => concurrentRegisterScenario(),
        'daily_limit_race' => dailyLimitScenario(),
        'same_character_room_limit_race' => sameCharacterRoomLimitScenario(),
        'concurrent_rank_battles' => concurrentRankBattleScenario(),
        'different_room_battles' => differentRoomBattleScenario(),
        'concurrent_initializer' => concurrentInitializerScenario(),
        'concurrent_finalizer' => concurrentFinalizerScenario(),
        'month_rollover' => monthRolloverScenario(),
    ];
}

function databaseIsolationLevel(): string
{
    $version = strtolower((string) DB::scalar('SELECT VERSION()'));
    $variable = str_contains($version, 'mariadb')
        ? '@@tx_isolation'
        : '@@transaction_isolation';

    return (string) DB::scalar("SELECT {$variable}");
}

/** @return array<string, mixed> */
function runPerformanceSuite(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-20 12:00:00');
    Carbon::setTestNow($at);
    $current = createSeason('2026-08', '2026-08-01', '2026-09-01', $at);
    $characters = createPerformanceCharacters(1000);
    seedHistoricalChampions($current, 60);
    seedPerformanceRankings($current, $characters, 100);
    seedPerformanceBattleLogs($current, $characters, 20000);
    DB::table('six_hero_daily_usages')->insert([
        'character_id' => $characters[99]->id,
        'usage_date' => $at->toDateString(),
        'official_attempts' => 3,
        'official_attempts_by_room' => json_encode([
            SixHeroRoomKey::DIVINE_SPEED->value => 3,
        ], JSON_THROW_ON_ERROR),
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $phase = 'small';
    $queryCounts = ['small' => 0, 'large' => 0];
    DB::listen(function () use (&$phase, &$queryCounts): void {
        if (isset($queryCounts[$phase])) {
            $queryCounts[$phase]++;
        }
    });

    $screenService = app(SixHeroHallScreenService::class);
    $smallStarted = microtime(true);
    $small = $screenService->screenData($characters[99], SixHeroRoomKey::DIVINE_SPEED);
    $smallRankings = performanceRankingPage($current, SixHeroRoomKey::DIVINE_SPEED);
    $smallDurationMs = (int) round((microtime(true) - $smallStarted) * 1000);
    releaseAssert($small['ready'] === true, 'Small performance fixture was not ready.');
    releaseAssert($smallRankings->count() === 20, 'Small performance fixture did not return a bounded page.');

    $phase = 'ignore';
    seedPerformanceRankings($current, $characters, 1000, 101);
    $phase = 'large';
    $largeStarted = microtime(true);
    $large = $screenService->screenData($characters[99], SixHeroRoomKey::DIVINE_SPEED);
    $largeRankings = performanceRankingPage($current, SixHeroRoomKey::DIVINE_SPEED);
    $largeDurationMs = (int) round((microtime(true) - $largeStarted) * 1000);
    $phase = 'ignore';
    releaseAssert($large['ready'] === true, 'Large performance fixture was not ready.');
    releaseAssert($largeRankings->count() === 20, 'Large performance fixture did not return a bounded page.');
    releaseAssert(
        $queryCounts['large'] <= $queryCounts['small'],
        "Screen query count grew with participant count: {$queryCounts['small']} -> {$queryCounts['large']}.",
    );

    $plans = performanceExplainPlans($current, $characters[99]);
    foreach ($plans as $name => $plan) {
        releaseAssert($plan !== [], "EXPLAIN returned no rows for {$name}.");
    }

    return [
        'characters' => count($characters),
        'rankings' => SixHeroRanking::query()->where('season_id', $current->id)->count(),
        'battle_logs' => SixHeroBattleLog::query()->where('season_id', $current->id)->count(),
        'historical_seasons' => SixHeroSeason::query()->whereNotNull('finalized_at')->count(),
        'champion_snapshots' => DB::table('six_hero_champions')->count(),
        'query_count_100_participants' => $queryCounts['small'],
        'query_count_1000_participants' => $queryCounts['large'],
        'duration_ms_100_participants' => $smallDurationMs,
        'duration_ms_1000_participants' => $largeDurationMs,
        'ranking_page_size' => $largeRankings->count(),
        'ranking_total' => $largeRankings->total(),
        'explain' => $plans,
    ];
}

function performanceRankingPage(
    SixHeroSeason $season,
    SixHeroRoomKey $room,
): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
    return SixHeroRanking::query()
        ->with('character')
        ->where('season_id', $season->id)
        ->where('room_key', $room->value)
        ->orderBy('rank')
        ->orderBy('id')
        ->paginate(20, ['*'], 'roomPage');
}

/** @return array<string, mixed> */
function runBrowserFixture(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-20 12:00:00');
    Carbon::setTestNow($at);

    DB::table('users')->where('email', 'phase6d-browser@example.invalid')->delete();
    $viewerUserId = DB::table('users')->insertGetId([
        'name' => 'Phase 6D Browser Viewer',
        'email' => 'phase6d-browser@example.invalid',
        'password' => Hash::make('Phase6D-browser-only'),
        'role' => 'user',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
    $viewerId = DB::table('characters')->insertGetId([
        'user_id' => $viewerUserId,
        'name' => '前月神速英雄B',
        'icon_path' => '/images/chara/chara_001.webp',
        'level' => 255,
        'hp_base' => 10000,
        'current_hp' => 10000,
        'attack_base' => 5000,
        'defense_base' => 500,
        'speed_base' => 5000,
        'magic_base' => 5000,
        'spirit_base' => 500,
        'luck_base' => 500,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
    $viewer = Character::query()->findOrFail($viewerId);

    $opponents = createCharacters(9, 'browser-opponents');
    $opponentNames = [
        '現在首位A',
        '昇格用の弱敵',
        '堅牢な強敵C',
        '直上の弱敵D',
        '<script>alert(1)</script>',
        '参加者F',
        '参加者G',
        '参加者H',
        '前月封刃英雄',
    ];
    foreach ($opponents as $index => $opponent) {
        $opponent->update([
            'name' => $opponentNames[$index],
            'icon_path' => '/images/chara/chara_002.webp',
            'level' => 255,
            'hp_base' => 100,
            'current_hp' => 100,
            'attack_base' => 1,
            'defense_base' => 1,
            'speed_base' => 1,
            'magic_base' => 1,
            'spirit_base' => 1,
            'luck_base' => 1,
        ]);
    }
    foreach ([$opponents[0], $opponents[2]] as $strong) {
        $strong->update([
            'hp_base' => 200000,
            'current_hp' => 200000,
            'attack_base' => 200000,
            'defense_base' => 200000,
            'speed_base' => 200000,
            'magic_base' => 200000,
            'spirit_base' => 200000,
            'luck_base' => 200000,
        ]);
    }

    $valmonMasterId = DB::table('valmon_masters')->orderBy('id')->value('id');
    if ($valmonMasterId === null) {
        $valmonMasterId = DB::table('valmon_masters')->insertGetId([
            'valmon_key' => 'phase6d-browser-guide',
            'name' => '六極殿案内モン',
            'rarity' => 'normal',
            'is_active' => true,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
    DB::table('player_valmons')->insert([
        'character_id' => $viewer->id,
        'valmon_master_id' => $valmonMasterId,
        'is_partner' => true,
        'obtained_source' => 'test',
        'obtained_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $may = createSeason('2026-05', '2026-05-01', '2026-06-01', releaseTime('2026-05-01 00:00:00'), releaseTime('2026-06-01 00:00:01'));
    $june = createSeason('2026-06', '2026-06-01', '2026-07-01', releaseTime('2026-06-01 00:00:00'), releaseTime('2026-07-01 00:00:01'));
    $july = createSeason('2026-07', '2026-07-01', '2026-08-01', releaseTime('2026-07-01 00:00:00'), releaseTime('2026-08-01 00:00:01'));
    $current = createSeason('2026-08', '2026-08-01', '2026-09-01', releaseTime('2026-08-01 00:00:00'));

    insertBrowserChampionSeason($may, $viewer, [SixHeroRoomKey::DIVINE_SPEED]);
    insertBrowserChampionSeason($june, $viewer, [SixHeroRoomKey::DIVINE_SPEED]);
    insertBrowserChampionSeason(
        $july,
        $viewer,
        [SixHeroRoomKey::DIVINE_SPEED, SixHeroRoomKey::REVERSE_TIME, SixHeroRoomKey::MIRACLE],
        $opponents[8],
    );

    $speedCharacters = [
        $opponents[0],
        $opponents[1],
        $opponents[2],
        $opponents[3],
        $viewer,
        $opponents[4],
        $opponents[5],
        $opponents[6],
    ];
    insertRankings($current, SixHeroRoomKey::DIVINE_SPEED, $speedCharacters);
    SixHeroRanking::query()
        ->where('season_id', $current->id)
        ->where('room_key', SixHeroRoomKey::DIVINE_SPEED)
        ->where('rank', 1)
        ->update(['official_attack_wins' => 5]);
    SixHeroRanking::query()
        ->where('season_id', $current->id)
        ->where('room_key', SixHeroRoomKey::DIVINE_SPEED)
        ->where('rank', 2)
        ->update(['official_attack_losses' => 5]);

    insertRankings($current, SixHeroRoomKey::SEAL_MAGIC, array_slice($opponents, 0, 3));
    foreach ([
        SixHeroRoomKey::SEAL_BLADE,
        SixHeroRoomKey::BURNING_LIFE,
        SixHeroRoomKey::REVERSE_TIME,
        SixHeroRoomKey::MIRACLE,
    ] as $room) {
        insertRankings($current, $room, [$opponents[0], $viewer]);
    }

    return [
        'login_email' => 'phase6d-browser@example.invalid',
        'current_season' => $current->season_key,
        'viewer_character_id' => $viewer->id,
        'current_leader' => $opponents[0]->name,
        'previous_speed_hero' => $viewer->name,
        'speed_rankings' => count($speedCharacters),
    ];
}

/**
 * @param  list<SixHeroRoomKey>  $viewerHeroRooms
 */
function insertBrowserChampionSeason(
    SixHeroSeason $season,
    Character $viewer,
    array $viewerHeroRooms,
    ?Character $bladeHero = null,
): void {
    $now = $season->ends_at;
    foreach (SixHeroRoomKey::cases() as $room) {
        $isViewerHero = in_array($room, $viewerHeroRooms, true);
        $isBladeHero = $bladeHero !== null && $room === SixHeroRoomKey::SEAL_BLADE;
        $isDeletedHero = $room === SixHeroRoomKey::SEAL_MAGIC && $season->season_key === '2026-07';
        $isHero = $isViewerHero || $isBladeHero || $isDeletedHero;
        $characterId = $isViewerHero ? $viewer->id : ($isBladeHero ? $bladeHero?->id : null);
        $snapshotId = $isDeletedHero ? 999999 : $characterId;
        $snapshotName = $isDeletedHero
            ? '削除済み英雄<script>alert(1)</script>'
            : ($isViewerHero ? $viewer->name : ($isBladeHero ? $bladeHero?->name : null));

        DB::table('six_hero_champions')->insert([
            'season_id' => $season->id,
            'room_key' => $room->value,
            'character_id' => $characterId,
            'character_id_snapshot' => $snapshotId,
            'character_name_snapshot' => $snapshotName,
            'is_vacant' => ! $isHero,
            'vacancy_reason' => $isHero ? null : 'insufficient_activity',
            'registered_count' => $isHero ? 8 : 2,
            'official_battle_count' => $isHero ? 10 : 1,
            'official_attack_wins' => $isHero ? 3 : null,
            'official_attack_losses' => $isHero ? 1 : null,
            'defense_wins' => $isHero ? 2 : null,
            'defense_losses' => $isHero ? 1 : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

/** @return array<int, Character> */
function createPerformanceCharacters(int $count): array
{
    $token = bin2hex(random_bytes(4));
    $now = now();
    $userId = DB::table('users')->insertGetId([
        'name' => 'Phase6D performance',
        'email' => "phase6d-race-performance-{$token}@example.invalid",
        'role' => 'user',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    for ($offset = 0; $offset < $count; $offset += 250) {
        $rows = [];
        $limit = min($count, $offset + 250);
        for ($index = $offset; $index < $limit; $index++) {
            $rows[] = [
                'user_id' => $userId,
                'name' => sprintf('Phase6D perf %04d', $index + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('characters')->insert($rows);
    }

    return Character::query()->where('user_id', $userId)->orderBy('id')->get()->all();
}

function seedHistoricalChampions(SixHeroSeason $current, int $monthCount): void
{
    $currentStart = CarbonImmutable::instance($current->starts_at)
        ->setTimezone((string) config('app.timezone'))
        ->startOfMonth();

    for ($monthsAgo = $monthCount; $monthsAgo >= 1; $monthsAgo--) {
        $start = $currentStart->subMonthsNoOverflow($monthsAgo);
        $end = $start->addMonthNoOverflow();
        $season = SixHeroSeason::query()->create([
            'season_key' => $start->format('Y-m'),
            'starts_at' => $start,
            'ends_at' => $end,
            'finalized_at' => $end->addMinutes(5),
            'ranking_initialized_at' => $start,
        ]);
        $rows = [];
        foreach (SixHeroRoomKey::cases() as $room) {
            $rows[] = [
                'season_id' => $season->id,
                'room_key' => $room->value,
                'character_id' => null,
                'character_id_snapshot' => null,
                'character_name_snapshot' => null,
                'is_vacant' => true,
                'vacancy_reason' => 'insufficient_activity',
                'registered_count' => 1000,
                'official_battle_count' => 0,
                'official_attack_wins' => null,
                'official_attack_losses' => null,
                'defense_wins' => null,
                'defense_losses' => null,
                'created_at' => $end->addMinutes(5),
                'updated_at' => $end->addMinutes(5),
            ];
        }
        DB::table('six_hero_champions')->insert($rows);
    }
}

/**
 * @param  array<int, Character>  $characters
 */
function seedPerformanceRankings(
    SixHeroSeason $season,
    array $characters,
    int $throughRank,
    int $fromRank = 1,
): void {
    foreach (SixHeroRoomKey::cases() as $room) {
        $rows = [];
        for ($rank = $fromRank; $rank <= $throughRank; $rank++) {
            $character = $characters[$rank - 1];
            $rows[] = [
                'season_id' => $season->id,
                'room_key' => $room->value,
                'character_id' => $character->id,
                'rank' => $rank,
                'official_attack_wins' => $rank % 3,
                'official_attack_losses' => $rank % 2,
                'defense_wins' => 0,
                'defense_losses' => 0,
                'registered_at' => $season->starts_at,
                'first_place_since' => $rank === 1 ? $season->starts_at : null,
                'created_at' => $season->starts_at,
                'updated_at' => $season->starts_at,
            ];
            if (count($rows) === 500) {
                DB::table('six_hero_rankings')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('six_hero_rankings')->insert($rows);
        }
    }
}

/** @param array<int, Character> $characters */
function seedPerformanceBattleLogs(SixHeroSeason $season, array $characters, int $count): void
{
    $rooms = SixHeroRoomKey::cases();
    $rows = [];
    for ($index = 0; $index < $count; $index++) {
        $room = $rooms[$index % count($rooms)];
        $rows[] = [
            'season_id' => $season->id,
            'room_key' => $room->value,
            'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
            'status' => SixHeroBattleLog::STATUS_COMPLETED,
            'attacker_id' => $characters[$index % 100]->id,
            'defender_id' => $characters[($index + 1) % 100]->id,
            'attacker_rank_at_start' => ($index % 100) + 1,
            'defender_rank_at_start' => (($index + 1) % 100) + 1,
            'is_attacker_win' => (bool) ($index % 2),
            'rank_changed' => false,
            'daily_attempt_number' => ($index % 5) + 1,
            'started_at' => $season->starts_at->copy()->addSeconds($index),
            'resolved_at' => $season->starts_at->copy()->addSeconds($index + 1),
            'completed_at' => $season->starts_at->copy()->addSeconds($index + 1),
            'created_at' => $season->starts_at->copy()->addSeconds($index),
            'updated_at' => $season->starts_at->copy()->addSeconds($index + 1),
        ];
        if (count($rows) === 500) {
            DB::table('six_hero_battle_logs')->insert($rows);
            $rows = [];
        }
    }
    if ($rows !== []) {
        DB::table('six_hero_battle_logs')->insert($rows);
    }
}

/** @return array<string, array<int, array<string, mixed>>> */
function performanceExplainPlans(SixHeroSeason $season, Character $character): array
{
    $queries = [
        'ranking_page' => [
            'EXPLAIN SELECT * FROM six_hero_rankings WHERE season_id = ? AND room_key = ? ORDER BY `rank`, id LIMIT 20',
            [$season->id, SixHeroRoomKey::DIVINE_SPEED->value],
        ],
        'character_ranking' => [
            'EXPLAIN SELECT * FROM six_hero_rankings WHERE season_id = ? AND room_key = ? AND character_id = ? LIMIT 1',
            [$season->id, SixHeroRoomKey::DIVINE_SPEED->value, $character->id],
        ],
        'registered_count' => [
            'EXPLAIN SELECT COUNT(*) FROM six_hero_rankings WHERE season_id = ? AND room_key = ?',
            [$season->id, SixHeroRoomKey::DIVINE_SPEED->value],
        ],
        'official_battle_count' => [
            'EXPLAIN SELECT SUM(official_attack_wins + official_attack_losses) FROM six_hero_rankings WHERE season_id = ? AND room_key = ?',
            [$season->id, SixHeroRoomKey::DIVINE_SPEED->value],
        ],
        'daily_usage' => [
            'EXPLAIN SELECT official_attempts, official_attempts_by_room FROM six_hero_daily_usages WHERE character_id = ? AND usage_date = ? LIMIT 1',
            [$character->id, '2026-08-20'],
        ],
        'champion_room_history' => [
            'EXPLAIN SELECT c.* FROM six_hero_champions c INNER JOIN six_hero_seasons s ON s.id = c.season_id WHERE s.finalized_at IS NOT NULL AND c.room_key = ? ORDER BY s.starts_at DESC, c.id DESC LIMIT 12',
            [SixHeroRoomKey::DIVINE_SPEED->value],
        ],
        'previous_season' => [
            'EXPLAIN SELECT * FROM six_hero_seasons WHERE season_key = ? LIMIT 1',
            ['2026-07'],
        ],
        'leaders_all_rooms' => [
            'EXPLAIN SELECT * FROM six_hero_rankings WHERE season_id = ? AND `rank` = 1',
            [$season->id],
        ],
        'character_all_rooms' => [
            'EXPLAIN SELECT * FROM six_hero_rankings WHERE season_id = ? AND character_id = ?',
            [$season->id, $character->id],
        ],
    ];

    $plans = [];
    foreach ($queries as $name => [$sql, $bindings]) {
        $plans[$name] = array_map(
            static fn (object $row): array => [
                'table' => (string) ($row->table ?? ''),
                'type' => (string) ($row->type ?? ''),
                'key' => $row->key ?? null,
                'rows' => (int) ($row->rows ?? 0),
                'extra' => (string) ($row->Extra ?? ''),
            ],
            DB::select($sql, $bindings),
        );
    }

    return $plans;
}

/** @return array<string, mixed> */
function concurrentRegisterScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-19 12:00:00');
    $season = createSeason('2026-08', '2026-08-01', '2026-09-01', $at);
    $characters = createCharacters(6, 'register');
    $room = SixHeroRoomKey::DIVINE_SPEED;

    $workers = [];
    foreach (array_slice($characters, 0, 5) as $character) {
        $workers[] = workerSpec('register', $at, [
            'season_id' => $season->id,
            'room' => $room->value,
            'character_id' => $character->id,
        ]);
    }
    $results = runWorkers($workers);
    releaseAssert(allWorkersSucceeded($results), 'One of the concurrent register workers failed.');
    assertRankingInvariant($season->id, $room);

    $duplicateSpecs = [
        workerSpec('register', $at, [
            'season_id' => $season->id,
            'room' => $room->value,
            'character_id' => $characters[5]->id,
        ]),
        workerSpec('register', $at, [
            'season_id' => $season->id,
            'room' => $room->value,
            'character_id' => $characters[5]->id,
        ]),
    ];
    $duplicateResults = runWorkers($duplicateSpecs);
    releaseAssert(allWorkersSucceeded($duplicateResults), 'Concurrent duplicate registration failed.');
    releaseAssert(
        SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->where('character_id', $characters[5]->id)
            ->count() === 1,
        'Concurrent duplicate registration created multiple rows.',
    );
    assertRankingInvariant($season->id, $room);

    return [
        'worker_count' => count($results) + count($duplicateResults),
        'distinct_connections' => distinctConnectionCount(array_merge($results, $duplicateResults)),
        'ranking_count' => SixHeroRanking::query()->where('season_id', $season->id)->count(),
        'ranks' => SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->where('room_key', $room->value)
            ->orderBy('rank')
            ->pluck('rank')
            ->all(),
        'duplicate_character_rows' => 1,
    ];
}

/** @return array<string, mixed> */
function dailyLimitScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-19 12:10:00');
    $season = createSeason('2026-08', '2026-08-01', '2026-09-01', $at);
    [$defender, $attacker] = createCharacters(2, 'daily');
    $room = SixHeroRoomKey::SEAL_MAGIC;
    insertRankings($season, $room, [$defender, $attacker]);
    DB::table('six_hero_daily_usages')->insert([
        'character_id' => $attacker->id,
        'usage_date' => $at->toDateString(),
        'official_attempts' => 4,
        'official_attempts_by_room' => json_encode([
            $room->value => 4,
        ], JSON_THROW_ON_ERROR),
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $spec = workerSpec('official', $at, [
        'season_id' => $season->id,
        'room' => $room->value,
        'attacker_id' => $attacker->id,
        'defender_id' => $defender->id,
        'attacker_won' => false,
    ]);
    $results = runWorkers([$spec, $spec]);

    $successCount = count(array_filter($results, static fn (array $result): bool => $result['ok']));
    $limitCount = count(array_filter(
        $results,
        static fn (array $result): bool => ! $result['ok']
            && ($result['exception'] ?? '') === DomainException::class,
    ));
    $attempts = (int) DB::table('six_hero_daily_usages')
        ->where('character_id', $attacker->id)
        ->where('usage_date', $at->toDateString())
        ->value('official_attempts');
    releaseAssert(
        $successCount === 1 && $limitCount === 1,
        'Daily-limit race outcome was not 1 success and 1 rejection: '
            .json_encode($results, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    releaseAssert($attempts === 5, 'Daily-limit race did not stop at five attempts.');
    releaseAssert(SixHeroBattleLog::query()->where('season_id', $season->id)->count() === 1, 'Rejected request created a battle log.');

    return [
        'distinct_connections' => distinctConnectionCount($results),
        'successes' => $successCount,
        'limit_rejections' => $limitCount,
        'official_attempts' => $attempts,
        'battle_logs' => SixHeroBattleLog::query()->where('season_id', $season->id)->count(),
        'terminal_logs' => terminalLogCount($season->id),
    ];
}

/** @return array<string, mixed> */
function sameCharacterRoomLimitScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-19 12:15:00');
    $season = createSeason('2026-08', '2026-08-01', '2026-09-01', $at);
    [$magicDefender, $bladeDefender, $attacker] = createCharacters(3, 'same-character-room-limit');
    $magicRoom = SixHeroRoomKey::SEAL_MAGIC;
    $bladeRoom = SixHeroRoomKey::SEAL_BLADE;
    insertRankings($season, $magicRoom, [$magicDefender, $attacker]);
    insertRankings($season, $bladeRoom, [$bladeDefender, $attacker]);
    DB::table('six_hero_daily_usages')->insert([
        'character_id' => $attacker->id,
        'usage_date' => $at->toDateString(),
        'official_attempts' => 8,
        'official_attempts_by_room' => json_encode([
            $magicRoom->value => 4,
            $bladeRoom->value => 4,
        ], JSON_THROW_ON_ERROR),
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $magicSpec = workerSpec('official', $at, [
        'season_id' => $season->id,
        'room' => $magicRoom->value,
        'attacker_id' => $attacker->id,
        'defender_id' => $magicDefender->id,
        'attacker_won' => false,
    ]);
    $bladeSpec = workerSpec('official', $at, [
        'season_id' => $season->id,
        'room' => $bladeRoom->value,
        'attacker_id' => $attacker->id,
        'defender_id' => $bladeDefender->id,
        'attacker_won' => false,
    ]);
    $results = runWorkers([$magicSpec, $magicSpec, $bladeSpec, $bladeSpec]);

    $successCount = count(array_filter($results, static fn (array $result): bool => $result['ok']));
    $limitCount = count(array_filter(
        $results,
        static fn (array $result): bool => ! $result['ok']
            && ($result['exception'] ?? '') === DomainException::class,
    ));
    $usage = SixHeroDailyUsage::query()
        ->where('character_id', $attacker->id)
        ->where('usage_date', $at->toDateString())
        ->sole();
    $attemptsByRoom = app(SixHeroDailyUsageService::class)->attemptsByRoom($usage);
    $attemptsTotal = array_sum($attemptsByRoom);

    releaseAssert($successCount === 2 && $limitCount === 2, 'Cross-room daily-limit race was not two successes and two rejections.');
    releaseAssert((int) $usage->official_attempts === 10, 'Cross-room daily-limit race total was not ten.');
    releaseAssert($attemptsTotal === 10, 'Cross-room attempt breakdown did not sum to ten.');
    releaseAssert($attemptsByRoom[$magicRoom->value] === 5, 'Seal Magic attempts did not stop at five.');
    releaseAssert($attemptsByRoom[$bladeRoom->value] === 5, 'Seal Blade attempts did not stop at five.');
    releaseAssert(SixHeroBattleLog::query()->where('season_id', $season->id)->count() === 2, 'Rejected cross-room requests created battle logs.');
    assertRankingInvariant($season->id, $magicRoom);
    assertRankingInvariant($season->id, $bladeRoom);

    return [
        'workers' => count($results),
        'distinct_connections' => distinctConnectionCount($results),
        'successes' => $successCount,
        'limit_rejections' => $limitCount,
        'official_attempts' => (int) $usage->official_attempts,
        'breakdown_sum' => $attemptsTotal,
        'attempts_by_room' => [
            $magicRoom->value => $attemptsByRoom[$magicRoom->value],
            $bladeRoom->value => $attemptsByRoom[$bladeRoom->value],
        ],
        'battle_logs' => SixHeroBattleLog::query()->where('season_id', $season->id)->count(),
        'attempts_over_limit' => roomUsageRowsOverLimit(),
    ];
}

/** @return array<string, mixed> */
function concurrentRankBattleScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-19 12:20:00');
    $season = createSeason('2026-08', '2026-08-01', '2026-09-01', $at);
    $characters = createCharacters(10, 'same-target');
    $room = SixHeroRoomKey::SEAL_BLADE;
    insertRankings($season, $room, $characters);
    $defender = $characters[6];
    $attackers = [$characters[7], $characters[8], $characters[9]];
    $resolveGate = newResolveGatePath('same-target');

    $workers = array_map(
        fn (Character $attacker): array => workerSpec('official', $at, [
            'season_id' => $season->id,
            'room' => $room->value,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_won' => true,
            'resolve_gate' => $resolveGate,
        ]),
        $attackers,
    );
    $startedAt = microtime(true);
    $results = runWorkers($workers, function () use ($season, $resolveGate): void {
        waitUntil(
            fn (): bool => SixHeroBattleLog::query()
                ->where('season_id', $season->id)
                ->where('status', SixHeroBattleLog::STATUS_STARTED)
                ->count() === 3,
            15,
            'Not all same-target official battles reached started.',
        );
        touch($resolveGate);
    });
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    releaseAssert(allWorkersSucceeded($results), 'A same-target official battle worker failed.');
    assertRankingInvariant($season->id, $room);
    $attackCount = (int) SixHeroRanking::query()
        ->where('season_id', $season->id)
        ->where('room_key', $room->value)
        ->sum(DB::raw('official_attack_wins + official_attack_losses'));
    releaseAssert($attackCount === 3, 'Official attack counters do not match completed battles.');
    releaseAssert(terminalLogCount($season->id) === 3, 'A same-target battle log was left non-terminal.');
    $rankChangedTrue = count(array_filter(
        $results,
        static fn (array $result): bool => (bool) ($result['data']['rank_changed'] ?? false),
    ));
    $rankChangedFalse = count($results) - $rankChangedTrue;
    $defenderRank = (int) SixHeroRanking::query()
        ->where('season_id', $season->id)
        ->where('room_key', $room->value)
        ->where('character_id', $defender->id)
        ->value('rank');
    $attackerRanks = SixHeroRanking::query()
        ->where('season_id', $season->id)
        ->where('room_key', $room->value)
        ->whereIn('character_id', array_map(static fn (Character $character): int => $character->id, $attackers))
        ->orderBy('rank')
        ->pluck('rank')
        ->map(static fn (mixed $rank): int => (int) $rank)
        ->all();
    releaseAssert($rankChangedTrue === 3 && $rankChangedFalse === 0, 'Same-target winners did not all move in this deterministic fixture.');
    releaseAssert($defenderRank === 10 && $attackerRanks === [7, 8, 9], 'Same-target final rank movement was incorrect.');

    return [
        'workers' => count($results),
        'distinct_connections' => distinctConnectionCount($results),
        'duration_ms' => $durationMs,
        'rank_changed_true' => $rankChangedTrue,
        'rank_changed_false' => $rankChangedFalse,
        'official_attack_counter_sum' => $attackCount,
        'terminal_logs' => terminalLogCount($season->id),
        'invariant' => rankingInvariant($season->id, $room),
    ];
}

/** @return array<string, mixed> */
function differentRoomBattleScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-19 12:30:00');
    $season = createSeason('2026-08', '2026-08-01', '2026-09-01', $at);
    $characters = createCharacters(12, 'different-room');
    $resolveGate = newResolveGatePath('different-room');
    $workers = [];

    foreach (SixHeroRoomKey::cases() as $index => $room) {
        $defender = $characters[$index * 2];
        $attacker = $characters[$index * 2 + 1];
        insertRankings($season, $room, [$defender, $attacker]);
        $workers[] = workerSpec('official', $at, [
            'season_id' => $season->id,
            'room' => $room->value,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_won' => true,
            'resolve_gate' => $resolveGate,
        ]);
    }

    $startedAt = microtime(true);
    $results = runWorkers($workers, function () use ($season, $resolveGate): void {
        waitUntil(
            fn (): bool => SixHeroBattleLog::query()
                ->where('season_id', $season->id)
                ->where('status', SixHeroBattleLog::STATUS_STARTED)
                ->count() === 6,
            20,
            'Not all cross-room official battles reached started.',
        );
        touch($resolveGate);
    });
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    releaseAssert(allWorkersSucceeded($results), 'A cross-room official battle worker failed.');
    foreach (SixHeroRoomKey::cases() as $room) {
        assertRankingInvariant($season->id, $room);
    }
    $terminalLogs = terminalLogCount($season->id);
    $attemptsOverLimit = roomUsageRowsOverLimit();
    releaseAssert($terminalLogs === 6, 'A cross-room battle log was left non-terminal.');
    releaseAssert($attemptsOverLimit === 0, 'A cross-room battle exceeded the daily attempt limit.');

    return [
        'workers' => count($results),
        'distinct_connections' => distinctConnectionCount($results),
        'duration_ms' => $durationMs,
        'terminal_logs' => $terminalLogs,
        'attempts_over_limit' => $attemptsOverLimit,
    ];
}

/** @return array<string, mixed> */
function concurrentInitializerScenario(): array
{
    $parallel = parallelInitializerSubScenario();
    $register = initializeAndRegisterSubScenario();
    $pending = initializeWithPendingEntrypointsSubScenario();

    return compact('parallel', 'register', 'pending');
}

/** @return array<string, mixed> */
function parallelInitializerSubScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-02 00:10:00');
    $source = createSeason('2026-07', '2026-07-01', '2026-08-01', null, $at);
    $target = createSeason('2026-08', '2026-08-01', '2026-09-01');
    $characters = createCharacters(3, 'initializer');
    foreach (SixHeroRoomKey::cases() as $room) {
        insertRankings($source, $room, $characters, 4, 2, 3, 1);
    }

    $workers = array_fill(0, 5, workerSpec('initialize', $at, [
        'season_id' => $target->id,
    ]));
    $results = runWorkers($workers);
    releaseAssert(allWorkersSucceeded($results), 'A concurrent initializer worker failed.');
    $target->refresh();
    releaseAssert($target->ranking_initialized_at !== null, 'Concurrent initializer left target uninitialized.');
    releaseAssert(SixHeroRanking::query()->where('season_id', $target->id)->count() === 18, 'Carryover row count is incorrect.');
    releaseAssert(
        SixHeroRanking::query()
            ->where('season_id', $target->id)
            ->where(function ($query): void {
                $query->where('official_attack_wins', '!=', 0)
                    ->orWhere('official_attack_losses', '!=', 0)
                    ->orWhere('defense_wins', '!=', 0)
                    ->orWhere('defense_losses', '!=', 0);
            })
            ->doesntExist(),
        'Carryover counters were not reset.',
    );
    $initializedAt = $target->ranking_initialized_at?->format('Y-m-d H:i:s.u');
    $repeat = app(SixHeroRankingInitializationService::class)->initialize(
        $target->fresh(),
        releaseTime('2026-08-02 00:11:00'),
    );
    $target->refresh();
    releaseAssert($repeat->alreadyInitialized, 'A later initializer did not reuse the completed initialization.');
    releaseAssert(
        $target->ranking_initialized_at?->format('Y-m-d H:i:s.u') === $initializedAt,
        'A later initializer changed ranking_initialized_at.',
    );
    foreach (SixHeroRoomKey::cases() as $room) {
        assertRankingInvariant($target->id, $room);
    }

    return [
        'workers' => count($results),
        'distinct_connections' => distinctConnectionCount($results),
        'copied_rankings' => SixHeroRanking::query()->where('season_id', $target->id)->count(),
        'initialized_at' => $target->ranking_initialized_at?->format('Y-m-d H:i:s'),
        'nonzero_counters' => 0,
    ];
}

/** @return array<string, mixed> */
function initializeAndRegisterSubScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-02 00:20:00');
    $source = createSeason('2026-07', '2026-07-01', '2026-08-01', null, $at);
    $target = createSeason('2026-08', '2026-08-01', '2026-09-01');
    $characters = createCharacters(3, 'init-register');
    foreach (SixHeroRoomKey::cases() as $room) {
        insertRankings($source, $room, array_slice($characters, 0, 2));
    }

    $results = runWorkers([
        workerSpec('initialize', $at, ['season_id' => $target->id]),
        workerSpec('register', $at, [
            'season_id' => $target->id,
            'room' => SixHeroRoomKey::MIRACLE->value,
            'character_id' => $characters[2]->id,
        ]),
    ]);
    releaseAssert(allWorkersSucceeded($results), 'Initializer + register did not safely converge.');
    assertRankingInvariant($target->id, SixHeroRoomKey::MIRACLE);

    $newRanking = SixHeroRanking::query()
        ->where('season_id', $target->id)
        ->where('room_key', SixHeroRoomKey::MIRACLE->value)
        ->where('character_id', $characters[2]->id)
        ->firstOrFail();
    releaseAssert((int) $newRanking->rank === 3, 'New registration was not appended after carryover.');

    return [
        'workers' => count($results),
        'distinct_connections' => distinctConnectionCount($results),
        'target_rankings' => SixHeroRanking::query()->where('season_id', $target->id)->count(),
        'new_character_rank' => (int) $newRanking->rank,
    ];
}

/** @return array<string, mixed> */
function initializeWithPendingEntrypointsSubScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-02 00:30:00');
    $source = createSeason('2026-07', '2026-07-01', '2026-08-01');
    $target = createSeason('2026-08', '2026-08-01', '2026-09-01');
    [$defender, $attacker] = createCharacters(2, 'pending-init');
    insertRankings($source, SixHeroRoomKey::BURNING_LIFE, [$defender, $attacker]);
    createPendingBattleLog($source, SixHeroRoomKey::BURNING_LIFE, $attacker, $defender, releaseTime('2026-07-31 23:59:59'));

    $baselineLogs = SixHeroBattleLog::query()->count();
    $results = runWorkers([
        workerSpec('initialize', $at, ['season_id' => $target->id]),
        workerSpec('official', $at, [
            'season_id' => $target->id,
            'room' => SixHeroRoomKey::BURNING_LIFE->value,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_won' => true,
        ]),
        workerSpec('practice', $at, [
            'season_id' => $target->id,
            'room' => SixHeroRoomKey::BURNING_LIFE->value,
            'attacker_id' => $attacker->id,
            'defender_id' => $defender->id,
            'attacker_won' => true,
        ]),
    ]);

    $notReadyCount = count(array_filter(
        $results,
        static fn (array $result): bool => ($result['exception'] ?? '') === SixHeroRankingNotReadyException::class,
    ));
    releaseAssert($notReadyCount === 2, 'Official and practice were not both blocked as NotReady.');
    releaseAssert(SixHeroRanking::query()->where('season_id', $target->id)->doesntExist(), 'Pending initialization wrote target rankings.');
    releaseAssert(DB::table('six_hero_daily_usages')->doesntExist(), 'Pending official entrypoint consumed usage.');
    releaseAssert(SixHeroBattleLog::query()->count() === $baselineLogs, 'Pending entrypoint created a battle log.');

    return [
        'distinct_connections' => distinctConnectionCount($results),
        'not_ready_entrypoints' => $notReadyCount,
        'target_rankings' => 0,
        'daily_usages' => 0,
        'new_battle_logs' => 0,
    ];
}

/** @return array<string, mixed> */
function concurrentFinalizerScenario(): array
{
    $parallel = parallelFinalizerSubScenario();
    $pending = pendingFinalizerSubScenario();

    return compact('parallel', 'pending');
}

/** @return array<string, mixed> */
function parallelFinalizerSubScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-02 01:00:00');
    $season = createSeason('2026-07', '2026-07-01', '2026-08-01');
    $workers = array_fill(0, 5, workerSpec('finalize', $at, [
        'season_id' => $season->id,
    ]));
    $results = runWorkers($workers);
    releaseAssert(allWorkersSucceeded($results), 'A concurrent finalizer worker failed.');
    $season->refresh();
    releaseAssert($season->finalized_at !== null, 'Concurrent finalizer did not finalize the Season.');
    releaseAssert(DB::table('six_hero_champions')->where('season_id', $season->id)->count() === 6, 'Concurrent finalizer did not create exactly six snapshots.');
    releaseAssert(duplicateChampionCount() === 0, 'Concurrent finalizer created duplicate Champion snapshots.');
    $finalizedAt = $season->finalized_at?->format('Y-m-d H:i:s.u');
    $repeat = app(SixHeroSeasonFinalizationService::class)->finalizeSeason(
        $season->fresh(),
        releaseTime('2026-08-02 01:05:00'),
    );
    $season->refresh();
    releaseAssert($repeat->alreadyFinalized, 'A later finalizer did not reuse the finalized result.');
    releaseAssert(
        $season->finalized_at?->format('Y-m-d H:i:s.u') === $finalizedAt,
        'A later finalizer changed finalized_at.',
    );

    return [
        'workers' => count($results),
        'distinct_connections' => distinctConnectionCount($results),
        'champions' => 6,
        'finalized_at' => $season->finalized_at?->format('Y-m-d H:i:s'),
        'duplicate_champions' => 0,
    ];
}

/** @return array<string, mixed> */
function pendingFinalizerSubScenario(): array
{
    resetCompetitionFixtures();
    $at = releaseTime('2026-08-02 01:10:00');
    $season = createSeason('2026-07', '2026-07-01', '2026-08-01');
    [$defender, $attacker] = createCharacters(2, 'pending-finalizer');
    $log = createPendingBattleLog(
        $season,
        SixHeroRoomKey::REVERSE_TIME,
        $attacker,
        $defender,
        releaseTime('2026-07-31 23:59:59'),
    );
    $workers = array_fill(0, 5, workerSpec('finalize', $at, ['season_id' => $season->id]));
    $pendingResults = runWorkers($workers);
    releaseAssert(allWorkersSucceeded($pendingResults), 'Pending finalizer worker failed.');
    $season->refresh();
    releaseAssert($season->finalized_at === null, 'Pending battle did not defer finalization.');
    releaseAssert(DB::table('six_hero_champions')->where('season_id', $season->id)->doesntExist(), 'Pending finalization created snapshots.');

    DB::table('six_hero_battle_logs')->where('id', $log->id)->update([
        'status' => SixHeroBattleLog::STATUS_COMPLETED,
        'is_attacker_win' => false,
        'rank_changed' => false,
        'completed_at' => $at,
        'updated_at' => $at,
    ]);
    $completedResults = runWorkers($workers);
    releaseAssert(allWorkersSucceeded($completedResults), 'Finalizer retry worker failed.');
    $season->refresh();
    releaseAssert($season->finalized_at !== null, 'Finalizer retry did not finalize the Season.');
    releaseAssert(DB::table('six_hero_champions')->where('season_id', $season->id)->count() === 6, 'Finalizer retry did not create six snapshots.');

    return [
        'pending_workers' => count($pendingResults),
        'pending_distinct_connections' => distinctConnectionCount($pendingResults),
        'champions_while_pending' => 0,
        'retry_workers' => count($completedResults),
        'retry_distinct_connections' => distinctConnectionCount($completedResults),
        'champions_after_retry' => 6,
    ];
}

/** @return array<string, mixed> */
function monthRolloverScenario(): array
{
    resetCompetitionFixtures();
    $beforeDeadline = releaseTime('2026-07-31 23:59:59');
    $afterDeadline = releaseTime('2026-08-01 00:00:02');
    $oldSeason = createSeason('2026-07', '2026-07-01', '2026-08-01', $beforeDeadline);
    $characters = createCharacters(8, 'rollover');
    $workers = [];
    $resolveGate = newResolveGatePath('rollover');
    $rooms = SixHeroRoomKey::cases();

    foreach ($rooms as $roomIndex => $room) {
        $ordered = array_values($characters);
        if ($roomIndex < 5) {
            $attackerIndex = $roomIndex + 1;
            [$ordered[1], $ordered[$attackerIndex]] = [$ordered[$attackerIndex], $ordered[1]];
        }
        insertRankings($oldSeason, $room, $ordered);
        if ($roomIndex < 5) {
            SixHeroRanking::query()
                ->where('season_id', $oldSeason->id)
                ->where('room_key', $room->value)
                ->where('rank', 2)
                ->update(['official_attack_wins' => 9]);
            $workers[] = workerSpec('official', $beforeDeadline, [
                'season_id' => $oldSeason->id,
                'room' => $room->value,
                'attacker_id' => $ordered[1]->id,
                'defender_id' => $ordered[0]->id,
                'attacker_won' => true,
                'resolve_gate' => $resolveGate,
                'resolved_at' => $afterDeadline->toIso8601String(),
            ]);
        }
    }

    $handles = startWorkers($workers);
    releaseWorkers($handles);
    waitUntil(
        fn (): bool => SixHeroBattleLog::query()
            ->where('season_id', $oldSeason->id)
            ->where('status', SixHeroBattleLog::STATUS_STARTED)
            ->count() === 5,
        20,
        'Month rollover battles did not all reach started before the deadline.',
    );

    Carbon::setTestNow(releaseTime('2026-08-01 00:00:01'));
    $newSeason = createSeason('2026-08', '2026-08-01', '2026-09-01');
    $initialization = app(SixHeroRankingInitializationService::class)
        ->initialize($newSeason, releaseTime('2026-08-01 00:00:01'));
    releaseAssert(! $initialization->initialized, 'New Season initialized from a pending old Season.');
    $pendingFinalization = app(SixHeroSeasonFinalizationService::class)
        ->finalizeSeason($oldSeason, releaseTime('2026-08-01 00:00:01'));
    releaseAssert($pendingFinalization->pendingBattles, 'Old Season did not report pending battles.');
    releaseAssert(SixHeroRanking::query()->where('season_id', $newSeason->id)->doesntExist(), 'Pending rollover wrote new rankings.');

    touch($resolveGate);
    $results = collectWorkers($handles);
    releaseAssert(allWorkersSucceeded($results), 'A rollover battle failed after the deadline.');
    releaseAssert(
        SixHeroBattleLog::query()
            ->where('season_id', $oldSeason->id)
            ->where('status', SixHeroBattleLog::STATUS_COMPLETED)
            ->count() === 5,
        'Pre-deadline battles did not complete after the deadline.',
    );
    releaseAssert(
        SixHeroBattleLog::query()
            ->where('season_id', $oldSeason->id)
            ->where('status', SixHeroBattleLog::STATUS_EXPIRED)
            ->doesntExist(),
        'A pre-deadline started battle was expired.',
    );

    Carbon::setTestNow(releaseTime('2026-08-01 00:05:00'));
    $finalization = app(SixHeroSeasonFinalizationService::class)
        ->finalizeSeason($oldSeason, releaseTime('2026-08-01 00:05:00'));
    releaseAssert($finalization->finalized, 'Old Season did not finalize after all battles completed.');
    releaseAssert($finalization->champions->count() === 6, 'Rollover finalization did not create six snapshots.');

    Carbon::setTestNow(releaseTime('2026-08-01 00:06:00'));
    $carryover = app(SixHeroRankingInitializationService::class)
        ->initialize($newSeason, releaseTime('2026-08-01 00:06:00'));
    releaseAssert($carryover->initialized, 'New Season did not initialize after finalization.');
    releaseAssert($carryover->copiedRankingCount === 48, 'Rollover did not copy all six rooms.');
    releaseAssert(
        SixHeroRanking::query()
            ->where('season_id', $newSeason->id)
            ->where(function ($query): void {
                $query->where('official_attack_wins', '!=', 0)
                    ->orWhere('official_attack_losses', '!=', 0)
                    ->orWhere('defense_wins', '!=', 0)
                    ->orWhere('defense_losses', '!=', 0);
            })
            ->doesntExist(),
        'Rollover counters were not reset.',
    );
    foreach ($rooms as $room) {
        assertRankingInvariant($oldSeason->id, $room);
        assertRankingInvariant($newSeason->id, $room);
    }

    $newAttacker = SixHeroRanking::query()
        ->where('season_id', $newSeason->id)
        ->where('room_key', SixHeroRoomKey::MIRACLE->value)
        ->where('rank', 2)
        ->firstOrFail();
    $newDefender = SixHeroRanking::query()
        ->where('season_id', $newSeason->id)
        ->where('room_key', SixHeroRoomKey::MIRACLE->value)
        ->where('rank', 1)
        ->firstOrFail();
    $newBattle = runWorkers([workerSpec('official', releaseTime('2026-08-01 00:07:00'), [
        'season_id' => $newSeason->id,
        'room' => SixHeroRoomKey::MIRACLE->value,
        'attacker_id' => $newAttacker->character_id,
        'defender_id' => $newDefender->character_id,
        'attacker_won' => false,
    ])]);
    releaseAssert(allWorkersSucceeded($newBattle), 'Official battle was not available after carryover.');
    releaseAssert(duplicateChampionCount() === 0, 'Rollover produced duplicate Champion snapshots.');
    releaseAssert(roomUsageRowsOverLimit() === 0, 'Rollover exceeded a Room daily limit.');

    return [
        'distinct_connections' => distinctConnectionCount($results),
        'pre_deadline_started' => 5,
        'completed_after_deadline' => 5,
        'expired' => 0,
        'champions' => DB::table('six_hero_champions')->where('season_id', $oldSeason->id)->count(),
        'carryover_rankings' => SixHeroRanking::query()->where('season_id', $newSeason->id)->count(),
        'nonzero_carryover_counters' => 0,
        'new_season_official_available' => true,
    ];
}

/** @param array<string, mixed> $payload */
function workerSpec(string $operation, CarbonImmutable $at, array $payload): array
{
    return array_merge($payload, [
        'operation' => $operation,
        'at' => $at->toIso8601String(),
    ]);
}

function runWorker(string $encodedPayload): void
{
    $payload = json_decode((string) base64_decode($encodedPayload, true), true, 512, JSON_THROW_ON_ERROR);
    $ready = (string) ($payload['ready_file'] ?? '');
    $go = (string) ($payload['go_file'] ?? '');
    $connectionId = (int) DB::scalar('SELECT CONNECTION_ID()');

    try {
        Carbon::setTestNow(releaseTime((string) $payload['at']));
        touch($ready);
        waitUntil(fn (): bool => is_file($go), 30, 'Worker start barrier timed out.');

        $operation = (string) $payload['operation'];
        $data = match ($operation) {
            'register' => workerRegister($payload),
            'official' => workerOfficial($payload),
            'practice' => workerPractice($payload),
            'initialize' => workerInitialize($payload),
            'finalize' => workerFinalize($payload),
            default => throw new RuntimeException("Unknown worker operation: {$operation}"),
        };
        printJson(['ok' => true, 'connection_id' => $connectionId, 'data' => $data]);
    } catch (Throwable $exception) {
        printJson([
            'ok' => false,
            'connection_id' => $connectionId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    } finally {
        DB::disconnect();
    }
}

/** @param array<string, mixed> $payload */
function workerRegister(array $payload): array
{
    $ranking = app(SixHeroRankingService::class)->register(
        SixHeroSeason::query()->findOrFail((int) $payload['season_id']),
        SixHeroRoomKey::from((string) $payload['room']),
        Character::query()->findOrFail((int) $payload['character_id']),
    );

    return ['ranking_id' => $ranking->id, 'rank' => $ranking->rank];
}

/** @param array<string, mixed> $payload */
function workerOfficial(array $payload): array
{
    bindDeterministicBattle($payload);
    $result = app(SixHeroOfficialBattleService::class)->execute(
        SixHeroSeason::query()->findOrFail((int) $payload['season_id']),
        SixHeroRoomKey::from((string) $payload['room']),
        Character::query()->findOrFail((int) $payload['attacker_id']),
        Character::query()->findOrFail((int) $payload['defender_id']),
    );

    return [
        'battle_log_id' => $result->battleLog->id,
        'status' => $result->battleLog->status,
        'attacker_won' => $result->resolution->attackerWon,
        'rank_changed' => $result->rankChange?->rankChanged,
        'attempts_used' => $result->officialAttemptsUsed,
    ];
}

/** @param array<string, mixed> $payload */
function workerPractice(array $payload): array
{
    bindDeterministicBattle($payload);
    $result = app(SixHeroPracticeBattleService::class)->execute(
        SixHeroSeason::query()->findOrFail((int) $payload['season_id']),
        SixHeroRoomKey::from((string) $payload['room']),
        Character::query()->findOrFail((int) $payload['attacker_id']),
        Character::query()->findOrFail((int) $payload['defender_id']),
    );

    return ['attacker_won' => $result->attackerWon];
}

/** @param array<string, mixed> $payload */
function workerInitialize(array $payload): array
{
    $result = app(SixHeroRankingInitializationService::class)->initialize(
        SixHeroSeason::query()->findOrFail((int) $payload['season_id']),
        releaseTime((string) $payload['at']),
    );

    return [
        'initialized' => $result->initialized,
        'already_initialized' => $result->alreadyInitialized,
        'waiting' => $result->waitingForPreviousFinalization,
        'copied' => $result->copiedRankingCount,
    ];
}

/** @param array<string, mixed> $payload */
function workerFinalize(array $payload): array
{
    $result = app(SixHeroSeasonFinalizationService::class)->finalizeSeason(
        SixHeroSeason::query()->findOrFail((int) $payload['season_id']),
        releaseTime((string) $payload['at']),
    );

    return [
        'finalized' => $result->finalized,
        'already_finalized' => $result->alreadyFinalized,
        'pending' => $result->pendingBattles,
        'champions' => $result->champions->count(),
    ];
}

/** @param array<string, mixed> $payload */
function bindDeterministicBattle(array $payload): void
{
    $resolvedAt = isset($payload['resolved_at'])
        ? releaseTime((string) $payload['resolved_at'])
        : null;
    app()->instance(PvPBattleService::class, new Phase6DDeterministicPvPBattleService(
        attackerWon: (bool) ($payload['attacker_won'] ?? true),
        resolveGate: isset($payload['resolve_gate']) ? (string) $payload['resolve_gate'] : null,
        resolvedAt: $resolvedAt,
    ));
}

/**
 * @param  array<int, array<string, mixed>>  $specs
 * @return array<int, array<string, mixed>>
 */
function runWorkers(array $specs, ?callable $afterRelease = null): array
{
    $handles = startWorkers($specs);
    releaseWorkers($handles);
    if ($afterRelease !== null) {
        $afterRelease();
    }

    return collectWorkers($handles);
}

/**
 * @param  array<int, array<string, mixed>>  $specs
 * @return array{directory: string, go_file: string, workers: array<int, array<string, mixed>>}
 */
function startWorkers(array $specs): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'six-hero-phase6d-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory, 0700, true);
    $goFile = $directory.DIRECTORY_SEPARATOR.'go';
    $workers = [];

    foreach ($specs as $index => $spec) {
        $readyFile = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
        $spec['ready_file'] = $readyFile;
        $spec['go_file'] = $goFile;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, 'worker', base64_encode(json_encode($spec, JSON_THROW_ON_ERROR))],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            null,
            ['bypass_shell' => true],
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a PHP worker process.');
        }
        fclose($pipes[0]);
        $workers[] = compact('process', 'pipes', 'readyFile');
    }

    waitUntil(
        fn (): bool => count(array_filter(
            $workers,
            static fn (array $worker): bool => is_file($worker['readyFile']),
        )) === count($workers),
        30,
        'Not all PHP workers reached the start barrier.',
    );

    return ['directory' => $directory, 'go_file' => $goFile, 'workers' => $workers];
}

/** @param array{go_file: string} $handles */
function releaseWorkers(array $handles): void
{
    touch($handles['go_file']);
}

/**
 * @param  array{directory: string, workers: array<int, array<string, mixed>>}  $handles
 * @return array<int, array<string, mixed>>
 */
function collectWorkers(array $handles): array
{
    $deadline = microtime(true) + 45;
    do {
        $running = false;
        foreach ($handles['workers'] as $worker) {
            $status = proc_get_status($worker['process']);
            $running = $running || $status['running'];
        }
        if (! $running) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    $results = [];
    foreach ($handles['workers'] as $worker) {
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process']);
            throw new RuntimeException('A PHP worker exceeded the release harness timeout.');
        }
        $stdout = trim((string) stream_get_contents($worker['pipes'][1]));
        $stderr = trim((string) stream_get_contents($worker['pipes'][2]));
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        if ($stdout === '') {
            throw new RuntimeException('A PHP worker returned no JSON. '.$stderr);
        }
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if ($stderr !== '') {
            $decoded['stderr'] = $stderr;
        }
        $results[] = $decoded;
    }

    File::deleteDirectory($handles['directory']);
    releaseAssert(
        distinctConnectionCount($results) === count($results),
        'Worker processes did not use distinct MySQL connections.',
    );

    return $results;
}

function resetCompetitionFixtures(): void
{
    Carbon::setTestNow();
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'six_hero_battle_logs',
        'six_hero_daily_usages',
        'six_hero_champions',
        'six_hero_rankings',
        'six_hero_seasons',
    ] as $table) {
        DB::table($table)->truncate();
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::table('users')->where('email', 'like', 'phase6d-race-%')->delete();
}

/** @return array<int, Character> */
function createCharacters(int $count, string $scenario): array
{
    $token = bin2hex(random_bytes(4));
    $now = now();
    $userId = DB::table('users')->insertGetId([
        'name' => "Phase6D {$scenario}",
        'email' => "phase6d-race-{$scenario}-{$token}@example.invalid",
        'role' => 'user',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $ids = [];
    for ($index = 1; $index <= $count; $index++) {
        $ids[] = DB::table('characters')->insertGetId([
            'user_id' => $userId,
            'name' => "Phase6D {$scenario} {$index}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return Character::query()->whereIn('id', $ids)->orderBy('id')->get()->all();
}

function createSeason(
    string $key,
    string $startsAt,
    string $endsAt,
    ?CarbonImmutable $rankingInitializedAt = null,
    ?CarbonImmutable $finalizedAt = null,
): SixHeroSeason {
    return SixHeroSeason::query()->create([
        'season_key' => $key,
        'starts_at' => releaseTime($startsAt.' 00:00:00'),
        'ends_at' => releaseTime($endsAt.' 00:00:00'),
        'finalized_at' => $finalizedAt,
        'ranking_initialized_at' => $rankingInitializedAt,
    ]);
}

/** @param array<int, Character> $characters */
function insertRankings(
    SixHeroSeason $season,
    SixHeroRoomKey $room,
    array $characters,
    int $attackWins = 0,
    int $attackLosses = 0,
    int $defenseWins = 0,
    int $defenseLosses = 0,
): void {
    $rows = [];
    foreach ($characters as $index => $character) {
        $rank = $index + 1;
        $rows[] = [
            'season_id' => $season->id,
            'room_key' => $room->value,
            'character_id' => $character->id,
            'rank' => $rank,
            'official_attack_wins' => $attackWins,
            'official_attack_losses' => $attackLosses,
            'defense_wins' => $defenseWins,
            'defense_losses' => $defenseLosses,
            'registered_at' => $season->starts_at,
            'first_place_since' => $rank === 1 ? $season->starts_at : null,
            'created_at' => $season->starts_at,
            'updated_at' => $season->starts_at,
        ];
    }
    DB::table('six_hero_rankings')->insert($rows);
}

function createPendingBattleLog(
    SixHeroSeason $season,
    SixHeroRoomKey $room,
    Character $attacker,
    Character $defender,
    CarbonImmutable $startedAt,
): SixHeroBattleLog {
    return SixHeroBattleLog::query()->create([
        'season_id' => $season->id,
        'room_key' => $room,
        'battle_mode' => SixHeroBattleLog::MODE_OFFICIAL,
        'status' => SixHeroBattleLog::STATUS_STARTED,
        'attacker_id' => $attacker->id,
        'defender_id' => $defender->id,
        'attacker_rank_at_start' => 2,
        'defender_rank_at_start' => 1,
        'daily_attempt_number' => 1,
        'started_at' => $startedAt,
    ]);
}

/** @return array<string, int> */
function rankingInvariant(int $seasonId, SixHeroRoomKey $room): array
{
    $metrics = DB::table('six_hero_rankings')
        ->where('season_id', $seasonId)
        ->where('room_key', $room->value)
        ->selectRaw('COUNT(*) AS row_count')
        ->selectRaw('COUNT(DISTINCT character_id) AS character_count')
        ->selectRaw('COUNT(DISTINCT `rank`) AS rank_count')
        ->selectRaw('COALESCE(MIN(`rank`), 0) AS min_rank')
        ->selectRaw('COALESCE(MAX(`rank`), 0) AS max_rank')
        ->selectRaw('SUM(CASE WHEN `rank` <= 0 THEN 1 ELSE 0 END) AS non_positive_count')
        ->first();

    return [
        'rows' => (int) $metrics->row_count,
        'characters' => (int) $metrics->character_count,
        'ranks' => (int) $metrics->rank_count,
        'min' => (int) $metrics->min_rank,
        'max' => (int) $metrics->max_rank,
        'non_positive' => (int) $metrics->non_positive_count,
    ];
}

function assertRankingInvariant(int $seasonId, SixHeroRoomKey $room): void
{
    $metrics = rankingInvariant($seasonId, $room);
    $rows = $metrics['rows'];
    releaseAssert($metrics['characters'] === $rows, "Duplicate Character in {$room->value}.");
    releaseAssert($metrics['ranks'] === $rows, "Duplicate rank in {$room->value}.");
    releaseAssert($metrics['non_positive'] === 0, "Non-positive rank in {$room->value}.");
    if ($rows > 0) {
        releaseAssert($metrics['min'] === 1 && $metrics['max'] === $rows, "Rank gap in {$room->value}.");
    }
}

function terminalLogCount(int $seasonId): int
{
    return SixHeroBattleLog::query()
        ->where('season_id', $seasonId)
        ->whereIn('status', [
            SixHeroBattleLog::STATUS_COMPLETED,
            SixHeroBattleLog::STATUS_FAILED,
            SixHeroBattleLog::STATUS_EXPIRED,
        ])
        ->count();
}

function duplicateChampionCount(): int
{
    return DB::query()
        ->fromSub(
            DB::table('six_hero_champions')
                ->select('season_id', 'room_key')
                ->selectRaw('COUNT(*) AS aggregate')
                ->groupBy('season_id', 'room_key')
                ->havingRaw('COUNT(*) > 1'),
            'duplicates',
        )
        ->count();
}

function roomUsageRowsOverLimit(): int
{
    $usageService = app(SixHeroDailyUsageService::class);

    return SixHeroDailyUsage::query()
        ->get()
        ->filter(static function (SixHeroDailyUsage $usage) use ($usageService): bool {
            return max($usageService->attemptsByRoom($usage)) > 5;
        })
        ->count();
}

/** @param array<int, array<string, mixed>> $results */
function allWorkersSucceeded(array $results): bool
{
    return count(array_filter(
        $results,
        static fn (array $result): bool => (bool) ($result['ok'] ?? false),
    )) === count($results);
}

/** @param array<int, array<string, mixed>> $results */
function distinctConnectionCount(array $results): int
{
    return count(array_unique(array_filter(
        array_map(
            static fn (array $result): int => (int) ($result['connection_id'] ?? 0),
            $results,
        ),
        static fn (int $connectionId): bool => $connectionId > 0,
    )));
}

function newResolveGatePath(string $name): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'six-hero-phase6d-resolve';
    File::ensureDirectoryExists($directory);

    return $directory.DIRECTORY_SEPARATOR.$name.'-'.bin2hex(random_bytes(6));
}

function releaseTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, (string) config('app.timezone'));
}

function waitUntil(callable $condition, int $timeoutSeconds, string $message): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        if ($condition()) {
            return;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException($message);
}

function catches(callable $callback, string $expectedClass): bool
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception instanceof $expectedClass;
    }

    return false;
}

function releaseAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $payload */
function printJson(array $payload): void
{
    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
}
