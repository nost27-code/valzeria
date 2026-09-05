<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;
use App\Services\Battle\ScopedBattleRandomizer;
use App\Services\BattleService;
use App\Services\JobArtV2DeckRoleResolution;
use App\Services\Nation\Raid\NationRaidRules;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 現行boss戦のplayer行動だけを20ターン採取する、DB writeなしのPhase 2 probe。
 *
 * レイド予告を既存BattleServiceへ注入していないため、火力・発動順の基準profileには
 * 使えるが、11戦技のraid固有応答まで正確に再現するものではない。したがって
 * authoritativeForBalanceGate() はfalseとし、結果だけでbalance PASSにしない。
 */
class NationRaidPassiveBossActionProfileProvider extends BattleService implements NationRaidSimulationActionProfileProvider
{
    /** @var array<int, list<array{kind:string,damage:int,hit_count:int,defense_ignore_50_damage:?int}>> */
    private array $sourcesByTurn = [];

    /** @var array<int, array<string, mixed>> */
    private array $actionsByTurn = [];

    private ?string $selectedCounterplayIdentity = null;

    /** @return list<array{profile_no:int,actions:list<array<string, mixed>>}> */
    public function profilesFor(Character $character, int $profileCount): array
    {
        $profileCount = max(1, min(25, $profileCount));
        $profiles = [];

        for ($profileNo = 1; $profileNo <= $profileCount; $profileNo++) {
            $this->sourcesByTurn = [];
            $this->actionsByTurn = [];
            $this->selectedCounterplayIdentity = null;

            // 現行戦闘のrand()相当だけをprofile-localな乱数列へ隔離する。
            // random_int()を含むため、再現の正本は生成後に保存する匿名snapshotそのものとする。
            $randomizer = new Randomizer(
                new Mt19937($this->profileSeed((int) $character->getKey(), $profileNo)),
            );
            $this->useScopedBattleRandomizer($randomizer);

            $stats = $this->statusService->getFinalStats($character);
            ScopedBattleRandomizer::run($randomizer, fn () => $this->executeBattle(
                $character,
                $this->passiveBoss(),
                0,
                [
                    'persist_character_state' => false,
                    'rewards_enabled' => false,
                    'exploration_support_enabled' => false,
                    'auto_unequip_invalid_items' => false,
                    'starting_hp' => (int) $stats['max_hp'],
                    'starting_mp' => (int) ($stats['max_mp'] ?? 0),
                    'job_art_context' => 'boss',
                    'max_turns' => NationRaidRules::MAX_TURNS,
                    'force_player_first' => true,
                ],
            ));

            $actions = [];
            foreach (range(1, NationRaidRules::MAX_TURNS) as $turn) {
                $actions[] = $this->actionsByTurn[$turn] ?? $this->emptyAction($turn);
            }
            $profiles[] = ['profile_no' => $profileNo, 'actions' => $actions];
        }

        return $profiles;
    }

    public function modelVersion(): string
    {
        return 'current-boss-passive-probe-v4-valgreid-dragon-killer';
    }

    public function authoritativeForBalanceGate(): bool
    {
        return false;
    }

    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        if (! $attacker->isPlayer) {
            parent::executeAction($attacker, $defender, $state);

            return;
        }

        $turn = $state->turnCount;
        $state->valmonAssistRolled = true;
        $marks = [
            'hunting' => $this->jobArtV2ProgressionService->huntingMarkCountFor($defender, $attacker),
            'break' => $this->jobArtV2ProgressionService->breakMarkCountFor($defender, $attacker),
        ];
        $before = $this->debuffStateKeys($defender);

        parent::executeAction($attacker, $defender, $state);

