<?php

namespace App\Services\Admin;

use App\Models\GameplayMetric;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class GameplayAnalyticsService
{
    private const WINDOWS = ['7', '30', '90', 'all'];

    private const JOB_ART_CONTEXT_LABELS = [
        'normal' => '通常探索',
        'region_depth' => '追加ダンジョン',
        'boss' => 'ボス戦',
        'sub_area' => '共有サブエリア',
        'map' => '探索の地図',
        'tower' => '星樹の塔',
        'hero_trial' => '英雄試練',
        'pvp' => 'プレイヤー闘技場',
        'champ' => 'チャンプ戦',
        'arena_npc' => 'NPCランク戦',
    ];

    private const EXPLORATION_CONTEXT_LABELS = [
        'normal' => '通常探索',
        'region_depth' => '追加ダンジョン',
        'sub_area' => '共有サブエリア',
        'map' => '探索の地図',
    ];

    /** @return array<string,mixed> */
    public function analyze(string $window = '30'): array
    {
        $window = in_array($window, self::WINDOWS, true) ? $window : '30';
        if (! Schema::hasTable('gameplay_metrics')) {
            return $this->emptyAnalysis($window);
        }

        $records = $this->metricQuery($window)
            ->with('character.user:id,role,email')
            ->orderBy('id')
            ->get()
            ->reject(fn (GameplayMetric $record): bool => $record->character?->isExcludedFromPublicLogs() ?? true)
            ->values();
        $jobArtRecords = $records->where('metric_type', GameplayMetric::TYPE_JOB_ART_BATTLE)->values();
        $explorationRecords = $records->where('metric_type', GameplayMetric::TYPE_EXPLORATION_REQUEST)->values();

        return [
            'ready' => true,
            'window' => $window,
            'generatedAt' => now(),
            'measurementStartedAt' => GameplayMetric::query()->min('created_at'),
            'jobArt' => $this->jobArtAnalysis($jobArtRecords),
            'exploration' => $this->explorationAnalysis($explorationRecords),
        ];
    }

    private function metricQuery(string $window): Builder
    {
        $query = GameplayMetric::query()
            ->whereHas('character.user', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereNull('role')->orWhere('role', '!=', 'admin');
                });
            });

        if ($window !== 'all') {
            $query->where('created_at', '>=', now()->subDays((int) $window));
        }

        return $query;
    }

    /** @param Collection<int,GameplayMetric> $records */
    private function jobArtAnalysis(Collection $records): array
    {
        $battles = $records->count();
        $withArt = $records->filter(fn (GameplayMetric $record): bool => (int) data_get($record->payload, 'activation_count', 0) > 0);
        $withoutArt = $records->reject(fn (GameplayMetric $record): bool => (int) data_get($record->payload, 'activation_count', 0) > 0);
        $activationCount = $records->sum(fn (GameplayMetric $record): int => (int) data_get($record->payload, 'activation_count', 0));
        $skills = [];
        $contexts = [];

        foreach ($records as $record) {
            $context = (string) $record->context;
            $contexts[$context] ??= ['context' => $context, 'battles' => 0, 'art_battles' => 0, 'activations' => 0, 'wins' => 0];
            $contexts[$context]['battles']++;
            $contextActivations = (int) data_get($record->payload, 'activation_count', 0);
            $contexts[$context]['activations'] += $contextActivations;
            $contexts[$context]['art_battles'] += $contextActivations > 0 ? 1 : 0;
            $contexts[$context]['wins'] += $record->result === 'victory' ? 1 : 0;

            foreach ((array) data_get($record->payload, 'skills', []) as $usage) {
                $skillId = (int) ($usage['skill_id'] ?? 0);
                if ($skillId <= 0) {
                    continue;
                }
                $skills[$skillId] ??= [
                    'skill_id' => $skillId,
                    'name' => (string) ($usage['name'] ?? '不明な戦技'),
                    'battles' => 0,
                    'activations' => 0,
                    'hits' => 0,
                    'misses' => 0,
                    'evades' => 0,
                    'no_resolution' => 0,
                    'vital_hits' => 0,
                    'wins' => 0,
                ];
                $skills[$skillId]['battles']++;
                $skills[$skillId]['activations'] += (int) ($usage['activation_count'] ?? 0);
                $skills[$skillId]['hits'] += (int) ($usage['hit_count'] ?? 0);
                $skills[$skillId]['misses'] += (int) ($usage['miss_count'] ?? 0);
                $skills[$skillId]['evades'] += (int) ($usage['evade_count'] ?? 0);
                $skills[$skillId]['no_resolution'] += (int) ($usage['no_resolution_count'] ?? 0);
                $skills[$skillId]['vital_hits'] += (int) ($usage['vital_hit_count'] ?? 0);
                $skills[$skillId]['wins'] += $record->result === 'victory' ? 1 : 0;
            }
        }

        $masterNames = Skill::query()->whereKey(array_keys($skills))->pluck('name', 'id');
        $skillRows = collect($skills)->map(function (array $row) use ($masterNames): array {
            $resolved = $row['hits'] + $row['misses'] + $row['evades'];
            $row['name'] = (string) ($masterNames[$row['skill_id']] ?? $row['name']);
            $row['hit_rate'] = $resolved > 0 ? round($row['hits'] / $resolved * 100, 1) : null;
            $row['vital_hit_rate'] = $row['hits'] > 0
                ? round($row['vital_hits'] / $row['hits'] * 100, 1)
                : null;
            $row['win_rate'] = $row['battles'] > 0 ? round($row['wins'] / $row['battles'] * 100, 1) : 0.0;

            return $row;
        })->sort(fn (array $left, array $right): int => [-$left['activations'], -$left['battles'], $left['name']] <=> [-$right['activations'], -$right['battles'], $right['name']])
            ->take(30)->values()->all();

        $contextRows = collect($contexts)->map(function (array $row): array {
            $row['label'] = self::JOB_ART_CONTEXT_LABELS[$row['context']] ?? $row['context'];
            $row['activation_battle_rate'] = $row['battles'] > 0 ? round($row['art_battles'] / $row['battles'] * 100, 1) : 0.0;
            $row['win_rate'] = $row['battles'] > 0 ? round($row['wins'] / $row['battles'] * 100, 1) : 0.0;

            return $row;
        })->sortByDesc('battles')->values()->all();

        return [
            'cards' => [
                'battles' => $battles,
                'art_battles' => $withArt->count(),
                'activation_battle_rate' => $battles > 0 ? round($withArt->count() / $battles * 100, 1) : 0.0,
                'activations' => $activationCount,
                'with_art_win_rate' => $this->winRate($withArt),
                'without_art_win_rate' => $this->winRate($withoutArt),
            ],
            'skillRows' => $skillRows,
            'contextRows' => $contextRows,
        ];
    }

    /** @param Collection<int,GameplayMetric> $records */
    private function explorationAnalysis(Collection $records): array
    {
        $groups = [];
        $contexts = [];
        $stops = [];

        foreach ($records as $record) {
            $payload = (array) $record->payload;
            $requested = max(1, (int) ($payload['requested_count'] ?? 1));
            $completed = max(0, (int) ($payload['completed_count'] ?? 0));
            $mode = $requested === 1 ? 'single' : 'batch';
            $groups[$mode] ??= $this->emptyExplorationGroup($mode);
            $this->accumulateExploration($groups[$mode], $payload);

            $context = (string) $record->context;
            $contexts[$context] ??= $this->emptyExplorationGroup($context);
            $this->accumulateExploration($contexts[$context], $payload);

            $stopReason = trim((string) ($payload['stop_reason'] ?? ''));
            if ($stopReason !== '') {
                $stops[$stopReason] = ($stops[$stopReason] ?? 0) + 1;
            }
        }

        $modeRows = collect($groups)->map(fn (array $group): array => $this->finishExplorationGroup(
            $group,
            $group['key'] === 'single' ? '1回探索' : 'まとめて探索',
        ))->values()->all();
        $contextRows = collect($contexts)->map(fn (array $group): array => $this->finishExplorationGroup(
            $group,
            self::EXPLORATION_CONTEXT_LABELS[$group['key']] ?? $group['key'],
        ))->sortByDesc('requests')->values()->all();
        $stopRows = collect($stops)->map(fn (int $count, string $reason): array => [
            'reason' => $reason,
            'label' => $this->stopReasonLabel($reason),
            'count' => $count,
        ])->sortByDesc('count')->values()->all();

        $requestedTotal = $records->sum(fn (GameplayMetric $record): int => (int) data_get($record->payload, 'requested_count', 0));
        $completedTotal = $records->sum(fn (GameplayMetric $record): int => (int) data_get($record->payload, 'completed_count', 0));

        return [
            'cards' => [
                'requests' => $records->count(),
                'requested_runs' => $requestedTotal,
                'completed_runs' => $completedTotal,
                'completion_rate' => $requestedTotal > 0 ? round($completedTotal / $requestedTotal * 100, 1) : 0.0,
                'single_requests' => collect($groups)->get('single')['requests'] ?? 0,
                'batch_requests' => collect($groups)->get('batch')['requests'] ?? 0,
            ],
            'modeRows' => $modeRows,
            'contextRows' => $contextRows,
            'stopRows' => $stopRows,
        ];
    }

    private function emptyExplorationGroup(string $key): array
    {
        return [
            'key' => $key,
            'requests' => 0,
            'requested' => 0,
            'completed' => 0,
            'wins' => 0,
            'defeats' => 0,
            'timeouts' => 0,
            'events' => 0,
            'exp' => 0,
            'gold' => 0,
            'job_exp' => 0,
            'equipment' => 0,
            'materials' => 0,
            'monster_marks' => 0,
            'maps' => 0,
            'danger_delta_total' => 0,
            'danger_completed' => 0,
            'stamina_delta_total' => 0,
            'stamina_completed' => 0,
        ];
    }

    /** @param array<string,mixed> $group @param array<string,mixed> $payload */
    private function accumulateExploration(array &$group, array $payload): void
    {
        $group['requests']++;
        $group['requested'] += (int) ($payload['requested_count'] ?? 0);
        $group['completed'] += (int) ($payload['completed_count'] ?? 0);
        foreach (['wins', 'defeats', 'timeouts', 'events'] as $key) {
            $group[$key] += (int) data_get($payload, 'outcomes.'.$key, 0);
        }
        foreach (['exp', 'gold', 'job_exp'] as $key) {
            $group[$key] += (int) data_get($payload, 'rewards.'.$key, 0);
        }
        foreach (['equipment', 'materials', 'monster_marks', 'maps'] as $key) {
            $group[$key] += (int) data_get($payload, 'drops.'.$key, 0);
        }

        $completed = max(0, (int) ($payload['completed_count'] ?? 0));
        if ($completed > 0
            && is_int($payload['danger_before'] ?? null)
            && is_int($payload['danger_after'] ?? null)) {
            $group['danger_delta_total'] += $payload['danger_after'] - $payload['danger_before'];
            $group['danger_completed'] += $completed;
        }
        if ($completed > 0
            && is_int($payload['stamina_before'] ?? null)
            && is_int($payload['stamina_after'] ?? null)) {
            $group['stamina_delta_total'] += $payload['stamina_before'] - $payload['stamina_after'];
            $group['stamina_completed'] += $completed;
        }
    }

    private function finishExplorationGroup(array $group, string $label): array
    {
        $completed = max(0, (int) $group['completed']);
        $group['label'] = $label;
        $group['completion_rate'] = $group['requested'] > 0 ? round($completed / $group['requested'] * 100, 1) : 0.0;
        $group['exp_per_run'] = $completed > 0 ? round($group['exp'] / $completed, 1) : 0.0;
        $group['gold_per_run'] = $completed > 0 ? round($group['gold'] / $completed, 1) : 0.0;
        $group['job_exp_per_run'] = $completed > 0 ? round($group['job_exp'] / $completed, 1) : 0.0;
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

    /** @param Collection<int,GameplayMetric> $records */
    private function winRate(Collection $records): ?float
    {
        if ($records->isEmpty()) {
            return null;
        }

        return round($records->where('result', 'victory')->count() / $records->count() * 100, 1);
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

    private function emptyAnalysis(string $window): array
    {
        return [
            'ready' => false,
            'window' => $window,
            'generatedAt' => now(),
            'measurementStartedAt' => null,
            'jobArt' => ['cards' => [], 'skillRows' => [], 'contextRows' => []],
            'exploration' => ['cards' => [], 'modeRows' => [], 'contextRows' => [], 'stopRows' => []],
        ];
    }
}
