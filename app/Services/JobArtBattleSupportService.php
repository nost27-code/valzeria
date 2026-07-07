<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;

class JobArtBattleSupportService
{
    public function __construct(
        private readonly JobArtService $jobArtService
    ) {
    }

    public function attachBossSet(BattleActor $actor, Character $character, string $context = 'champ'): void
    {
        $actor->jobArtActivationPolicy = (string) ($character->job_art_activation_policy ?: 'normal');
        $jobArts = $this->jobArtService->battleArtsFor($character, $context);
        $actor->jobArts = $jobArts->all();

        foreach ($jobArts as $art) {
            $actor->jobArtRates[(int) $art->id] = (float) $art->getAttribute('job_art_rate');
            $actor->jobArtOrigins[(int) $art->id] = (string) $art->getAttribute('job_art_origin');
            $actor->jobArtPolicies[(int) $art->id] = (string) ($art->getAttribute('job_art_activation_policy') ?: $actor->jobArtActivationPolicy);
        }
    }

    public function tickCooldowns(BattleState $state, BattleActor $actor): void
    {
        $prefix = $this->actorStatePrefix($actor);
        foreach ($state->jobArtCooldowns as $key => $remaining) {
            if (!str_starts_with((string) $key, $prefix)) {
                continue;
            }

            $remaining = max(0, (int) $remaining - 1);
            if ($remaining <= 0) {
                unset($state->jobArtCooldowns[$key]);
            } else {
                $state->jobArtCooldowns[$key] = $remaining;
            }
        }
    }

    public function selectForTurn(BattleActor $actor, BattleState $state): ?Skill
    {
        foreach ($actor->jobArts as $art) {
            if (!$art instanceof Skill) {
                continue;
            }

            $stateKey = $this->actorSkillStateKey($actor, $art);
            if (($state->jobArtCooldowns[$stateKey] ?? 0) > 0) {
                continue;
            }

            if ($art->max_uses_per_battle !== null
                && ($state->jobArtUseCounts[$stateKey] ?? 0) >= (int) $art->max_uses_per_battle
            ) {
                continue;
            }

            $spCost = $this->spCost($actor, $art);
            $policy = (string) ($actor->jobArtPolicies[(int) $art->id] ?? $actor->jobArtActivationPolicy);
            if (!$this->canActivateByPolicy($actor, $spCost, $policy)) {
                continue;
            }

            if (!$this->canActivateRecoveryArt($actor, $art)) {
                continue;
            }

            if (random_int(1, 100) <= $art->effectiveActivationRate()) {
                return $art;
            }
        }

        return null;
    }

    public function spCost(BattleActor $actor, Skill $skill): int
    {
        $origin = (string) ($actor->jobArtOrigins[(int) $skill->id] ?? 'current');

        return $skill->jobArtSpCostForMaxSp($actor->maxMp, $origin);
    }

    public function consumeAndMarkUse(BattleActor $actor, BattleState $state, Skill $skill): void
    {
        $stateKey = $this->actorSkillStateKey($actor, $skill);
        $actor->mp -= $this->spCost($actor, $skill);
        $state->jobArtUseCounts[$stateKey] = (int) ($state->jobArtUseCounts[$stateKey] ?? 0) + 1;

        if ((int) $skill->cooldown_turns > 0) {
            $state->jobArtCooldowns[$stateKey] = (int) $skill->cooldown_turns;
        }
    }

    public function skillForExecution(BattleActor $actor, Skill $skill): Skill
    {
        $rate = (float) ($actor->jobArtRates[(int) $skill->id] ?? 1.0);
        $executionSkill = clone $skill;
        $power = max(0, (int) round(((int) $skill->power ?: 100) * $rate));
        $executionSkill->power = $power;
        $executionSkill->power_multiplier = max(0, $power / 100);

        return $executionSkill;
    }

    public function activationLog(BattleActor $attacker, BattleActor $defender, Skill $skill): string
    {
        $origin = (string) ($attacker->jobArtOrigins[(int) $skill->id] ?? 'current');
        $prefix = $origin === 'inherited' ? '継承奥義' : '奥義';
        $lines = [
            "<span class=\"text-indigo-700 font-extrabold\">【{$prefix}】" . e($skill->name) . " が発動！</span>",
        ];

        $phrase = trim((string) ($skill->activation_phrase ?? ''));
        if ($phrase !== '') {
            $lines[] = '<span class="text-slate-700 font-bold">' . e($this->formatFlavorText($phrase, $attacker, $defender, $skill)) . '</span>';
        }

        $description = trim((string) ($skill->activation_description ?? ''));
        if ($description !== '') {
            $lines[] = '<span class="text-indigo-800 font-bold">' . e($this->formatFlavorText($description, $attacker, $defender, $skill)) . '</span>';
        }

        return implode('<br>', $lines);
    }

    private function canActivateByPolicy(BattleActor $actor, int $spCost, string $policy): bool
    {
        if ($actor->mp < $spCost) {
            return false;
        }

        $spRate = $actor->maxMp > 0 ? $actor->mp / $actor->maxMp : 0.0;

        return match ($policy) {
            'aggressive' => true,
            'conserve' => $spRate >= 0.60,
            default => $spRate >= 0.30,
        };
    }

    private function canActivateRecoveryArt(BattleActor $actor, Skill $skill): bool
    {
        $needsHp = $skill->isHealArt()
            || in_array((string) $skill->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)
            || ((string) $skill->effect_template === 'DRAIN' && (float) $skill->drain_hp_rate > 0)
            || (int) $skill->heal_percent > 0;
        $needsSp = (int) $skill->mp_recover_percent > 0;
        if ($needsHp || $needsSp) {
            return ($needsHp && $this->hasMissingHp($actor))
                || ($needsSp && $this->hasMissingSp($actor));
        }

        return true;
    }

    private function hasMissingHp(BattleActor $actor): bool
    {
        return $actor->maxHp > 0 && $actor->hp < $actor->maxHp;
    }

    private function hasMissingSp(BattleActor $actor): bool
    {
        return $actor->maxMp > 0 && $actor->mp < $actor->maxMp;
    }

    private function actorSkillStateKey(BattleActor $actor, Skill $skill): string
    {
        return $this->actorStatePrefix($actor) . (int) $skill->id;
    }

    private function actorStatePrefix(BattleActor $actor): string
    {
        return spl_object_id($actor) . ':';
    }

    private function formatFlavorText(string $text, BattleActor $attacker, BattleActor $defender, Skill $skill): string
    {
        return strtr($text, [
            '{user}' => $attacker->name,
            '{target}' => $defender->name,
            '{skill}' => (string) $skill->name,
        ]);
    }
}
