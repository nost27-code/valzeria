<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\ChampBattleLog;
use App\Models\ChampHistory;
use App\Models\ChampState;
use App\Models\Enemy;
use App\Models\Material;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleTypeAffinity;
use App\Services\Battle\DamageCalculator;
use App\Support\CharacterIconCatalog;
use Illuminate\Support\Facades\DB;

class ChampBattleService
{
    private const MATERIAL_CODE = 'MAT_CHAMP_CHALLENGER_FRAGMENT';
    private const MAX_TURNS = 20;
    private const PVP_HIT_AGI_FACTOR = 0.15;
    private const PVP_MIN_HIT_RATE = 75;
    private const PVP_MAX_HIT_RATE = 98;

    public function __construct(
        private CharacterStatusService $statusService,
        private DamageCalculator $damageCalculator,
        private LevelService $levelService,
    ) {
    }

    public function summary(?Character $viewer = null): array
    {
        $champ = $this->ensureChamp();
        $champ->icon_path = $this->currentChampIconPath($champ);
        $availability = $viewer ? $this->challengeAvailability($viewer, $champ) : [
            'can_challenge' => false,
            'reason' => null,
            'cooldown_until' => null,
            'cooldown_remaining_seconds' => 0,
        ];

        $maxHp = max(1, (int) $champ->max_hp);
        $currentHp = max(0, (int) $champ->current_hp);

        return [
            'champ' => $champ,
            'hp_percent' => min(100, (int) floor(($currentHp / $maxHp) * 100)),
            'is_self' => $viewer && $champ->character_id === $viewer->id,
            'can_challenge' => $availability['can_challenge'],
            'reason' => $availability['reason'],
            'cooldown_until' => $availability['cooldown_until'],
            'cooldown_remaining_seconds' => $availability['cooldown_remaining_seconds'],
            'recent_logs' => $this->recentLogs(5),
        ];
    }

    public function challengeAvailability(Character $character, ?ChampState $champ = null): array
    {
        $champ ??= $this->ensureChamp();

        if ($champ->character_id === $character->id) {
            return [
                'can_challenge' => false,
                'reason' => 'あなたは現在のチャンプです',
                'cooldown_until' => null,
                'cooldown_remaining_seconds' => 0,
            ];
        }

        $cooldownSeconds = app(CooldownSettingService::class)->champBattleSeconds();
        $cooldownUntil = $character->last_champ_battle_at && $cooldownSeconds > 0
            ? $character->last_champ_battle_at->copy()->addSeconds($cooldownSeconds)
            : null;

        if ($cooldownUntil && now()->lt($cooldownUntil)) {
            return [
                'can_challenge' => false,
                'reason' => '再挑戦まで待機中です',
                'cooldown_until' => $cooldownUntil,
                'cooldown_remaining_seconds' => (int) ceil(now()->diffInSeconds($cooldownUntil, false)),
            ];
        }

        return [
            'can_challenge' => true,
            'reason' => null,
            'cooldown_until' => null,
            'cooldown_remaining_seconds' => 0,
        ];
    }

