<?php

namespace App\Services;

use App\Models\Skill;
use App\Services\Battle\BattleActor;

/**
 * 冠位までの説明文L列を正本にした、戦闘中の数値差分。
 *
 * DB masterのIDや既存レコードは変更せず、exact identityでexecution cloneと
 * battle-memory effectへ同じ値を供給する。
 */
final class JobArtV2CrownBalanceCatalog
{
    /** 王冠剣陣の受け流し成功時に発生する、会心なしの物理反撃power。 */
    public const ROYAL_SWORD_COUNTER_POWER = 90;

    /** @var array<string, array<string, mixed>> */
    private const ARTS = [
        '1:5:受け返し' => [],
        '1:9:剣気解放' => ['buffs' => ['str' => 35,'def' => 20],'duration' => 5],
        '2:5:二段穿ち' => ['hit_count' => 2],
        '2:9:穿貫' => [],
        '3:1:影狩りの構え' => ['debuffs' => ['agi' => 15], 'duration' => 3],
        '3:5:急所狙い' => [],
        '3:9:ファントムロブ' => ['hit_count' => 3,'debuffs' => ['agi' => 20],'duration' => 5],
        '4:1:精密射撃' => [],
        '4:9:五月雨流星射ち' => ['hit_count' => 5],
        '5:1:崩し打ち' => ['debuffs' => ['def' => 15], 'duration' => 3],
        '5:5:連環崩打' => ['hit_count' => 3, 'debuffs' => ['def' => 15, 'spr' => 15], 'duration' => 3],
        '7:1:ヒール' => ['heal_spr' => 100],
        '7:5:癒しの祈り' => ['heal_spr' => 180],
        '7:9:聖域展開' => ['heal_spr' => 300],
        '9:1:属性付与' => ['dynamic_buff' => ['stats' => ['str', 'mag'], 'select' => 'higher_current_stat', 'rate' => 15],'duration' => 4],
        '10:1:聖盾撃' => ['reduction' => 10],
        '10:5:ホーリーブレイド' => ['heal_hp' => 7],
        '11:1:納刀' => ['buffs' => ['str' => 15],'duration' => 4],
        '11:5:居合斬り' => ['buffs' => ['str' => 25,'def' => 10,'mag' => 25,'spr' => 10],'duration' => 4],
        '11:9:刹那雪月花' => ['hit_count' => 3],
        '12:1:敵情分析' => ['debuffs' => ['def' => 10, 'spr' => 10], 'duration' => 3],
        '12:5:勝利の采配' => ['buffs' => ['str' => 25,'def' => 15,'mag' => 25,'spr' => 15],'duration' => 4],
        '13:1:闘争本能' => ['buffs' => ['str' => 25,'def' => 20],'duration' => 5],
        '13:5:闘技連斬' => ['hit_count' => 3],
        '14:1:血潮の咆哮' => ['buffs' => ['str' => 30,'mag' => 25],'duration' => 5],
        '14:9:狂神乱舞' => ['buffs' => ['str' => 35,'def' => 15,'mag' => 35,'spr' => 15],'duration' => 5],
        '15:5:ガーディアンブロウ' => ['reduction' => 16],
        '15:9:不落要塞' => ['reduction' => 30],
        '16:9:傭兵団の総攻撃' => ['debuffs' => ['def' => 20, 'spr' => 20], 'duration' => 4],
        '17:1:影伏せ' => [],
        '17:5:影縫い' => ['debuffs' => ['agi' => 15],'duration' => 3],
        '18:1:マーキング' => ['debuffs' => ['def' => 10, 'spr' => 10], 'duration' => 3],
        '19:1:マナピック' => ['mp_recover_percent' => 0, 'sp_pressure_rate' => 0.02],
        '19:5:スピリットスティール' => ['debuffs' => ['spr' => 12], 'duration' => 3, 'drain_hp_rate' => 0.30, 'mp_recover_percent' => 0, 'sp_pressure_rate' => 0.03],
        '19:9:魂喰らい' => ['drain_hp_rate' => 0.35, 'mp_recover_percent' => 0, 'sp_pressure_rate' => 0.10],
        '20:1:旅支度' => ['buffs' => ['str' => 10, 'def' => 10, 'mag' => 10, 'spr' => 10], 'buff_route' => 'simultaneous', 'duration' => 4],
        '20:9:大商隊の守護' => ['reduction' => 30],
        '21:1:練気呼吸' => ['heal_spr' => 100],
        '21:9:金剛不壊' => ['reduction' => 30],
        '22:9:星霊連弓' => ['hit_count' => 3, 'debuffs' => ['def' => 20, 'spr' => 20], 'duration' => 4],
        '23:9:英雄譚の終章' => ['reduction' => 12],
        '24:1:浄化の光' => ['heal_spr' => 100],
        '24:5:セイクリッドライト' => ['heal_hp' => 6],
        '24:9:大聖堂の奇跡' => ['heal_spr' => 250,'reduction' => 30],
        '25:1:応急手当' => ['heal_spr' => 100],
        '25:5:秘薬調合' => ['heal_spr' => 110],
        '25:9:万能霊薬' => ['heal_spr' => 300],
        '26:1:錬成火花' => ['debuffs' => ['def' => 12],'duration' => 3],
        '26:5:錬成爆弾' => ['debuffs' => ['def' => 15, 'spr' => 15], 'duration' => 3],
        '26:9:賢者の反応炉' => ['buffs' => ['str' => 35,'def' => 20,'mag' => 35,'spr' => 20],'duration' => 5],
        '27:1:勇気の灯' => ['buffs' => ['str' => 15,'def' => 10,'mag' => 15,'spr' => 10],'duration' => 4],
        '27:5:ブレイブヒール' => ['heal_hp' => 9],
        '28:5:無拍子' => ['hit_count' => 2],
        '28:9:無双一閃' => [],
        '29:5:賢者の結界' => ['reduction' => 18],
        '29:9:極大魔法' => ['hit_count' => 2],
        '30:1:闇の契約' => ['buffs' => ['str' => 15,'def' => 5,'mag' => 15,'spr' => 5],'duration' => 4],
        '30:9:深紅の契約' => ['buffs' => ['str' => 35,'def' => 15,'mag' => 35,'spr' => 15],'duration' => 5],
        '31:5:ゴールドラッシュ' => ['hit_count' => 4],
        '32:1:竜槍構え' => ['buffs' => ['str' => 15,'def' => 5,'mag' => 15,'spr' => 5],'duration' => 4],
        '32:5:ドラゴンダイブ' => ['hit_count' => 2],
        '32:9:竜牙天翔' => ['hit_count' => 2],
        '33:1:練気' => ['buffs' => ['str' => 15,'def' => 5,'mag' => 15,'spr' => 5],'duration' => 4],
        '33:5:羅刹連撃' => ['hit_count' => 5],
        '33:9:崩落' => ['hit_count' => 1, 'debuffs' => ['def' => 25, 'spr' => 25], 'duration' => 5],
        '34:1:幻惑歩法' => ['buffs' => ['str' => 15,'def' => 10,'mag' => 15,'spr' => 10],'duration' => 4,'reduction' => 10],
        '34:5:夢幻殺' => ['debuffs' => ['def' => 15, 'spr' => 15], 'duration' => 3],
        '34:9:百影夜行' => ['hit_count' => 3,'reduction' => 25],
        '35:1:機巧展開' => ['buffs' => ['str' => 15,'def' => 10,'mag' => 15,'spr' => 10]],
        '35:9:王機グラビトン' => ['debuffs' => ['def' => 20, 'agi' => 15], 'duration' => 4],
        '36:1:聖戦の祈り' => ['heal_spr' => 65,'reduction' => 25],
        '36:5:神罰の槌' => ['debuffs' => ['mag' => 18],'duration' => 3],
        '36:9:神域審判' => ['heal_hp' => 12],
        '37:1:影追い' => ['debuffs' => ['def' => 10, 'spr' => 10], 'duration' => 3],
        '37:5:シャドウスナイプ' => ['hit_count' => 2],
        '37:9:黒翼処刑' => ['debuffs' => ['def' => 20, 'spr' => 20], 'duration' => 4],
        '38:5:王者の秘薬' => ['heal_spr' => 110],
        '38:9:富国の錬金陣' => ['buffs' => ['str' => 30, 'mag' => 30], 'duration' => 5],
        '44:1:守護の構え' => ['buffs' => ['str' => 15,'def' => 15,'mag' => 15,'spr' => 15],'duration' => 4],
        '44:5:聖盾裁き' => ['buffs' => ['str' => 25,'def' => 20,'mag' => 25,'spr' => 20],'duration' => 4],
        '44:9:天壁イージス' => ['reduction' => 35],
        '46:1:祝詞の一節' => ['buffs' => ['mag' => 15,'spr' => 10],'duration' => 4],
        '46:5:祝福の大旋律' => ['buffs' => ['mag' => 25,'spr' => 15],'duration' => 5],
        '46:9:聖譚フィナーレ' => ['buffs' => ['mag' => 35,'spr' => 20],'duration' => 6],
        '47:9:神薬アムリタ' => ['heal_hp' => 15],
        '50:1:聖剣構え' => ['buffs' => ['def' => 10, 'spr' => 10], 'duration' => 4],
        '50:5:聖剣烈破' => ['buffs' => ['str' => 25,'def' => 15,'mag' => 25,'spr' => 15],'duration' => 4],
        '50:9:光翼クロスブレイク' => ['buffs' => ['def' => 20, 'spr' => 20], 'duration' => 5],
        '56:1:聖域の印' => ['buffs' => ['mag' => 15,'spr' => 15],'duration' => 4],
        '56:5:聖域結界' => ['buffs' => ['mag' => 25,'spr' => 20],'duration' => 4],
        '56:9:聖壁アルカディア' => ['reduction' => 40],
        '58:5:雷拳乱舞' => ['hit_count' => 3],
        '66:1:聖冠加護' => ['reduction' => 25],
        '66:5:聖冠大結界' => ['reduction' => 35],
        '66:9:聖冠アイギスロード' => ['reduction' => 45],
    ];

