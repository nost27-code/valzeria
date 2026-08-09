<?php

namespace Tests\Support;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

final class JobArtV2BattleTrace
{
    /** @var list<string> */
    private array $lines = [];

    /** @return array<string, mixed> */
    public function snapshot(BattleActor $actor, BattleActor $target, BattleState $state): array
    {
        $resourceKey = $actor->currentJobId === 62 ? 'dragon_force' : 'star_mark';

        return [
            'resource_key' => $resourceKey,
            'resource' => $actor->getResource($resourceKey),
            'sp' => $actor->mp,
            'hp' => $actor->hp,
            'target_hp' => $target->hp,
            'use_counts' => $state->jobArtUseCounts,
            'log_count' => count($state->logs),
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<int, Skill> $arts
     */
    public function capture(
        int $turn,
        BattleActor $actor,
        BattleActor $target,
        BattleState $state,
        array $before,
        array $arts,
    ): string {
        $usedSkillId = null;
        foreach ($state->jobArtUseCounts as $key => $count) {
            if ((int) $count > (int) ($before['use_counts'][$key] ?? 0)) {
                $parts = explode(':', (string) $key);
                $usedSkillId = (int) end($parts);
                break;
            }
        }
        $skill = null;
        foreach ($arts as $candidate) {
            if ((int) $candidate->id === $usedSkillId) {
                $skill = $candidate;
                break;
            }
        }

        $newLogs = array_slice($state->logs, (int) $before['log_count']);
        $joinedLogs = implode("\n", $newLogs);
        $hit = $skill === null
            ? 'N/A'
            : (str_contains($joinedLogs, '回避された')
                ? 'EVADE'
                : (str_contains($joinedLogs, '外れた')
                    ? 'MISS'
                    : ($this->dealsDamage($skill) ? 'HIT' : 'SUPPORT')));
        $reason = $skill !== null
            ? ($hit === 'MISS' ? 'hit_miss' : ($hit === 'EVADE' ? 'active_evade' : 'activated'))
            : 'legacy_fallback';
        $field = $state->primaryField();
        $overlay = $state->fieldOverlay();
        $resourceKey = (string) $before['resource_key'];
        $line = sprintf(
            'T%02d action=%s resource=%s:%d->%d field=%s overlay=%s stance=%s hit=%s SP=%d->%d damage=%d heal=%d reason=%s',
            $turn,
            $skill?->name ?? 'normal_attack',
            $resourceKey,
            (int) $before['resource'],
            $actor->getResource($resourceKey),
            $field !== null ? "{$field->key}/{$field->remainingRounds}" : '-',
            $overlay !== null ? "{$overlay->key}/{$overlay->remainingRounds}" : '-',
            $actor->hasPiercingStance() ? 'on' : 'off',
            $hit,
            (int) $before['sp'],
            $actor->mp,
            max(0, (int) $before['target_hp'] - $target->hp),
            max(0, $actor->hp - (int) $before['hp']),
            $reason,
        );
        $this->lines[] = $line;

        return $line;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    private function dealsDamage(Skill $skill): bool
    {
        return in_array((string) $skill->effect_template, [
            'MAGICAL_DAMAGE',
            'PHYSICAL_DAMAGE',
            'MAGICAL_DAMAGE_BUFF',
            'DAMAGE_BUFF',
            'DAMAGE_DEBUFF',
            'MULTI_HIT',
            'HYBRID_DAMAGE',
            'DAMAGE_GUARD_BARRIER',
            'PHYSICAL_DAMAGE_REWARD',
            'PHYSICAL_DAMAGE_GOLD_REWARD',
            'MAGICAL_DAMAGE_REWARD',
            'DRAIN',
        ], true);
    }
}
