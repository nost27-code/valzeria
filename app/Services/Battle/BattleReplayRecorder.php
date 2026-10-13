<?php

namespace App\Services\Battle;

use App\Models\Skill;
use App\Services\JobArtV2ResourceCatalog;

/** Display-only observations: never calculate damage, roll RNG, or write models. */
final class BattleReplayRecorder
{
    private array $frames = [];
    private array $resources = [];
    private array $action = [];
    private int $logOffset = 0;
    private array $initial;
    private bool $nextCritical = false;

    public function __construct(private readonly BattleState $state)
    {
        foreach (['player' => $state->player, 'enemy' => $state->enemy] as $side => $actor) {
            $this->resources[$side] = app(JobArtV2ResourceCatalog::class)->resourcesForActor($actor);
        }
        $this->initial = $this->snapshot();
    }

    public function begin(BattleActor $actor): void
    {
        $this->capture();
        $this->action = ['side' => $this->side($actor), 'name' => '通常攻撃', 'lineage' => null, 'skill' => false, 'ultimate' => false, 'hits' => [], 'critical' => false];
    }

    public function skill(string $name, ?Skill $skill = null): void
    {
        $this->action['name'] = $name;
        $this->action['skill'] = true;
        $this->action['ultimate'] = $skill !== null && (int) $skill->learn_rank === 9;
        $this->action['lineage'] = $skill ? (app(JobArtV2ResourceCatalog::class)->forArt($skill)['resource_name'] ?? null) : null;
    }

    public function critical(bool $critical): void
    {
        $this->nextCritical = $critical;
        $this->action['critical'] = $critical || ($this->action['critical'] ?? false);
    }

    public function miss(BattleActor $target): void
    {
        $this->action['hits'][] = ['target' => $this->side($target), 'kind' => 'miss', 'damage' => 0];
    }

    public function damage(BattleActor $target, DamageApplicationResult $result): void
    {
        $this->action['hits'][] = ['target' => $this->side($target), 'kind' => 'hit', 'damage' => $result->actualHpLoss, 'critical' => $this->nextCritical];
        $this->nextCritical = false;
    }

    public function finish(): void
    {
        $this->capture(true);
        $this->action = [];
        $this->nextCritical = false;
    }

    /** Capture round effects and the result/recovery separately from attacks. */
    public function capture(bool $force = false): void
    {
        $snapshot = $this->snapshot();
        $logs = array_slice($this->state->logs, $this->logOffset);
        $previous = $this->frames === [] ? $this->initial : $this->frames[array_key_last($this->frames)]['actors'];
        if (! $force && $logs === [] && $snapshot === $previous) {
            return;
        }
        $this->frames[] = ['turn' => $this->state->turnCount, 'actors' => $snapshot, 'logs' => $logs, 'action' => $this->action];
        $this->logOffset = count($this->state->logs);
    }

    public function result(): array
    {
        $this->capture();

        return ['version' => 1, 'initial' => $this->initial, 'frames' => $this->frames];
    }

    private function side(BattleActor $actor): string
    {
        return $actor === $this->state->player ? 'player' : 'enemy';
    }

    private function snapshot(): array
    {
        $snapshots = [];
        $labels = ['poison' => '毒', 'burn' => '火傷', 'bleed' => '出血', 'paralysis' => '麻痺', 'stun' => '気絶', 'sleep' => '睡眠', 'confusion' => '混乱', 'blind' => '暗闇', 'silence' => '沈黙', 'def_down' => '防御低下', 'spr_down' => '精神低下', 'slow' => '鈍足', 'recovery_block' => '回復阻害'];
        foreach (['player' => $this->state->player, 'enemy' => $this->state->enemy] as $side => $actor) {
            $badges = [];
            foreach ($actor->conditions as $key => $condition) {
                $turns = (int) (is_array($condition) ? ($condition['turns'] ?? 0) : $condition);
                if ($turns > 0 && isset($labels[$key])) {
                    $badges[] = $labels[$key].' '.$turns.'T';
                }
            }
            if ($actor->isDefending) {
                $badges[] = '防御';
            }
            if ($guard = $actor->jobArtV2GuardState()) {
                $badges[] = '軽減 '.$guard->charges.'回';
            }
            if ($counter = $actor->counterStanceState()) {
                $badges[] = '反撃構え '.$counter->remainingRounds.'T';
            }
            if ($actor->hasPiercingStance()) {
                $badges[] = '貫通構え';
            }
            if ($break = $actor->breakDebuffState()) {
                $badges[] = '防御崩し '.$break->remainingRounds.'T';
            }
            $statLabels = ['str' => '攻撃', 'def' => '防御', 'mag' => '魔力', 'spr' => '精神', 'agi' => '敏捷', 'luk' => '運'];
            foreach ($actor->jobArtV2TimedEffects() as $effect) {
                foreach ($effect->statModifiers as $stat => $modifier) {
                    if (isset($statLabels[$stat]) && $modifier != 0 && $effect->remainingRounds > 0) {
                        $badges[] = $statLabels[$stat].($modifier > 0 ? '↑ ' : '↓ ').$effect->remainingRounds.'T';
                    }
                }
            }
            $badges = array_values(array_unique($badges));
            $resources = [];
            foreach ($this->resources[$side] as $resource) {
                $resources[] = ['name' => (string) $resource['resource_name'], 'points' => $actor->getResource((string) $resource['resource_key'])];
            }
            $snapshots[$side] = ['name' => $actor->name, 'hp' => $actor->hp, 'maxHp' => $actor->maxHp, 'sp' => $actor->mp, 'maxSp' => $actor->maxMp, 'resources' => $resources, 'badges' => $badges];
        }

        return $snapshots;
    }
}
