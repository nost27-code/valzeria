<?php

namespace App\Services;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\CharacterJob;
use App\Models\Enemy;
use App\Models\NamelessWorkshopOperation;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Explicitly approved historical repair; never invoked by player requests. */
class NamelessRuinCompensationService
{
    public const POLICY = 'ruin-launch-20261007-v1';
    public const COUNTER_CUTOFF = '2026-10-07 01:43:27';
    public const REWARD_CUTOFF = '2026-10-07 00:37:10';

    /** Read-only: fix random draws in a private plan before any asset repair. */
    public function createPlan(array $targets): array
    {
        $entries = [];
        $seen = [];
        foreach ($targets as $target) {
            $id = (int) $target['operation_id'];
            if (isset($seen[$id])) {
                throw new RuntimeException('補填元の操作IDが重複しています。');
            }
            $seen[$id] = true;
            $source = $this->source($target);
            $data = $source->result;
            $summary = app(NamelessBattleHistoryService::class)->summarize($data);
            if ($summary['wins'] !== (int) $target['wins'] || $summary['losses'] !== 0) {
                throw new RuntimeException('承認済みの勝利数と補填元の記録が一致しません。');
            }
            $rewards = [];
            foreach ($this->unpaidRuns($source) as $run) {
                $enemy = $this->enemy($data, $run);
                $reference = app(MapExplorationRewardService::class)->normalReferenceFor($enemy);
                $enemy->forceFill(['gold_reward' => $reference['gold']]);
                // Reuse the ordinary battle chance/variance, without unknown historical bonuses.
                $gold = (new \ReflectionMethod(BattleService::class, 'rollGoldReward'))->invoke(app(BattleService::class), $enemy);
                $rewards[] = ['experience' => (int) $reference['experience'], 'gold' => $gold,
                    'reference_gold' => (int) $reference['gold'], 'reference_level' => (int) $reference['level'],
                    'reference_job_exp' => (int) $reference['job_experience'], 'boss' => (bool) $enemy->is_boss,
                    'job_draws' => [random_int(0, 1), random_int(1, 2), random_int(1, 3), random_int(1, 2)]];
            }
            if (count($rewards) !== (int) $target['zero_exp_wins']) {
                throw new RuntimeException('承認済みの未付与報酬数と記録が一致しません。');
            }
            $entries[] = ['operation_id' => $id, 'character_id' => (int) $source->character_id,
                'original_result_sha256' => $target['original_result_sha256'], 'wins' => $summary['wins'], 'rewards' => $rewards];
        }
        return ['policy' => self::POLICY, 'entries' => $entries];
    }

