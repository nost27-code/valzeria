<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\ChampBattleLog;
use App\Models\ChampHistory;
use App\Models\ChampState;
use App\Models\Enemy;
use App\Models\Material;
use App\Models\Skill;
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
    private const CHAMP_SKILL_COST_MAX_SP_RATE = 0.25;
    private const LOW_LEVEL_SKILL_BONUS_PER_LEVELS = 2;
    private const LOW_LEVEL_SKILL_BONUS_MAX = 25;
    private const UPSET_DAMAGE_MIN_LEVEL_GAP = 10;
    private const UPSET_DAMAGE_BASE_CHANCE = 12;
    private const UPSET_DAMAGE_CHANCE_PER_10_LEVELS = 3;
    private const UPSET_DAMAGE_MAX_CHANCE = 35;
    private const UPSET_DAMAGE_MIN_HP_PERCENT = 8;
    private const UPSET_DAMAGE_HP_PERCENT_PER_20_LEVELS = 3;
    private const UPSET_DAMAGE_MAX_HP_PERCENT = 22;
    private const STREAK_DEBUFF_PERCENT_PER_WIN = 2;
    private const STREAK_DEBUFF_MAX_PERCENT = 40;
    private const CHAMP_REWARD_EXCLUDED_RECIPE_CODES = [
        'TOKEN_CITY_HIGH_MATERIAL',
        '5052',
        '5053',
        '5054',
        'ACC_CITY_HIGH_MATERIAL',
    ];
    private const CHAMP_REWARD_EXCLUDED_MATERIAL_TYPES = [
        'accessory_city_high',
        'back_dungeon',
        'back_high',
        'branch_evolution',
        'city_high',
        'secret',
        'weapon_city_high',
    ];
    private const CHAMP_REWARD_EXCLUDED_MATERIAL_CODES = [
        '5009',
        'ACC0009',
        'MAT_ENHANCE_HIGH_STONE',
        'MAT_REFINING_CORE',
        'MAT_REFINING_CORE_LOW',
        'MAT_REFINING_CORE_PART_A',
        'MAT_REFINING_CORE_PART_B',
        'MAT_REFINING_CORE_PART_C',
    ];
    private const CHAMP_REWARD_EXCLUDED_NAME_KEYWORDS = [
        '古代片',
        '極印',
        '導石',
        '秘境晶',
        '高純度',
        '精錬核',
        '粗精錬核',
        '覇王黒晶',
        '蒼炉魔晶',
        '星樹氷晶',
    ];
    private const CHAMP_COMMON_REWARD_MATERIALS = [
        ['material_code' => 'MAT_COMMON_MONSTER_CORE', 'name' => '魔物の魔核'],
    ];

    public function __construct(
        private CharacterStatusService $statusService,
        private DamageCalculator $damageCalculator,
        private LevelService $levelService,
        private JobArtBattleSupportService $jobArtBattleSupport,
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
        $maxMp = max(1, (int) ($champ->max_mp ?? 0));
        $currentMp = max(0, (int) ($champ->current_mp ?? 0));

        return [
            'champ' => $champ,
            'champ_fatigue' => $this->champStreakFatigue($champ),
            'champ_effective_stats' => $this->fatiguedChampStats($champ),
            'champ_power' => $this->champPower($champ),
            'hp_percent' => min(100, (int) floor(($currentHp / $maxHp) * 100)),
            'mp_percent' => min(100, (int) floor(($currentMp / $maxMp) * 100)),
            'is_self' => $viewer && $champ->character_id === $viewer->id,
            'can_challenge' => $availability['can_challenge'],
            'reason' => $availability['reason'],
            'cooldown_until' => $availability['cooldown_until'],
            'cooldown_remaining_seconds' => $availability['cooldown_remaining_seconds'],
            'recent_logs' => $this->recentLogs(5),
        ];
    }

    private function champPower(ChampState $champ): int
    {
        $stats = $this->fatiguedChampStats($champ);

        return app(CharacterPowerService::class)->fromFinalStats([
            'max_hp' => (int) $champ->max_hp,
            'max_mp' => (int) ($champ->max_mp ?? 0),
            'str' => (int) $stats['atk'],
            'def' => (int) $stats['def'],
            'agi' => (int) $stats['spd'],
            'mag' => (int) $stats['mag'],
            'spr' => (int) $stats['spr'],
            'luk' => (int) $stats['luk'],
        ]);
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
                ->with(['jobClass', 'user'])
                ->lockForUpdate()
                ->findOrFail($challenger->id);
            $isAdminTester = $challenger->isAdminTester();

            $champ = ChampState::query()->lockForUpdate()->first() ?? $this->createInitialChamp();
            $champ = $this->replaceAdminTesterChamp($champ);
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
            $champDefeated = (bool) ($battle['champ_defeated'] ?? false);
            $champHpAfter = $champDefeated ? 0 : max(0, (int) ($battle['champ_hp_after_battle'] ?? $champHpBefore));
            $damage = max(0, $champHpBefore - $champHpAfter);

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
                if ($isAdminTester) {
                    $battle['log'][] = '<span class="text-slate-600 font-bold">【検証】テストキャラのため、チャンプ交代は本番表示へ反映しません。</span>';
                } else {
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
                }
            } else {
                $champ->current_hp = $champHpAfter;
                $champ->current_mp = max(0, (int) ($battle['champ_mp_after'] ?? $champ->current_mp ?? 0));
                $champ->defense_count = (int) $champ->defense_count + 1;
                $champ->save();
            }

            $log = ChampBattleLog::create([
                'champ_character_id' => $oldChamp['character_id'],
                'champ_player_name' => $oldChamp['player_name'],
                'challenger_character_id' => $challenger->id,
                'challenger_player_name' => $challenger->name,
                'damage' => $damage,
                'is_champ_defeated' => $champDefeated && ! $isAdminTester,
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
                'champ_defeated' => $champDefeated && ! $isAdminTester,
                'damage' => $damage,
                'turns' => $battle['turns'],
                'battle_log' => $battle['log'],
                'champ_before_name' => $oldChamp['player_name'],
                'champ_after_name' => ($champDefeated && ! $isAdminTester) ? $challenger->name : $champ->player_name,
                'challenger_actor' => array_merge($challengerActor, [
                    'current_mp' => $battle['challenger_mp_after'] ?? (int) ($challengerActor['current_mp'] ?? 0),
                ]),
                'champ_actor' => array_merge($oldChamp, [
                    'current_mp' => $battle['champ_mp_after'] ?? (int) ($oldChamp['current_mp'] ?? 0),
                ]),
                'champ_fatigue' => $battle['champ_fatigue'],
                'champ_hp_before' => $champHpBefore,
                'champ_hp_after' => $champHpAfter,
                'champ_mp_after' => $battle['champ_mp_after'] ?? (int) ($oldChamp['current_mp'] ?? 0),
                'challenger_mp_after' => $battle['challenger_mp_after'] ?? (int) ($challengerActor['current_mp'] ?? 0),
                'champ_max_hp' => ($champDefeated && ! $isAdminTester) ? $this->snapshotStats($challenger)['max_hp'] : (int) $champ->max_hp,
                'exp_gained' => $expGained,
                'job_exp_gained' => $jobExpGained,
                'progression' => $levelResult['progression'] ?? null,
                'gap_reward_note' => $gapRewardNote,
                'material_code' => $material->material_code,
                'material_icon_image' => $material->iconImagePath(),
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
            ->where(function ($query): void {
                $query->whereNull('challenger_character_id')
                    ->orWhereHas('challenger', fn ($characterQuery) => $characterQuery->visibleToPublic());
            })
            ->where(function ($query): void {
                $query->whereNull('champ_character_id')
                    ->orWhereHas('champCharacter', fn ($characterQuery) => $characterQuery->visibleToPublic());
            })
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function ensureChamp(): ChampState
    {
        $champ = ChampState::query()->first() ?? $this->createInitialChamp();

        return $this->replaceAdminTesterChamp($champ);
    }

    private function createInitialChamp(): ChampState
    {
        return ChampState::create($this->initialChampPayload());
    }

    private function replaceAdminTesterChamp(ChampState $champ): ChampState
    {
        if (! $champ->character_id) {
            return $champ;
        }

        $character = Character::query()
            ->with('user')
            ->find((int) $champ->character_id);

        if (! $character?->isAdminTester()) {
            return $champ;
        }

        $champ->forceFill($this->initialChampPayload())->save();

        return $champ->refresh();
    }

    private function initialChampPayload(): array
    {
        return [
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
        ];
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
        $champFatigue = $this->champStreakFatigue($champ);
        $champStats = $this->fatiguedChampStats($champ, $champFatigue);

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
        $challengerJob = $challenger->relationLoaded('currentJob')
            ? $challenger->currentJob
            : $challenger->currentJob()->with('skill')->first();
        if ($challengerJob?->skill) {
            $attacker->skill = $challengerJob->skill;
        }
        $this->jobArtBattleSupport->attachBossSet($attacker, $challenger, 'champ');

        $defender = new BattleActor($champ->player_name, false, [
            'hp' => max(0, (int) $champ->current_hp),
            'max_hp' => max(1, (int) $champ->max_hp),
            'mp' => max(0, (int) ($champ->current_mp ?? 0)),
            'max_mp' => max(0, (int) ($champ->max_mp ?? 0)),
            'str' => $champStats['atk'],
            'def' => $champStats['def'],
            'agi' => $champStats['spd'],
            'mag' => $champStats['mag'],
            'spr' => $champStats['spr'],
            'luk' => $champStats['luk'],
            'battle_type_weights' => $champAffinity,
            'normal_attack_type' => $champ->normal_attack_type ?? 'physical',
        ], $champ);
        $champSkill = $this->champSkill($champ);
        if ($champSkill) {
            $defender->skill = $champSkill;
        }
        $champCharacter = $champ->character_id
            ? Character::query()->find($champ->character_id)
            : null;
        if ($champCharacter) {
            $this->jobArtBattleSupport->attachBossSet($defender, $champCharacter, 'champ');
        }

        $affinityMultiplier = BattleTypeAffinity::multiplier($challengerAffinity, $champAffinity);
        $affinityNet = round($affinityMultiplier - 1.0, 4);
        $champAttackMultiplier = BattleTypeAffinity::multiplier($champAffinity, $challengerAffinity);
        $levelGap = max(0, (int) $champ->level - (int) $challenger->level);
        $upsetDamageUsed = false;
        $totalDamage = 0;
        $log = [];
        $jobArtState = new \App\Services\Battle\BattleState($attacker, $defender, 'champ');

        $log[] = $this->affinityLog($attacker->name, $defender->name, $affinityMultiplier);
        $log[] = $this->affinityLog($defender->name, $attacker->name, $champAttackMultiplier);
        if ($champFatigue['percent'] > 0) {
            $log[] = "<span class=\"text-orange-700 font-bold\">【連勝疲労】{$champ->defense_count}連勝中の疲労で、チャンプの戦闘能力が {$champFatigue['percent']}% 低下している！</span>";
        }

        $challengerFirst = (bool) random_int(0, 1);
        if ($challengerFirst) {
            $log[] = "<br><span class=\"text-blue-600 font-bold\">【先制】挑戦者が先手を取った！</span>";
        } else {
            $log[] = "<br><span class=\"text-rose-600 font-bold\">【先制】チャンプが先手を取った！</span>";
        }

        for ($turn = 1; $turn <= self::MAX_TURNS; $turn++) {
            $log[] = "<br><br>--- ターン {$turn} ---";
            $actors = $challengerFirst
                ? [[$attacker, $defender], [$defender, $attacker]]
                : [[$defender, $attacker], [$attacker, $defender]];

            foreach ($actors as [$actor, $target]) {
                if ($actor->isDead() || $target->isDead()) {
                    continue;
                }

                $actorLevel = $actor->isPlayer ? (int) $challenger->level : (int) $champ->level;
                $targetLevel = $actor->isPlayer ? (int) $champ->level : (int) $challenger->level;
                $action = $this->champAction($actor, $target, $actorLevel, $targetLevel, $jobArtState);
                $damage = $action['damage'];
                $upsetDamage = 0;

                if ($actor->isPlayer && !$upsetDamageUsed && ($action['hit'] ?? false)) {
                    $upsetDamage = $this->rollUpsetDamage($target, $levelGap);
                    if ($upsetDamage > 0) {
                        $damage += $upsetDamage;
                        $upsetDamageUsed = true;
                    }
                }

                $target->takeDamage($damage);
                if ($target->gutsJustTriggered) {
                    $target->gutsJustTriggered = false;
                    $log[] = "<span class=\"text-orange-700 font-extrabold\">{$target->name} は不屈の精神で致死ダメージを耐えた！（HP1）</span>";
                }

                if ($actor->isPlayer) {
                    $totalDamage += $damage;
                }

                $log[] = $action['log'];
                if ($upsetDamage > 0) {
                    $log[] = "<span class=\"text-fuchsia-700 font-extrabold\">【格上への一撃】レベル差を覆す渾身の一撃！ 追加で {$upsetDamage} ダメージ！</span>";
                }

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
            'champ_defeated' => $defender->isDead(),
            'champ_hp_after_battle' => max(0, (int) $defender->hp),
            'challenger_defeated' => $attacker->isDead(),
            'challenger_hp_after_battle' => max(0, (int) $attacker->hp),
            'turns' => min($turn, self::MAX_TURNS),
            'log' => $log,
            'affinity_multiplier' => $affinityMultiplier,
            'affinity_net' => $affinityNet,
            'champ_fatigue' => $champFatigue,
            'challenger_mp_after' => $attacker->mp,
            'champ_mp_after' => $defender->mp,
        ];
    }

    private function champSkill(ChampState $champ): ?Skill
    {
        if (!$champ->character_id) {
            return null;
        }

        $character = Character::query()->find($champ->character_id);
        $job = $character?->currentJob()->with('skill')->first();

        return $job?->skill;
    }

    private function champAction(
        BattleActor $attacker,
        BattleActor $defender,
        int $attackerLevel,
        int $defenderLevel,
        \App\Services\Battle\BattleState $jobArtState
    ): array
    {
        $this->jobArtBattleSupport->tickCooldowns($jobArtState, $attacker);
        $jobArt = $this->jobArtBattleSupport->selectForTurn($attacker, $jobArtState);
        if ($jobArt) {
            $this->jobArtBattleSupport->consumeAndMarkUse($attacker, $jobArtState, $jobArt);

            return $this->skillAttack(
                $attacker,
                $defender,
                $this->jobArtBattleSupport->skillForExecution($attacker, $jobArt),
                $this->jobArtBattleSupport->activationLog($attacker, $defender, $jobArt)
            );
        }

        if ($attacker->skill && random_int(1, 100) <= $this->champSkillActivationRate($attacker->skill, $attackerLevel, $defenderLevel)) {
            $spCost = $this->champSkillSpCost($attacker, $attacker->skill);

            if ($attacker->mp >= $spCost) {
                $attacker->mp -= $spCost;
                return $this->skillAttack($attacker, $defender, $attacker->skill);
            }

            $normal = $this->attack($attacker, $defender, BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights));
            $normal['log'] = "<span class=\"text-slate-500 font-bold\">{$attacker->name} は {$attacker->skill->name} を狙ったが、SPが足りない！</span><br>" . $normal['log'];

            return $normal;
        }

        return $this->attack($attacker, $defender, BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights));
    }

    private function champSkillActivationRate(Skill $skill, int $attackerLevel, int $defenderLevel): int
    {
        $baseRate = max(0, min(100, $skill->effectiveActivationRate()));
        $levelGap = max(0, $defenderLevel - $attackerLevel);
        if ($levelGap <= 0) {
            return $baseRate;
        }

        $bonus = min(
            self::LOW_LEVEL_SKILL_BONUS_MAX,
            (int) floor($levelGap / self::LOW_LEVEL_SKILL_BONUS_PER_LEVELS)
        );

        return min(100, $baseRate + $bonus);
    }

    private function skillAttack(BattleActor $attacker, BattleActor $defender, Skill $skill, ?string $openingLog = null): array
    {
        $hitCount = max(1, (int) $skill->hit_count);
        if ((int) $skill->hit_count === 0 && in_array($skill->damage_type, ['heal', 'support'], true)) {
            $hitCount = 1;
        }
        if ((int) $skill->extra_hit_chance_percent > 0 && random_int(1, 100) <= (int) $skill->extra_hit_chance_percent) {
            $hitCount++;
        }

        $totalDamage = 0;
        $logs = [$openingLog ?: "<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>"];
        $affinityMultiplier = BattleTypeAffinity::multiplier($attacker->battleTypeWeights, $defender->battleTypeWeights);

        for ($i = 0; $i < $hitCount; $i++) {
            $damage = 0;
            $skillPowerInt = max(0, (int) round((float) $skill->power_multiplier * 100));
            $overrideDef = null;
            $overrideSpr = null;

            if ((int) $skill->def_ignore_percent > 0) {
                $overrideDef = (int) floor($defender->def * (1 - ((int) $skill->def_ignore_percent / 100)));
                $overrideSpr = (int) floor($defender->spr * (1 - ((int) $skill->def_ignore_percent / 100)));
            }

            if ((float) $skill->luk_power_rate > 0) {
                $skillPowerInt += (int) floor($attacker->luk * (float) $skill->luk_power_rate);
            }

            if ((float) $skill->power_multiplier > 0) {
                if (in_array($skill->damage_type, ['physical', 'gold', 'drop', 'support'], true)) {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $skillPowerInt,
                        false,
                        $affinityMultiplier,
                        null,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                } elseif ($skill->damage_type === 'magical') {
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'magical',
                        $skillPowerInt,
                        false,
                        $affinityMultiplier,
                        null,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                } elseif ($skill->damage_type === 'hybrid') {
                    $hybridAtk = (string) $skill->hybrid_scaling === 'max'
                        ? max($attacker->str, $attacker->mag)
                        : (int) floor(($attacker->str + $attacker->mag) / 2);
                    $damage = $this->damageCalculator->calculateRankBattleDamage(
                        $attacker,
                        $defender,
                        'physical',
                        $skillPowerInt,
                        false,
                        $affinityMultiplier,
                        $hybridAtk,
                        $overrideDef,
                        $overrideSpr,
                        true,
                        $hitCount
                    );
                }
            }

            if ($damage > 0) {
                $totalDamage += $damage;
                $logs[] = "{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！";
            }
        }

        if ((int) $skill->heal_percent > 0) {
            $healAmount = (int) floor($attacker->maxHp * ((int) $skill->heal_percent / 100));
            $attacker->healHp($healAmount);
            $logs[] = "<span class=\"text-green-600 font-bold\">{$attacker->name} の傷が {$healAmount} 回復した！</span>";
        }

        if ((int) $skill->mp_recover_percent > 0 && $attacker->maxMp > 0) {
            $mpHealAmount = (int) floor($attacker->maxMp * ((int) $skill->mp_recover_percent / 100));
            $attacker->mp = min($attacker->maxMp, $attacker->mp + $mpHealAmount);
            $logs[] = "<span class=\"text-blue-500 font-bold\">{$attacker->name} はSPを {$mpHealAmount} 回復した！</span>";
        }

        if ((int) $skill->self_damage_percent > 0) {
            $selfDamage = (int) floor($attacker->maxHp * ((int) $skill->self_damage_percent / 100));
            $attacker->takeDamage($selfDamage);
            $logs[] = "<span class=\"text-purple-600 font-bold\">反動により、{$attacker->name} は {$selfDamage} のダメージを受けた！</span>";
            if ($attacker->gutsJustTriggered) {
                $attacker->gutsJustTriggered = false;
                $logs[] = "<span class=\"text-orange-700 font-extrabold\">{$attacker->name} は不屈の精神で致死ダメージを耐えた！（HP1）</span>";
            }
        }

        if ($skill->isJobArt()) {
            $this->applyJobArtTemplateEffects($attacker, $defender, $skill, $totalDamage, $logs);
        }

        $this->applyStructuredDebuffs($defender, $skill, $logs);

        if ((int) $skill->damage_reduction_percent > 0 && ! ($skill->isJobArt() && in_array((string) $skill->effect_template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true))) {
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(25, (int) $skill->damage_reduction_percent));
            $logs[] = "{$attacker->name} は次の被ダメージを軽減する構えをとった！";
        }

        if (!$skill->isJobArt() && (int) $skill->self_buff_percent > 0) {
            $rate = (int) $skill->self_buff_percent / 100;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + (int) floor($attacker->baseStr * $rate));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + (int) floor($attacker->baseMag * $rate));
            $logs[] = "{$attacker->name} の攻撃力と魔法力が上昇した！";
        }

        return [
            'hit' => $totalDamage > 0,
            'damage' => $totalDamage,
            'log' => implode('<br>', $logs),
        ];
    }

    private function applyJobArtTemplateEffects(
        BattleActor $attacker,
        BattleActor $defender,
        Skill $skill,
        int $totalDamage,
        array &$logs
    ): void {
        $template = (string) $skill->effect_template;
        $power = max(1, (int) ($skill->power ?: 100));

        if (in_array($template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $heal = max(1, (int) floor($attacker->spr * ($power / 100)));
            $attacker->healHp($heal);
            $logs[] = "<span class=\"text-emerald-600 font-bold\">HPが {$heal} 回復した！</span>";
        }

        if ($template === 'DRAIN' && $totalDamage > 0 && (float) $skill->drain_hp_rate > 0) {
            $heal = max(1, (int) floor($totalDamage * (float) $skill->drain_hp_rate));
            $attacker->healHp($heal);
            $logs[] = "<span class=\"text-emerald-600 font-bold\">与えた力を吸収し、HPが {$heal} 回復した！</span>";
        }

        if ($template === 'GUTS') {
            $attacker->gutsReady = true;
            $logs[] = "<span class=\"text-orange-700 font-bold\">{$attacker->name} は一度だけ踏みとどまる覚悟を固めた！</span>";
        }

        if (in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $rate = (float) ($attacker->jobArtRates[(int) $skill->id] ?? 1.0);
            $reduction = $this->jobArtGuardReduction($skill, $rate);
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(25, $reduction));
            $logs[] = "<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>";
        }

        if (in_array($template, ['SELF_BUFF', 'DAMAGE_BUFF', 'MAGICAL_DAMAGE_BUFF'], true)) {
            $beforeStr = $attacker->str;
            $beforeMag = $attacker->mag;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * 0.10)));
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * 0.10)));
            $logs[] = $this->statChangeLog($attacker->name, 'ATK', $beforeStr, $attacker->str, 'MAG', $beforeMag, $attacker->mag, true);
        }

        if (in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true) && !$this->hasStructuredDebuff($skill)) {
            $beforeDef = $defender->def;
            $beforeSpr = $defender->spr;
            $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * 0.10)));
            $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * 0.05)));
            $logs[] = $this->statChangeLog($defender->name, 'DEF', $beforeDef, $defender->def, 'SPR', $beforeSpr, $defender->spr, false);
        }

        if ($template === 'TIME_CONTROL_CURRENT_ONLY' && !$this->hasStructuredDebuff($skill)) {
            $rate = (int) $skill->enemy_spd_down_percent > 0 ? (int) $skill->enemy_spd_down_percent / 100 : 0.10;
            $before = $defender->agi;
            $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * $rate)));
            $pct = $before > 0 ? (int) round((abs($before - $defender->agi) / $before) * 100) : 0;
            $logs[] = "<span class=\"text-sky-700 font-bold\">{$defender->name} のSPDが {$pct}% 低下した！</span>";
        }
    }

    private function statChangeLog(
        string $actorName,
        string $mainLabel,
        int $mainBefore,
        int $mainAfter,
        string $subLabel,
        int $subBefore,
        int $subAfter,
        bool $isBuff
    ): string {
        $mainPct = $mainBefore > 0 ? (int) round((abs($mainAfter - $mainBefore) / $mainBefore) * 100) : 0;
        $subPct = $subBefore > 0 ? (int) round((abs($subAfter - $subBefore) / $subBefore) * 100) : 0;

        if ($mainAfter === $mainBefore && $subAfter === $subBefore) {
            $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
            $verb = $isBuff ? '強化' : '弱体化';
            return "<span class=\"{$color} font-bold\">{$actorName} はこれ以上{$verb}できない！</span>";
        }

        $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
        $direction = $isBuff ? '上昇' : '低下';
        return "<span class=\"{$color} font-bold\">{$actorName} の{$mainLabel}が {$mainPct}% / {$subLabel}が {$subPct}% {$direction}した！</span>";
    }

    private function hasStructuredDebuff(Skill $skill): bool
    {
        return (int) $skill->enemy_atk_down_percent > 0
            || (int) $skill->enemy_mag_down_percent > 0
            || (int) $skill->enemy_def_down_percent > 0
            || (int) $skill->enemy_spr_down_percent > 0
            || (int) $skill->enemy_spd_down_percent > 0;
    }

    private function jobArtGuardReduction(Skill $skill, float $rate = 1.0): int
    {
        if ((int) $skill->damage_reduction_percent > 0) {
            return min(25, max(1, (int) floor((int) $skill->damage_reduction_percent * $rate)));
        }

        // powerは呼び出し元でskillForExecution()により既に継承倍率でスケール済み
        return min(25, max(10, (int) floor(max(80, (int) ($skill->power ?: 100)) / 10)));
    }

    private function applyStructuredDebuffs(BattleActor $defender, Skill $skill, array &$logs): void
    {
        $debuffs = [
            'enemy_atk_down_percent' => ['prop' => 'str', 'base' => 'baseStr', 'label' => '攻撃力'],
            'enemy_mag_down_percent' => ['prop' => 'mag', 'base' => 'baseMag', 'label' => '魔法力'],
            'enemy_def_down_percent' => ['prop' => 'def', 'base' => 'baseDef', 'label' => '防御力'],
            'enemy_spr_down_percent' => ['prop' => 'spr', 'base' => 'baseSpr', 'label' => '精神力'],
            'enemy_spd_down_percent' => ['prop' => 'agi', 'base' => 'baseAgi', 'label' => '素早さ'],
        ];

        foreach ($debuffs as $field => $config) {
            $effect = (int) ($skill->{$field} ?? 0);
            if ($effect <= 0) {
                continue;
            }

            $prop = $config['prop'];
            $base = $config['base'];
            $defender->{$prop} = max(1, $defender->{$prop} - (int) floor($defender->{$base} * ($effect / 100)));
            $logs[] = "{$defender->name} の{$config['label']}が {$effect}% 低下した！";
        }
    }

    private function champSkillSpCost(BattleActor $attacker, Skill $skill): int
    {
        $baseCost = $skill->specialSkillSpCostForMaxSp($attacker->maxMp);
        if ($baseCost <= 0 || $attacker->maxMp <= 0) {
            return $baseCost;
        }

        $cap = max(1, (int) ceil($attacker->maxMp * self::CHAMP_SKILL_COST_MAX_SP_RATE));

        return min($baseCost, $cap);
    }

    private function champStreakFatigue(ChampState $champ): array
    {
        $defenseCount = max(0, (int) $champ->defense_count);
        $percent = min(
            self::STREAK_DEBUFF_MAX_PERCENT,
            $defenseCount * self::STREAK_DEBUFF_PERCENT_PER_WIN
        );

        return [
            'defense_count' => $defenseCount,
            'percent' => $percent,
            'multiplier' => max(0.1, (100 - $percent) / 100),
        ];
    }

    private function fatiguedChampStats(ChampState $champ, ?array $fatigue = null): array
    {
        $fatigue ??= $this->champStreakFatigue($champ);
        $multiplier = (float) ($fatigue['multiplier'] ?? 1.0);

        return [
            'atk' => $this->fatiguedCombatStat((int) $champ->atk, $multiplier),
            'def' => $this->fatiguedCombatStat((int) $champ->def, $multiplier),
            'mag' => $this->fatiguedCombatStat((int) $champ->mag, $multiplier),
            'spr' => $this->fatiguedCombatStat((int) $champ->spr, $multiplier),
            'spd' => $this->fatiguedCombatStat((int) $champ->spd, $multiplier),
            'luk' => $this->fatiguedCombatStat((int) $champ->luk, $multiplier),
        ];
    }

    private function fatiguedCombatStat(int $value, float $multiplier): int
    {
        if ($value <= 0) {
            return 0;
        }

        return max(1, (int) floor($value * $multiplier));
    }

    private function rollUpsetDamage(BattleActor $defender, int $levelGap): int
    {
        if ($levelGap < self::UPSET_DAMAGE_MIN_LEVEL_GAP) {
            return 0;
        }

        $chance = min(
            self::UPSET_DAMAGE_MAX_CHANCE,
            self::UPSET_DAMAGE_BASE_CHANCE + (int) floor($levelGap / 10) * self::UPSET_DAMAGE_CHANCE_PER_10_LEVELS
        );

        if (random_int(1, 100) > $chance) {
            return 0;
        }

        $percent = min(
            self::UPSET_DAMAGE_MAX_HP_PERCENT,
            self::UPSET_DAMAGE_MIN_HP_PERCENT + (int) floor($levelGap / 20) * self::UPSET_DAMAGE_HP_PERCENT_PER_20_LEVELS
        );
        $percent = random_int(self::UPSET_DAMAGE_MIN_HP_PERCENT, $percent);
        $maxHp = max(1, (int) $defender->maxHp);

        return max(1, (int) floor($maxHp * $percent / 100));
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
                'hit' => false,
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
            'hit' => true,
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

    /**
     * 現チャンプ(character_idがある場合のみ)の保存済み戦闘ステータスを、
     * 現在のCharacterStatusServiceの計算結果で上書きする。防衛回数・現在HP・任命日時は変更しない
     * （HP/SPは新最大値を超えないようクランプするのみ）。
     * 武器の再スケール後、appointed_at時点のスナップショットを最新化するために使う。
     */
    public function refreshCurrentChampStats(): ?ChampState
    {
        $champ = ChampState::query()->first();
        if (! $champ || ! $champ->character_id) {
            return null;
        }

        $challenger = Character::find($champ->character_id);
        if (! $challenger) {
            return null;
        }

        CharacterStatusService::clearRequestCache($challenger->id);
        $stats = $this->snapshotStats($challenger);

        $champ->fill([
            'atk' => $stats['str'],
            'def' => $stats['def'],
            'mag' => $stats['mag'],
            'spr' => $stats['spr'],
            'spd' => $stats['agi'],
            'luk' => $stats['luk'],
            'max_hp' => $stats['max_hp'],
            'max_mp' => $stats['max_mp'] ?? 0,
            'current_hp' => min((int) $champ->current_hp, (int) $stats['max_hp']),
            'current_mp' => min((int) ($champ->current_mp ?? 0), (int) ($stats['max_mp'] ?? 0)),
        ])->save();

        return $champ;
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
        $candidates = $this->champRewardMaterialCandidates($character);
        $candidate = !empty($candidates)
            ? $candidates[array_rand($candidates)]
            : ['material_code' => 'MAT_COMMON_MONSTER_FRAGMENT', 'name' => '魔物の欠片'];

        $material = $this->materialByCodeOrFallback($candidate['material_code'], $candidate['name']);

        return [
            'material' => $material,
            'quantity' => random_int(1, 2),
        ];
    }

    private function champRewardMaterialCandidates(Character $character): array
    {
        $candidates = $this->equippedEvolutionMaterialCandidates($character);

        foreach (self::CHAMP_COMMON_REWARD_MATERIALS as $commonMaterial) {
            $material = $this->materialByCodeOrName(
                (string) $commonMaterial['material_code'],
                (string) $commonMaterial['name']
            );

            if (!$material || $this->isExcludedChampRewardMaterial($material)) {
                continue;
            }

            $candidates[] = [
                'material_code' => (string) $material->material_code,
                'name' => $material->displayName(),
            ];
        }

        return $this->uniqueMaterialCandidates($candidates);
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

        return $this->uniqueMaterialCandidates($candidates);
    }

    private function uniqueMaterialCandidates(array $candidates): array
    {
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

        if (in_array($code, self::CHAMP_REWARD_EXCLUDED_RECIPE_CODES, true)) {
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

        if ($material && $this->isExcludedChampRewardMaterial($material)) {
            return null;
        }

        return $material ? ['material_code' => (string) $material->material_code, 'name' => $material->displayName()] : null;
    }

    private function isExcludedChampRewardMaterial(Material $material): bool
    {
        $code = (string) ($material->material_code ?? '');
        if (in_array($code, self::CHAMP_REWARD_EXCLUDED_MATERIAL_CODES, true)) {
            return true;
        }

        $type = (string) ($material->material_type ?? '');
        if (in_array($type, self::CHAMP_REWARD_EXCLUDED_MATERIAL_TYPES, true)) {
            return true;
        }

        $name = (string) ($material->name ?? '');
        foreach (self::CHAMP_REWARD_EXCLUDED_NAME_KEYWORDS as $keyword) {
            if ($keyword !== '' && str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
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
        $material = $this->materialByCodeOrName($code, $fallbackName);
        if ($material) {
            return $material;
        }

        return $this->fallbackChampMaterial();
    }

    private function materialByCodeOrName(string $code, string $name): ?Material
    {
        return Material::where('material_code', $code)->first()
            ?: ($name !== '' ? Material::where('name', $name)->first() : null);
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
