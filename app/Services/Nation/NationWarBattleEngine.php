<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Enemy;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleResult;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\BattleService;

final class NationWarBattleEngine extends BattleService
{
    private bool $nationBattle = false;
    private bool $cannonOperational = false;
    private int $cannonLevel = 1;
    private int $retreatLine = 20;
    private int $cannonHits = 0;
    private bool $directHit = false;
    private bool $died = false;
    private bool $retreated = false;

    /** @return array{result:BattleResult,cannon_hits:int,direct_hit:bool,died:bool,retreated:bool} */
    public function fight(Character $character, int $targetHp, bool $cannonOperational, int $cannonLevel, int $retreatLine): array
    {
        $stats = $this->statusService->getFinalStats($character);
        $enemy = new Enemy([
            'name' => '敵国要塞', 'level' => max(1, (int) $character->level), 'max_hp' => max(1, $targetHp), 'max_mp' => 0,
            'str' => 1, 'def' => 0, 'agi' => 1, 'mag' => 1, 'spr' => 0, 'luk' => 1,
            'is_boss' => false, 'role_key' => 'nation_fortress', 'family_key' => 'standard', 'type_name' => '標準型',
            'normal_attack_type' => 'physical', 'exp_reward' => 0, 'job_exp_reward' => 0, 'gold_reward' => 0,
            'appearance_weight' => 0, 'skip_danger_bonus' => true, 'skip_durability_bonus' => true, 'force_zero_defense' => true,
        ]);
        $enemy->setRelation('actions', collect()); $enemy->setRelation('area', null);
        $this->nationBattle = true; $this->cannonOperational = $cannonOperational; $this->cannonLevel = $cannonLevel;
        $this->retreatLine = in_array($retreatLine, [0,12,20,30], true) ? $retreatLine : 20;
        $this->cannonHits = 0; $this->directHit = false; $this->died = false; $this->retreated = false;
        try {
            $result = parent::executeBattle($character, $enemy, 0, [
                'persist_character_state' => false, 'rewards_enabled' => false, 'exploration_support_enabled' => false,
                'auto_unequip_invalid_items' => false, 'starting_hp' => max(1, (int) $stats['max_hp']),
                'starting_mp' => max(0, (int) $stats['max_mp']), 'job_art_context' => 'pve',
                'max_turns' => app(NationWarSettingsService::class)->maxTurns(), 'force_player_first' => true,
            ]);
        } finally { $this->nationBattle = false; }
        return ['result' => $result, 'cannon_hits' => $this->cannonHits, 'direct_hit' => $this->directHit, 'died' => $this->died, 'retreated' => $this->retreated];
    }

    protected function executeEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        if (! $this->nationBattle) { parent::executeEnemyAction($attacker, $defender, $state); return; }
        if ($this->cannonOperational && app(NationWarCannonService::class)->firesOnTurn($this->cannonLevel, $state->turnCount)) {
            $shot = app(NationWarCannonService::class)->fire($defender, $this->cannonLevel);
            $this->cannonHits++; $this->directHit = $this->directHit || $shot['direct_hit'];
            $this->applyResolvedDamage($attacker, $defender, $state, $shot['damage'], DamageSourceType::OTHER, 'nation_magic_cannon');
            $direct = $shot['direct_hit'] ? '<span class="text-orange-600 font-black">【直撃】</span>' : '';
            $state->addDamageLog("魔導砲が火を噴いた！ {$direct} {$defender->name}に <span class=\"text-red-600 font-extrabold\">{$shot['damage']}</span> のダメージ！");
        }
        if ($defender->isDead()) { $this->died = true; return; }
        if ($this->retreatLine > 0 && $defender->hp <= (int) floor($defender->maxHp * ($this->retreatLine / 100))) {
            $this->retreated = true; $state->maxTurns = $state->turnCount;
            $state->addLog('<span class="text-sky-700 font-black">撤退ラインに達したため要塞から離脱した！</span>');
        }
    }
}