        $after = $this->debuffStateKeys($defender);
        $sources = array_values($this->sourcesByTurn[$turn] ?? []);
        $this->actionsByTurn[$turn] = [
            'turn' => $turn,
            'damage_sources' => $sources,
            'selected_counterplay_identity' => $this->selectedCounterplayIdentity,
            'boss_debuff_keys_applied' => array_values(array_diff($after, $before)),
            'counterplay_hit' => array_sum(array_column($sources, 'damage')) > 0,
            'hunting_mark_count' => $marks['hunting'],
            'break_mark_count' => $marks['break'],
        ];
    }

    protected function executeEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        // Phase 2 action profileはplayer側の現行20手だけを採取する。敵damageはPhase 1 engineが解決する。
    }

    protected function selectJobArtForAction(BattleActor $attacker, BattleState $state): ?Skill
    {
        $skill = parent::selectJobArtForAction($attacker, $state);
        $this->selectedCounterplayIdentity = null;
        if ($skill instanceof Skill) {
            $identity = JobArtV2DeckRoleResolution::artKey($skill);
            if (app(NationRaidRules::class)->counterplayArt($identity) !== null) {
                $this->selectedCounterplayIdentity = $identity;
            }
        }

        return $skill;
    }

    protected function applyResolvedDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
        ?HitResult $hitResult = null,
        int $hitIndex = 1,
        int $hitCount = 1,
        bool $isDirect = false,
        ?string $damageCategory = null,
    ): ?DamageApplicationResult {
        $result = parent::applyResolvedDamage(
            $source,
            $target,
            $state,
            $damage,
            $sourceType,
            $sourceId,
            $hitResult,
            $hitIndex,
            $hitCount,
            $isDirect,
            $damageCategory,
        );

        if ($source !== $state->player || $target !== $state->enemy || $damage <= 0) {
            return $result;
        }

        $resolvedDamage = max(0, (int) ($result?->requestedDamage ?? $damage));
        $kind = $this->raidDamageKind($sourceType);
        $groupKey = implode('|', [
            $kind,
            $sourceType->value,
            (string) ($sourceId ?? 'none'),
            (string) ($state->currentSourceActionId() ?? 0),
            (string) ($damageCategory ?? 'none'),
        ]);
        $turn = $state->turnCount;
        $existing = $this->sourcesByTurn[$turn][$groupKey] ?? [
            'kind' => $kind,
            'damage' => 0,
            'hit_count' => max(1, $hitCount),
            'defense_ignore_50_damage' => 0,
        ];
        $existing['damage'] += $resolvedDamage;
        $existing['hit_count'] = max($existing['hit_count'], $hitCount);
        $existing['defense_ignore_50_damage'] += $this->defenseIgnoreDamage(
            $resolvedDamage,
            $source,
            $target,
            $damageCategory,
            $kind,
        );
        $this->sourcesByTurn[$turn][$groupKey] = $existing;

        return $result;
    }

    private function passiveBoss(): Enemy
    {
        $enemy = new Enemy([
            'name' => 'Phase 2 Passive Raid Probe',
            'species_key' => NationRaidRules::BOSS_SPECIES_KEY,
            'level' => 1,
            'max_hp' => 2_000_000_000,
            'max_mp' => NationRaidRules::BOSS_MAX_SP,
            'str' => 1,
            'def' => NationRaidRules::BOSS_DEFENSE,
            'agi' => 1,
            'mag' => 1,
            'spr' => NationRaidRules::BOSS_SPIRIT,
            'luk' => 1,
            'is_boss' => true,
            'normal_attack_type' => 'physical',
            'skip_danger_bonus' => true,
            'skip_durability_bonus' => true,
            'exp_reward' => 0,
            'gold_reward' => 0,
            'job_exp_reward' => 0,
        ]);
        $enemy->setRelation('actions', collect());
        $enemy->setRelation('area', null);

        return $enemy;
    }

    /** @return list<string> */
    private function debuffStateKeys(BattleActor $actor): array
    {
        $keys = [];
        foreach ($actor->conditions as $key => $value) {
            if ($value !== null && $value !== false && $value !== 0) {
                $keys[] = 'condition:'.(string) $key;
            }
        }
        foreach ($actor->jobArtV2TimedEffects() as $effect) {
            if (! $effect->isExpired() && array_filter(
                $effect->statModifiers,
                static fn (mixed $rate): bool => (float) $rate < 0,
            ) !== []) {
                $keys[] = 'timed:'.$effect->key;
            }
        }
        if ($actor->breakDebuffState() !== null) {
            $keys[] = 'break_debuff';
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    private function raidDamageKind(DamageSourceType $sourceType): string
    {
        return match ($sourceType) {
            DamageSourceType::DOT => NationRaidRules::DAMAGE_DOT,
            DamageSourceType::COUNTER => NationRaidRules::DAMAGE_COUNTER,
            DamageSourceType::NORMAL_ATTACK, DamageSourceType::JOB_SKILL, DamageSourceType::JOB_ART => NationRaidRules::DAMAGE_DIRECT,
            default => NationRaidRules::DAMAGE_SIMULTANEOUS,
        };
    }

    private function defenseIgnoreDamage(
        int $damage,
        BattleActor $source,
        BattleActor $target,
        ?string $damageCategory,
        string $kind,
    ): int {
        if (! in_array($kind, [NationRaidRules::DAMAGE_DIRECT, NationRaidRules::DAMAGE_SIMULTANEOUS], true)
            || ! in_array($damageCategory, ['physical', 'magical'], true)) {
            return $damage;
        }

        $attack = $damageCategory === 'magical' ? $source->effectiveMag() : $source->effectiveStr();
        $defense = $damageCategory === 'magical' ? $target->effectiveSpr() : $target->effectiveDef();
        $normalDenominator = $attack + (NationRaidRules::DEFENSE_COEFFICIENT * $defense);
        $ignoredDenominator = $attack + (NationRaidRules::DEFENSE_COEFFICIENT * $defense * 0.50);
        if ($normalDenominator <= 0 || $ignoredDenominator <= 0) {
            return $damage;
        }

        return max($damage, (int) floor($damage * $normalDenominator / $ignoredDenominator));
    }

    /** @return array<string, mixed> */
    private function emptyAction(int $turn): array
    {
        return [
            'turn' => $turn,
            'damage_sources' => [],
            'selected_counterplay_identity' => null,
            'boss_debuff_keys_applied' => [],
            'counterplay_hit' => false,
            'hunting_mark_count' => 0,
            'break_mark_count' => 0,
        ];
    }

    private function profileSeed(int $characterId, int $profileNo): int
    {
        return (int) (hexdec(substr(hash('sha256', "raid-profile|{$characterId}|{$profileNo}"), 0, 7)) ?: 1);
    }
}