    public function __construct(
        private readonly JobArtV2CardDescriptionCatalog $descriptionCatalog,
        private readonly JobArtV2Rank5V6Catalog $rank5V6Catalog,
    ) {}

    /** @return array<string, mixed>|null */
    public function forArt(Skill $skill): ?array
    {
        if (! $skill->isJobArt()) {
            return null;
        }

        $metadata = self::ARTS[$this->identity($skill)] ?? [];
        $basePower = $this->descriptionCatalog->basePower($skill);
        if ($basePower !== null) {
            $metadata['power'] = $basePower;
        }
        if ($this->rank5V6Enabled() && $this->rank5V6Catalog->forSkill($skill) !== null) {
            $metadata['_rank5_v6'] = true;
            $jobId = (int) $skill->job_id;
            if (in_array($jobId, [3, 11, 12, 44, 46, 50, 56], true)) {
                unset($metadata['buffs'], $metadata['dynamic_buff'], $metadata['duration']);
            }
            if ($jobId === 36) {
                $metadata['debuffs'] = ['mag' => 15];
            }
            if ($jobId === 15) {
                $metadata['reduction'] = 20;
            }
            if ($jobId === 29) {
                // v6.1ではRoleEffect側の20%へ一本化し、旧18%を二重表示しない。
                unset($metadata['reduction']);
            }
            if ($jobId === 7) {
                $metadata['heal_spr'] = 150;
            }
            if ($this->rank5V6Catalog->isAttackless($skill)) {
                $metadata['power'] = 0;
            }
        }

        return $metadata !== [] ? $metadata : null;
    }