    public function executeChallenge(Character $challenger): array
    {
        return DB::transaction(function () use ($challenger) {
            $challenger = Character::query()
                ->with('jobClass')
                ->lockForUpdate()
                ->findOrFail($challenger->id);

            $champ = ChampState::query()->lockForUpdate()->first() ?? $this->createInitialChamp();
            $availability = $this->challengeAvailability($challenger, $champ);
            if (!$availability['can_challenge']) {
                return [
                    'ok' => false,
                    'message' => $availability['reason'] ?? '今は挑戦できません。',
                    'cooldown_until' => $availability['cooldown_until'],
                ];
            }

            $champHpBefore = max(0, (int) $champ->current_hp);
            $oldChamp = [
                'character_id' => $champ->character_id,
                'player_name' => $champ->player_name,
                'icon_path' => $this->currentChampIconPath($champ),
                'level' => (int) $champ->level,
                'job_name' => $champ->job_name ?? '冒険者',
                'job_rank' => (int) $champ->job_rank,
                'current_hp' => $champHpBefore,
                'max_hp' => (int) $champ->max_hp,
                'current_mp' => (int) ($champ->current_mp ?? 0),
                'max_mp' => (int) ($champ->max_mp ?? 0),
                'weapon_name' => $champ->weapon_name,
                'armor_name' => $champ->armor_name,
                'accessory_name' => $champ->accessory_name,
            ];
            $challengerActor = $this->resultActorSnapshot($challenger);
            $battle = $this->runBattle($challenger, $champ);
            $damage = min($champHpBefore, max(0, (int) $battle['damage']));
            $champDefeated = $damage >= $champHpBefore;
            $champHpAfter = $champDefeated ? 0 : max(0, $champHpBefore - $damage);

            $baseExp = $this->baseExpReward($challenger);
            $levelGap = max(0, (int) $champ->level - (int) $challenger->level);
            $gapMultiplier = $this->champLevelGapExpMultiplier($levelGap);
            $expGained = $this->champExpReward($baseExp, $champDefeated, $gapMultiplier);
            $jobExpGained = $this->champJobExpReward($champDefeated, $levelGap);
            $gapRewardNote = $levelGap > 0
                ? "格上チャンプ挑戦ボーナス Lv差{$levelGap} / EXP倍率x" . number_format($gapMultiplier, 1)
                : null;
            $materialReward = $this->materialReward($challenger);
            $material = $materialReward['material'];
            $materialQuantity = $materialReward['quantity'];

            $levelResult = $this->levelService->addRewardAndCheckLevelUp($challenger, $expGained, 0, $jobExpGained);
            $challenger->refresh();
            $this->grantMaterial($challenger, $material, $materialQuantity);
            if ($gapRewardNote) {
                $battle['log'][] = "<br><span class=\"text-indigo-700 font-bold\">【経験】{$gapRewardNote} が発生した！</span>";
            }
            $materialName = $material->displayName();
            $battle['log'][] = "<br><span class=\"text-green-600 font-bold\">【素材獲得】{$materialName} x{$materialQuantity} を手に入れた！</span>";

            if ($champDefeated) {
                ChampHistory::create([
                    'character_id' => $champ->character_id,
                    'player_name' => $champ->player_name,
                    'job_name' => $champ->job_name,
                    'job_rank' => $champ->job_rank,
                    'level' => $champ->level,
                    'max_hp' => $champ->max_hp,
                    'defense_count' => $champ->defense_count,
                    'appointed_at' => $champ->appointed_at,
                    'defeated_at' => now(),
                    'defeated_by_character_id' => $challenger->id,
                    'defeated_by_player_name' => $challenger->name,
                ]);

                $this->appointNewChamp($champ, $challenger);
                $battle['log'][] = "<span class=\"text-amber-700 font-bold\">【チャンプ交代】{$challenger->name}が新しいチャンプになった！</span>";
            } else {
                $champ->current_hp = $champHpAfter;
                $champ->defense_count = (int) $champ->defense_count + 1;
                $champ->save();
            }

            $log = ChampBattleLog::create([
                'champ_character_id' => $oldChamp['character_id'],
                'champ_player_name' => $oldChamp['player_name'],
                'challenger_character_id' => $challenger->id,
                'challenger_player_name' => $challenger->name,
                'damage' => $damage,
                'is_champ_defeated' => $champDefeated,
                'champ_hp_before' => $champHpBefore,
                'champ_hp_after' => $champHpAfter,
                'exp_gained' => $expGained,
                'job_exp_gained' => $jobExpGained,
                'material_id' => $material->id,
                'material_name' => $materialName,
                'material_quantity' => $materialQuantity,
            ]);

            $challenger->last_champ_battle_at = now();
            $challenger->save();

            return [
                'ok' => true,
                'champ_defeated' => $champDefeated,
                'damage' => $damage,
                'turns' => $battle['turns'],
                'battle_log' => $battle['log'],
                'champ_before_name' => $oldChamp['player_name'],
                'champ_after_name' => $champDefeated ? $challenger->name : $champ->player_name,
                'challenger_actor' => $challengerActor,
                'champ_actor' => $oldChamp,
                'champ_hp_before' => $champHpBefore,
                'champ_hp_after' => $champHpAfter,
                'champ_max_hp' => $champDefeated ? $this->snapshotStats($challenger)['max_hp'] : (int) $champ->max_hp,
                'exp_gained' => $expGained,
                'job_exp_gained' => $jobExpGained,
                'gap_reward_note' => $gapRewardNote,
                'material_name' => $materialName,
                'material_quantity' => $materialQuantity,
                'level_result' => $levelResult,
                'next_available_at' => now()->addSeconds(app(CooldownSettingService::class)->champBattleSeconds()),
            ];
        });
    }

