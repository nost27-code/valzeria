<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterExplorationState;
use App\Models\CharacterSubAreaExplorationState;
use App\Models\GameplayMetric;
use App\Services\Battle\BattleResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GameplayMetricService
{
    private const JOB_ART_ROLLUP_TABLE = 'gameplay_job_art_rollups';

    private const JOB_ART_SKILL_ROLLUP_TABLE = 'gameplay_job_art_skill_rollups';

    /** @return array{danger_rate:?int,stamina:?int} */
    public function explorationSnapshot(
        Character $character,
        string $context,
        ?int $areaId = null,
        ?int $subAreaRouteId = null,
    ): array {
        try {
            $dangerRate = match ($context) {
                'normal' => $areaId === null || ! app(SchemaStateService::class)->hasTable('character_exploration_states') ? null : CharacterExplorationState::query()
                    ->where('character_id', $character->id)
                    ->where('area_id', $areaId)
                    ->value('danger_rate'),
                'sub_area' => $subAreaRouteId === null || ! app(SchemaStateService::class)->hasTable('character_sub_area_exploration_states') ? null : CharacterSubAreaExplorationState::query()
                    ->where('character_id', $character->id)
                    ->where('sub_area_route_id', $subAreaRouteId)
                    ->value('danger_rate'),
                default => null,
            };
        } catch (Throwable) {
            $dangerRate = null;
        }

        try {
            $stamina = app(ExplorationStaminaService::class)->summary($character);
        } catch (Throwable) {
            $stamina = [];
        }

        return [
            'danger_rate' => $dangerRate === null ? null : (int) $dangerRate,
            'stamina' => isset($stamina['current']) ? (int) $stamina['current'] : null,
        ];
    }

    public function recordJobArtBattle(
        Character $character,
        string $context,
        BattleResult|array $battle,
    ): void {
        try {
            $this->recordJobArtBattleUnsafe($character, $context, $battle);
        } catch (Throwable $e) {
            $this->logFailure(GameplayMetric::TYPE_JOB_ART_BATTLE, $context, $e);
        }
    }

    private function recordJobArtBattleUnsafe(
        Character $character,
        string $context,
        BattleResult|array $battle,
    ): void {
        $usage = $battle instanceof BattleResult
            ? $battle->jobArtUsage
            : (array) ($battle['job_art_usage'] ?? []);
        $result = $battle instanceof BattleResult
            ? $battle->result
            : (string) ($battle['result'] ?? 'unknown');
        $turnCount = $battle instanceof BattleResult
            ? $battle->turnCount
            : (int) ($battle['turn_count'] ?? $battle['turns'] ?? 0);
        if ($turnCount <= 0) {
            return;
        }

        $characterLevelAtStart = $battle instanceof BattleResult
            ? $battle->playerLevelAtStart
            : ($battle['character_level_at_start'] ?? null);
        $characterLevelAtStart = is_numeric($characterLevelAtStart) && (int) $characterLevelAtStart > 0
            ? (int) $characterLevelAtStart
            : null;
        $currentJobIdAtStart = $battle instanceof BattleResult
            ? $battle->playerJobIdAtStart
            : ($battle['current_job_id_at_start'] ?? null);
        $currentJobIdAtStart = is_numeric($currentJobIdAtStart) && (int) $currentJobIdAtStart > 0
            ? (int) $currentJobIdAtStart
            : null;
        $loadout = $this->normalizeLoadout($battle instanceof BattleResult
            ? $battle->jobArtLoadout
            : (array) ($battle['job_art_loadout'] ?? []));
        $levelBandAtStart = $this->levelBandFor($characterLevelAtStart);
        $normalizedResult = $this->normalizeBattleResult($result);
        $activationCount = collect($usage)->sum(
            fn (array $row): int => max(0, (int) ($row['activation_count'] ?? 0)),
        );

        $recorded = $this->record($character, [
            'metric_type' => GameplayMetric::TYPE_JOB_ART_BATTLE,
            'context' => $context,
            'result' => $normalizedResult,
            'payload' => [
                'version' => 3,
                'turn_count' => max(0, $turnCount),
                'activation_count' => $activationCount,
                'character_level_at_start' => $characterLevelAtStart,
                'current_job_id_at_start' => $currentJobIdAtStart,
                'level_band_at_start' => $levelBandAtStart,
                'loadout' => $loadout,
                'skills' => array_values($usage),
            ],
        ]);

        if ($recorded) {
            $this->recordJobArtRollups(
                context: $context,
                result: $normalizedResult,
                turnCount: max(0, $turnCount),
                activationCount: $activationCount,
                characterLevelAtStart: $characterLevelAtStart,
                currentJobIdAtStart: $currentJobIdAtStart,
                loadout: $loadout,
                usage: array_values($usage),
            );
        }
    }

    /** @param array<string,mixed> $result */
    public function recordJobArtExplorationResult(Character $character, string $context, array $result): void
    {
        $runs = is_array(data_get($result, 'batch_explore.runs'))
            ? data_get($result, 'batch_explore.runs')
            : [];
        if ($runs === []) {
            $runs = [$result];
        }

        foreach ($runs as $run) {
            if (is_array($run)) {
                $this->recordJobArtBattle($character, $context, $run);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array{danger_rate:?int,stamina:?int}  $before
     */
    public function recordExplorationRequest(
        Character $character,
        string $context,
        int $requestedCount,
        array $result,
        array $before,
        ?int $areaId = null,
        ?int $subAreaRouteId = null,
    ): void {
        try {
            $this->recordExplorationRequestUnsafe(
                $character,
                $context,
                $requestedCount,
                $result,
                $before,
                $areaId,
                $subAreaRouteId,
            );
        } catch (Throwable $e) {
            $this->logFailure(GameplayMetric::TYPE_EXPLORATION_REQUEST, $context, $e);
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array{danger_rate:?int,stamina:?int}  $before
     */
    private function recordExplorationRequestUnsafe(
        Character $character,
        string $context,
        int $requestedCount,
        array $result,
        array $before,
        ?int $areaId = null,
        ?int $subAreaRouteId = null,
    ): void {
        $batch = is_array($result['batch_explore'] ?? null) ? $result['batch_explore'] : [];
        $requested = max(1, (int) ($batch['requested'] ?? $requestedCount));
        $completed = max(0, (int) ($batch['completed'] ?? (isset($result['error']) ? 0 : 1)));
        $runs = is_array($batch['runs'] ?? null) ? $batch['runs'] : [];
        if ($runs === [] && $completed > 0) {
            $runs = [['result' => $result['result'] ?? 'unknown']];
        }

        $outcomes = ['wins' => 0, 'defeats' => 0, 'timeouts' => 0, 'events' => 0];
        foreach ($runs as $run) {
            $normalized = $this->normalizeBattleResult((string) ($run['result'] ?? 'unknown'));
            match ($normalized) {
                'victory' => $outcomes['wins']++,
                'defeat' => $outcomes['defeats']++,
                'timeout' => $outcomes['timeouts']++,
                'event' => $outcomes['events']++,
                default => null,
            };
        }

        $after = $this->explorationSnapshot($character, $context, $areaId, $subAreaRouteId);
        $equipmentDrops = is_array($result['equipment_drops'] ?? null) ? $result['equipment_drops'] : [];
        $materialDrops = is_array($result['material_drop'] ?? null) ? $result['material_drop'] : [];
        $monsterMarkDrops = is_array($batch['monster_mark_drops'] ?? null)
            ? $batch['monster_mark_drops']
            : (is_array($result['monster_mark_drop'] ?? null) ? [$result['monster_mark_drop']] : []);
        $mapDrops = is_array($batch['map_drops'] ?? null)
            ? $batch['map_drops']
            : (is_array($result['map_drop'] ?? null) ? [$result['map_drop']] : []);

        $stopReason = $batch['stop_reason'] ?? $result['metric_stop_reason'] ?? null;
        if ($stopReason === null && isset($result['error'])) {
            $stopReason = 'error';
        } elseif ($stopReason === null && $completed > 0) {
            $stopReason = match ($this->normalizeBattleResult((string) ($result['result'] ?? 'unknown'))) {
                'defeat' => 'defeat',
                'timeout' => 'timeout',
                default => null,
            };
        }

        $this->record($character, [
            'metric_type' => GameplayMetric::TYPE_EXPLORATION_REQUEST,
            'context' => $context,
            'result' => $completed > 0 ? $this->normalizeBattleResult((string) ($result['result'] ?? 'unknown')) : 'not_started',
            'payload' => [
                'version' => 1,
                'requested_count' => $requested,
                'completed_count' => $completed,
                'stop_reason' => $stopReason,
                'outcomes' => $outcomes,
                'rewards' => [
                    'exp' => max(0, (int) ($batch['total_exp'] ?? $result['exp_gained'] ?? 0)),
                    'gold' => max(0, (int) ($batch['total_gold'] ?? $result['gold_gained'] ?? 0)),
                    'job_exp' => max(0, (int) ($batch['total_job_exp'] ?? $result['job_exp_gained'] ?? 0)),
                    'kiseki' => max(0, (int) ($batch['total_kiseki'] ?? data_get($result, 'kiseki_drop.amount', 0))),
                ],
                'drops' => [
                    'equipment' => count($equipmentDrops),
                    'materials' => collect($materialDrops)->sum(fn ($drop): int => max(0, (int) ($drop['quantity'] ?? $drop['total_quantity'] ?? 1))),
                    'monster_marks' => collect($monsterMarkDrops)->sum(fn ($drop): int => max(0, (int) ($drop['total_quantity'] ?? $drop['quantity'] ?? 1))),
                    'maps' => count($mapDrops),
                ],
                'danger_before' => $before['danger_rate'] ?? null,
                'danger_after' => $after['danger_rate'],
                'stamina_before' => $before['stamina'] ?? null,
                'stamina_after' => $after['stamina'],
            ],
        ]);
    }

    /** @param array{metric_type:string,context:string,result:?string,payload:array<string,mixed>} $attributes */
    private function record(Character $character, array $attributes): bool
    {
        try {
            if (! app(SchemaStateService::class)->hasTable('gameplay_metrics')) {
                return false;
            }

            // 呼び出し元でuserが一部カラムだけload済みでも、除外判定を弱めない。
            $character->load('user:id,role,email');
            if ($character->isExcludedFromPublicLogs()) {
                return false;
            }

            GameplayMetric::query()->create([
                'character_id' => $character->id,
                ...$attributes,
                'created_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logFailure($attributes['metric_type'], $attributes['context'], $e);

            return false;
        }
    }

    /**
     * @param  list<array{slot_no:int,skill_id:int,name:string,origin:string}>  $loadout
     * @param  list<array<string,mixed>>  $usage
     */
    private function recordJobArtRollups(
        string $context,
        string $result,
        int $turnCount,
        int $activationCount,
        ?int $characterLevelAtStart,
        ?int $currentJobIdAtStart,
        array $loadout,
        array $usage,
    ): void {
        if (! app(SchemaStateService::class)->hasTable(self::JOB_ART_ROLLUP_TABLE)
            || ! app(SchemaStateService::class)->hasTable(self::JOB_ART_SKILL_ROLLUP_TABLE)
        ) {
            return;
        }

        $now = now();
        $bucket = $now->copy()->startOfHour();
        $levelBand = $this->levelBandFor($characterLevelAtStart);
        $jobId = $currentJobIdAtStart ?? 0;
        $loadoutJson = json_encode($loadout, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $loadoutSignature = hash('sha256', $loadoutJson);
        $hpRecovered = collect($usage)->sum(
            fn (array $row): int => max(0, (int) ($row['hp_recovered'] ?? 0)),
        );
        $spRecovered = collect($usage)->sum(
            fn (array $row): int => max(0, (int) ($row['sp_recovered'] ?? 0)),
        );
        $won = $result === 'victory' ? 1 : 0;
        $artBattle = $activationCount > 0 ? 1 : 0;

        $this->additiveUpsert(
            self::JOB_ART_ROLLUP_TABLE,
            [[
                'bucket_started_at' => $bucket,
                'context' => $context,
                'current_job_id' => $jobId,
                'level_band' => $levelBand,
                'loadout_signature' => $loadoutSignature,
                'loadout' => $loadoutJson,
                'battles' => 1,
                'art_battles' => $artBattle,
                'wins' => $won,
                'art_wins' => $artBattle * $won,
                'turns' => $turnCount,
                'activations' => $activationCount,
                'hp_recovered' => $hpRecovered,
                'sp_recovered' => $spRecovered,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['bucket_started_at', 'context', 'current_job_id', 'level_band', 'loadout_signature'],
            ['battles', 'art_battles', 'wins', 'art_wins', 'turns', 'activations', 'hp_recovered', 'sp_recovered'],
            ['loadout', 'updated_at'],
        );

        $skillRows = [];
        foreach ($usage as $row) {
            $skillId = max(0, (int) ($row['skill_id'] ?? 0));
            $activations = max(0, (int) ($row['activation_count'] ?? 0));
            if ($skillId <= 0 || $activations <= 0) {
                continue;
            }

            $skillRows[] = [
                'bucket_started_at' => $bucket,
                'context' => $context,
                'current_job_id' => $jobId,
                'level_band' => $levelBand,
                'skill_id' => $skillId,
                'skill_name' => mb_substr((string) ($row['name'] ?? '不明な戦技'), 0, 255),
                'battles' => 1,
                'wins' => $won,
                'turns' => $turnCount,
                'activations' => $activations,
                'hits' => max(0, (int) ($row['hit_count'] ?? 0)),
                'misses' => max(0, (int) ($row['miss_count'] ?? 0)),
                'evades' => max(0, (int) ($row['evade_count'] ?? 0)),
                'no_resolution' => max(0, (int) ($row['no_resolution_count'] ?? 0)),
                'vital_hits' => max(0, (int) ($row['vital_hit_count'] ?? 0)),
                'hp_recovered' => max(0, (int) ($row['hp_recovered'] ?? 0)),
                'sp_recovered' => max(0, (int) ($row['sp_recovered'] ?? 0)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($skillRows !== []) {
            $this->additiveUpsert(
                self::JOB_ART_SKILL_ROLLUP_TABLE,
                $skillRows,
                ['bucket_started_at', 'context', 'current_job_id', 'level_band', 'skill_id'],
                ['battles', 'wins', 'turns', 'activations', 'hits', 'misses', 'evades', 'no_resolution', 'vital_hits', 'hp_recovered', 'sp_recovered'],
                ['skill_name', 'updated_at'],
            );
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $incrementColumns
     * @param  list<string>  $replaceColumns
     */
    private function additiveUpsert(
        string $table,
        array $rows,
        array $uniqueBy,
        array $incrementColumns,
        array $replaceColumns,
    ): void {
        $driver = DB::connection()->getDriverName();
        $updates = $replaceColumns;
        foreach ($incrementColumns as $column) {
            $updates[$column] = match ($driver) {
                'mysql' => DB::raw("`{$column}` + values(`{$column}`)"),
                default => DB::raw("\"{$column}\" + excluded.\"{$column}\""),
            };
        }

        DB::table($table)->upsert($rows, $uniqueBy, $updates);
    }

    /** @param array<int,mixed> $loadout @return list<array{slot_no:int,skill_id:int,name:string,origin:string}> */
    private function normalizeLoadout(array $loadout): array
    {
        $normalized = [];
        foreach (array_values($loadout) as $index => $row) {
            if (! is_array($row) || (int) ($row['skill_id'] ?? 0) <= 0) {
                continue;
            }

            $normalized[] = [
                'slot_no' => max(1, (int) ($row['slot_no'] ?? $index + 1)),
                'skill_id' => (int) $row['skill_id'],
                'name' => mb_substr((string) ($row['name'] ?? '不明な戦技'), 0, 255),
                'origin' => mb_substr((string) ($row['origin'] ?? 'current'), 0, 40),
            ];
        }

        return $normalized;
    }

    private function levelBandFor(?int $level): string
    {
        return match (true) {
            $level === null || $level <= 0 => 'unknown',
            $level <= 49 => '1-49',
            $level <= 99 => '50-99',
            $level <= 149 => '100-149',
            $level <= 199 => '150-199',
            default => '200-255',
        };
    }

    private function logFailure(string $metricType, string $context, Throwable $exception): void
    {
        try {
            Log::warning('[GameplayMetrics] 計測レコードを保存できませんでした。', [
                'metric_type' => $metricType,
                'context' => $context,
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // 計測とその警告は、ゲーム結果を失敗させない。
        }
    }

    private function normalizeBattleResult(string $result): string
    {
        return match ($result) {
            'win', 'victory' => 'victory',
            'lose', 'loss', 'defeat' => 'defeat',
            'event' => 'event',
            'timeout' => 'timeout',
            default => 'unknown',
        };
    }
}