    public function applyToExecution(Skill $skill): Skill
    {
        $metadata = $this->forArt($skill);
        if ($metadata === null) {
            return $skill;
        }

        $copy = clone $skill;
        if (isset($metadata['power'])) {
            $copy->power = (int) $metadata['power'];
            $copy->power_multiplier = (int) $metadata['power'] / 100;
        }
        if (isset($metadata['hit_count'])) {
            $copy->hit_count = (int) $metadata['hit_count'];
        }
        if (isset($metadata['duration'])) {
            $copy->duration_turns = (int) $metadata['duration'];
        }
        if (isset($metadata['heal_hp'])) {
            $copy->heal_percent = (int) $metadata['heal_hp'];
        }
        if (isset($metadata['reduction'])) {
            $copy->damage_reduction_percent = (int) $metadata['reduction'];
        }
        if ($this->rank5V6Enabled() && $this->rank5V6Catalog->forSkill($skill) !== null) {
            $this->applyRank5V6Execution($copy, $skill);
        }
        if (array_key_exists('mp_recover_percent', $metadata)) {
            $copy->mp_recover_percent = (int) $metadata['mp_recover_percent'];
        }
        if (array_key_exists('drain_hp_rate', $metadata)) {
            $copy->drain_hp_rate = (float) $metadata['drain_hp_rate'];
        }
        if (isset($metadata['heal_spr']) && in_array((string) $copy->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)) {
            $copy->power = (int) $metadata['heal_spr'];
            $copy->power_multiplier = (int) $metadata['heal_spr'] / 100;
        }
        if (is_array($metadata['debuffs'] ?? null)) {
            foreach (['str' => 'enemy_atk_down_percent', 'def' => 'enemy_def_down_percent', 'mag' => 'enemy_mag_down_percent', 'spr' => 'enemy_spr_down_percent', 'agi' => 'enemy_spd_down_percent'] as $stat => $attribute) {
                $copy->{$attribute} = (int) ($metadata['debuffs'][$stat] ?? 0);
            }
        }

        return $copy;
    }

