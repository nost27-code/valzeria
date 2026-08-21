<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroChampion;
use App\Models\SixHeroDailyUsage;
use App\Models\SixHeroRanking;
use App\Models\SixHeroSeason;
use App\Support\SixHeroCompetitionRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SixHeroOperationsService
{
    /** @var array<string, array<int, string>> */
    private const REQUIRED_SCHEMA = [
        'six_hero_seasons' => [
            'id',
            'season_key',
            'starts_at',
            'ends_at',
            'finalized_at',
            'ranking_initialized_at',
        ],
        'six_hero_rankings' => [
            'season_id',
            'room_key',
            'character_id',
            'rank',
            'official_attack_wins',
            'official_attack_losses',
        ],
        'six_hero_daily_usages' => [
            'character_id',
            'usage_date',
            'official_attempts',
            'official_attempts_by_room',
        ],
        'six_hero_battle_logs' => [
            'season_id',
            'room_key',
            'battle_mode',
            'status',
            'attacker_id',
            'defender_id',
            'started_at',
            'resolved_at',
            'completed_at',
            'failed_at',
            'failure_code',
        ],
        'six_hero_champions' => [
            'season_id',
            'room_key',
            'character_id',
            'character_id_snapshot',
            'character_name_snapshot',
            'is_vacant',
        ],
    ];

    public function __construct(
        private readonly SixHeroSeasonService $seasonService,
        private readonly SixHeroDailyUsageService $dailyUsageService,
    ) {}

    public function healthReport(
        ?CarbonInterface $at = null,
    ): SixHeroHealthReport {
        $checkedAt = $this->inAppTimezone($at);
        $items = [
            $this->databaseCheck(),
            $schema = $this->schemaCheck(),
            $this->featureFlagCheck(),
        ];
        $schemaReady = (bool) ($schema->metadata['ready'] ?? false);
        $currentSeason = null;

        if (! $schemaReady) {
            foreach ($this->dependentCheckDefinitions() as [$key, $label]) {
                $items[] = $this->unavailableItem($key, $label);
            }

            return new SixHeroHealthReport($checkedAt, $items);
        }

        $items[] = $this->safeItem(
            'current_season',
            '現在Season',
            function () use ($checkedAt, &$currentSeason): SixHeroHealthCheckItem {
                $currentSeason = $this->seasonService->findCurrentSeason($checkedAt);
                if ($currentSeason === null) {
                    return $this->fail(
                        'current_season',
                        '現在Season',
                        '現在月のSeasonがありません。安全なSeason確認を再実行してください。',
                    );
                }

                if ($currentSeason->finalized_at !== null) {
                    return $this->fail(
                        'current_season',
                        '現在Season',
                        "{$currentSeason->season_key} が期間中に確定済みになっています。",
                        $this->seasonMetadata($currentSeason),
                    );
                }

                return $this->pass(
                    'current_season',
                    '現在Season',
                    sprintf(
                        '%s（%s ～ %s）',
                        $currentSeason->season_key,
                        $this->localTimestamp($currentSeason->starts_at),
                        $this->localTimestamp($currentSeason->ends_at),
                    ),
                    $this->seasonMetadata($currentSeason),
                );
            },
        );

        $rankingInitialization = $this->safeItem(
            'ranking_initialization',
            'ランキング初期化',
            fn (): SixHeroHealthCheckItem => $this->rankingInitializationCheck(
                $currentSeason,
            ),
        );
        $items[] = $rankingInitialization;
        $items[] = $this->safeItem(
            'ranking_invariants',
            '6部屋Ranking整合性',
            fn (): SixHeroHealthCheckItem => $this->rankingInvariantCheck(
                $currentSeason,
                $rankingInitialization,
            ),
        );
        $items[] = $this->safeItem(
            'daily_usage',
            '公式戦挑戦回数',
            fn (): SixHeroHealthCheckItem => $this->dailyUsageCheck(),
        );
        $items[] = $this->safeItem(
            'champions',
            '確定Champion',
            fn (): SixHeroHealthCheckItem => $this->championCheck(),
        );
        $items[] = $this->safeItem(
            'historical_identity',
            '英雄identity',
            fn (): SixHeroHealthCheckItem => $this->historicalIdentityCheck(),
        );
        $items[] = $this->safeItem(
            'pending_battles',
            '未完了公式戦',
            fn (): SixHeroHealthCheckItem => $this->pendingBattleCheck($checkedAt),
        );
        $items[] = $this->safeItem(
            'failed_battles',
            '失敗公式戦',
            fn (): SixHeroHealthCheckItem => $this->failedBattleCheck($checkedAt),
        );
        $items[] = $this->safeItem(
            'previous_season',
            '直前月Season',
            fn (): SixHeroHealthCheckItem => $this->previousSeasonCheck(
                $currentSeason,
            ),
        );

        return new SixHeroHealthReport($checkedAt, $items);
    }

    /**
     * @return array{
     *     report:SixHeroHealthReport,
     *     current_season:array{key:string,starts_at:string,ends_at:string,ranking_initialized:bool}|null,
     *     rooms:array<int, array<string, bool|int|string|null>>,
     *     daily_usage:array{usage_date:string,player_count:int,attempt_count:int,limit_reached_count:int,limit:int},
     *     battle_list_limit:int,
     *     pending_battles:array<int, array<string, int|string|null>>,
     *     failed_battles:array<int, array<string, int|string|null>>
     * }
     */
    public function dashboardData(
        ?CarbonInterface $at = null,
    ): array {
        $report = $this->healthReport($at);
        $checkedAt = $report->checkedAt;
        $schemaReady = (bool) ($report->item('required_schema')?->metadata['ready'] ?? false);
        $season = null;

        if ($schemaReady) {
            try {
                $season = $this->seasonService->findCurrentSeason($checkedAt);
            } catch (Throwable) {
                $season = null;
            }
        }

        return [
            'report' => $report,
            'current_season' => $season === null ? null : [
                'key' => (string) $season->season_key,
                'starts_at' => $this->localTimestamp($season->starts_at),
                'ends_at' => $this->localTimestamp($season->ends_at),
                'ranking_initialized' => $season->ranking_initialized_at !== null,
            ],
            'rooms' => $schemaReady
                ? $this->roomSummaries($season, $report)
                : $this->emptyRoomSummaries('fail', '確認不可'),
            'daily_usage' => $schemaReady
                ? $this->dailyUsageSummary($checkedAt)
                : $this->emptyDailyUsageSummary($checkedAt),
            'battle_list_limit' => $this->battleListLimit(),
            'pending_battles' => $schemaReady
                ? $this->pendingBattleRows($checkedAt)
                : [],
            'failed_battles' => $schemaReady
                ? $this->failedBattleRows()
                : [],
        ];
    }

    private function databaseCheck(): SixHeroHealthCheckItem
    {
        return $this->safeItem(
            'database',
            'Database',
            function (): SixHeroHealthCheckItem {
                $driver = DB::connection()->getDriverName();
                $version = match ($driver) {
                    'sqlite' => (string) DB::scalar('SELECT sqlite_version()'),
                    default => (string) DB::scalar('SELECT VERSION()'),
                };
                $product = $this->databaseProduct($driver, $version);
                $expectedProduct = strtolower(trim((string) config(
                    'six_heroes.operations.expected_database_product',
                    'mariadb',
                )));
                $minimumVersion = trim((string) config(
                    'six_heroes.operations.minimum_database_version',
                    '10.5.13',
                ));
                $detectedVersion = $this->comparableDatabaseVersion($version);
                $metadata = [
                    'driver' => $driver,
                    'product' => $product,
                    'version' => $version,
                    'expected_product' => $expectedProduct,
                    'minimum_version' => $minimumVersion,
                    'detected_version' => $detectedVersion,
                ];

                if ($expectedProduct === '' || $product !== $expectedProduct) {
                    return $this->fail(
                        'database',
                        'Database',
                        "DB製品がRelease baselineと一致しません（検出: {$product} / 期待: "
                            .($expectedProduct === '' ? '未設定' : $expectedProduct).'）。',
                        $metadata,
                    );
                }

                if ($minimumVersion === '' || $detectedVersion === null) {
                    return $this->fail(
                        'database',
                        'Database',
                        'DB versionまたは最低versionを比較可能な形式で確認できません。',
                        $metadata,
                    );
                }

                if (version_compare($detectedVersion, $minimumVersion, '<')) {
                    return $this->fail(
                        'database',
                        'Database',
                        "DB version {$detectedVersion} はRelease baseline {$minimumVersion} 未満です。",
                        $metadata,
                    );
                }

                return $this->pass(
                    'database',
                    'Database',
                    sprintf(
                        '%s %s（baseline: %s >= %s）',
                        strtoupper($product),
                        $version,
                        $expectedProduct,
                        $minimumVersion,
                    ),
                    $metadata,
                );
            },
        );
    }

    private function databaseProduct(string $driver, string $version): string
    {
        if ($driver === 'mysql' && str_contains(strtolower($version), 'mariadb')) {
            return 'mariadb';
        }

        return match ($driver) {
            'pgsql' => 'postgresql',
            default => strtolower($driver),
        };
    }

    private function comparableDatabaseVersion(string $version): ?string
    {
        if (preg_match('/\d+\.\d+(?:\.\d+)?/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function schemaCheck(): SixHeroHealthCheckItem
    {
        return $this->safeItem(
            'required_schema',
            '必須DB構造',
            function (): SixHeroHealthCheckItem {
                $missing = [];
                foreach (self::REQUIRED_SCHEMA as $table => $columns) {
                    if (! Schema::hasTable($table)) {
                        $missing[] = $table;

                        continue;
                    }

                    $available = Schema::getColumnListing($table);
                    foreach (array_diff($columns, $available) as $column) {
                        $missing[] = "{$table}.{$column}";
                    }
                }

                if ($missing !== []) {
                    return $this->fail(
                        'required_schema',
                        '必須DB構造',
                        '六英雄戦の必須table/columnが不足しています。',
                        [
                            'ready' => false,
                            'missing_count' => count($missing),
                            'missing' => implode(', ', $missing),
                        ],
                    );
                }

                return $this->pass(
                    'required_schema',
                    '必須DB構造',
                    '必須table/columnを確認しました。',
                    [
                        'ready' => true,
                        'table_count' => count(self::REQUIRED_SCHEMA),
                    ],
                );
            },
        );
    }

    private function featureFlagCheck(): SixHeroHealthCheckItem
    {
        $enabled = (bool) config('features.six_hero_ui_enabled', false);

        return $this->pass(
            'feature_flag',
            '公開状態',
            'Master switch: '.($enabled ? 'ON' : 'OFF'),
            ['enabled' => $enabled],
        );
    }

    private function rankingInitializationCheck(
        ?SixHeroSeason $season,
    ): SixHeroHealthCheckItem {
        if ($season === null) {
            return $this->fail(
                'ranking_initialization',
                'ランキング初期化',
                '現在Seasonを確認できないため判定できません。',
            );
        }

        if ($season->ranking_initialized_at !== null) {
            return $this->pass(
                'ranking_initialization',
                'ランキング初期化',
                "{$season->season_key} は初期化済みです。",
                [
                    'initialized' => true,
                    'initialized_at' => $this->localTimestamp(
                        $season->ranking_initialized_at,
                    ),
                ],
            );
        }

        $previous = $this->previousSeason($season);
        if ($previous !== null && $previous->finalized_at === null) {
            $this->seasonService->assertMatchesCalendarMonth($previous);
            $pending = $this->pendingCountForSeason($previous);
            if ($pending > 0) {
                return $this->warning(
                    'ranking_initialization',
                    'ランキング初期化',
                    "{$previous->season_key} の未完了公式戦{$pending}件を待っています。",
                    [
                        'initialized' => false,
                        'waiting_for_previous_finalization' => true,
                        'previous_season' => (string) $previous->season_key,
                        'pending_battle_count' => $pending,
                    ],
                );
            }
        }

        return $this->fail(
            'ranking_initialization',
            'ランキング初期化',
            "{$season->season_key} のランキング初期化が完了していません。",
            [
                'initialized' => false,
                'waiting_for_previous_finalization' => false,
            ],
        );
    }

    private function rankingInvariantCheck(
        ?SixHeroSeason $season,
        SixHeroHealthCheckItem $initialization,
    ): SixHeroHealthCheckItem {
        if ($season === null) {
            return $this->fail(
                'ranking_invariants',
                '6部屋Ranking整合性',
                '現在Seasonを確認できないため判定できません。',
            );
        }

        if ($season->ranking_initialized_at === null) {
            $message = 'ランキング初期化完了後に整合性を確認します。';

            return $initialization->status === SixHeroHealthCheckItem::STATUS_WARNING
                ? $this->warning(
                    'ranking_invariants',
                    '6部屋Ranking整合性',
                    $message,
                    ['ready' => false],
                )
                : $this->fail(
                    'ranking_invariants',
                    '6部屋Ranking整合性',
                    $message,
                    ['ready' => false],
                );
        }

        $metrics = $this->rankingMetrics($season);
        $validRooms = $this->roomValues();
        $unknownRoomCount = SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->whereNotIn('room_key', $validRooms)
            ->count();
        $invalidRooms = [];

        foreach (SixHeroRoomKey::cases() as $room) {
            $metric = $metrics[$room->value] ?? $this->emptyRankingMetric();
            if (! $this->rankingMetricIsValid($metric)) {
                $invalidRooms[] = $room->value;
            }
        }

        if ($unknownRoomCount > 0 || $invalidRooms !== []) {
            return $this->fail(
                'ranking_invariants',
                '6部屋Ranking整合性',
                '重複・欠番・非正rank・Character重複のいずれかを検知しました。',
                [
                    'ready' => true,
                    'invalid_room_count' => count($invalidRooms),
                    'invalid_rooms' => implode(', ', $invalidRooms),
                    'unknown_room_rows' => $unknownRoomCount,
                ],
            );
        }

        return $this->pass(
            'ranking_invariants',
            '6部屋Ranking整合性',
            '全6部屋でrank 1..N・一意Characterを確認しました。',
            [
                'ready' => true,
                'room_count' => count(SixHeroRoomKey::cases()),
            ],
        );
    }

    private function dailyUsageCheck(): SixHeroHealthCheckItem
    {
        $limit = SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT;
        $invalid = 0;
        $minimum = null;
        $maximum = null;
        $maximumTotal = 0;

        SixHeroDailyUsage::query()->orderBy('id')->chunkById(
            200,
            function ($usages) use (
                &$invalid,
                &$minimum,
                &$maximum,
                &$maximumTotal,
            ): void {
                foreach ($usages as $usage) {
                    try {
                        $attempts = $this->dailyUsageService->attemptsByRoom($usage);
                    } catch (Throwable) {
                        $invalid++;

                        continue;
                    }

                    foreach ($attempts as $count) {
                        $minimum = $minimum === null ? $count : min($minimum, $count);
                        $maximum = $maximum === null ? $count : max($maximum, $count);
                    }
                    $maximumTotal = max($maximumTotal, (int) $usage->official_attempts);
                }
            },
        );

        if ($invalid > 0) {
            return $this->fail(
                'daily_usage',
                '公式戦挑戦回数',
                "各間0～{$limit}の範囲外、または合計不整合のDailyUsageを{$invalid}件検知しました。",
                [
                    'invalid_count' => $invalid,
                    'minimum' => $minimum === null ? null : (int) $minimum,
                    'maximum' => $maximum === null ? null : (int) $maximum,
                    'maximum_total' => $maximumTotal,
                    'limit' => $limit,
                ],
            );
        }

        return $this->pass(
            'daily_usage',
            '公式戦挑戦回数',
            "全DailyUsageで各間0～{$limit}回と日次合計の整合を確認しました。",
            [
                'invalid_count' => 0,
                'minimum' => $minimum === null ? null : (int) $minimum,
                'maximum' => $maximum === null ? null : (int) $maximum,
                'maximum_total' => $maximumTotal,
                'limit' => $limit,
            ],
        );
    }

    private function championCheck(): SixHeroHealthCheckItem
    {
        $expected = count(SixHeroRoomKey::cases());
        $finalized = DB::table('six_hero_seasons as seasons')
            ->leftJoin(
                'six_hero_champions as champions',
                'champions.season_id',
                '=',
                'seasons.id',
            )
            ->whereNotNull('seasons.finalized_at')
            ->groupBy('seasons.id', 'seasons.season_key')
            ->select('seasons.id', 'seasons.season_key')
            ->selectRaw('COUNT(champions.id) as champion_count')
            ->selectRaw('COUNT(DISTINCT champions.room_key) as room_count')
            ->get();
        $invalid = $finalized->filter(
            static fn (object $row): bool => (int) $row->champion_count !== $expected
                || (int) $row->room_count !== $expected,
        );
        $unknownRoomRows = SixHeroChampion::query()
            ->whereNotIn('room_key', $this->roomValues())
            ->count();
        $prematureSnapshots = SixHeroChampion::query()
            ->whereHas(
                'season',
                static fn (Builder $query): Builder => $query->whereNull('finalized_at'),
            )
            ->count();

        if ($invalid->isNotEmpty() || $unknownRoomRows > 0 || $prematureSnapshots > 0) {
            return $this->fail(
                'champions',
                '確定Champion',
                '確定Seasonの6件snapshotまたは確定境界に不整合があります。',
                [
                    'finalized_season_count' => $finalized->count(),
                    'invalid_season_count' => $invalid->count(),
                    'invalid_seasons' => $invalid->pluck('season_key')->implode(', '),
                    'unknown_room_rows' => $unknownRoomRows,
                    'premature_snapshots' => $prematureSnapshots,
                ],
            );
        }

        return $this->pass(
            'champions',
            '確定Champion',
            $finalized->isEmpty()
                ? '確定済みSeasonはまだありません。'
                : "確定済み{$finalized->count()}Seasonすべてに6件あります。",
            [
                'finalized_season_count' => $finalized->count(),
                'invalid_season_count' => 0,
                'unknown_room_rows' => 0,
                'premature_snapshots' => 0,
            ],
        );
    }

    private function historicalIdentityCheck(): SixHeroHealthCheckItem
    {
        $invalid = SixHeroChampion::query()
            ->where('is_vacant', false)
            ->where(function (Builder $query): void {
                $query->whereNull('character_id_snapshot')
                    ->orWhereNull('character_name_snapshot')
                    ->orWhereRaw("TRIM(character_name_snapshot) = ''");
            })
            ->count();

        if ($invalid > 0) {
            return $this->fail(
                'historical_identity',
                '英雄identity',
                "identity snapshotが欠けた英雄を{$invalid}件検知しました。",
                ['invalid_count' => $invalid],
            );
        }

        return $this->pass(
            'historical_identity',
            '英雄identity',
            '全英雄のimmutable identity snapshotを確認しました。',
            ['invalid_count' => 0],
        );
    }

    private function pendingBattleCheck(
        CarbonImmutable $checkedAt,
    ): SixHeroHealthCheckItem {
        $threshold = $this->staleBattleMinutes();
        $staleBefore = $checkedAt->subMinutes($threshold);
        $query = $this->pendingBattleQuery();
        $total = (clone $query)->count();
        $oldest = (clone $query)->min('started_at');
        $stale = (clone $query)
            ->where('started_at', '<=', $staleBefore)
            ->count();
        $endedStaleBlocking = DB::table('six_hero_battle_logs as logs')
            ->join('six_hero_seasons as seasons', 'seasons.id', '=', 'logs.season_id')
            ->where('logs.battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->whereIn('logs.status', [
                SixHeroBattleLog::STATUS_STARTED,
                SixHeroBattleLog::STATUS_RESOLVED,
            ])
            ->whereNull('seasons.finalized_at')
            ->where('seasons.ends_at', '<=', $checkedAt)
            ->whereColumn('logs.started_at', '<', 'seasons.ends_at')
            ->where('logs.started_at', '<=', $staleBefore)
            ->count();
        $metadata = [
            'pending_count' => $total,
            'stale_count' => $stale,
            'ended_stale_blocking_count' => $endedStaleBlocking,
            'oldest_started_at' => $oldest === null
                ? null
                : $this->localTimestamp($oldest),
            'stale_minutes' => $threshold,
        ];

        if ($endedStaleBlocking > 0) {
            return $this->fail(
                'pending_battles',
                '未完了公式戦',
                "終了Season確定を阻害する長時間未完了戦が{$endedStaleBlocking}件あります。",
                $metadata,
            );
        }

        if ($stale > 0) {
            return $this->warning(
                'pending_battles',
                '未完了公式戦',
                "{$threshold}分を超えた未完了公式戦が{$stale}件あります。",
                $metadata,
            );
        }

        return $this->pass(
            'pending_battles',
            '未完了公式戦',
            $total === 0
                ? '未完了公式戦はありません。'
                : "進行中の公式戦が{$total}件あります（staleなし）。",
            $metadata,
        );
    }

    private function failedBattleCheck(
        CarbonImmutable $checkedAt,
    ): SixHeroHealthCheckItem {
        $hours = $this->failedBattleWindowHours();
        $cutoff = $checkedAt->subHours($hours);
        $count = SixHeroBattleLog::query()
            ->where('battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->where('status', SixHeroBattleLog::STATUS_FAILED)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('failed_at', '>=', $cutoff)
                    ->orWhere(function (Builder $missingTimestamp) use ($cutoff): void {
                        $missingTimestamp->whereNull('failed_at')
                            ->where('updated_at', '>=', $cutoff);
                    });
            })
            ->count();

        if ($count > 0) {
            return $this->warning(
                'failed_battles',
                '失敗公式戦',
                "直近{$hours}時間にfailed公式戦が{$count}件あります。",
                [
                    'failed_count' => $count,
                    'window_hours' => $hours,
                ],
            );
        }

        return $this->pass(
            'failed_battles',
            '失敗公式戦',
            "直近{$hours}時間のfailed公式戦はありません。",
            [
                'failed_count' => 0,
                'window_hours' => $hours,
            ],
        );
    }

    private function previousSeasonCheck(
        ?SixHeroSeason $currentSeason,
    ): SixHeroHealthCheckItem {
        if ($currentSeason === null) {
            return $this->fail(
                'previous_season',
                '直前月Season',
                '現在Seasonを確認できないため判定できません。',
            );
        }

        $previous = $this->previousSeason($currentSeason);
        if ($previous === null) {
            return $this->pass(
                'previous_season',
                '直前月Season',
                '直前暦月のSeasonはありません（初回運用状態）。',
                ['exists' => false],
            );
        }

        $this->seasonService->assertMatchesCalendarMonth($previous);
        if ($previous->finalized_at !== null) {
            return $this->pass(
                'previous_season',
                '直前月Season',
                "{$previous->season_key} は確定済みです。",
                [
                    'exists' => true,
                    'season_key' => (string) $previous->season_key,
                    'finalized' => true,
                ],
            );
        }

        $pending = $this->pendingCountForSeason($previous);
        if ($pending > 0) {
            return $this->warning(
                'previous_season',
                '直前月Season',
                "{$previous->season_key} は未完了公式戦{$pending}件の完了待ちです。",
                [
                    'exists' => true,
                    'season_key' => (string) $previous->season_key,
                    'finalized' => false,
                    'pending_battle_count' => $pending,
                ],
            );
        }

        return $this->fail(
            'previous_season',
            '直前月Season',
            "{$previous->season_key} はpendingなしで未確定です。確定処理を再試行してください。",
            [
                'exists' => true,
                'season_key' => (string) $previous->season_key,
                'finalized' => false,
                'pending_battle_count' => 0,
            ],
        );
    }

    /** @return array<int, array<string, bool|int|string|null>> */
    private function roomSummaries(
        ?SixHeroSeason $season,
        SixHeroHealthReport $report,
    ): array {
        if ($season === null) {
            return $this->emptyRoomSummaries('fail', '確認不可');
        }

        $metrics = $this->rankingMetrics($season);
        $leaders = SixHeroRanking::query()
            ->with('character:id,name')
            ->where('season_id', $season->id)
            ->where('rank', 1)
            ->get()
            ->keyBy(fn (SixHeroRanking $ranking): string => $ranking->room_key->value);
        $initializationStatus = $report->item('ranking_initialization')?->status
            ?? SixHeroHealthCheckItem::STATUS_FAIL;

        return array_map(
            function (SixHeroRoomKey $room) use (
                $season,
                $metrics,
                $leaders,
                $initializationStatus,
            ): array {
                $metric = $metrics[$room->value] ?? $this->emptyRankingMetric();
                $leader = $leaders->get($room->value);
                $ready = $season->ranking_initialized_at !== null;
                $valid = $ready && $this->rankingMetricIsValid($metric);
                $status = $ready
                    ? ($valid ? 'pass' : 'fail')
                    : ($initializationStatus === SixHeroHealthCheckItem::STATUS_WARNING
                        ? 'warning'
                        : 'fail');

                return [
                    'room_key' => $room->value,
                    'room_label' => $room->label(),
                    'registered_count' => $metric['row_count'],
                    'official_battle_count' => $metric['official_battle_count'],
                    'leader_id' => $leader instanceof SixHeroRanking
                        ? (int) $leader->character_id
                        : null,
                    'leader_name' => $leader instanceof SixHeroRanking
                        ? ($leader->character?->name ?? '削除済みCharacter')
                        : null,
                    'integrity_status' => $status,
                    'integrity_label' => match ($status) {
                        'pass' => '正常',
                        'warning' => '準備中',
                        default => '要対応',
                    },
                ];
            },
            SixHeroRoomKey::cases(),
        );
    }

    /** @return array{usage_date:string,player_count:int,attempt_count:int,limit_reached_count:int,limit:int} */
    private function dailyUsageSummary(CarbonImmutable $checkedAt): array
    {
        $date = $checkedAt->toDateString();
        $limit = SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT;
        $usages = SixHeroDailyUsage::query()
            ->where('usage_date', $date)
            ->get();
        $limitReachedCount = 0;
        foreach ($usages as $usage) {
            try {
                $limitReachedCount += count(array_filter(
                    $this->dailyUsageService->attemptsByRoom($usage),
                    static fn (int $attempts): bool => $attempts >= $limit,
                ));
            } catch (Throwable) {
                // The Health Check reports the invalid row; keep the dashboard available.
            }
        }

        return [
            'usage_date' => $date,
            'player_count' => $usages->count(),
            'attempt_count' => (int) $usages->sum('official_attempts'),
            'limit_reached_count' => $limitReachedCount,
            'limit' => $limit,
        ];
    }

    /** @return array<int, array<string, int|string|null>> */
    private function pendingBattleRows(CarbonImmutable $checkedAt): array
    {
        return $this->pendingBattleQuery()
            ->with([
                'season:id,season_key',
                'attacker:id,name',
                'defender:id,name',
            ])
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($this->battleListLimit())
            ->get()
            ->map(function (SixHeroBattleLog $log) use ($checkedAt): array {
                $startedAt = CarbonImmutable::instance($log->started_at)
                    ->setTimezone($this->timezone());
                $ageSeconds = max(0, (int) $startedAt->diffInSeconds($checkedAt));

                return [
                    'id' => (int) $log->id,
                    'season_key' => $log->season?->season_key,
                    'room_label' => $log->room_key->label(),
                    'attacker_name' => $log->attacker?->name ?? '削除済みCharacter',
                    'defender_name' => $log->defender?->name ?? '削除済みCharacter',
                    'status' => (string) $log->status,
                    'started_at' => $this->localTimestamp($log->started_at),
                    'age_seconds' => $ageSeconds,
                    'age_label' => $this->durationLabel($ageSeconds),
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, int|string|null>> */
    private function failedBattleRows(): array
    {
        return SixHeroBattleLog::query()
            ->with([
                'season:id,season_key',
                'attacker:id,name',
                'defender:id,name',
            ])
            ->where('battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->where('status', SixHeroBattleLog::STATUS_FAILED)
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($this->battleListLimit())
            ->get()
            ->map(fn (SixHeroBattleLog $log): array => [
                'id' => (int) $log->id,
                'season_key' => $log->season?->season_key,
                'room_label' => $log->room_key->label(),
                'attacker_name' => $log->attacker?->name ?? '削除済みCharacter',
                'defender_name' => $log->defender?->name ?? '削除済みCharacter',
                'failure_code' => $log->failure_code,
                'failed_at' => $log->failed_at === null
                    ? null
                    : $this->localTimestamp($log->failed_at),
            ])
            ->all();
    }

    /** @return array<string, array<string, int>> */
    private function rankingMetrics(SixHeroSeason $season): array
    {
        return SixHeroRanking::query()
            ->where('season_id', $season->id)
            ->select('room_key')
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COUNT(DISTINCT `rank`) as distinct_rank_count')
            ->selectRaw('COUNT(DISTINCT character_id) as distinct_character_count')
            ->selectRaw('MIN(`rank`) as minimum_rank')
            ->selectRaw('MAX(`rank`) as maximum_rank')
            ->selectRaw('SUM(CASE WHEN `rank` <= 0 THEN 1 ELSE 0 END) as non_positive_count')
            ->selectRaw(
                'COALESCE(SUM(official_attack_wins + official_attack_losses), 0) as official_battle_count',
            )
            ->groupBy('room_key')
            ->get()
            ->mapWithKeys(static fn (SixHeroRanking $row): array => [
                $row->getRawOriginal('room_key') => [
                    'row_count' => (int) $row->row_count,
                    'distinct_rank_count' => (int) $row->distinct_rank_count,
                    'distinct_character_count' => (int) $row->distinct_character_count,
                    'minimum_rank' => $row->minimum_rank === null
                        ? 0
                        : (int) $row->minimum_rank,
                    'maximum_rank' => $row->maximum_rank === null
                        ? 0
                        : (int) $row->maximum_rank,
                    'non_positive_count' => (int) $row->non_positive_count,
                    'official_battle_count' => (int) $row->official_battle_count,
                ],
            ])
            ->all();
    }

    /** @param array<string, int> $metric */
    private function rankingMetricIsValid(array $metric): bool
    {
        $count = $metric['row_count'];
        if ($count === 0) {
            return true;
        }

        return $metric['non_positive_count'] === 0
            && $metric['distinct_rank_count'] === $count
            && $metric['distinct_character_count'] === $count
            && $metric['minimum_rank'] === 1
            && $metric['maximum_rank'] === $count;
    }

    /** @return array<string, int> */
    private function emptyRankingMetric(): array
    {
        return [
            'row_count' => 0,
            'distinct_rank_count' => 0,
            'distinct_character_count' => 0,
            'minimum_rank' => 0,
            'maximum_rank' => 0,
            'non_positive_count' => 0,
            'official_battle_count' => 0,
        ];
    }

    private function pendingBattleQuery(): Builder
    {
        return SixHeroBattleLog::query()
            ->where('battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->whereIn('status', [
                SixHeroBattleLog::STATUS_STARTED,
                SixHeroBattleLog::STATUS_RESOLVED,
            ]);
    }

    private function pendingCountForSeason(SixHeroSeason $season): int
    {
        return $this->pendingBattleQuery()
            ->where('season_id', $season->id)
            ->where('started_at', '<', $season->ends_at)
            ->count();
    }

    private function previousSeason(SixHeroSeason $season): ?SixHeroSeason
    {
        $previousKey = CarbonImmutable::instance($season->starts_at)
            ->setTimezone($this->timezone())
            ->subMonthNoOverflow()
            ->format('Y-m');

        return SixHeroSeason::query()
            ->where('season_key', $previousKey)
            ->first();
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function dependentCheckDefinitions(): array
    {
        return [
            ['current_season', '現在Season'],
            ['ranking_initialization', 'ランキング初期化'],
            ['ranking_invariants', '6部屋Ranking整合性'],
            ['daily_usage', '公式戦挑戦回数'],
            ['champions', '確定Champion'],
            ['historical_identity', '英雄identity'],
            ['pending_battles', '未完了公式戦'],
            ['failed_battles', '失敗公式戦'],
            ['previous_season', '直前月Season'],
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function seasonMetadata(SixHeroSeason $season): array
    {
        return [
            'season_key' => (string) $season->season_key,
            'starts_at' => $this->localTimestamp($season->starts_at),
            'ends_at' => $this->localTimestamp($season->ends_at),
            'finalized' => $season->finalized_at !== null,
            'ranking_initialized' => $season->ranking_initialized_at !== null,
        ];
    }

    private function safeItem(
        string $key,
        string $label,
        Closure $check,
    ): SixHeroHealthCheckItem {
        try {
            return $check();
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail(
                $key,
                $label,
                '診断処理中に異常を検知しました。アプリケーションログを確認してください。',
            );
        }
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function pass(
        string $key,
        string $label,
        string $message,
        array $metadata = [],
    ): SixHeroHealthCheckItem {
        return new SixHeroHealthCheckItem(
            $key,
            $label,
            SixHeroHealthCheckItem::STATUS_PASS,
            $message,
            $metadata,
        );
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function warning(
        string $key,
        string $label,
        string $message,
        array $metadata = [],
    ): SixHeroHealthCheckItem {
        return new SixHeroHealthCheckItem(
            $key,
            $label,
            SixHeroHealthCheckItem::STATUS_WARNING,
            $message,
            $metadata,
        );
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function fail(
        string $key,
        string $label,
        string $message,
        array $metadata = [],
    ): SixHeroHealthCheckItem {
        return new SixHeroHealthCheckItem(
            $key,
            $label,
            SixHeroHealthCheckItem::STATUS_FAIL,
            $message,
            $metadata,
        );
    }

    private function unavailableItem(
        string $key,
        string $label,
    ): SixHeroHealthCheckItem {
        return $this->fail(
            $key,
            $label,
            '必須DB構造が不足しているため判定できません。',
        );
    }

    /** @return array<int, string> */
    private function roomValues(): array
    {
        return array_map(
            static fn (SixHeroRoomKey $room): string => $room->value,
            SixHeroRoomKey::cases(),
        );
    }

    /** @return array<int, array<string, bool|int|string|null>> */
    private function emptyRoomSummaries(string $status, string $label): array
    {
        return array_map(
            static fn (SixHeroRoomKey $room): array => [
                'room_key' => $room->value,
                'room_label' => $room->label(),
                'registered_count' => 0,
                'official_battle_count' => 0,
                'leader_id' => null,
                'leader_name' => null,
                'integrity_status' => $status,
                'integrity_label' => $label,
            ],
            SixHeroRoomKey::cases(),
        );
    }

    /** @return array{usage_date:string,player_count:int,attempt_count:int,limit_reached_count:int,limit:int} */
    private function emptyDailyUsageSummary(CarbonImmutable $checkedAt): array
    {
        return [
            'usage_date' => $checkedAt->toDateString(),
            'player_count' => 0,
            'attempt_count' => 0,
            'limit_reached_count' => 0,
            'limit' => SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT,
        ];
    }

    private function staleBattleMinutes(): int
    {
        return max(1, (int) config(
            'six_heroes.operations.stale_battle_minutes',
            30,
        ));
    }

    private function failedBattleWindowHours(): int
    {
        return max(1, (int) config(
            'six_heroes.operations.failed_battle_window_hours',
            24,
        ));
    }

    private function battleListLimit(): int
    {
        return min(100, max(1, (int) config(
            'six_heroes.operations.battle_list_limit',
            20,
        )));
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}秒";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'分'.($seconds % 60).'秒';
        }

        return intdiv($seconds, 3600).'時間'.intdiv($seconds % 3600, 60).'分';
    }

    private function localTimestamp(CarbonInterface|string $value): string
    {
        return CarbonImmutable::parse($value)
            ->setTimezone($this->timezone())
            ->format('Y-m-d H:i:s');
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