    /** One source operation, one transaction, one immutable receipt. Busy play is deferred. */
    public function applyEntry(array $entry): array
    {
        $result = ['status' => 'deferred'];
        $hash = hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, self::POLICY.':'.$entry['operation_id'])->toString();
        $acquired = app(CharacterBackgroundOperation::class)->run((int) $entry['character_id'], function (Character $character) use ($entry, $hash, $uuid, &$result): void {
            $previous = NamelessWorkshopOperation::query()->where('character_id', $character->id)->where('request_uuid', $uuid)->first();
            if ($previous) {
                if ($previous->action !== 'ruin_compensation' || ! hash_equals($hash, $previous->payload_hash)) {
                    throw new RuntimeException('既存の補填計画と一致しません。再抽選・再付与はできません。');
                }
                $result = ['status' => 'replayed', 'receipt' => $previous->result];
                return;
            }
            $source = $this->source($entry);
            if (app(NamelessBattleHistoryService::class)->summarize($source->result)['wins'] !== (int) $entry['wins']
                || count($this->unpaidRuns($source)) !== count($entry['rewards'])) {
                throw new RuntimeException('補填計画と戦闘記録が一致しません。');
            }
            if (app(MapExplorationItemService::class)->activeRegistration($character)) {
                $result = ['status' => 'deferred', 'reason' => 'active_map'];
                return;
            }
            $before = ['level' => (int) $character->level, 'exp' => (int) $character->exp,
                'money' => (int) $character->money, 'wins' => (int) $character->wins,
                'current_job_id' => $character->current_job_id, 'job_exp' => $this->jobExp($character)];
            $experience = $gold = $jobExp = $levelUps = 0;
            CharacterStatusService::clearRequestCache((int) $character->id);
            foreach ($entry['rewards'] as $reward) {
                if ((int) $reward['experience'] < 1 || (int) $reward['gold'] < 0) {
                    throw new RuntimeException('補填報酬が不正です。');
                }
                $appliedExp = $character->level < 255 ? (int) $reward['experience'] : 0;
                $jobGain = $this->jobReward($reward, (int) $character->level);
                $growth = app(LevelService::class)->addRewardAndCheckLevelUp($character, $appliedExp, 0, $jobGain);
                $experience += $appliedExp;
                $jobExp += $jobGain;
                $gold += (int) $reward['gold'];
                $levelUps += (int) $growth['level_up_count'];
            }
            $character->wins = (int) $character->wins + (int) $entry['wins'];
            $character->save();
            if ($gold > 0) {
                app(GoldService::class)->add($character, $gold, 'ruin_compensation', '遺跡の未付与報酬の補填',
                    'nameless_workshop_operation', (int) $source->id, ['policy' => self::POLICY, 'receipt_uuid' => $uuid]);
            }
            $receipt = ['policy' => self::POLICY, 'operation_id' => (int) $source->id, 'before' => $before,
                'wins_added' => (int) $entry['wins'], 'reward_wins' => count($entry['rewards']),
                'experience_added' => $experience, 'gold_added' => $gold, 'job_exp_requested' => $jobExp,
                'job_exp_added' => $this->jobExp($character) - $before['job_exp'], 'level_ups' => $levelUps,
                'after' => ['level' => (int) $character->level, 'exp' => (int) $character->exp,
                    'money' => (int) $character->money, 'wins' => (int) $character->wins]];
            NamelessWorkshopOperation::query()->create(['character_id' => $character->id, 'request_uuid' => $uuid,
                'action' => 'ruin_compensation', 'payload_hash' => $hash, 'result' => $receipt]);
            $result = ['status' => 'applied', 'receipt' => $receipt];
        });
        return $acquired ? $result : ['status' => 'deferred', 'reason' => 'busy_or_missing_character'];
    }

    private function source(array $target): NamelessWorkshopOperation
    {
        $source = NamelessWorkshopOperation::query()->whereKey((int) $target['operation_id'])
            ->where('character_id', (int) $target['character_id'])->where('action', 'ruin')->firstOrFail();
        if ($source->created_at->format('Y-m-d H:i:s') >= self::COUNTER_CUTOFF
            || ! hash_equals((string) $target['original_result_sha256'], hash('sha256', $source->getRawOriginal('result')))) {
            throw new RuntimeException('補填元の期間または記録ハッシュが一致しません。');
        }
        return $source;
    }

    private function unpaidRuns(NamelessWorkshopOperation $source): array
    {
        if ($source->created_at->format('Y-m-d H:i:s') >= self::REWARD_CUTOFF) {
            return [];
        }
        $data = $source->result;
        $batch = is_array(data_get($data, 'batch_explore.runs'));
        $runs = $batch ? $data['batch_explore']['runs'] : [$data];
        return array_values(array_filter($runs, fn ($run) => is_array($run) && (int) ($run['turn_count'] ?? 0) > 0
            && in_array($run['result'] ?? null, BattleLog::WIN_RESULTS, true)
            && array_key_exists($batch ? 'exp' : 'exp_gained', $run) && (int) $run[$batch ? 'exp' : 'exp_gained'] === 0));
    }

    private function enemy(array $data, array $run): Enemy
    {
        if (! is_array(data_get($data, 'batch_explore.runs'))) {
            if (! is_array($data['enemy'] ?? null)) {
                throw new RuntimeException('補填元の敵情報がありません。');
            }
            return new Enemy($data['enemy'] + ['is_boss' => (bool) ($data['boss'] ?? false)]);
        }
        $zone = app(NamelessRuinService::class)->zones()[$data['zone_key']] ?? throw new RuntimeException('補填元の遺跡が不明です。');
        $definition = collect(array_merge($zone['enemies'], $zone['bosses'], [(array) config('nameless_relics.relic_goblin')]))
            ->first(fn ($enemy) => ($enemy['name'] ?? null) === ($run['enemy_name'] ?? null));
        if (! $definition) {
            throw new RuntimeException('補填元の敵を復元できません。');
        }
        $boss = collect($data['rare_encounters'] ?? [])->contains(fn ($encounter) => ($encounter['kind'] ?? null) === 'cleared_boss'
            && (int) ($encounter['index'] ?? 0) === (int) ($run['index'] ?? -1));
        return new Enemy(app(NamelessRuinService::class)->enemyStats($definition, (int) $data['depth'], $boss) + ['is_boss' => $boss]);
    }

    private function jobReward(array $reward, int $level): int
    {
        if ((int) $reward['reference_job_exp'] > 0) {
            return min(LevelService::MAX_JOB_EXP_GAIN, (int) $reward['reference_job_exp']);
        }
        $difference = (int) $reward['reference_level'] - $level;
        $draws = $reward['job_draws'];
        $gain = $difference <= -3 ? 0 : ($difference <= -1 ? $draws[0] : ($difference <= 2 ? $draws[1] : $draws[2]));
        return min(LevelService::MAX_JOB_EXP_GAIN, (int) $gain + ($reward['boss'] ? (int) $draws[3] : 0));
    }

    private function jobExp(Character $character): int
    {
        return (int) CharacterJob::query()->where('character_id', $character->id)->where('job_class_id', $character->current_job_id)->value('job_exp');
    }
}