    /**
     * Apply the same canonical overrides to an execution clone that has
     * already been allocated by a battle route.
     */
    public function applyToExistingExecution(Skill $skill): void
    {
        $balanced = $this->applyToExecution($skill);
        if ($balanced === $skill) {
            return;
        }

        foreach ([
            'power',
            'power_multiplier',
            'hit_count',
            'duration_turns',
            'heal_percent',
            'damage_reduction_percent',
            'mp_recover_percent',
            'drain_hp_rate',
            'enemy_atk_down_percent',
            'enemy_def_down_percent',
            'enemy_mag_down_percent',
            'enemy_spr_down_percent',
            'enemy_spd_down_percent',
            'effect_template',
            'damage_type',
        ] as $attribute) {
            if ($balanced->getAttribute($attribute) !== null) {
                $skill->setAttribute($attribute, $balanced->getAttribute($attribute));
            }
        }
    }

    /**
     * Reassert canonical offensive values after lineage mechanics selected a
     * route. Heal/guard side effects are intentionally excluded because the
     * role service applies those once through its dedicated state handlers.
     * L列で明示された吸収率とSP回復廃止は、旧master値を確実に上書きする。
     */
    public function reapplyCoreExecutionValues(Skill $skill): void
    {
        $balanced = $this->applyToExecution($skill);
        if ($balanced === $skill) {
            return;
        }

        foreach ([
            'power',
            'power_multiplier',
            'hit_count',
            'duration_turns',
            'mp_recover_percent',
            'drain_hp_rate',
            'enemy_atk_down_percent',
            'enemy_def_down_percent',
            'enemy_mag_down_percent',
            'enemy_spr_down_percent',
            'enemy_spd_down_percent',
            'effect_template',
            'damage_type',
        ] as $attribute) {
            if ($balanced->getAttribute($attribute) !== null) {
                $skill->setAttribute($attribute, $balanced->getAttribute($attribute));
            }
        }
    }

    /** @return array<string, float> */
    public function selfBuffModifiers(Skill $skill, BattleActor $actor): array
    {
        $metadata = $this->forArt($skill) ?? [];
        $dynamic = $metadata['dynamic_buff'] ?? null;
        if (is_array($dynamic)) {
            $stats = array_values(array_filter($dynamic['stats'] ?? [], 'is_string'));
            $selected = null;
            $selectedValue = null;
            foreach ($stats as $stat) {
                $value = match ($stat) {
                    'str' => $actor->effectiveStr(),
                    'mag' => $actor->effectiveMag(),
                    default => null,
                };
                if ($value !== null && ($selected === null || $value > $selectedValue)) {
                    $selected = $stat;
                    $selectedValue = $value;
                }
            }

            return $selected !== null ? [$selected => (int) ($dynamic['rate'] ?? 0) / 100] : [];
        }

        $buffs = $metadata['buffs'] ?? null;
        if (! is_array($buffs)) {
            return [];
        }

        // Four-stat entries describe a physical/magical branch. Two-stat
        // entries such as DEF+SPR or ATK+MAG are simultaneous buffs and must
        // not be reduced to one side by the normal-attack route.
        $hasPhysicalPair = isset($buffs['str']) && isset($buffs['def']);
        $hasMagicalPair = isset($buffs['mag']) && isset($buffs['spr']);
        if ($hasPhysicalPair
            && $hasMagicalPair
            && count($buffs) === 4
            && ($metadata['buff_route'] ?? 'normal_attack') !== 'simultaneous'
        ) {
            $buffs = $actor->usesMagForNormalAttack()
                ? array_intersect_key($buffs, ['mag' => true, 'spr' => true])
                : array_intersect_key($buffs, ['str' => true, 'def' => true]);
        }

        return array_map(static fn (mixed $percent): float => (int) $percent / 100, $buffs);
    }

