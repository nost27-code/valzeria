<?php

namespace App\Services;

use App\Models\Skill;

/**
 * Human-approved boundary for the ten-lineage C-design release candidate.
 *
 * Natural keys are used deliberately. Names, ranks and source jobs must all
 * match; a same-name special skill must never inherit C-design semantics.
 */
final class JobArtV2CDesignCatalog
{
    /** @var list<string> */
    public const PROTOTYPE_LINEAGES = [
        'counter',
        'pierce',
        'hunt',
        'aim',
        'guard',
        'transmute',
        'break',
        'field',
        'eclipse',
        'command',
    ];

    /** @var array<string, true> */
    private const PORTABLE_RANK_ONE = [
        '1:1:斬り払い' => true,
        '2:1:挑発撃' => true,
        '3:1:すり抜け' => true,
        '4:1:足止め矢' => true,
        '5:1:気合拳' => true,
        '6:1:魔力の火種' => true,
        '7:1:ヒール' => true,
        '8:1:金貨投げ' => true,
        '9:1:属性付与' => true,
        '12:1:敵情分析' => true,
    ];

    /**
     * Runtime template needed to expose only the audited base layer. The
     * legacy DAMAGE_BUFF on 挑発撃 represented its now-lineage-only prepared
     * effect, so a tech copy must retain the physical hit but not that buff.
     *
     * @var array<string, string>
     */
    private const TECH_REPLACEMENT_TEMPLATES = [
        '2:1:挑発撃' => 'PHYSICAL_DAMAGE',
        '6:1:魔力の火種' => 'MAGICAL_DAMAGE',
    ];

    /**
     * Exact base-layer effects already modelled by RoleEffectCatalog. These
     * are allowed for a tech card even though all lineage metadata is normally
     * stripped. Resource handling is still disabled by ResourceCatalog.
     *
     * @var array<string, true>
     */
    private const TECH_BASE_ROLE_METADATA = [
        '9:1:属性付与' => true,
    ];

    /**
     * The 16 rows found by the 165-art audit whose proposed base_effect still
     * contained one of the ten lineage resource names. Runtime portability
     * must use these resource/state-free descriptions, never the dirty audit
     * text itself.
     *
     * @var array<string, string>
     */
    private const NORMALIZED_BASE_EFFECTS = [
        '5:1:気合拳' => '相手に自身の通常攻撃と同じ種類のダメージを与える。その後、自分の通常攻撃が物理なら攻撃を+10%、防御を+5%、魔法なら魔力を+10%、精神を+5%する。',
        '9:1:属性付与' => '相手に物理ダメージを与える。その後、現在値が高い方の攻撃または魔力を+10%する（同値なら攻撃・2ターン）。',
        '19:1:マナピック' => '相手に魔力ダメージを与える。その後、最大SPの5%を回復する。',
        '22:1:魔矢装填' => '相手に魔力ダメージを与える。',
        '33:1:練気' => 'なし（系譜専用効果のみ）。',
        '47:1:聖薬散布' => '火傷・毒・出血・防御低下・鈍足・回復阻害から、優先順に最大1種類を浄化する。',
        '51:1:黒炎纏い' => '相手に物理ダメージを与える。',
        '54:1:影糸仕込み' => '相手に物理ダメージを与える。',
        '54:5:影縫い乱舞' => '相手に物理ダメージを与える。',
        '55:1:機導起動' => '相手に物理ダメージを与える。',
        '55:5:鋼機魔導砲' => '相手に物理ダメージを与える。この攻撃の命中率を+5ポイントする。',
        '57:1:金脈錬成' => '相手に魔力ダメージを与える。その後、通常探索勝利時のGold獲得量を1%増やし、通常素材枠の抽選率を1ポイント上げる。',
        '57:5:黄金転化' => '相手に魔力ダメージを与える。その後、通常探索勝利時のGold獲得量を2%増やし、通常素材枠の抽選率を2ポイント上げる。',
        '57:9:黄金創世陣' => '相手に魔力ダメージを与える。その後、通常探索勝利時のGold獲得量を3%増やし、通常素材枠の抽選率を3ポイント上げる。',
        '58:1:雷気充填' => '相手に物理ダメージを与える。',
        '59:1:戦線把握' => '相手に物理ダメージを与える。',
    ];

    /** @var list<string> */
    private const FORBIDDEN_BASE_TERMS = [
        '星印', '剣勢', '冥蝕', '竜気', '狩猟印',
        '照準', '聖護', '触媒', '崩し', '指揮点',
    ];

    public function supportsLineage(?string $lineage): bool
    {
        return is_string($lineage) && in_array($lineage, self::PROTOTYPE_LINEAGES, true);
    }

    public function isPortableRankOne(Skill $skill): bool
    {
        return $skill->isJobArt()
            && isset(self::PORTABLE_RANK_ONE[JobArtV2DeckRoleResolution::artKey($skill)]);
    }

    public function normalizedBaseEffect(Skill $skill): ?string
    {
        if (! $skill->isJobArt()) {
            return null;
        }

        return self::NORMALIZED_BASE_EFFECTS[JobArtV2DeckRoleResolution::artKey($skill)] ?? null;
    }

    public function techReplacementTemplate(Skill $skill): ?string
    {
        if (! $this->isPortableRankOne($skill)) {
            return null;
        }

        return self::TECH_REPLACEMENT_TEMPLATES[JobArtV2DeckRoleResolution::artKey($skill)] ?? null;
    }

    public function allowsTechBaseRoleMetadata(Skill $skill): bool
    {
        return $this->isPortableRankOne($skill)
            && isset(self::TECH_BASE_ROLE_METADATA[JobArtV2DeckRoleResolution::artKey($skill)]);
    }

    /** @return array<string, string> */
    public function normalizedBaseEffects(): array
    {
        return self::NORMALIZED_BASE_EFFECTS;
    }

    /** @return list<string> */
    public function forbiddenBaseTerms(): array
    {
        return self::FORBIDDEN_BASE_TERMS;
    }
}