    public function recentLogs(int $limit = 5)
    {
        return ChampBattleLog::query()
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function ensureChamp(): ChampState
    {
        return ChampState::query()->first() ?? $this->createInitialChamp();
    }

    private function createInitialChamp(): ChampState
    {
        return ChampState::create([
            'character_id' => null,
            'player_name' => '冒険者協会の試練官',
            'icon_path' => '/images/chara/chara_001.webp',
            'job_name' => '戦士',
            'job_rank' => 1,
            'level' => 30,
            'current_hp' => 520,
            'max_hp' => 520,
            'current_mp' => 0,
            'max_mp' => 0,
            'atk' => 95,
            'def' => 58,
            'mag' => 24,
            'spr' => 38,
            'spd' => 52,
            'luk' => 20,
            'weapon_name' => '訓練用の剣',
            'armor_name' => '協会の軽鎧',
            'accessory_name' => '試練官の徽章',
            'affinity_physical' => 1.0,
            'affinity_speed' => 0.0,
            'affinity_magical' => 0.0,
            'normal_attack_type' => 'physical',
            'defense_count' => 0,
            'appointed_at' => now(),
        ]);
    }

    private function currentChampIconPath(ChampState $champ): string
    {
        if ($champ->character_id) {
            $currentIconPath = Character::query()
                ->whereKey($champ->character_id)
                ->value('icon_path');

            if ($currentIconPath) {
                return CharacterIconCatalog::normalize($currentIconPath);
            }
        }

        return CharacterIconCatalog::normalize($champ->icon_path);
    }

    private function runBattle(Character $challenger, ChampState $champ): array
    {
        $challengerStats = $this->snapshotStats($challenger);

        $challengerAffinity = [
            'physical' => (float) ($challenger->jobClass?->affinity_physical ?? 1.0),
            'speed'    => (float) ($challenger->jobClass?->affinity_speed    ?? 0.0),
            'magical'  => (float) ($challenger->jobClass?->affinity_magical  ?? 0.0),
        ];
        $champAffinity = [
            'physical' => (float) ($champ->affinity_physical ?? 1.0),
            'speed'    => (float) ($champ->affinity_speed    ?? 0.0),
            'magical'  => (float) ($champ->affinity_magical  ?? 0.0),
        ];

        $attacker = new BattleActor($challenger->name, true, [
            'hp' => $challengerStats['max_hp'],
            'max_hp' => $challengerStats['max_hp'],
            'mp' => $challengerStats['max_mp'],
            'max_mp' => $challengerStats['max_mp'],
            'str' => $challengerStats['str'],
            'def' => $challengerStats['def'],
            'agi' => $challengerStats['agi'],
            'mag' => $challengerStats['mag'],
            'spr' => $challengerStats['spr'],
            'luk' => $challengerStats['luk'],
            'job_key' => $challenger->jobClass?->key,
            'battle_type_weights' => $challengerAffinity,
            'normal_attack_type' => $challenger->jobClass?->normal_attack_type ?? null,
        ], $challenger);

        $defender = new BattleActor($champ->player_name, false, [
            'hp' => max(0, (int) $champ->current_hp),
            'max_hp' => max(1, (int) $champ->max_hp),
            'mp' => 0,
            'max_mp' => 0,
            'str' => (int) $champ->atk,
            'def' => (int) $champ->def,
            'agi' => (int) $champ->spd,
            'mag' => (int) $champ->mag,
            'spr' => (int) $champ->spr,
            'luk' => (int) $champ->luk,
            'battle_type_weights' => $champAffinity,
            'normal_attack_type' => $champ->normal_attack_type ?? 'physical',
        ], $champ);

        $affinityMultiplier = BattleTypeAffinity::multiplier($challengerAffinity, $champAffinity);
        $affinityNet = round($affinityMultiplier - 1.0, 4);
        $champAttackMultiplier = BattleTypeAffinity::multiplier($champAffinity, $challengerAffinity);
        $totalDamage = 0;
        $log = [];

        $log[] = $this->affinityLog($attacker->name, $defender->name, $affinityMultiplier);
        $log[] = $this->affinityLog($defender->name, $attacker->name, $champAttackMultiplier);

        $log[] = "<br><span class=\"text-blue-600 font-bold\">【先制】挑戦者が先手を取った！</span>";

        for ($turn = 1; $turn <= self::MAX_TURNS; $turn++) {
            $log[] = "<br><br>--- ターン {$turn} ---";
            // チャンプ戦は挑戦者が常に先攻
            $actors = [[$attacker, $defender], [$defender, $attacker]];

            foreach ($actors as [$actor, $target]) {
                if ($actor->isDead() || $target->isDead()) {
                    continue;
                }

                $multiplier = BattleTypeAffinity::multiplier($actor->battleTypeWeights, $target->battleTypeWeights);
                $action = $this->attack($actor, $target, $multiplier);
                $damage = $action['damage'];
                $target->takeDamage($damage);

                if ($actor->isPlayer) {
                    $totalDamage += $damage;
                }

                $log[] = $action['log'];

                if ($target->isDead()) {
                    break 2;
                }
            }
        }

        if ($defender->isDead()) {
            $log[] = "<br><span class=\"text-black font-extrabold text-xl\">{$attacker->name}は、{$defender->name}を倒した！</span>";
        } elseif ($attacker->isDead()) {
            $log[] = "<br><span class=\"text-black font-extrabold text-xl\">{$attacker->name}は、倒れてしまった……。</span>";
        } else {
            $log[] = "<br><span class=\"text-black font-extrabold text-xl\">双方が疲弊し、戦闘は終了した。</span>";
        }

        return [
            'damage' => $totalDamage,
            'turns' => min($turn, self::MAX_TURNS),
            'log' => $log,
            'affinity_multiplier' => $affinityMultiplier,
            'affinity_net' => $affinityNet,
        ];
    }

    private function attack(BattleActor $attacker, BattleActor $defender, float $affinityMultiplier = 1.0): array
    {
        if (!$this->damageCalculator->isHit(
            $attacker,
            $defender,
            100,
            self::PVP_HIT_AGI_FACTOR,
            self::PVP_MIN_HIT_RATE,
            self::PVP_MAX_HIT_RATE
        )) {
            return [
                'damage' => 0,
                'log' => "{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！",
            ];
        }

        $attackType = $attacker->usesMagForNormalAttack() ? 'magical' : 'physical';
        $critical = $this->damageCalculator->isDuelCritical($attacker, $defender);
        $damage = $this->damageCalculator->calculateDuelDamage(
            $attacker,
            $defender,
            $attackType,
            100,
            $critical,
            $affinityMultiplier
        );
        $critText = $critical ? '<span class="text-yellow-500 font-bold">クリティカル！</span>' : '';
        $damageClass = $attackType === 'magical' ? 'text-purple-600' : 'text-red-600';

        return [
            'damage' => $damage,
            'log' => "{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！",
        ];
    }

    private function affinityLog(string $attackerName, string $defenderName, float $multiplier): string
    {
        $label = BattleTypeAffinity::label($multiplier);

        if ($multiplier > 1.01) {
            $bonusPercent = (int) round(($multiplier - 1.0) * 100);
            return "<span class=\"text-emerald-700 font-bold\">【戦型相性】{$attackerName} → {$defenderName}: {$label}！ 与ダメージ +{$bonusPercent}%</span>";
        }

        if ($multiplier < 0.99) {
            $penaltyPercent = (int) round((1.0 - $multiplier) * 100);
            return "<span class=\"text-rose-700 font-bold\">【戦型相性】{$attackerName} → {$defenderName}: {$label}…… 与ダメージ -{$penaltyPercent}%</span>";
        }

        return "<span class=\"text-slate-500 font-bold\">【戦型相性】{$attackerName} → {$defenderName}: 互角</span>";
    }

    private function appointNewChamp(ChampState $champ, Character $challenger): void
    {
        $stats = $this->snapshotStats($challenger);
        $equipment = $this->snapshotEquipment($challenger);
        $champ->fill([
            'character_id' => $challenger->id,
            'player_name' => $challenger->name,
            'icon_path' => $challenger->icon_path ?: '/images/chara/chara_001.webp',
            'job_name' => $challenger->jobClass?->name ?? '冒険者',
            'job_rank' => $this->currentJobRank($challenger),
            'level' => (int) $challenger->level,
            'current_hp' => $stats['max_hp'],
            'max_hp' => $stats['max_hp'],
            'current_mp' => $stats['max_mp'] ?? 0,
            'max_mp' => $stats['max_mp'] ?? 0,
            'atk' => $stats['str'],
            'def' => $stats['def'],
            'mag' => $stats['mag'],
            'spr' => $stats['spr'],
            'spd' => $stats['agi'],
            'luk' => $stats['luk'],
            'weapon_name' => $equipment['weapon'],
            'armor_name' => $equipment['armor'],
            'accessory_name' => $equipment['accessory'],
            'affinity_physical' => (float) ($challenger->jobClass?->affinity_physical ?? 1.0),
            'affinity_speed'    => (float) ($challenger->jobClass?->affinity_speed    ?? 0.0),
            'affinity_magical'  => (float) ($challenger->jobClass?->affinity_magical  ?? 0.0),
            'normal_attack_type' => $challenger->jobClass?->normal_attack_type ?? 'physical',
            'defense_count' => 0,
            'appointed_at' => now(),
        ])->save();
    }

    private function snapshotEquipment(Character $character): array
    {
        $equipped = $character->characterItems()
            ->where('is_equipped', true)
            ->with('item')
            ->get();

        $result = [
            'weapon' => null,
            'armor' => null,
            'accessory' => null,
        ];

        foreach ($equipped as $characterItem) {
            $slot = $characterItem->equipped_slot;
            if (array_key_exists((string) $slot, $result) && $characterItem->item) {
                $result[$slot] = $characterItem->item->name;
            }
        }

        return $result;
    }

    private function resultActorSnapshot(Character $character): array
    {
        $stats = $this->snapshotStats($character);
        $equipment = $this->snapshotEquipment($character);

        return [
            'name' => $character->name,
            'icon_path' => $character->icon_path ?: '/images/chara/chara_001.webp',
            'level' => (int) $character->level,
            'job_name' => $character->jobClass?->name ?? '冒険者',
            'job_rank' => $this->currentJobRank($character),
            'current_hp' => (int) $character->current_hp,
            'max_hp' => (int) ($stats['max_hp'] ?? 0),
            'current_mp' => (int) $character->current_mp,
            'max_mp' => (int) ($stats['max_mp'] ?? 0),
            'weapon_name' => $equipment['weapon'],
            'armor_name' => $equipment['armor'],
            'accessory_name' => $equipment['accessory'],
        ];
    }

    private function snapshotStats(Character $character): array
    {
        return $this->statusService->getFinalStats($character);
    }

    private function currentJobRank(Character $character): int
    {
        if (!$character->current_job_id) {
            return 1;
        }

        return (int) ($character->jobHistories()
            ->where('job_class_id', $character->current_job_id)
            ->value('job_level') ?: 1);
    }

    private function baseExpReward(Character $character): int
    {
        $areaId = DB::table('character_area_progresses')
            ->where('character_id', $character->id)
            ->where('is_unlocked', true)
            ->max('area_id');

        $query = Enemy::query()
            ->where('is_boss', false)
            ->where('area_id', $areaId ?: 1);

        $averageExp = (float) $query->avg('exp_reward');
        if ($averageExp <= 0) {
            $averageExp = (float) Enemy::query()
                ->where('is_boss', false)
                ->where('area_id', 1)
                ->avg('exp_reward');
        }

        $multiplier = match (true) {
            $character->level <= 30 => 2.5,
            $character->level <= 80 => 2.0,
            $character->level <= 150 => 1.5,
            default => 1.2,
        };

        return max(1, (int) floor(max(1, $averageExp) * $multiplier));
    }

    private function champLevelGapExpMultiplier(int $levelGap): float
    {
        if ($levelGap <= 0) {
            return 1.0;
        }

        // 格上への挑戦ほど学びを増やす。Lv差10ごとに+50%、最大で5倍。
        return min(5.0, 1.0 + floor($levelGap / 10) * 0.5);
    }

    private function champExpReward(int $baseExp, bool $champDefeated, float $gapMultiplier): int
    {
        if ($champDefeated) {
            return max(1, (int) floor($baseExp * max(3.0, $gapMultiplier + 2.0)));
        }

        return max(1, (int) floor($baseExp * $gapMultiplier));
    }

    private function champJobExpReward(bool $champDefeated, int $levelGap): int
    {
        $gapBonus = min(6, (int) floor(max(0, $levelGap) / 20));

        return $champDefeated
            ? min(16, 8 + $gapBonus)
            : min(9, 3 + $gapBonus);
    }

    private function materialReward(Character $character): array
    {
        $candidates = $this->equippedEvolutionMaterialCandidates($character);
        $candidate = !empty($candidates)
            ? $candidates[array_rand($candidates)]
            : ['material_code' => 'MAT_COMMON_MONSTER_FRAGMENT', 'name' => '魔物の欠片'];

        $material = $this->materialByCodeOrFallback($candidate['material_code'], $candidate['name']);

        return [
            'material' => $material,
            'quantity' => random_int(3, 5),
        ];
    }

    private function equippedEvolutionMaterialCandidates(Character $character): array
    {
        $equipped = $character->characterItems()
            ->where('is_equipped', true)
            ->with('item')
            ->get();

        $candidates = [];
        foreach ($equipped as $characterItem) {
            $item = $characterItem->item;
            if (!$item) {
                continue;
            }

            $candidates = array_merge($candidates, match ($item->type) {
                'weapon' => $this->weaponEvolutionMaterialCandidates($character, $item),
                'armor' => $this->armorEvolutionMaterialCandidates($character, $item),
                'accessory' => $this->accessoryEvolutionMaterialCandidates($character, $item),
                default => [],
            });
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $code = (string) ($candidate['material_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $unique[$code] = $candidate;
        }

        return array_values($unique);
    }

    private function weaponEvolutionMaterialCandidates(Character $character, object $item): array
    {
        if (!$item->external_item_id || !DB::getSchemaBuilder()->hasTable('weapon_evolution_recipes')) {
            return [];
        }

        $recipe = DB::table('weapon_evolution_recipes')
            ->where('from_weapon_id', $item->external_item_id)
            ->where('is_active', true)
            ->first();
        if (!$recipe || !DB::getSchemaBuilder()->hasTable('weapon_evolution_recipe_ingredients')) {
            return [];
        }

        return DB::table('weapon_evolution_recipe_ingredients')
            ->where('recipe_id', $recipe->recipe_id)
            ->where('ingredient_type', '<>', 'same_weapon')
            ->get()
            ->map(fn ($row) => $this->resolveChampRewardMaterial(
                (string) $row->ingredient_id,
                (string) $row->ingredient_name,
                $character,
                $recipe,
                $item
            ))
            ->filter()
            ->values()
            ->all();
    }

    private function armorEvolutionMaterialCandidates(Character $character, object $item): array
    {
        if (!$item->external_item_id || !DB::getSchemaBuilder()->hasTable('armor_evolution_recipes')) {
            return [];
        }

        $recipe = DB::table('armor_evolution_recipes')
            ->where('source_armor_id', $item->external_item_id)
            ->where('is_active', true)
            ->first();
        if (!$recipe || !DB::getSchemaBuilder()->hasTable('armor_evolution_recipe_ingredients')) {
            return [];
        }

        return DB::table('armor_evolution_recipe_ingredients')
            ->where('evolution_recipe_id', $recipe->evolution_recipe_id)
            ->get()
            ->map(fn ($row) => $this->resolveChampRewardMaterial(
                (string) $row->material_id,
                (string) $row->material_name,
                $character,
                $recipe,
                $item
            ))
            ->filter()
            ->values()
            ->all();
    }

    private function accessoryEvolutionMaterialCandidates(Character $character, object $item): array
    {
        if (!$item->external_item_id || !DB::getSchemaBuilder()->hasTable('accessory_evolution_recipes')) {
            return [];
        }

        $recipe = DB::table('accessory_evolution_recipes')
            ->where('from_accessory_id', $item->external_item_id)
            ->where('is_active', true)
            ->first();
        if (!$recipe || !DB::getSchemaBuilder()->hasTable('accessory_evolution_recipe_ingredients')) {
            return [];
        }

        return DB::table('accessory_evolution_recipe_ingredients')
            ->where('recipe_id', $recipe->recipe_id)
            ->where('ingredient_type', '<>', 'same_accessory')
            ->get()
            ->map(fn ($row) => $this->resolveChampRewardMaterial(
                (string) $row->material_code,
                (string) $row->material_name,
                $character,
                $recipe,
                $item
            ))
            ->filter()
            ->values()
            ->all();
    }

    private function resolveChampRewardMaterial(string $code, string $fallbackName, Character $character, object $recipe, object $item): ?array
    {
        if (in_array($code, ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'], true)) {
            return null;
        }

        $cityId = $this->champRewardCityId($character, $recipe, $item);
        $material = match ($code) {
            'TOKEN_CITY_MATERIAL' => $this->firstMaterialByType('weapon_city', $cityId),
            'TOKEN_CITY_HIGH_MATERIAL' => $this->firstMaterialByType('weapon_city_high', $cityId),
            '5051' => $this->armorCityMaterial($cityId, false),
            '5052' => $this->armorCityMaterial($cityId, true),
            '5053' => Material::where('material_type', 'back_dungeon')->orderBy('material_code')->first(),
            '5054' => Material::where('material_type', 'back_high')->orderBy('material_code')->first(),
            'ACC_CITY_MATERIAL' => $this->firstMaterialByType('accessory_city', $cityId),
            'ACC_CITY_HIGH_MATERIAL' => $this->firstMaterialByType('accessory_city_high', $cityId),
            default => Material::where('material_code', $code)->first(),
        };

        if (!$material && $fallbackName !== '') {
            $material = Material::where('name', $fallbackName)->first();
        }

        if ($material && (string) ($material->material_type ?? '') === 'branch_evolution') {
            return null;
        }

        return $material ? ['material_code' => (string) $material->material_code, 'name' => $material->displayName()] : null;
    }

    private function champRewardCityId(Character $character, object $recipe, object $item): int
    {
        foreach ([
            $recipe->unlock_city_id ?? null,
            $item->unlock_city_id ?? null,
            $character->highest_city_id ?? null,
            $character->current_city_id ?? null,
        ] as $cityId) {
            $cityId = (int) $cityId;
            if ($cityId >= 1 && $cityId <= 10) {
                return $cityId;
            }
        }

        return 1;
    }

    private function firstMaterialByType(string $materialType, int $cityId): ?Material
    {
        return Material::where('material_type', $materialType)
            ->where('city_id', $cityId)
            ->first()
            ?: Material::where('material_type', $materialType)->orderBy('city_id')->first();
    }

    private function armorCityMaterial(int $cityId, bool $high): ?Material
    {
        $codes = $high
            ? [5026, 5028, 5030, 5032, 5034, 5036, 5038, 5040, 5042, 5044]
            : [5025, 5027, 5029, 5031, 5033, 5035, 5037, 5039, 5041, 5043];

        return Material::where('material_code', (string) ($codes[$cityId - 1] ?? $codes[0]))->first();
    }

    private function materialByCodeOrFallback(string $code, string $fallbackName): Material
    {
        $material = Material::where('material_code', $code)->first()
            ?: ($fallbackName !== '' ? Material::where('name', $fallbackName)->first() : null);

        if ($material) {
            return $material;
        }

        return $this->fallbackChampMaterial();
    }

    private function fallbackChampMaterial(): Material
    {
        return Material::firstOrCreate(
            ['material_code' => self::MATERIAL_CODE],
            [
                'name' => '挑戦者の証片',
                'category' => 'チャンプ戦素材',
                'rarity' => 'N',
                'element' => null,
                'main_use' => 'チャンプ戦記念素材',
                'npc_sale_price' => 0,
                'is_tradable' => false,
            ]
        );
    }

    private function grantMaterial(Character $character, Material $material, int $quantity): void
    {
        $row = CharacterMaterial::firstOrCreate(
            ['character_id' => $character->id, 'material_id' => $material->id],
            ['quantity' => 0]
        );
        $row->increment('quantity', $quantity);
    }
}
