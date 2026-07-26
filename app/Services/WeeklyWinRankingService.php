<?php

namespace App\Services;

use App\Models\Character;
use App\Models\KisekiTransaction;
use App\Models\WeeklyWinRankingRecord;
use App\Models\WeeklyWinRankingSeason;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class WeeklyWinRankingService
{
    public const NOTIFICATION_TYPE = 'weekly_win_ranking_reward';

    public const TRANSACTION_TYPE = 'weekly_win_ranking_reward';

    public const TRANSACTION_SOURCE_TYPE = 'weekly_win_ranking_record';

    private const TESTER_EMAIL_PATTERN = 'tester_%@valzeria.local';

    public function __construct(
        private readonly CharacterNotificationService $notificationService
    ) {}

    /**
     * @return array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * }
     */
    public function currentPeriod(?Carbon $at = null): array
    {
        $timezone = $this->timezone();
        $now = ($at ?? Carbon::now($timezone))->copy()->setTimezone($timezone);
        $start = $now
            ->copy()
            ->subHours($this->periodStartHour())
            ->startOfWeek(Carbon::MONDAY)
            ->startOfDay();

        return $this->periodFromStart($start);
    }

    /**
     * @return array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * }
     */
    public function previousCompletedPeriod(?Carbon $at = null): array
    {
        $current = $this->currentPeriod($at);

        return $this->periodFromStart($current['start']->copy()->subWeek());
    }

    /**
     * @return array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * }
     */
    public function periodForWeekStart(string $weekStart): array
    {
        $weekStart = trim($weekStart);
        $start = Carbon::parse($weekStart, $this->timezone())->startOfDay();

        if ($start->format('Y-m-d') !== $weekStart || $start->dayOfWeekIso !== 1) {
            throw new \InvalidArgumentException('週開始日は月曜日を YYYY-MM-DD 形式で指定してください。');
        }

        return $this->periodFromStart($start);
    }

    /**
     * @return array{key: string, start_at: string, end_at: string, label: string}
     */
    public function currentPeriodSummary(): array
    {
        return $this->periodSummary($this->currentPeriod());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rewardTiers(): array
    {
        return collect(config('weekly_win_ranking.reward_tiers', []))
            ->map(function (array $tier): array {
                return [
                    'key' => (string) ($tier['key'] ?? ''),
                    'label' => (string) ($tier['label'] ?? ''),
                    'min_rank' => max(1, (int) ($tier['min_rank'] ?? 1)),
                    'max_rank' => isset($tier['max_rank']) ? max(1, (int) $tier['max_rank']) : null,
                    'minimum_wins' => max(1, (int) ($tier['minimum_wins'] ?? 1)),
                    'free_kiseki' => max(0, (int) ($tier['free_kiseki'] ?? 0)),
                    'badge_key' => filled($tier['badge_key'] ?? null) ? (string) $tier['badge_key'] : null,
                    'badge_label' => filled($tier['badge_label'] ?? null) ? (string) $tier['badge_label'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   key: ?string,
     *   label: ?string,
     *   free_kiseki: int,
     *   badge_key: ?string,
     *   badge_label: ?string
     * }
     */
    public function rewardFor(int $rank, int $wins): array
    {
        foreach ($this->rewardTiers() as $tier) {
            if ($rank < $tier['min_rank']) {
                continue;
            }
            if ($tier['max_rank'] !== null && $rank > $tier['max_rank']) {
                continue;
            }
            if ($wins < $tier['minimum_wins']) {
                continue;
            }

            return [
                'key' => $tier['key'],
                'label' => $tier['label'],
                'free_kiseki' => $tier['free_kiseki'],
                'badge_key' => $tier['badge_key'],
                'badge_label' => $tier['badge_label'],
            ];
        }

        return [
            'key' => null,
            'label' => null,
            'free_kiseki' => 0,
            'badge_key' => null,
            'badge_label' => null,
        ];
    }

    /**
     * 現在週の同率順位を含む50位までを返す。
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function currentRows(): Collection
    {
        $limit = max(1, (int) config('weekly_win_ranking.ranking_limit', 50));
        $period = $this->currentPeriod();

        return $this->cachedRankedRowsForPeriod($period)
            ->filter(fn (array $row): bool => $row['rank'] <= $limit)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function currentStatusFor(Character $character): array
    {
        $period = $this->currentPeriod();
        $rows = $this->cachedRankedRowsForPeriod($period);

        return $this->statusFromRows($character, $period, $rows);
    }

    /**
     * 全タブ共通ウィジェット向けに、上位行と本人進捗を同じ集計結果から返す。
     *
     * @return array{
     *   period: array{key: string, start_at: string, end_at: string, label: string},
     *   rows: Collection<int, array<string, mixed>>,
     *   status: array<string, mixed>|null
     * }
     */
    public function currentWidgetData(?Character $character, int $displayLimit = 5): array
    {
        $period = $this->currentPeriod();
        $rows = $this->cachedRankedRowsForPeriod($period);
        $rankingLimit = max(1, (int) config('weekly_win_ranking.ranking_limit', 50));

        return [
            'period' => $this->periodSummary($period),
            'rows' => $rows
                ->filter(fn (array $row): bool => $row['rank'] <= $rankingLimit)
                ->take(max(1, $displayLimit))
                ->values(),
            'status' => $character
                ? $this->statusFromRows($character, $period, $rows)
                : null,
        ];
    }

    /**
     * @param array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * } $period
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function statusFromRows(Character $character, array $period, Collection $rows): array
    {
        $character->loadMissing('user');
        $accountEligible = $this->isAccountEligible($character);
        $excluded = $this->isExcludedCharacter($character);
        $minimumWins = max(1, (int) config('weekly_win_ranking.minimum_participation_wins', 10));

        if ($excluded) {
            return [
                'period' => $this->periodSummary($period),
                'excluded' => true,
                'is_account_eligible' => false,
                'wins' => 0,
                'rank' => null,
                'reward_free_kiseki' => 0,
                'potential_reward_free_kiseki' => 0,
                'badge_label' => null,
                'participation_remaining_wins' => $minimumWins,
            ];
        }

        $row = $rows->firstWhere('character_id', (int) $character->id);

        if (! $row) {
            return [
                'period' => $this->periodSummary($period),
                'excluded' => false,
                'is_account_eligible' => $accountEligible,
                'wins' => 0,
                'rank' => null,
                'reward_free_kiseki' => 0,
                'potential_reward_free_kiseki' => 0,
                'badge_label' => null,
                'participation_remaining_wins' => $minimumWins,
            ];
        }

        return [
            'period' => $this->periodSummary($period),
            'excluded' => false,
            'is_account_eligible' => $accountEligible,
            'wins' => (int) $row['score'],
            'rank' => (int) $row['rank'],
            'reward_free_kiseki' => (int) $row['reward_free_kiseki'],
            'potential_reward_free_kiseki' => (int) $row['potential_reward_free_kiseki'],
            'badge_label' => $row['badge_label'],
            'participation_remaining_wins' => max(0, $minimumWins - (int) $row['score']),
        ];
    }

    /**
     * 前週上位10位の名誉表示を、確定後から次の月曜9:00まで返す。
     *
     * @return array<string, mixed>|null
     */
    public function latestBadgeFor(Character $character): ?array
    {
        if (! Schema::hasTable('weekly_win_ranking_seasons')
            || ! Schema::hasTable('weekly_win_ranking_records')) {
            return null;
        }

        $current = $this->currentPeriod();
        $record = WeeklyWinRankingRecord::query()
            ->with('season')
            ->where('character_id', $character->id)
            ->whereNotNull('badge_key')
            ->whereHas('season', function ($query) use ($current): void {
                $query
                    ->whereNotNull('finalized_at')
                    ->where('week_ended_at', $current['start_at']);
            })
            ->orderByDesc('id')
            ->first();

        if (! $record || ! $record->season || blank($record->badge_label)) {
            return null;
        }

        return [
            'key' => (string) $record->badge_key,
            'label' => (string) $record->badge_label,
            'rank' => (int) $record->rank,
            'wins' => (int) $record->wins,
            'period_label' => $this->periodSummary([
                'key' => (string) $record->season->season_key,
                'start' => $record->season->week_started_at->copy()->setTimezone($this->timezone()),
                'end' => $record->season->week_ended_at->copy()->setTimezone($this->timezone()),
                'start_at' => $record->season->week_started_at->format('Y-m-d H:i:s'),
                'end_at' => $record->season->week_ended_at->format('Y-m-d H:i:s'),
                'label' => '',
            ])['label'],
            'valid_until' => $current['end']->copy()->subSecond()->format('n月j日 H:i'),
        ];
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function finalizePreviousWeek(?Carbon $at = null): array
    {
        return $this->finalizePeriod($this->previousCompletedPeriod($at));
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function previewPreviousWeek(?Carbon $at = null): array
    {
        return $this->previewPeriod($this->previousCompletedPeriod($at));
    }

    /**
     * @param array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * } $period
     * @return array<string, int|string|bool>
     */
    public function previewPeriod(array $period): array
    {
        $rows = $this->rankedRowsForPeriod($period);

        return [
            'season_key' => $period['key'],
            'participant_count' => $rows->count(),
            'rewarded_count' => $rows
                ->filter(fn (array $row): bool => $row['is_reward_eligible']
                    && $row['reward_free_kiseki'] > 0)
                ->count(),
            'total_free_kiseki' => (int) $rows->sum('reward_free_kiseki'),
            'already_finalized' => Schema::hasTable('weekly_win_ranking_seasons')
                && WeeklyWinRankingSeason::query()
                    ->where('season_key', $period['key'])
                    ->whereNotNull('finalized_at')
                    ->exists(),
            'preview' => true,
        ];
    }

    /**
     * @param array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * } $period
     * @return array<string, int|string|bool>
     */
    public function finalizePeriod(array $period): array
    {
        if (! $this->schemaReady()) {
            throw new LogicException('週間勝利数番付のmigrationが未適用、または輝石台帳の準備ができていません。');
        }

        $now = Carbon::now($this->timezone());
        if ($period['end']->greaterThan($now)) {
            throw new LogicException('終了していない週の番付は確定できません。');
        }

        $result = DB::transaction(function () use ($period, $now): array {
            DB::table('weekly_win_ranking_seasons')->insertOrIgnore([
                'season_key' => $period['key'],
                'week_started_at' => $period['start_at'],
                'week_ended_at' => $period['end_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $season = WeeklyWinRankingSeason::query()
                ->where('season_key', $period['key'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($season->finalized_at !== null) {
                return $this->seasonResult($season, true);
            }

            $rows = $this->rankedRowsForPeriod($period);
            $rewardedCount = 0;
            $totalFreeKiseki = 0;

            foreach ($rows as $row) {
                $record = WeeklyWinRankingRecord::query()->updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'character_id' => $row['character_id'],
                    ],
                    [
                        'wins' => $row['score'],
                        'rank' => $row['rank'],
                        'reward_free_kiseki' => $row['reward_free_kiseki'],
                        'badge_key' => $row['badge_key'],
                        'badge_label' => $row['badge_label'],
                        'is_reward_eligible' => $row['is_reward_eligible'],
                    ]
                );

                if (! $row['is_reward_eligible'] || $row['reward_free_kiseki'] <= 0) {
                    continue;
                }

                $grantedAmount = $this->grantReward($season, $record);
                if ($grantedAmount > 0) {
                    $rewardedCount++;
                    $totalFreeKiseki += $grantedAmount;
                }
            }

            $season->forceFill([
                'participant_count' => $rows->count(),
                'rewarded_count' => $rewardedCount,
                'total_free_kiseki' => $totalFreeKiseki,
                'finalized_at' => $now,
            ])->save();

            return $this->seasonResult($season->fresh(), false);
        }, 3);

        Cache::forget(TownRankingService::cacheKey());

        return $result;
    }

    public function schemaReady(): bool
    {
        foreach ([
            'weekly_win_ranking_seasons',
            'weekly_win_ranking_records',
            'battle_logs',
            'characters',
            'users',
            'kiseki_transactions',
            'character_notifications',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        foreach (['kiseki', 'paid_kiseki', 'free_kiseki'] as $column) {
            if (! Schema::hasColumn('characters', $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * } $period
     * @return Collection<int, array<string, mixed>>
     */
    private function rankedRowsForPeriod(array $period): Collection
    {
        if (! Schema::hasTable('battle_logs')
            || ! Schema::hasTable('characters')
            || ! Schema::hasTable('users')) {
            return collect();
        }

        $rawRows = DB::table('battle_logs')
            ->join('characters', 'characters.id', '=', 'battle_logs.character_id')
            ->join('users as ranking_users', 'ranking_users.id', '=', 'characters.user_id')
            // 通常探索系は win、亜域の既存ログは victory を保存している。
            ->whereIn('battle_logs.result', ['win', 'victory'])
            // 探索イベントと時間切れも既存処理では win 保存されるため、EXPが発生した実戦だけを数える。
            ->where('battle_logs.exp_gained', '>', 0)
            ->whereIn('battle_logs.battle_type', [
                'normal',
                'boss',
                'sub_area',
                'exploration_map',
            ])
            ->where('battle_logs.created_at', '>=', $period['start_at'])
            ->where('battle_logs.created_at', '<', $period['end_at'])
            ->where(function ($query): void {
                $query->whereNull('ranking_users.role')
                    ->orWhere('ranking_users.role', '!=', 'admin');
            })
            ->where(function ($query): void {
                $query->whereNull('ranking_users.email')
                    ->orWhere('ranking_users.email', 'not like', self::TESTER_EMAIL_PATTERN);
            })
            ->groupBy([
                'characters.id',
                'characters.name',
                'characters.icon_path',
                'characters.level',
                'characters.profile_comment',
            ])
            ->select([
                'characters.id',
                'characters.name',
                'characters.icon_path',
                'characters.level',
                'characters.profile_comment',
                DB::raw('COUNT(battle_logs.id) as score'),
                DB::raw(
                    "MAX(CASE WHEN COALESCE(ranking_users.google_id, '') <> ''"
                    ." OR COALESCE(ranking_users.password, '') <> '' THEN 1 ELSE 0 END)"
                    .' as is_account_eligible'
                ),
            ])
            ->orderByDesc('score')
            ->orderBy('characters.id')
            ->get();

        $position = 0;
        $rank = 0;
        $previousScore = null;

        return $rawRows->map(function (object $row) use (&$position, &$rank, &$previousScore): array {
            $position++;
            $score = (int) $row->score;
            if ($previousScore === null || $score !== $previousScore) {
                $rank = $position;
                $previousScore = $score;
            }

            $reward = $this->rewardFor($rank, $score);
            $accountEligible = (bool) $row->is_account_eligible;
            $potentialReward = (int) $reward['free_kiseki'];
            $rewardEligible = $accountEligible && $potentialReward > 0;

            return [
                'character_id' => (int) $row->id,
                'name' => (string) $row->name,
                'icon_path' => CharacterIconCatalog::normalize($row->icon_path ?? null),
                'image_type' => 'character',
                'level' => (int) ($row->level ?? 1),
                'profile_comment' => trim((string) ($row->profile_comment ?? '')) ?: 'よろしくお願いします',
                'score' => $score,
                'rank' => $rank,
                'detail' => '週間勝利 '.number_format($score),
                'is_account_eligible' => $accountEligible,
                'is_reward_eligible' => $rewardEligible,
                'potential_reward_free_kiseki' => $potentialReward,
                'reward_free_kiseki' => $rewardEligible ? $potentialReward : 0,
                'reward_tier_label' => $rewardEligible ? $reward['label'] : null,
                'badge_key' => $rewardEligible ? $reward['badge_key'] : null,
                'badge_label' => $rewardEligible ? $reward['badge_label'] : null,
            ];
        });
    }

    /**
     * @param array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * } $period
     * @return Collection<int, array<string, mixed>>
     */
    private function cachedRankedRowsForPeriod(array $period): Collection
    {
        $seconds = max(1, (int) config('weekly_win_ranking.live_cache_seconds', 30));

        return Cache::remember(
            "weekly_win_ranking_live_rows_v1:{$period['key']}",
            now()->addSeconds($seconds),
            fn (): Collection => $this->rankedRowsForPeriod($period)
        );
    }

    private function grantReward(
        WeeklyWinRankingSeason $season,
        WeeklyWinRankingRecord $record
    ): int {
        $amount = max(0, (int) $record->reward_free_kiseki);
        if ($amount <= 0) {
            return 0;
        }

        $character = Character::query()
            ->with('user')
            ->whereKey($record->character_id)
            ->lockForUpdate()
            ->first();

        if (! $character
            || $this->isExcludedCharacter($character)
            || ! $this->isAccountEligible($character)) {
            $record->forceFill([
                'reward_free_kiseki' => 0,
                'badge_key' => null,
                'badge_label' => null,
                'is_reward_eligible' => false,
                'rewarded_at' => null,
            ])->save();

            return 0;
        }

        $existingTransaction = KisekiTransaction::query()
            ->where('transaction_type', self::TRANSACTION_TYPE)
            ->where('source_type', self::TRANSACTION_SOURCE_TYPE)
            ->where('source_id', $record->id)
            ->lockForUpdate()
            ->first();

        if ($existingTransaction) {
            $record->forceFill(['rewarded_at' => $record->rewarded_at ?? now()])->save();

            return $amount;
        }

        $freeTotal = (int) ($character->free_kiseki ?? 0) + $amount;
        $paidTotal = (int) ($character->paid_kiseki ?? 0);
        $character->forceFill([
            'free_kiseki' => $freeTotal,
            'kiseki' => $paidTotal + $freeTotal,
        ])->save();

        $transaction = KisekiTransaction::query()->create([
            'character_id' => $character->id,
            'kiseki_type' => 'free',
            'amount' => $amount,
            'transaction_type' => self::TRANSACTION_TYPE,
            'source_type' => self::TRANSACTION_SOURCE_TYPE,
            'source_id' => $record->id,
            'description' => sprintf(
                '週間勝利数番付 %s %d位報酬',
                $season->season_key,
                $record->rank
            ),
        ]);

        $periodLabel = $this->periodSummary([
            'key' => (string) $season->season_key,
            'start' => $season->week_started_at->copy()->setTimezone($this->timezone()),
            'end' => $season->week_ended_at->copy()->setTimezone($this->timezone()),
            'start_at' => $season->week_started_at->format('Y-m-d H:i:s'),
            'end_at' => $season->week_ended_at->format('Y-m-d H:i:s'),
            'label' => '',
        ])['label'];
        $badgeMessage = filled($record->badge_label)
            ? "名誉「{$record->badge_label}」が今週の冒険者カードに表示されます。"
            : '';

        $this->notificationService->create(
            $character,
            'system',
            self::NOTIFICATION_TYPE,
            '週間勝利数番付の報酬',
            "{$periodLabel}の番付で{$record->rank}位となり、無償輝石{$amount}個を獲得しました。{$badgeMessage}",
            '番付を見る',
            route('ranking.index', ['board' => 'weekly_wins']),
            [
                'season_key' => $season->season_key,
                'rank' => (int) $record->rank,
                'wins' => (int) $record->wins,
                'free_kiseki' => $amount,
                'badge_key' => $record->badge_key,
                'transaction_id' => $transaction->id,
            ],
            10
        );

        $record->forceFill(['rewarded_at' => now()])->save();

        return $amount;
    }

    private function isAccountEligible(Character $character): bool
    {
        $character->loadMissing('user');
        $user = $character->user;

        return $user !== null
            && (filled($user->google_id) || filled($user->password));
    }

    private function isExcludedCharacter(Character $character): bool
    {
        $character->loadMissing('user');
        $user = $character->user;
        if (! $user) {
            return true;
        }

        return $user->role === 'admin'
            || $this->isTesterEmail((string) $user->email);
    }

    private function isTesterEmail(string $email): bool
    {
        return (bool) preg_match('/^tester_.*@valzeria\.local$/i', $email);
    }

    /**
     * @return array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * }
     */
    private function periodFromStart(Carbon $start): array
    {
        $start = $start
            ->copy()
            ->setTimezone($this->timezone())
            ->startOfDay()
            ->addHours($this->periodStartHour());
        $end = $start->copy()->addWeek();

        return [
            'key' => $start->format('Y-m-d'),
            'start' => $start,
            'end' => $end,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
            'label' => $this->formatPeriodLabel($start, $end),
        ];
    }

    /**
     * @param array{
     *   key: string,
     *   start: Carbon,
     *   end: Carbon,
     *   start_at: string,
     *   end_at: string,
     *   label: string
     * } $period
     * @return array{key: string, start_at: string, end_at: string, label: string}
     */
    private function periodSummary(array $period): array
    {
        return [
            'key' => $period['key'],
            'start_at' => $period['start_at'],
            'end_at' => $period['end_at'],
            'label' => $period['label'] !== ''
                ? $period['label']
                : $this->formatPeriodLabel($period['start'], $period['end']),
        ];
    }

    private function formatPeriodLabel(Carbon $start, Carbon $end): string
    {
        return $start->format('Y年n月j日 G:i')
            .'〜'
            .$end->copy()->subMinute()->format('n月j日 G:i');
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function seasonResult(WeeklyWinRankingSeason $season, bool $alreadyFinalized): array
    {
        return [
            'season_key' => (string) $season->season_key,
            'participant_count' => (int) $season->participant_count,
            'rewarded_count' => (int) $season->rewarded_count,
            'total_free_kiseki' => (int) $season->total_free_kiseki,
            'already_finalized' => $alreadyFinalized,
        ];
    }

    private function timezone(): string
    {
        return (string) config('weekly_win_ranking.timezone', 'Asia/Tokyo');
    }

    private function periodStartHour(): int
    {
        return min(23, max(0, (int) config('weekly_win_ranking.period_start_hour', 9)));
    }
}
