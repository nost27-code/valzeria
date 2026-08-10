<?php

namespace App\Services;

use App\Models\Skill;

/**
 * Frozen v2-only role-diversity semantics for released job arts.
 *
 * Master power and hit count deliberately stay in the Skill master. Entries are
 * keyed by the complete trusted identity so same-named special skills cannot
 * inherit these effects accidentally.
 */
final class JobArtV2RoleEffectCatalog
{
    private const PRODUCTION_BALANCE_CANDIDATE = 'A';

    /**
     * Calibration candidates are selected only by an explicit constructor
     * argument. Runtime configuration and environment variables cannot change
     * the production candidate accidentally.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const BALANCE_CANDIDATES = [
        'A' => [
            '28:1:剣気集中' => [
                'prepared_effect' => ['damage_multiplier' => 1.20],
                'effect_texts' => ['次の反撃系Rank5/9の最終ダメージ ×1.20（2回・最大6回の自分の行動機会）'],
            ],
        ],
        'B' => [
            '28:1:剣気集中' => [
                'prepared_effect' => ['damage_multiplier' => 1.25],
                'effect_texts' => ['次の反撃系Rank5/9の最終ダメージ ×1.25（2回・最大6回の自分の行動機会）'],
            ],
        ],
        'C' => [
            '28:1:剣気集中' => [
                'prepared_effect' => ['damage_multiplier' => 1.30],
                'effect_texts' => ['次の反撃系Rank5/9の最終ダメージ ×1.30（2回・最大6回の自分の行動機会）'],
            ],
        ],
    ];

    public function __construct(
        private readonly string $balanceCandidate = self::PRODUCTION_BALANCE_CANDIDATE,
    ) {
        if (! isset(self::BALANCE_CANDIDATES[$balanceCandidate])) {
            throw new \InvalidArgumentException("Unknown Job Art role balance candidate: {$balanceCandidate}");
        }
    }

    /** @var array<string, array<string, mixed>> */
    private const ARTS = [
        // Counter: tempo, sustained preparation, and distinct finishers.
        '11:1:納刀' => [
            'role_key' => 'counter_tempo',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'timed_effect' => [
                'key' => 'counter_sheathed_tempo',
                'modifiers' => ['str' => 0.05],
                'rounds' => 2,
                'removable' => true,
                'strength' => 5,
            ],
            'effect_texts' => ['ATK +5%（2ラウンド）'],
        ],
        '13:1:闘争本能' => [
            'role_key' => 'counter_sustained_buff',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'timed_effect' => [
                'key' => 'counter_fighting_instinct',
                'modifiers' => ['str' => 0.25, 'def' => 0.20],
                'rounds' => 5,
                'removable' => true,
                'strength' => 25,
            ],
            'effect_texts' => ['ATK +25% / DEF +20%（5ラウンド）'],
        ],
        '28:1:剣気集中' => [
            'role_key' => 'counter_finisher_prep',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'prepared_effect' => [
                'key' => 'counter_focus',
                'charges' => 2,
                'action_opportunities' => 6,
                'trigger' => ['lineage_key' => 'counter', 'learn_ranks' => [5, 9]],
                'damage_multiplier' => 1.20,
                'consume_on_execution' => true,
                'consume_results' => ['hit', 'miss', 'evade'],
                'retain_on_activation_failure' => true,
                'retain_on_ineligible_action' => true,
                'stack_group' => 'counter_focus',
            ],
            'effect_texts' => ['次の反撃系Rank5/9の最終ダメージ ×1.20（2回・最大6回の自分の行動機会）'],
        ],
        '1:9:剣気解放' => [
            'role_key' => 'counter_sustained_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'timed_effect' => [
                'key' => 'counter_release',
                'modifiers' => ['str' => 0.20, 'def' => 0.10],
                'rounds' => 3,
                'removable' => true,
                'strength' => 20,
            ],
            'effect_texts' => ['発動後 ATK +20% / DEF +10%（3ラウンド）'],
        ],
        '11:9:刹那雪月花' => [
            'role_key' => 'counter_multi_hit_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'preserve_existing_critical_per_hit' => true,
            'effect_texts' => ['追加自己強化なし', '既存の各Hit会心判定を維持'],
        ],
        '13:9:コロッセオブレイク' => [
            'role_key' => 'counter_reactive_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'conditional_damage_multiplier' => [
                'condition' => 'physical_attack_received_since_previous_own_action',
                'multiplier' => 1.15,
                'source_action_once' => true,
                'parried_hit_counts' => true,
                'miss_or_evade_counts' => false,
            ],
            'effect_texts' => ['直前の自分の行動後に物理攻撃を受けていれば最終ダメージ ×1.15'],
        ],

        // Aim: three Rank5 choices with distinct accuracy/critical/routes.
        '4:5:狙い撃ち' => [
            'role_key' => 'aim_high_accuracy',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'accuracy_delta_points' => 8,
            'accuracy_max_percent' => 97,
            'effect_texts' => ['この攻撃だけ命中率 +8ポイント（上限97%）'],
        ],
        '18:5:クリティカルショット' => [
            'role_key' => 'aim_critical_shot',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'accuracy_delta_points' => 5,
            'critical_delta_points' => 10,
            'critical_mode' => 'existing_roll_delta',
            'effect_texts' => ['この攻撃だけ命中率 +5ポイント', '既存会心率 +10ポイント'],
        ],
        '22:5:エレメントアロー' => [
            'role_key' => 'aim_adaptive_route',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'adaptive_route' => [
                'routes' => ['physical', 'magical'],
                'selection' => 'higher_expected_damage',
                'physical_attack_stat' => 'str',
                'physical_defense_stat' => 'def',
                'magical_attack_stat' => 'mag',
                'magical_defense_stat' => 'spr',
                'tie_breaker' => 'master_damage_type',
                'consume_rng' => false,
                'additional_attack' => false,
            ],
            'effect_texts' => ['物理経路と魔法経路を比較し、期待ダメージが高い方で1回攻撃'],
        ],

        // Field v1.3.1: portable field creation and the bard's neutral extension.
        '6:1:魔力の火種' => [
            'role_key' => 'field_star_light_producer',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'MAGICAL_DAMAGE',
            'field' => [
                'operation' => 'deploy',
                'selection_mode' => 'fixed',
                'field_key' => 'star_light',
                'apply_new_field_to_source_action' => false,
            ],
            'effect_texts' => ['星光の場を展開', '生成した場はこの戦技自身に適用しない'],
        ],
        '23:1:鼓舞の小節' => [
            'role_key' => 'field_melody_producer',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'MAGICAL_DAMAGE',
            'field' => [
                'operation' => 'deploy',
                'selection_mode' => 'fixed',
                'field_key' => 'melody',
                'apply_new_field_to_source_action' => false,
            ],
            'effect_texts' => ['旋律の場を展開', '生成した場はこの戦技自身に適用しない'],
        ],
        '23:5:勇気の旋律' => [
            'role_key' => 'field_neutral_extension',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'resource_override' => [
                'resource_role' => 'neutral',
                'resource_gain_points' => 0,
                'resource_cost_points' => 0,
                'minimum_resource_points' => 0,
            ],
            'field' => [
                'operation' => 'extend',
                'extend_rounds' => 2,
                'requires_primary_field' => true,
            ],
            'effect_texts' => ['現在の場を2ラウンド延長', '星印を増減しない'],
        ],

        // Eclipse: portable combat buffs; foreign resource remains forbidden.
        '9:1:属性付与' => [
            'role_key' => 'eclipse_adaptive_buff',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'timed_effect' => [
                'key' => 'eclipse_attribute_enchant',
                'dynamic_modifier' => [
                    'select' => 'higher_current_stat',
                    'stats' => ['str', 'mag'],
                    'tie_breaker' => 'str',
                    'rate' => 0.10,
                ],
                'rounds' => 2,
                'removable' => true,
                'strength' => 10,
            ],
            'effect_texts' => ['現在値が高い方のATK/MAGを +10%（同値はATK・2ラウンド）'],
        ],
        '14:1:血潮の咆哮' => [
            'role_key' => 'eclipse_blood_buff',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'self_cost' => [
                'type' => 'max_hp_rate',
                'rate' => 0.03,
                'non_lethal' => true,
                'damage_source' => 'self_damage',
            ],
            'timed_effect' => [
                'key' => 'eclipse_blood_roar',
                'modifiers' => ['str' => 0.30, 'mag' => 0.25],
                'rounds' => 5,
                'removable' => true,
                'strength' => 30,
            ],
            'effect_texts' => ['最大HP3%を非致死で消費', 'ATK +30% / MAG +25%（5ラウンド）'],
        ],

        // Pierce: mutually exclusive burst/flexible preparation.
        '2:1:挑発撃' => [
            'role_key' => 'pierce_burst_prep',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'prepared_effect' => [
                'key' => 'pierce_burst_prep',
                'charges' => 1,
                'trigger' => ['lineage_key' => 'pierce', 'learn_ranks' => [5, 9]],
                'damage_multiplier' => 1.15,
                'retain_on_activation_failure' => true,
                'expire_on_next_executed_non_trigger_action' => true,
                'stack_group' => 'pierce_prep',
                'replace_same_group' => true,
            ],
            'effect_texts' => ['次の行動が貫通系Rank5/9なら最終ダメージ ×1.15'],
        ],
        '16:1:実戦勘' => [
            'role_key' => 'pierce_flexible_prep',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'prepared_effect' => [
                'key' => 'pierce_flexible_prep',
                'charges' => 1,
                'rounds' => 3,
                'trigger' => ['lineage_key' => 'pierce', 'learn_ranks' => [5, 9]],
                'damage_multiplier' => 1.10,
                'retain_on_intervening_action' => true,
                'consume_on_trigger_execution' => true,
                'stack_group' => 'pierce_prep',
                'replace_same_group' => true,
            ],
            'effect_texts' => ['3ラウンド以内の次の貫通系Rank5/9を ×1.10', '途中で別行動をしても維持'],
        ],

        // Transmute Rank1 choices.
        '20:1:旅支度' => [
            'role_key' => 'transmute_long_battle_buff',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'timed_effect' => [
                'key' => 'transmute_travel_preparation',
                'modifiers' => [
                    'str' => 0.05,
                    'def' => 0.05,
                    'mag' => 0.05,
                    'spr' => 0.05,
                ],
                'rounds' => 3,
                'removable' => true,
                'strength' => 5,
            ],
            'effect_texts' => ['ATK / DEF / MAG / SPR +5%（3ラウンド）'],
        ],
        '38:1:商聖の助言' => [
            'role_key' => 'transmute_lowest_stat_advice',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'timed_effect' => [
                'key' => 'transmute_sage_advice',
                'dynamic_modifier' => [
                    'select' => 'lowest_current_stat',
                    'stats' => ['str', 'def', 'mag', 'spr', 'agi', 'luk'],
                    'tie_break_order' => ['str', 'def', 'mag', 'spr', 'agi', 'luk'],
                    'rate' => 0.20,
                    'consume_rng' => false,
                ],
                'rounds' => 3,
                'removable' => true,
                'strength' => 20,
            ],
            'effect_texts' => ['現在値が最も低い能力1つを +20%（3ラウンド）'],
        ],

        // Guard: raw healing versus lower healing plus accident prevention.
        '7:1:ヒール' => [
            'role_key' => 'guard_pure_heal',
            'portable' => true,
            'suppress_legacy_effect' => false,
            'heal' => ['formula' => 'existing_spr', 'multiplier' => 1.0],
            'effect_texts' => ['既存SPR依存回復を100%使用', '追加加護なし'],
        ],
        '36:1:聖戦の祈り' => [
            'role_key' => 'guard_heal_and_guard',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'heal' => ['formula' => 'existing_spr', 'multiplier' => 0.70],
            'guard' => [
                'damage_reduction_rate' => 0.15,
                'charges' => 1,
                'scope' => 'direct_damage',
                'exclude' => ['dot'],
            ],
            'effect_texts' => ['基礎回復量の70%を回復', '次のdirect damageを15%軽減（1回）'],
        ],

        // Transmute reward/support identity.
        '8:5:幸運の一手' => [
            'role_key' => 'transmute_gold_specialist',
            'portable' => true,
            'suppress_legacy_effect' => false,
            'reward' => ['gold' => 'preserve_master', 'drop' => false],
            'effect_texts' => ['Gold補正のみ（既存補正量を維持）'],
        ],
        '20:5:掘り出し物' => [
            'role_key' => 'transmute_drop_specialist',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'reward' => ['gold' => false, 'drop' => 'preserve_master'],
            'effect_texts' => ['Drop補正のみ（既存補正量を維持）'],
        ],
        '31:1:黄金鑑定' => [
            'role_key' => 'transmute_appraisal',
            'portable' => true,
            'suppress_legacy_effect' => false,
            'reward' => ['gold' => 'preserve_master', 'drop' => 'preserve_master'],
            'appraisal' => ['apply_to_target' => true],
            'effect_texts' => ['鑑定付与', '既存の探索報酬補助を維持'],
        ],
        '47:1:聖薬散布' => [
            'role_key' => 'transmute_single_cleanse_medicine',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'cleanse' => [
                'maximum_states' => 1,
                'priority' => ['burn', 'poison', 'bleed', 'def_down', 'slow', 'recovery_block'],
            ],
            'heal' => ['formula' => 'max_hp_rate', 'rate' => 0.05],
            'reward' => ['gold' => false, 'drop' => false],
            'effect_texts' => ['有害状態を優先順に最大1種類解除', '最大HPの5%回復'],
        ],
        '8:9:大番振る舞い' => [
            'role_key' => 'transmute_basic_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'heal' => [
                'hp' => ['formula' => 'max_hp_rate', 'rate' => 0.10],
                'sp' => ['formula' => 'max_sp_rate', 'rate' => 0.05],
            ],
            'guard' => false,
            'effect_texts' => ['最大HPの10%回復', '最大SPの5%回復'],
        ],
        '20:9:大商隊の守護' => [
            'role_key' => 'transmute_long_battle_guard',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'guard' => [
                'damage_reduction_rate' => 0.20,
                'charges' => 1,
                'scope' => 'direct_damage',
            ],
            'heal' => false,
            'effect_texts' => ['次のdirect damageを20%軽減（1回）'],
        ],
        '31:9:王立独占契約' => [
            'role_key' => 'transmute_reward_finisher',
            'portable' => true,
            'suppress_legacy_effect' => false,
            'reward' => ['gold' => 'preserve_master', 'drop' => 'preserve_master'],
            'effect_texts' => ['既存のGold / Drop報酬補正を維持'],
        ],
        '38:9:富国の錬金陣' => [
            'role_key' => 'transmute_buff_harvest',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'remove_positive_effect' => [
                'maximum_effects' => 1,
                'selection' => 'highest_strength',
                'removable_only' => true,
            ],
            'timed_effect' => [
                'key' => 'transmute_harvested_power',
                'requires' => 'positive_effect_removed',
                'dynamic_modifier' => [
                    'select' => 'lowest_current_stat',
                    'stats' => ['str', 'def', 'mag', 'spr', 'agi', 'luk'],
                    'tie_break_order' => ['str', 'def', 'mag', 'spr', 'agi', 'luk'],
                    'rate' => 0.15,
                    'consume_rng' => false,
                ],
                'rounds' => 3,
                'removable' => true,
                'strength' => 15,
            ],
            'reward' => ['gold' => false, 'drop' => false],
            'effect_texts' => ['解除可能な最も強い強化を1種類解除', '成功時、現在値が最も低い能力を +15%（3ラウンド）'],
        ],
        '47:9:神薬アムリタ' => [
            'role_key' => 'transmute_support_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'cleanse' => [
                'maximum_states' => 'all',
                'states' => ['burn', 'poison', 'bleed', 'def_down', 'slow', 'recovery_block'],
            ],
            'heal' => ['formula' => 'max_hp_rate', 'rate' => 0.15],
            'reward' => ['gold' => false, 'drop' => false],
            'effect_texts' => ['解除可能な有害状態を全件解除', '最大HPの15%回復'],
        ],

        // Mechanical support eligibility. Cleanse authority remains the template.
        '25:5:秘薬調合' => [
            'role_key' => 'heal_cleanse_support',
            'portable' => true,
            'suppress_legacy_effect' => false,
            'support_eligibility' => ['hp_recoverable', 'sp_recoverable', 'template_cleanse_possible'],
            'cleanse_authority' => 'effect_template',
            'cleanse' => [
                'maximum_states' => 1,
                'states' => ['burn', 'poison', 'bleed', 'def_down', 'slow', 'recovery_block'],
            ],
            'effect_texts' => ['HP / SP中回復', '有害状態を優先順に最大1種類解除'],
        ],
        '38:5:王者の秘薬' => [
            'role_key' => 'heal_sp_support',
            'portable' => true,
            'suppress_legacy_effect' => false,
            'support_eligibility' => ['hp_recoverable', 'sp_recoverable'],
            'cleanse_authority' => 'effect_template',
            'adaptive_sustain' => [
                'lower_ratio_multiplier' => 1.50,
                'tie_breaker' => 'hp',
            ],
            'effect_texts' => ['HP / SP中回復', '残存割合が低い側の回復量 ×1.50（同率はHP）', '有害状態は解除しない'],
        ],

        // Sword saint / holy sword general overlap.
        '50:1:聖剣構え' => [
            'role_key' => 'counter_holy_guard_stance',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'timed_effect' => [
                'key' => 'counter_holy_sword_stance',
                'modifiers' => ['def' => 0.10, 'spr' => 0.10],
                'rounds' => 2,
                'removable' => true,
                'strength' => 10,
            ],
            'effect_texts' => ['DEF +10% / SPR +10%（2ラウンド）'],
        ],
        '28:9:無双一閃' => [
            'role_key' => 'counter_focused_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'accepts_prepared_effects' => ['counter_focus'],
            'effect_texts' => ['追加自己強化なし', 'counter_focusの共通倍率対象'],
        ],
        '50:9:光翼クロスブレイク' => [
            'role_key' => 'counter_defensive_finisher',
            'portable' => true,
            'suppress_legacy_effect' => true,
            'replacement_template' => 'PHYSICAL_DAMAGE',
            'timed_effect' => [
                'key' => 'counter_light_wing_guard',
                'modifiers' => ['def' => 0.15, 'spr' => 0.15],
                'rounds' => 2,
                'removable' => true,
                'strength' => 15,
            ],
            'rejects_prepared_effects' => ['counter_focus'],
            'effect_texts' => ['発動後 DEF +15% / SPR +15%（2ラウンド）', 'counter_focus専用追加効果なし'],
        ],
    ];

    /** @return array<string, mixed>|null */
    public function forArt(Skill $skill): ?array
    {
        if (! $skill->isJobArt()) {
            return null;
        }

        $key = $this->key($skill);
        $metadata = self::ARTS[$key] ?? null;
        if ($metadata === null) {
            return null;
        }

        $candidate = self::BALANCE_CANDIDATES[$this->balanceCandidate][$key] ?? null;

        return is_array($candidate)
            ? array_replace_recursive($metadata, $candidate)
            : $metadata;
    }

    public function replacementTemplate(Skill $skill): ?string
    {
        $template = $this->forArt($skill)['replacement_template'] ?? null;

        return is_string($template) && $template !== '' ? $template : null;
    }

    public function suppressesLegacyEffect(Skill $skill): bool
    {
        return (bool) ($this->forArt($skill)['suppress_legacy_effect'] ?? false);
    }

    /** @return list<string> */
    public function effectTexts(Skill $skill): array
    {
        $texts = $this->forArt($skill)['effect_texts'] ?? [];

        return is_array($texts)
            ? array_values(array_filter($texts, static fn (mixed $text): bool => is_string($text) && $text !== ''))
            : [];
    }

    public function isPortable(Skill $skill): bool
    {
        return (bool) ($this->forArt($skill)['portable'] ?? false);
    }

    private function key(Skill $skill): string
    {
        return sprintf(
            '%d:%d:%s',
            (int) $skill->job_id,
            (int) $skill->learn_rank,
            trim((string) $skill->name),
        );
    }
}
