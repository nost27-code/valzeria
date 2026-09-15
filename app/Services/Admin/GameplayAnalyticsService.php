<?php

namespace App\Services\Admin;

use App\Models\GameplayMetric;
use App\Models\JobClass;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GameplayAnalyticsService
{
    private const WINDOWS = ['7', '30', '90', 'all'];

    private const LEVEL_BANDS = ['all', '1-49', '50-99', '100-149', '150-199', '200-255'];

    private const JOB_ART_CONTEXT_LABELS = [
        'normal' => '通常探索',
        'region_depth' => '追加ダンジョン',
        'boss' => 'ボス戦',
        'sub_area' => '共有サブエリア',
        'map' => '探索の地図',
        'tower' => '星樹の塔',
        'hero_trial' => '英雄試練',
        'pvp' => 'プレイヤーPvP（六英雄戦を含む）',
        'champ' => 'チャンプ戦',
        'arena_npc' => 'NPCランク戦',
    ];

    private const EXPLORATION_CONTEXT_LABELS = [
        'normal' => '通常探索',
        'region_depth' => '追加ダンジョン',
        'sub_area' => '共有サブエリア',
        'map' => '探索の地図',
    ];

    /** @param array<string,mixed>|string $filters @return array<string,mixed> */
    public function analyze(array|string $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        if (! $this->tablesReady()) {
            return $this->emptyAnalysis($filters);
        }

        $jobArtMeasurementStartedAt = DB::table('gameplay_job_art_rollups')->min('bucket_started_at');
        $explorationMeasurementStartedAt = GameplayMetric::query()
            ->where('metric_type', GameplayMetric::TYPE_EXPLORATION_REQUEST)
            ->min('created_at');

        return [
            'ready' => true,
            'filters' => $filters,
            'window' => $filters['activity_window'],
            'generatedAt' => now(),
            'measurementStartedAt' => $jobArtMeasurementStartedAt ?? $explorationMeasurementStartedAt,
            'jobArtMeasurementStartedAt' => $jobArtMeasurementStartedAt,
            'explorationMeasurementStartedAt' => $explorationMeasurementStartedAt,
            'jobOptions' => JobClass::query()->orderBy('id')->get(['id', 'name']),
            'contextOptions' => self::JOB_ART_CONTEXT_LABELS,
            'levelBandOptions' => self::LEVEL_BANDS,
            'jobArt' => $this->jobArtAnalysis($filters),
            'exploration' => $this->explorationAnalysis($filters['activity_window']),
        ];
    }

    /** @param array<string,mixed> $filters */
    private function jobArtAnalysis(array $filters): array
    {
        $base = $this->rollupQuery('gameplay_job_art_rollups', $filters);
        $cardsRow = (clone $base)->selectRaw(implode(', ', [
            'COALESCE(SUM(battles), 0) AS battles',
            'COALESCE(SUM(art_battles), 0) AS art_battles',
            'COALESCE(SUM(wins), 0) AS wins',
            'COALESCE(SUM(art_wins), 0) AS art_wins',
            'COALESCE(SUM(turns), 0) AS turns',
            'COALESCE(SUM(activations), 0) AS activations',
            'COALESCE(SUM(hp_recovered), 0) AS hp_recovered',
            'COALESCE(SUM(sp_recovered), 0) AS sp_recovered',
        ]))->first();

        $battles = (int) ($cardsRow->battles ?? 0);
        $artBattles = (int) ($cardsRow->art_battles ?? 0);
        $wins = (int) ($cardsRow->wins ?? 0);
        $artWins = (int) ($cardsRow->art_wins ?? 0);
        $withoutArtBattles = max(0, $battles - $artBattles);
        $withoutArtWins = max(0, $wins - $artWins);

        $contextRows = (clone $base)
            ->select('context')
            ->selectRaw(implode(', ', [
                'SUM(battles) AS battles',
                'SUM(art_battles) AS art_battles',
                'SUM(wins) AS wins',
                'SUM(turns) AS turns',
                'SUM(activations) AS activations',
            ]))
            ->groupBy('context')
            ->orderByDesc('battles')
            ->get()
            ->map(function (object $row): array {
                $battles = (int) $row->battles;
                $artBattles = (int) $row->art_battles;

                return [
                    'context' => (string) $row->context,
                    'label' => self::JOB_ART_CONTEXT_LABELS[(string) $row->context] ?? (string) $row->context,
                    'battles' => $battles,
                    'art_battles' => $artBattles,
                    'activations' => (int) $row->activations,
                    'activation_battle_rate' => $this->rate($artBattles, $battles),
                    'win_rate' => $this->rate((int) $row->wins, $battles),
                    'average_turns' => $this->average((int) $row->turns, $battles),
                ];
            })->all();

        $loadoutRows = (clone $base)
            ->select('loadout_signature')
            ->selectRaw(implode(', ', [
                'MAX(loadout) AS loadout',
                'SUM(battles) AS battles',
                'SUM(art_battles) AS art_battles',
                'SUM(wins) AS wins',
                'SUM(turns) AS turns',
                'SUM(activations) AS activations',
                'SUM(hp_recovered) AS hp_recovered',
                'SUM(sp_recovered) AS sp_recovered',
            ]))
            ->groupBy('loadout_signature')
            ->orderByDesc('battles')
            ->limit(20)
            ->get()
            ->map(function (object $row): array {
                $battles = (int) $row->battles;
                $loadout = $this->decodeLoadout($row->loadout ?? '[]');

                return [
                    'signature' => (string) $row->loadout_signature,
                    'loadout' => $loadout,
                    'label' => $loadout === []
                        ? '戦技未設定'
                        : collect($loadout)->pluck('name')->implode(' → '),
                    'battles' => $battles,
                    'art_battles' => (int) $row->art_battles,
                    'activations' => (int) $row->activations,
                    'activation_battle_rate' => $this->rate((int) $row->art_battles, $battles),
                    'win_rate' => $this->rate((int) $row->wins, $battles),
                    'average_turns' => $this->average((int) $row->turns, $battles),
                    'hp_recovered_per_battle' => $this->average((int) $row->hp_recovered, $battles),
                    'sp_recovered_per_battle' => $this->average((int) $row->sp_recovered, $battles),
                ];
            })->all();

        $skillBase = $this->rollupQuery('gameplay_job_art_skill_rollups', $filters);
        $skillRows = (clone $skillBase)
            ->select('skill_id')
            ->selectRaw(implode(', ', [
                'MAX(skill_name) AS skill_name',
                'SUM(battles) AS battles',
                'SUM(wins) AS wins',
                'SUM(turns) AS turns',
                'SUM(activations) AS activations',
                'SUM(hits) AS hits',
                'SUM(misses) AS misses',
                'SUM(evades) AS evades',
                'SUM(no_resolution) AS no_resolution',
                'SUM(vital_hits) AS vital_hits',
                'SUM(hp_recovered) AS hp_recovered',
                'SUM(sp_recovered) AS sp_recovered',
            ]))
            ->groupBy('skill_id')
            ->orderByDesc('activations')
            ->orderByDesc('battles')
            ->limit(30)
            ->get();
        $masterNames = Skill::query()->whereKey($skillRows->pluck('skill_id'))->pluck('name', 'id');
        $skillRows = $skillRows->map(function (object $row) use ($masterNames): array {
            $battles = (int) $row->battles;
            $hits = (int) $row->hits;
            $resolved = $hits + (int) $row->misses + (int) $row->evades;

            return [
                'skill_id' => (int) $row->skill_id,
                'name' => (string) ($masterNames[(int) $row->skill_id] ?? $row->skill_name),
                'battles' => $battles,
                'activations' => (int) $row->activations,
                'hits' => $hits,
                'misses' => (int) $row->misses,
                'evades' => (int) $row->evades,
                'no_resolution' => (int) $row->no_resolution,
                'vital_hits' => (int) $row->vital_hits,
                'hit_rate' => $resolved > 0 ? $this->rate($hits, $resolved) : null,
                'vital_hit_rate' => $hits > 0 ? $this->rate((int) $row->vital_hits, $hits) : null,
                'win_rate' => $this->rate((int) $row->wins, $battles),
                'average_turns' => $this->average((int) $row->turns, $battles),
                'hp_recovered_per_battle' => $this->average((int) $row->hp_recovered, $battles),
                'sp_recovered_per_battle' => $this->average((int) $row->sp_recovered, $battles),
            ];
        })->all();

        return [
            'cards' => [
                'battles' => $battles,
                'art_battles' => $artBattles,
                'activation_battle_rate' => $this->rate($artBattles, $battles),
                'activations' => (int) ($cardsRow->activations ?? 0),
                'with_art_win_rate' => $artBattles > 0 ? $this->rate($artWins, $artBattles) : null,
                'without_art_win_rate' => $withoutArtBattles > 0 ? $this->rate($withoutArtWins, $withoutArtBattles) : null,
                'average_turns' => $this->average((int) ($cardsRow->turns ?? 0), $battles),
                'hp_recovered_per_battle' => $this->average((int) ($cardsRow->hp_recovered ?? 0), $battles),
                'sp_recovered_per_battle' => $this->average((int) ($cardsRow->sp_recovered ?? 0), $battles),
            ],
            'skillRows' => $skillRows,
            'contextRows' => $contextRows,
            'loadoutRows' => $loadoutRows,
        ];
    }

    /** @param array<string,mixed> $filters */
    private function rollupQuery(string $table, array $filters): Builder
    {
        $query = DB::table($table);
        if ($filters['activity_window'] !== 'all') {
            $query->where('bucket_started_at', '>=', now()->subDays((int) $filters['activity_window'])->startOfHour());
        }
        if ($filters['battle_context'] !== 'all') {
            $query->where('context', $filters['battle_context']);
        }
        if ($filters['current_job_id'] > 0) {
            $query->where('current_job_id', $filters['current_job_id']);
        }
        if ($filters['level_band'] !== 'all') {
            $query->where('level_band', $filters['level_band']);
        }

        return $query;
    }

    private function explorationAnalysis(string $window): array
    {
        $base = $this->explorationQuery($window);
        $requested = $this->jsonInteger('$.requested_count');
        $mode = "CASE WHEN {$requested} = 1 THEN 'single' ELSE 'batch' END";
        $aggregates = $this->explorationAggregateSql();

        $modeRows = (clone $base)
            ->selectRaw("{$mode} AS aggregate_key, {$aggregates}")
            ->groupByRaw($mode)
            ->get()
            ->map(fn (object $row): array => $this->finishExplorationGroup(
                $this->explorationGroupFromRow($row),
                $row->aggregate_key === 'single' ? '1回探索' : 'まとめて探索',
            ))
            ->sortBy(fn (array $row): int => $row['key'] === 'single' ? 0 : 1)
            ->values()
            ->all();

        $contextRows = (clone $base)
            ->selectRaw("context AS aggregate_key, {$aggregates}")
            ->groupBy('context')
            ->get()
            ->map(fn (object $row): array => $this->finishExplorationGroup(
                $this->explorationGroupFromRow($row),
                self::EXPLORATION_CONTEXT_LABELS[(string) $row->aggregate_key] ?? (string) $row->aggregate_key,
            ))
            ->sortByDesc('requests')
            ->values()
            ->all();

        $stopReason = $this->jsonScalar('$.stop_reason');
        $stopRows = (clone $base)
            ->whereRaw("{$stopReason} IS NOT NULL AND TRIM({$stopReason}) <> ''")
            ->selectRaw("{$stopReason} AS reason, COUNT(*) AS aggregate_count")
            ->groupByRaw($stopReason)
            ->orderByDesc('aggregate_count')
            ->get()
            ->map(fn (object $row): array => [
                'reason' => (string) $row->reason,
                'label' => $this->stopReasonLabel((string) $row->reason),
                'count' => (int) $row->aggregate_count,
            ])->all();

        $requests = collect($modeRows)->sum('requests');
        $requestedRuns = collect($modeRows)->sum('requested');
        $completedRuns = collect($modeRows)->sum('completed');

        return [
            'cards' => [
                'requests' => $requests,
                'requested_runs' => $requestedRuns,
                'completed_runs' => $completedRuns,
                'completion_rate' => $this->rate($completedRuns, $requestedRuns),
                'single_requests' => (int) (collect($modeRows)->firstWhere('key', 'single')['requests'] ?? 0),
                'batch_requests' => (int) (collect($modeRows)->firstWhere('key', 'batch')['requests'] ?? 0),
            ],
            'modeRows' => $modeRows,
            'contextRows' => $contextRows,
            'stopRows' => $stopRows,
        ];
    }

    private function explorationQuery(string $window): EloquentBuilder
    {
        $query = GameplayMetric::query()
            ->where('metric_type', GameplayMetric::TYPE_EXPLORATION_REQUEST)
            ->whereHas('character.user', function (EloquentBuilder $query): void {
                $query->where(function (EloquentBuilder $query): void {
                    $query->whereNull('role')->orWhere('role', '!=', 'admin');
                })->where(function (EloquentBuilder $query): void {
                    $query->whereRaw('LOWER(SUBSTR(email, 1, 7)) <> ?', ['tester_'])
                        ->orWhereRaw('LOWER(SUBSTR(email, -15)) <> ?', ['@valzeria.local']);
                });
            });

        if ($window !== 'all') {
            $query->where('created_at', '>=', now()->subDays((int) $window));
        }

        return $query;
    }

    private function explorationAggregateSql(): string
    {
        $requested = $this->jsonInteger('$.requested_count');
        $completed = $this->jsonInteger('$.completed_count');
        $dangerBefore = $this->jsonNullableInteger('$.danger_before');
        $dangerAfter = $this->jsonNullableInteger('$.danger_after');
        $staminaBefore = $this->jsonNullableInteger('$.stamina_before');
        $staminaAfter = $this->jsonNullableInteger('$.stamina_after');

        return implode(', ', [
            'COUNT(*) AS requests',
            "SUM({$requested}) AS requested",
            "SUM({$completed}) AS completed",
            'SUM('.$this->jsonInteger('$.outcomes.wins').') AS wins',
            'SUM('.$this->jsonInteger('$.outcomes.defeats').') AS defeats',
            'SUM('.$this->jsonInteger('$.outcomes.timeouts').') AS timeouts',
            'SUM('.$this->jsonInteger('$.outcomes.events').') AS events',
            'SUM('.$this->jsonInteger('$.rewards.exp').') AS exp',
            'SUM('.$this->jsonInteger('$.rewards.gold').') AS gold',
            'SUM('.$this->jsonInteger('$.rewards.job_exp').') AS job_exp',
            'SUM('.$this->jsonInteger('$.drops.equipment').') AS equipment',
            'SUM('.$this->jsonInteger('$.drops.materials').') AS materials',
            'SUM('.$this->jsonInteger('$.drops.monster_marks').') AS monster_marks',
            'SUM('.$this->jsonInteger('$.drops.maps').') AS maps',
            "SUM(CASE WHEN {$completed} > 0 AND {$dangerBefore} IS NOT NULL AND {$dangerAfter} IS NOT NULL THEN {$dangerAfter} - {$dangerBefore} ELSE 0 END) AS danger_delta_total",
            "SUM(CASE WHEN {$completed} > 0 AND {$dangerBefore} IS NOT NULL AND {$dangerAfter} IS NOT NULL THEN {$completed} ELSE 0 END) AS danger_completed",
            "SUM(CASE WHEN {$completed} > 0 AND {$staminaBefore} IS NOT NULL AND {$staminaAfter} IS NOT NULL THEN {$staminaBefore} - {$staminaAfter} ELSE 0 END) AS stamina_delta_total",
            "SUM(CASE WHEN {$completed} > 0 AND {$staminaBefore} IS NOT NULL AND {$staminaAfter} IS NOT NULL THEN {$completed} ELSE 0 END) AS stamina_completed",
        ]);
    }

    private function jsonScalar(string $path): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "NULLIF(json_extract(payload, '{$path}'), 'null')"
            : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '{$path}')), 'null')";
    }

    private function jsonInteger(string $path): string
    {
        $value = $this->jsonScalar($path);

        return DB::connection()->getDriverName() === 'sqlite'
            ? "COALESCE(CAST({$value} AS INTEGER), 0)"
            : "COALESCE(CAST({$value} AS UNSIGNED), 0)";
    }

    private function jsonNullableInteger(string $path): string
    {
        $value = $this->jsonScalar($path);

        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST({$value} AS INTEGER)"
            : "CAST({$value} AS SIGNED)";
    }

    private function explorationGroupFromRow(object $row): array
    {
        return [
            'key' => (string) $row->aggregate_key,
            'requests' => (int) $row->requests,
            'requested' => (int) $row->requested,
            'completed' => (int) $row->completed,
            'wins' => (int) $row->wins,
            'defeats' => (int) $row->defeats,
            'timeouts' => (int) $row->timeouts,
            'events' => (int) $row->events,
            'exp' => (int) $row->exp,
            'gold' => (int) $row->gold,
            'job_exp' => (int) $row->job_exp,
            'equipment' => (int) $row->equipment,
            'materials' => (int) $row->materials,
            'monster_marks' => (int) $row->monster_marks,
            'maps' => (int) $row->maps,
            'danger_delta_total' => (int) $row->danger_delta_total,
            'danger_completed' => (int) $row->danger_completed,
            'stamina_delta_total' => (int) $row->stamina_delta_total,
            'stamina_completed' => (int) $row->stamina_completed,
        ];
    }

    private function finishExplorationGroup(array $group, string $label): array
    {
        $completed = max(0, (int) $group['completed']);
        $group['label'] = $label;
        $group['completion_rate'] = $this->rate($completed, (int) $group['requested']);
        $group['exp_per_run'] = $this->average((int) $group['exp'], $completed);
        $group['gold_per_run'] = $this->average((int) $group['gold'], $completed);
        $group['job_exp_per_run'] = $this->average((int) $group['job_exp'], $completed);
        foreach (['equipment', 'materials', 'monster_marks', 'maps'] as $key) {
            $group[$key.'_per_100'] = $completed > 0 ? round($group[$key] / $completed * 100, 2) : 0.0;
        }
        $group['average_danger_delta'] = $group['danger_completed'] > 0
            ? round($group['danger_delta_total'] / $group['danger_completed'], 2)
            : null;
        $group['average_stamina_cost'] = $group['stamina_completed'] > 0
            ? round($group['stamina_delta_total'] / $group['stamina_completed'], 2)
            : null;

        return $group;
    }

    /** @param array<string,mixed>|string $filters @return array<string,mixed> */
    private function normalizeFilters(array|string $filters): array
    {
        if (is_string($filters)) {
            $filters = ['activity_window' => $filters];
        }

        $window = (string) ($filters['activity_window'] ?? '30');
        $context = (string) ($filters['battle_context'] ?? 'all');
        $levelBand = (string) ($filters['level_band'] ?? 'all');

        return [
            'activity_window' => in_array($window, self::WINDOWS, true) ? $window : '30',
            'battle_context' => $context === 'all' || array_key_exists($context, self::JOB_ART_CONTEXT_LABELS)
                ? $context
                : 'all',
            'current_job_id' => max(0, (int) ($filters['current_job_id'] ?? 0)),
            'level_band' => in_array($levelBand, self::LEVEL_BANDS, true) ? $levelBand : 'all',
        ];
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('gameplay_metrics')
            && Schema::hasTable('gameplay_job_art_rollups')
            && Schema::hasTable('gameplay_job_art_skill_rollups');
    }

    /** @return list<array{slot_no:int,skill_id:int,name:string,origin:string}> */
    private function decodeLoadout(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : 0.0;
    }

    private function average(int $total, int $count): float
    {
        return $count > 0 ? round($total / $count, 1) : 0.0;
    }

    private function stopReasonLabel(string $reason): string
    {
        return [
            'defeat' => '敗北',
            'timeout' => '時間切れ',
            'hp_pinch' => 'HP低下',
            'stamina_shortage' => '開始時の探索力不足',
            'stamina_empty' => '途中で探索力切れ',
            'map_availability_exhausted' => '地図の探索可能回数終了',
            'dungeon_lord_encounter' => 'ダンジョン主との遭遇',
            'secret_realm_lord_victory' => '秘境主撃破',
            'hidden_area_gate' => '秘境入口発見',
            'sub_area_gate' => '共有サブエリア入口発見',
            'depth_transition' => '探索深度到達',
            'special_event' => '特殊イベント',
            'error' => '実行エラー',
            'completed' => '予定回数完了',
        ][$reason] ?? $reason;
    }

    /** @param array<string,mixed> $filters */
    private function emptyAnalysis(array $filters): array
    {
        return [
            'ready' => false,
            'filters' => $filters,
            'window' => $filters['activity_window'],
            'generatedAt' => now(),
            'measurementStartedAt' => null,
            'jobArtMeasurementStartedAt' => null,
            'explorationMeasurementStartedAt' => null,
            'jobOptions' => collect(),
            'contextOptions' => self::JOB_ART_CONTEXT_LABELS,
            'levelBandOptions' => self::LEVEL_BANDS,
            'jobArt' => ['cards' => [], 'skillRows' => [], 'contextRows' => [], 'loadoutRows' => []],
            'exploration' => ['cards' => [], 'modeRows' => [], 'contextRows' => [], 'stopRows' => []],
        ];
    }
}
