<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleResult;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\HitResult;

class TrainingGroundBattleService extends BattleService
{
    private const DEFAULT_MAX_TURNS = 50;
    private const DEFAULT_INCOMING_DAMAGE_CAP_PERCENT = 1.0;

    private bool $training = false;
    private int $incomingDamageCap = 1;

    /**
     * @return array{context:string,context_label:string,damage_cap:int,result:BattleResult}
     */
    public function practice(Character $character, string $context): array
    {
        $context = $context === 'boss' ? 'boss' : 'pve';
        $stats = $this->statusService->getFinalStats($character);
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base));
        $maxMp = max(0, (int) ($stats['max_mp'] ?? $character->mp_base));
        $maxTurns = $this->maxTurns();
        $damageCapPercent = $this->incomingDamageCapPercent();
        $this->incomingDamageCap = max(1, (int) floor($maxHp * ($damageCapPercent / 100)));

        $enemy = $this->trainingEnemy($character, $stats, $context);
        $this->training = true;

        try {
            $result = parent::executeBattle($character, $enemy, 0, [
                'persist_character_state' => false,
                'rewards_enabled' => false,
                'exploration_support_enabled' => false,
                'auto_unequip_invalid_items' => false,
                'starting_hp' => $maxHp,
                'starting_mp' => $maxMp,
                'job_art_context' => $context,
                // 通常探索／ボスの予測が目的なので、非永続でも出力予算Kは付けない。
                'sp_output_budget_enabled' => false,
                'max_turns' => $maxTurns,
            ]);
        } finally {
            $this->training = false;
        }

        $result->result = 'training_complete';
        if ($result->logs !== []) {
            array_pop($result->logs);
        }
        $result->logs[] = '<br><span class="text-sky-800 font-extrabold text-xl">'
            .number_format($maxTurns).'ターンの模擬訓練を終えた！</span>';

        return [
            'context' => $context,
            'context_label' => $context === 'boss' ? 'ボス戦用セット' : '通常戦用セット',
            'damage_cap' => $this->incomingDamageCap,
            'damage_cap_percent' => $damageCapPercent,
            'max_turns' => $maxTurns,
            'result' => $result,
        ];
    }

    public function maxTurns(): int
    {
        return max(1, (int) config('training_ground.max_turns', self::DEFAULT_MAX_TURNS));
    }

    public function incomingDamageCapPercent(): float
    {
        return max(0.01, (float) config(
            'training_ground.incoming_damage_cap_percent',
            self::DEFAULT_INCOMING_DAMAGE_CAP_PERCENT,
        ));
    }

    protected function executeEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        if (! $this->training) {
            parent::executeEnemyAction($attacker, $defender, $state);

            return;
        }

        // 訓練人形は受け流し・防御の確認に向く、単発の直接物理攻撃だけを行う。
        $this->executePhysicalAttack($attacker, $defender, $state, 100, null, false);
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
        if ($this->training && $source !== null && ! $source->isPlayer && $target->isPlayer && $isDirect) {
            $damage = min($damage, $this->incomingDamageCap);
        }

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

        // 模擬戦では双方をHP1で踏みとどまらせ、必ず50ターン観察できるようにする。
        if ($this->training) {
            foreach ([$state->player, $state->enemy] as $actor) {
                if ($actor->isDead()) {
                    $actor->hp = 1;
                }
            }
        }

        return $result;
    }

    /** @param array<string, int|float> $stats */
    private function trainingEnemy(Character $character, array $stats, string $context): Enemy
    {
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base));
        $def = max(1, (int) ($stats['def'] ?? $character->defense_base));
        $spr = max(1, (int) ($stats['spr'] ?? $character->spirit_base));
        $agi = max(1, (int) ($stats['agi'] ?? $character->speed_base));
        $targetDamage = max(1, (int) floor($this->incomingDamageCap / 1.15));
        $attack = $this->trainingAttackFor($targetDamage, $def);

        $enemy = new Enemy([
            'name' => $context === 'boss' ? '対ボス訓練人形' : '訓練人形',
            'level' => max(1, (int) $character->level),
            'max_hp' => max(1, $maxHp * $this->maxTurns()),
            'max_mp' => 0,
            'str' => $attack,
            'def' => $def,
            'agi' => $agi,
            'mag' => 1,
            'spr' => $spr,
            'luk' => 1,
            'is_boss' => $context === 'boss',
            'role_key' => $context === 'boss' ? 'boss' : 'normal',
            'family_key' => 'standard',
            'type_name' => '標準型',
            'normal_attack_type' => 'physical',
            'exp_reward' => 0,
            'job_exp_reward' => 0,
            'gold_reward' => 0,
            'appearance_weight' => 0,
            'skip_danger_bonus' => true,
            'skip_durability_bonus' => true,
        ]);
        $enemy->setRelation('actions', collect());
        $enemy->setRelation('area', null);

        return $enemy;
    }

    private function trainingAttackFor(int $targetDamage, int $defense): int
    {
        if (! (bool) config('battle.pve_enemy_percentage_defense.enabled', true)) {
            return max(1, (int) ceil(($defense / 2) + $targetDamage));
        }

        $coefficient = max(0.0, (float) config('battle.pve_enemy_percentage_defense.defense_coefficient', 3.5));
        $discriminant = ($targetDamage * $targetDamage)
            + (4 * $targetDamage * $coefficient * $defense);

        return max(1, (int) ceil(($targetDamage + sqrt($discriminant)) / 2));
    }
}