    public function hasSelfBuff(Skill $skill): bool
    {
        $metadata = $this->forArt($skill) ?? [];

        return is_array($metadata['buffs'] ?? null) || is_array($metadata['dynamic_buff'] ?? null);
    }

    public function durationTurns(Skill $skill): int
    {
        return max(1, (int) ($this->forArt($skill)['duration'] ?? $skill->duration_turns ?? 1));
    }

    public function healSprPercent(Skill $skill): ?int
    {
        $value = $this->forArt($skill)['heal_spr'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public function healHpPercent(Skill $skill): ?int
    {
        $value = $this->forArt($skill)['heal_hp'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    public function reductionPercent(Skill $skill): ?int
    {
        $value = $this->forArt($skill)['reduction'] ?? null;

        return is_numeric($value) ? max(0, min(50, (int) $value)) : null;
    }

    public function guardUntilNextOwnAction(Skill $skill): bool
    {
        return $this->rank5V6Enabled()
            && (int) $skill->job_id === 15
            && $this->rank5V6Catalog->forSkill($skill) !== null;
    }

    private function identity(Skill $skill): string
    {
        return implode(':', [(int) $skill->job_id, (int) $skill->learn_rank, (string) $skill->name]);
    }

    private function applyRank5V6Execution(Skill $copy, Skill $source): void
    {
        $power = $this->rank5V6Catalog->powerFor($source);
        if ($power !== null) {
            $copy->power = $power;
            $copy->power_multiplier = $power / 100;
        }

        if ($this->rank5V6Catalog->isAttackless($source)) {
            $copy->power = 0;
            $copy->power_multiplier = 0;
            $copy->hit_count = 0;
            $copy->damage_type = 'support';
        }

        $jobId = (int) $source->job_id;
        if (in_array($jobId, [8, 20, 31, 57, 77, 91], true)) {
            $copy->mp_recover_percent = 0;
        }
        if ($jobId === 8) {
            $copy->effect_template = 'PHYSICAL_DAMAGE_GOLD_REWARD';
            $copy->hit_count = 1;
            $copy->damage_type = 'physical';
        } elseif ($jobId === 20) {
            $copy->effect_template = 'PHYSICAL_DAMAGE_REWARD';
            $copy->hit_count = 1;
            $copy->damage_type = 'physical';
        } elseif (in_array($jobId, [11, 44, 50], true)) {
            $copy->effect_template = 'PHYSICAL_DAMAGE';
            $copy->hit_count = 1;
            $copy->damage_type = 'physical';
        } elseif ($jobId === 12) {
            $copy->effect_template = 'V2_ROLE_EFFECT_ONLY';
            $copy->hit_count = 0;
            $copy->damage_type = 'support';
        } elseif ($jobId === 29) {
            $copy->effect_template = 'MAGICAL_DAMAGE';
            $copy->hit_count = 1;
            $copy->damage_type = 'magical';
        } elseif ($jobId === 31) {
            $copy->hit_count = 4;
            $copy->damage_type = 'physical';
        } elseif ($jobId === 46) {
            $copy->effect_template = 'MAGICAL_DAMAGE';
            $copy->hit_count = 1;
            $copy->damage_type = 'magical';
        } elseif (in_array($jobId, [57, 77, 84, 91], true)) {
            $copy->damage_type = 'magical';
        }
    }

    private function rank5V6Enabled(): bool
    {
        return (bool) config('battle.job_art_v2.rank5_v6', false)
            && (bool) config('battle.job_art_v2.dynamic_single', false)
            && (bool) config('battle.job_art_v2.hit_resolution', false)
            && (bool) config('battle.job_art_v2.damage_application', false)
            && (bool) config('battle.job_art_v2.resources', false);
    }
}
