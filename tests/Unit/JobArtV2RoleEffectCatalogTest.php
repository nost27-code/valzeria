<?php

namespace Tests\Unit;

use App\Models\Skill;
use App\Services\JobArtV2RoleEffectCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JobArtV2RoleEffectCatalogTest extends TestCase
{
    #[DataProvider('roleArtProvider')]
    public function test_catalog_recognizes_all_frozen_arts_by_complete_natural_key(
        int $jobId,
        int $rank,
        string $name,
        string $roleKey,
    ): void {
        $catalog = new JobArtV2RoleEffectCatalog;
        $skill = $this->art($jobId, $rank, $name, power: 999, hitCount: 7);
        $metadata = $catalog->forArt($skill);

        $this->assertNotNull($metadata, "{$jobId}:{$rank}:{$name}");
        $this->assertSame($roleKey, $metadata['role_key'] ?? null, $name);
        $this->assertTrue($catalog->isPortable($skill), $name);
        $this->assertSame(
            (bool) ($metadata['suppress_legacy_effect'] ?? false),
            $catalog->suppressesLegacyEffect($skill),
            $name,
        );
        $this->assertSame($metadata['replacement_template'] ?? null, $catalog->replacementTemplate($skill), $name);
        $this->assertSame($metadata['effect_texts'] ?? [], $catalog->effectTexts($skill), $name);

        foreach (['power', 'hit_count', 'source_power', 'source_hit_count'] as $masterKey) {
            $this->assertArrayNotHasKey($masterKey, $metadata, "{$name}: {$masterKey} belongs to the master Skill.");
        }
    }

    public function test_catalog_contains_exactly_the_sixty_three_frozen_natural_keys(): void
    {
        $this->assertCount(63, self::roleArtProvider());
    }

    public function test_same_named_special_skill_is_not_treated_as_a_job_art(): void
    {
        $catalog = new JobArtV2RoleEffectCatalog;
        $special = $this->art(11, 1, '納刀', skillType: 'special');

        $this->assertNull($catalog->forArt($special));
        $this->assertNull($catalog->replacementTemplate($special));
        $this->assertFalse($catalog->suppressesLegacyEffect($special));
        $this->assertSame([], $catalog->effectTexts($special));
        $this->assertFalse($catalog->isPortable($special));
    }

    public function test_job_rank_and_name_must_all_match(): void
    {
        $catalog = new JobArtV2RoleEffectCatalog;

        foreach ([
            $this->art(999, 1, '納刀'),
            $this->art(11, 5, '納刀'),
            $this->art(11, 1, '納刀・別名'),
        ] as $untrusted) {
            $this->assertNull($catalog->forArt($untrusted));
            $this->assertFalse($catalog->suppressesLegacyEffect($untrusted));
            $this->assertFalse($catalog->isPortable($untrusted));
        }
    }

    public function test_rank5_v6_guard_overrides_require_the_complete_natural_key(): void
    {
        $catalog = new JobArtV2RoleEffectCatalog;

        foreach ([
            [29, '賢者の結界'],
            [36, '神罰の槌'],
        ] as [$jobId, $name]) {
            $metadata = $catalog->rank5V6MetadataForArt($this->art($jobId, 5, $name));
            $this->assertNotNull($metadata, $name);
            $this->assertSame(20, $metadata['next_action_damage_reduction_percent'], $name);
        }

        $this->assertNull($catalog->rank5V6MetadataForArt($this->art(29, 1, '賢者の結界')));
        $this->assertNull($catalog->rank5V6MetadataForArt($this->art(29, 5, '賢者の結界・別名')));
        $this->assertNull($catalog->rank5V6MetadataForArt($this->art(36, 5, '神罰の槌', skillType: 'special')));
    }

    public function test_master_power_and_hit_count_do_not_change_catalog_resolution(): void
    {
        $catalog = new JobArtV2RoleEffectCatalog;
        $masterValues = $this->art(11, 9, '刹那雪月花', power: 255, hitCount: 3);
        $differentValues = $this->art(11, 9, '刹那雪月花', power: 1, hitCount: 99);

        $this->assertSame($catalog->forArt($masterValues), $catalog->forArt($differentValues));
        $this->assertSame('PHYSICAL_DAMAGE', $catalog->replacementTemplate($masterValues));
    }

    public function test_pierce_burst_preparation_waits_for_a_pierce_consumer_or_finisher(): void
    {
        $metadata = (new JobArtV2RoleEffectCatalog)->forArt($this->art(2, 1, '挑発撃'));
        $prepared = $metadata['prepared_effect'] ?? [];

        $this->assertTrue((bool) ($prepared['retain_on_intervening_action'] ?? false));
        $this->assertArrayNotHasKey('expire_on_next_executed_non_trigger_action', $prepared);
        $this->assertSame([5, 9], $prepared['trigger']['learn_ranks'] ?? null);
        $this->assertSame(1.15, $prepared['damage_multiplier'] ?? null);
    }

    public function test_balance_candidates_are_explicit_environment_independent_and_default_to_a(): void
    {
        $fightingInstinct = $this->art(13, 1, '闘争本能');
        $bloodRoar = $this->art(14, 1, '血潮の咆哮');
        $focus = $this->art(28, 1, '剣気集中');

        $production = new JobArtV2RoleEffectCatalog;
        $this->assertSame(0.25, $this->nestedValue($production->forArt($fightingInstinct), 'timed_effect.modifiers.str'));
        $this->assertSame(0.20, $this->nestedValue($production->forArt($fightingInstinct), 'timed_effect.modifiers.def'));
        $this->assertSame(5, $this->nestedValue($production->forArt($fightingInstinct), 'timed_effect.rounds'));
        $this->assertSame(0.30, $this->nestedValue($production->forArt($bloodRoar), 'timed_effect.modifiers.str'));
        $this->assertSame(0.25, $this->nestedValue($production->forArt($bloodRoar), 'timed_effect.modifiers.mag'));
        $this->assertSame(5, $this->nestedValue($production->forArt($bloodRoar), 'timed_effect.rounds'));
        $this->assertSame(1.20, $this->nestedValue($production->forArt($focus), 'prepared_effect.damage_multiplier'));

        $candidateB = new JobArtV2RoleEffectCatalog('B');
        $this->assertSame(0.25, $this->nestedValue($candidateB->forArt($fightingInstinct), 'timed_effect.modifiers.str'));
        $this->assertSame(0.25, $this->nestedValue($candidateB->forArt($bloodRoar), 'timed_effect.modifiers.mag'));
        $this->assertSame(1.25, $this->nestedValue($candidateB->forArt($focus), 'prepared_effect.damage_multiplier'));

        $candidateC = new JobArtV2RoleEffectCatalog('C');
        $this->assertSame(5, $this->nestedValue($candidateC->forArt($fightingInstinct), 'timed_effect.rounds'));
        $this->assertSame(0.30, $this->nestedValue($candidateC->forArt($bloodRoar), 'timed_effect.modifiers.str'));
        $this->assertSame(1.30, $this->nestedValue($candidateC->forArt($focus), 'prepared_effect.damage_multiplier'));

        $this->expectException(\InvalidArgumentException::class);
        new JobArtV2RoleEffectCatalog('ENV_VALUE');
    }

    #[DataProvider('fixedValueProvider')]
    public function test_frozen_role_values_are_kept_in_the_catalog(
        int $jobId,
        int $rank,
        string $name,
        string $path,
        mixed $expected,
    ): void {
        $metadata = (new JobArtV2RoleEffectCatalog)->forArt($this->art($jobId, $rank, $name));

        $this->assertNotNull($metadata, $name);
        $this->assertSame($expected, $this->nestedValue($metadata, $path), "{$name}:{$path}");
    }

    /** @return array<string, array{int, int, string, string}> */
    public static function roleArtProvider(): array
    {
        return [
            'wave 2-C phase 1 counter combo' => [1, 5, '受け返し', 'counter_parry_riposte_combo'],
            'wave 2-C phase 1 hunt producer' => [17, 1, '影伏せ', 'hunt_shadow_ambush_preparation'],
            'wave 2-B pierce finisher' => [2, 9, '穿貫', 'pierce_single_high_penetration_finisher'],
            'wave 2-B hunt producer' => [3, 1, '影狩りの構え', 'hunt_speed_debuff_producer'],
            'wave 2-B hunt combo' => [3, 5, '急所狙い', 'hunt_critical_combo'],
            'wave 2-B aim producer' => [4, 1, '精密射撃', 'aim_precision_producer'],
            'wave 2-B break producer' => [5, 1, '崩し打ち', 'break_defense_debuff_producer'],
            'wave 2-B break combo' => [5, 5, '連環崩打', 'break_chain_debuff_combo'],
            'field silence producer' => [29, 1, '静寂の帳', 'field_silence_producer'],
            'break low hp finisher' => [5, 9, '大崩拳', 'break_desperate_finisher'],
            'eclipse low hp finisher' => [9, 9, '蝕みの終端', 'eclipse_attrition_finisher'],
            'command total war' => [12, 9, '総力戦', 'command_total_war_finisher'],
            'guard unyielding vow' => [15, 1, '不屈の誓い', 'guard_unyielding_vow'],
            'counter tempo' => [11, 1, '納刀', 'counter_tempo'],
            'counter sustained' => [13, 1, '闘争本能', 'counter_sustained_buff'],
            'counter focus' => [28, 1, '剣気集中', 'counter_finisher_prep'],
            'counter release' => [1, 9, '剣気解放', 'counter_sustained_finisher'],
            'counter multi hit' => [11, 9, '刹那雪月花', 'counter_multi_hit_finisher'],
            'counter reactive' => [13, 9, 'コロッセオブレイク', 'counter_reactive_finisher'],
            'hunt defensive producer' => [34, 1, '幻惑歩法', 'hunt_defensive_producer'],
            'aim cannon preparation' => [35, 1, '機巧展開', 'aim_cannon_preparation'],
            'aim spirit piercing cannon' => [35, 5, '魔導砲', 'aim_spirit_piercing_cannon'],
            'aim accuracy' => [4, 5, '狙い撃ち', 'aim_high_accuracy'],
            'aim critical' => [18, 5, 'クリティカルショット', 'aim_critical_shot'],
            'aim adaptive' => [22, 5, 'エレメントアロー', 'aim_adaptive_route'],
            'aim magic against defense' => [45, 5, '魔弓連星', 'aim_magic_against_pierced_defense'],
            'counter adaptive break' => [70, 5, '暁光ブレイク', 'counter_adaptive_break'],
            'sage attack barrier' => [29, 5, '賢者の結界', 'field_sage_attack_barrier'],
            'sage spr finisher' => [29, 9, '極大魔法', 'field_sage_spr_piercing_finisher'],
            'field star light' => [6, 1, '魔力の火種', 'field_star_light_producer'],
            'field melody' => [23, 1, '鼓舞の小節', 'field_melody_producer'],
            'field extension' => [23, 5, '勇気の旋律', 'field_neutral_extension'],
            'field melody timed blessing' => [46, 1, '祝詞の一節', 'field_melody_timed_blessing'],
            'eclipse adaptive' => [9, 1, '属性付与', 'eclipse_adaptive_buff'],
            'eclipse blood' => [14, 1, '血潮の咆哮', 'eclipse_blood_buff'],
            'pierce burst' => [2, 1, '挑発撃', 'pierce_burst_prep'],
            'pierce flexible' => [16, 1, '実戦勘', 'pierce_flexible_prep'],
            'transmute travel' => [20, 1, '旅支度', 'transmute_long_battle_buff'],
            'transmute advice' => [38, 1, '商聖の助言', 'transmute_lowest_stat_advice'],
            'break exorcising strike' => [21, 5, '破邪拳', 'break_exorcising_strike'],
            'transmute adaptive bomb' => [26, 5, '錬成爆弾', 'transmute_adaptive_bomb'],
            'guard heal' => [7, 1, 'ヒール', 'guard_pure_heal'],
            'guard prayer' => [36, 1, '聖戦の祈り', 'guard_heal_and_guard'],
            'guard sanctuary' => [56, 5, '聖域結界', 'guard_sanctuary_barrier'],
            'guard physical shield finisher' => [44, 9, '天壁イージス', 'guard_physical_shield_finisher'],
            'guard magical shield finisher' => [56, 9, '聖壁アルカディア', 'guard_magical_shield_finisher'],
            'gold specialist' => [8, 5, '幸運の一手', 'transmute_gold_specialist'],
            'drop specialist' => [20, 5, '掘り出し物', 'transmute_drop_specialist'],
            'appraisal' => [31, 1, '黄金鑑定', 'transmute_appraisal'],
            'single cleanse' => [47, 1, '聖薬散布', 'transmute_single_cleanse_medicine'],
            'basic finisher' => [8, 9, '大番振る舞い', 'transmute_basic_finisher'],
            'long battle guard' => [20, 9, '大商隊の守護', 'transmute_long_battle_guard'],
            'single guard finisher' => [21, 9, '金剛不壊', 'break_single_guard_finisher'],
            'cathedral cleanse finisher' => [24, 9, '大聖堂の奇跡', 'field_cathedral_cleanse_finisher'],
            'hybrid multi hit finisher' => [27, 9, 'ギガブレイク', 'command_hybrid_multi_hit_finisher'],
            'reward finisher' => [31, 9, '王立独占契約', 'transmute_reward_finisher'],
            'buff harvest' => [38, 9, '富国の錬金陣', 'transmute_buff_harvest'],
            'support finisher' => [47, 9, '神薬アムリタ', 'transmute_support_finisher'],
            'heal cleanse eligibility' => [25, 5, '秘薬調合', 'heal_cleanse_support'],
            'heal sp eligibility' => [38, 5, '王者の秘薬', 'heal_sp_support'],
            'holy guard stance' => [50, 1, '聖剣構え', 'counter_holy_guard_stance'],
            'focused finisher' => [28, 9, '無双一閃', 'counter_focused_finisher'],
            'defensive finisher' => [50, 9, '光翼クロスブレイク', 'counter_defensive_finisher'],
        ];
    }

    /** @return array<string, array{int, int, string, string, mixed}> */
    public static function fixedValueProvider(): array
    {
        return [
            'riposte condition' => [1, 5, '受け返し', 'conditional_damage_multiplier.condition', 'parry_success_since_previous_own_action'],
            'riposte multiplier' => [1, 5, '受け返し', 'conditional_damage_multiplier.multiplier', 1.35],
            'shadow ambush charge' => [17, 1, '影伏せ', 'prepared_effect.charges', 1],
            'shadow ambush opportunities' => [17, 1, '影伏せ', 'prepared_effect.action_opportunities', 4],
            'shadow ambush multiplier' => [17, 1, '影伏せ', 'prepared_effect.damage_multiplier', 1.20],
            'shadow ambush lineage' => [17, 1, '影伏せ', 'prepared_effect.trigger.lineage_key', 'hunt'],
            'shadow ambush ranks' => [17, 1, '影伏せ', 'prepared_effect.trigger.learn_ranks', [5, 9]],
            'silence field key' => [29, 1, '静寂の帳', 'field.field_key', 'silence'],
            'silence field duration' => [29, 1, '静寂の帳', 'field.duration_rounds', 5],
            'silence no self apply' => [29, 1, '静寂の帳', 'field.apply_new_field_to_source_action', false],
            'break hp threshold' => [5, 9, '大崩拳', 'conditional_damage_multiplier.maximum', 0.30],
            'break hp multiplier' => [5, 9, '大崩拳', 'conditional_damage_multiplier.multiplier', 1.60],
            'eclipse hp threshold' => [9, 9, '蝕みの終端', 'conditional_damage_multiplier.maximum', 0.40],
            'eclipse hp multiplier' => [9, 9, '蝕みの終端', 'conditional_damage_multiplier.multiplier', 1.50],
            'total war atk' => [12, 9, '総力戦', 'timed_effect.modifiers.str', 0.30],
            'total war mag' => [12, 9, '総力戦', 'timed_effect.modifiers.mag', 0.30],
            'total war duration' => [12, 9, '総力戦', 'timed_effect.rounds', 3],
            'unyielding guard' => [15, 1, '不屈の誓い', 'guard.damage_reduction_rate', 0.40],
            'unyielding charge' => [15, 1, '不屈の誓い', 'guard.charges', 1],
            'noto atk' => [11, 1, '納刀', 'timed_effect.modifiers.str', 0.05],
            'noto duration' => [11, 1, '納刀', 'timed_effect.rounds', 2],
            'fighting instinct atk' => [13, 1, '闘争本能', 'timed_effect.modifiers.str', 0.25],
            'fighting instinct def' => [13, 1, '闘争本能', 'timed_effect.modifiers.def', 0.20],
            'fighting instinct duration' => [13, 1, '闘争本能', 'timed_effect.rounds', 5],
            'counter focus charge' => [28, 1, '剣気集中', 'prepared_effect.charges', 2],
            'counter focus opportunities' => [28, 1, '剣気集中', 'prepared_effect.action_opportunities', 6],
            'counter focus multiplier' => [28, 1, '剣気集中', 'prepared_effect.damage_multiplier', 1.20],
            'counter focus ranks' => [28, 1, '剣気集中', 'prepared_effect.trigger.learn_ranks', [5, 9]],
            'machine setup charge' => [35, 1, '機巧展開', 'prepared_effect.charges', 1],
            'machine setup opportunities' => [35, 1, '機巧展開', 'prepared_effect.action_opportunities', 4],
            'machine setup multiplier' => [35, 1, '機巧展開', 'prepared_effect.damage_multiplier', 1.10],
            'machine setup ranks' => [35, 1, '機巧展開', 'prepared_effect.trigger.learn_ranks', [5, 9]],
            'magic cannon spr ignore' => [35, 5, '魔導砲', 'spr_ignore_percent', 15],
            'pierce burst multiplier' => [2, 1, '挑発撃', 'prepared_effect.damage_multiplier', 1.15],
            'pierce burst retains intervening actions' => [2, 1, '挑発撃', 'prepared_effect.retain_on_intervening_action', true],
            'high accuracy delta' => [4, 5, '狙い撃ち', 'accuracy_delta_points', 12],
            'high accuracy preserves legacy sure hit' => [4, 5, '狙い撃ち', 'preserve_legacy_sure_hit', true],
            'critical accuracy' => [18, 5, 'クリティカルショット', 'accuracy_delta_points', 6],
            'critical chance' => [18, 5, 'クリティカルショット', 'critical_delta_points', 10],
            'critical existing roll' => [18, 5, 'クリティカルショット', 'critical_mode', 'existing_roll_delta'],
            'magic bow attack stat' => [45, 5, '魔弓連星', 'damage_stat_route.attack_stat', 'mag'],
            'magic bow defense stat' => [45, 5, '魔弓連星', 'damage_stat_route.defense_stat', 'def'],
            'magic bow category' => [45, 5, '魔弓連星', 'damage_stat_route.damage_category', 'magical'],
            'magic bow defense ignore' => [45, 5, '魔弓連星', 'damage_stat_route.defense_ignore_percent', 25],
            'dawn physical attack stat' => [70, 5, '暁光ブレイク', 'adaptive_route.physical_attack_stat', 'str'],
            'dawn physical defense stat' => [70, 5, '暁光ブレイク', 'adaptive_route.physical_defense_stat', 'def'],
            'dawn magical attack stat' => [70, 5, '暁光ブレイク', 'adaptive_route.magical_attack_stat', 'mag'],
            'dawn magical defense stat' => [70, 5, '暁光ブレイク', 'adaptive_route.magical_defense_stat', 'spr'],
            'dawn adaptive selection' => [70, 5, '暁光ブレイク', 'adaptive_route.selection', 'higher_expected_damage'],
            'dawn adaptive no rng' => [70, 5, '暁光ブレイク', 'adaptive_route.consume_rng', false],
            'sage barrier power' => [29, 5, '賢者の結界', 'execution_power', 110],
            'sage barrier reduction' => [29, 5, '賢者の結界', 'next_action_damage_reduction_percent', 18],
            'extreme magic spr ignore' => [29, 9, '極大魔法', 'spr_ignore_percent', 25],
            'star light key' => [6, 1, '魔力の火種', 'field.field_key', 'star_light'],
            'star light no self apply' => [6, 1, '魔力の火種', 'field.apply_new_field_to_source_action', false],
            'melody key' => [23, 1, '鼓舞の小節', 'field.field_key', 'melody'],
            'melody no self apply' => [23, 1, '鼓舞の小節', 'field.apply_new_field_to_source_action', false],
            'field extension rounds' => [23, 5, '勇気の旋律', 'field.extend_rounds', 3],
            'field extension neutral' => [23, 5, '勇気の旋律', 'resource_override.resource_role', 'neutral'],
            'poem blessing mag' => [46, 1, '祝詞の一節', 'timed_effect.modifiers.mag', 0.15],
            'poem blessing spr' => [46, 1, '祝詞の一節', 'timed_effect.modifiers.spr', 0.07],
            'poem blessing duration' => [46, 1, '祝詞の一節', 'timed_effect.rounds', 2],
            'exorcising attack stat' => [21, 5, '破邪拳', 'damage_stat_route.attack_stat', 'str'],
            'exorcising defense stat' => [21, 5, '破邪拳', 'damage_stat_route.defense_stat', 'spr'],
            'exorcising category' => [21, 5, '破邪拳', 'damage_stat_route.damage_category', 'magical'],
            'exorcising target species' => [21, 5, '破邪拳', 'conditional_target_multiplier.species_keys', ['mage', 'undead']],
            'exorcising multiplier' => [21, 5, '破邪拳', 'conditional_target_multiplier.multiplier', 1.20],
            'bomb normal attack type' => [26, 5, '錬成爆弾', 'use_normal_attack_damage_type', true],
            'bomb def down' => [26, 5, '錬成爆弾', 'structured_debuff.enemy_def_down_percent', 15],
            'bomb spr down' => [26, 5, '錬成爆弾', 'structured_debuff.enemy_spr_down_percent', 15],
            'bomb debuff duration' => [26, 5, '錬成爆弾', 'structured_debuff.duration_turns', 3],
            'pure heal' => [7, 1, 'ヒール', 'heal.multiplier', 1.0],
            'prayer heal' => [36, 1, '聖戦の祈り', 'heal.multiplier', 0.70],
            'prayer guard' => [36, 1, '聖戦の祈り', 'guard.damage_reduction_rate', 0.15],
            'prayer guard charge' => [36, 1, '聖戦の祈り', 'guard.charges', 1],
            'sanctuary mag' => [56, 5, '聖域結界', 'timed_effect.modifiers.mag', 0.20],
            'sanctuary spr' => [56, 5, '聖域結界', 'timed_effect.modifiers.spr', 0.10],
            'sanctuary duration' => [56, 5, '聖域結界', 'timed_effect.rounds', 2],
            'aegis guard' => [44, 9, '天壁イージス', 'guard.damage_reduction_rate', 0.20],
            'aegis guard charge' => [44, 9, '天壁イージス', 'guard.charges', 1],
            'arcadia guard' => [56, 9, '聖壁アルカディア', 'guard.damage_reduction_rate', 0.25],
            'arcadia guard charge' => [56, 9, '聖壁アルカディア', 'guard.charges', 1],
            'gold only' => [8, 5, '幸運の一手', 'reward.drop', false],
            'drop only' => [20, 5, '掘り出し物', 'reward.gold', false],
            'appraisal retained' => [31, 1, '黄金鑑定', 'appraisal.apply_to_target', true],
            'single cleanse maximum' => [47, 1, '聖薬散布', 'cleanse.maximum_states', 1],
            'single cleanse order' => [47, 1, '聖薬散布', 'cleanse.priority', ['burn', 'poison', 'bleed', 'def_down', 'slow', 'recovery_block']],
            'single cleanse heal' => [47, 1, '聖薬散布', 'heal.rate', 0.05],
            'single cleanse exact conversion refund' => [47, 1, '聖薬散布', 'heal.refund_conversion_hp_loss', true],
            'basic finisher hp' => [8, 9, '大番振る舞い', 'heal.hp.rate', 0.10],
            'basic finisher sp' => [8, 9, '大番振る舞い', 'heal.sp.rate', 0.05],
            'merchant guard' => [20, 9, '大商隊の守護', 'guard.damage_reduction_rate', 0.20],
            'diamond guard' => [21, 9, '金剛不壊', 'guard.damage_reduction_rate', 0.25],
            'cathedral heal' => [24, 9, '大聖堂の奇跡', 'heal.multiplier', 1.0],
            'cathedral cleanse all' => [24, 9, '大聖堂の奇跡', 'cleanse.maximum_states', 'all'],
            'cathedral guard' => [24, 9, '大聖堂の奇跡', 'guard.damage_reduction_rate', 0.20],
            'giga hybrid replacement' => [27, 9, 'ギガブレイク', 'replacement_template', 'HYBRID_DAMAGE'],
            'reward finisher gold' => [31, 9, '王立独占契約', 'reward.gold', 'preserve_master'],
            'reward finisher drop' => [31, 9, '王立独占契約', 'reward.drop', 'preserve_master'],
            'harvest removes one' => [38, 9, '富国の錬金陣', 'remove_positive_effect.maximum_effects', 1],
            'harvest strongest' => [38, 9, '富国の錬金陣', 'remove_positive_effect.selection', 'highest_strength'],
            'harvest atk buff' => [38, 9, '富国の錬金陣', 'timed_effect.modifiers.str', 0.15],
            'harvest mag buff' => [38, 9, '富国の錬金陣', 'timed_effect.modifiers.mag', 0.15],
            'harvest duration' => [38, 9, '富国の錬金陣', 'timed_effect.rounds', 3],
            'amrita cleanse all' => [47, 9, '神薬アムリタ', 'cleanse.maximum_states', 'all'],
            'amrita heal' => [47, 9, '神薬アムリタ', 'heal.rate', 0.15],
            'potion mix cleanses one' => [25, 5, '秘薬調合', 'cleanse.maximum_states', 1],
            'potion mix cleanse order' => [25, 5, '秘薬調合', 'cleanse.states', ['burn', 'poison', 'bleed', 'def_down', 'slow', 'recovery_block']],
            'king elixir lower ratio' => [38, 5, '王者の秘薬', 'adaptive_sustain.lower_ratio_multiplier', 1.50],
            'king elixir tie breaker' => [38, 5, '王者の秘薬', 'adaptive_sustain.tie_breaker', 'hp'],
            'holy stance def' => [50, 1, '聖剣構え', 'timed_effect.modifiers.def', 0.10],
            'holy stance spr' => [50, 1, '聖剣構え', 'timed_effect.modifiers.spr', 0.10],
            'holy stance duration' => [50, 1, '聖剣構え', 'timed_effect.rounds', 2],
            'musou accepts focus' => [28, 9, '無双一閃', 'accepts_prepared_effects', ['counter_focus']],
            'light wing def' => [50, 9, '光翼クロスブレイク', 'timed_effect.modifiers.def', 0.15],
            'light wing spr' => [50, 9, '光翼クロスブレイク', 'timed_effect.modifiers.spr', 0.15],
            'light wing duration' => [50, 9, '光翼クロスブレイク', 'timed_effect.rounds', 2],
            'light wing rejects focus' => [50, 9, '光翼クロスブレイク', 'rejects_prepared_effects', ['counter_focus']],
        ];
    }

    private function art(
        int $jobId,
        int $rank,
        string $name,
        string $skillType = 'job_art',
        int $power = 100,
        int $hitCount = 1,
    ): Skill {
        return new Skill([
            'job_id' => $jobId,
            'learn_rank' => $rank,
            'name' => $name,
            'skill_type' => $skillType,
            'power' => $power,
            'hit_count' => $hitCount,
        ]);
    }

    private function nestedValue(array $metadata, string $path): mixed
    {
        $value = $metadata;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                $this->fail("Missing catalog path: {$path}");
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
