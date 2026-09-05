<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

/**
 * Phase 1のレイド候補値を一元化する、副作用のないrules catalog。
 *
 * ここにある段階倍率はPhase 2のsweep入力であり、本番balanceの確定値ではない。
 */
final class NationRaidRules
{
    public const BATTLE_TYPE = 'raid';

    public const MAX_TURNS = 20;

    public const MAX_STAGES = 20;

    /** 第1再臨の既定HP。個体生成にはstageMaxHp()を使う。 */
    public const BOSS_MAX_HP = 10_000_000;

    public const RULESET_VERSION = 'nation-raid-v5-staged-hp';

    public function stageMaxHp(int $stage): int
    {
        $this->assertRange($stage, 1, self::MAX_STAGES, 'stage');

        return self::BOSS_MAX_HP * (1 + intdiv($stage - 1, 4));
    }

    public function totalTargetHp(): int
    {
        return array_sum(array_map($this->stageMaxHp(...), range(1, self::MAX_STAGES)));
    }

    /** ヴァルグレイド全4形態で共通する、既存の装備特攻判定用種族。 */
    public const BOSS_SPECIES_KEY = 'dragon';

    /**
     * 固有特攻と個体特攻の合算率へ掛けるレイド専用倍率と、その実効上限。
     *
     * 通常PvEのconfigとは意図的に独立するPhase 2較正値。
     * 変更時はruleset hashを変え、Phase 2 simulationと人間裁定をやり直す。
     */
    public const BOSS_KILLER_DAMAGE_RATE_MULTIPLIER = 2.0;

    public const BOSS_KILLER_DAMAGE_RATE_CAP = 1.0;

    /** 通常PvEと同じ装備耐性を、レイドボスの種族に対して有効にする。 */
    public const ARMOR_SPECIES_RESISTANCE_ENABLED = true;

    /** 現行防具耐性の上限35%を固定する、通常PvE configと独立したレイド較正値。 */
    public const ARMOR_SPECIES_RESISTANCE_RATE_CAP = 0.35;

    public const COORDINATION_WINDOW_MINUTES = 180;

    /** @var array<int, float> */
    public const COORDINATION_DAMAGE_RATES = [
        2 => 0.03,
        3 => 0.06,
        4 => 0.09,
        5 => 0.12,
    ];

    public const BOSS_MAX_SP = 100;

    public const BOSS_DEFENSE = 100;

    public const BOSS_SPIRIT = 100;

    public const BOSS_AGILITY = 1_000;

    public const BOSS_LUCK = 100;

    public const VIRTUAL_MAX_HP = 100_000;

    /**
     * 通常PvEのconfigとは意図的に独立する、Phase 2較正用のレイドruleset値。
     *
     * 変更時はruleset hashを変え、Phase 2 simulationと人間裁定をやり直す。
     */
    public const DEFENSE_COEFFICIENT = 3.5;

    public const CRITICAL_MULTIPLIER = 1.5;

    public const VARIANCE_MIN = 85;

    public const VARIANCE_MAX = 115;

    public const BOSS_INCOMING_REDUCTION_CAP = 0.25;

    public const RESERVATION_SP_COST = 20;

    public const ACTION_SP_RECOVERY = 5;

    public const ULTIMATE_SP_COST = 90;

    public const FORM_SEALED_SCALE = 'sealed_scale';

    public const FORM_SPLIT_WING = 'split_wing';

    public const FORM_LINEAGE_INVASION = 'lineage_invasion';

    public const FORM_EXPOSED_CORE = 'exposed_core';

    /** 作戦補正なし。既存ボス戦セットの候補順をそのまま使う。 */
    public const STRATEGY_BOSS_SET = 'boss_set';

    public const STRATEGY_ASSAULT = 'assault';

    public const STRATEGY_INTERCEPT = 'intercept';

    public const STRATEGY_FORTIFY = 'fortify';

    public const DAMAGE_DIRECT = 'direct';

    public const DAMAGE_SIMULTANEOUS = 'simultaneous';

    public const DAMAGE_DOT = 'dot';

    public const DAMAGE_COUNTER = 'counter';

    public const DAMAGE_ECLIPSE_BACKLASH = 'eclipse_backlash';

    /** @var array<string, array{name:string,outgoing_multiplier:float,incoming_reduction:float,form_action:string,weights:array{basic_physical:int,basic_magical:int,form_action:int},image_path:string}> */
    private const FORMS = [
        self::FORM_SEALED_SCALE => [
            'name' => '封鱗',
            'outgoing_multiplier' => 0.85,
            'incoming_reduction' => 0.00,
            'form_action' => 'sealed_quake',
            'weights' => ['basic_physical' => 45, 'basic_magical' => 45, 'form_action' => 10],
            'image_path' => 'images/raid/valgreid_form_01.webp',
        ],
        self::FORM_SPLIT_WING => [
            'name' => '裂翼',
            'outgoing_multiplier' => 1.00,
            'incoming_reduction' => 0.05,
            'form_action' => 'split_wing_combo',
            'weights' => ['basic_physical' => 40, 'basic_magical' => 40, 'form_action' => 20],
            'image_path' => 'images/raid/valgreid_form_02.webp',
        ],
        self::FORM_LINEAGE_INVASION => [
            'name' => '十系侵蝕',
            'outgoing_multiplier' => 1.15,
            'incoming_reduction' => 0.10,
            'form_action' => 'lineage_roar',
            'weights' => ['basic_physical' => 35, 'basic_magical' => 35, 'form_action' => 30],
            'image_path' => 'images/raid/valgreid_form_03.webp',
        ],
        self::FORM_EXPOSED_CORE => [
            'name' => '露核',
            'outgoing_multiplier' => 1.30,
            'incoming_reduction' => 0.00,
            'form_action' => 'dragon_core_backlight',
            'weights' => ['basic_physical' => 30, 'basic_magical' => 30, 'form_action' => 40],
            'image_path' => 'images/raid/valgreid_form_04.webp',
        ],
    ];

    /** @var array<string, array{name:string,hits:list<array{type:string,power:int}>,effect:?string,can_be_guarded:bool}> */
    private const BASIC_ACTIONS = [
        'black_sky_claw' => [
            'name' => '黒天裂爪',
            'hits' => [['type' => 'physical', 'power' => 70]],
            'effect' => null,
            'can_be_guarded' => false,
        ],
        'void_corrosion_orb' => [
            'name' => '虚蝕弾',
            'hits' => [['type' => 'magical', 'power' => 70]],
            'effect' => null,
            'can_be_guarded' => false,
        ],
        'sealed_quake' => [
            'name' => '封鱗震',
            'hits' => [['type' => 'physical', 'power' => 60]],
            'effect' => 'defense_down_10_two_actions',
            'can_be_guarded' => false,
        ],
        'split_wing_combo' => [
            'name' => '裂翼連爪',
            'hits' => [
                ['type' => 'physical', 'power' => 55],
                ['type' => 'physical', 'power' => 55],
            ],
            'effect' => null,
            'can_be_guarded' => false,
        ],
        'lineage_roar' => [
            'name' => '侵系咆哮',
            'hits' => [['type' => 'magical', 'power' => 85]],
            'effect' => 'healing_down_25_two_actions',
            'can_be_guarded' => false,
        ],
        'dragon_core_backlight' => [
            'name' => '竜核逆光',
            'hits' => [
                ['type' => 'physical', 'power' => 60],
                ['type' => 'magical', 'power' => 60],
            ],
            'effect' => null,
            'can_be_guarded' => false,
        ],
        'ten_lineage_end' => [
            'name' => '十系終焉・ヴァルグレイド',
            'hits' => [
                ['type' => 'physical', 'power' => 90],
                ['type' => 'magical', 'power' => 90],
            ],
            'effect' => null,
            'can_be_guarded' => true,
        ],
    ];

    /** @var array<string, array{boss_lineage:string,action_id:string,name:string,hits:list<array{type:string,power:int,defense_ignore?:float}>,effect:string,preparation_kind:?string,can_be_guarded:bool}> */
    private const COUNTER_ACTIONS = [
        'counter' => [
            'boss_lineage' => 'field', 'action_id' => 'silent_black_field', 'name' => '無響黒界',
            'hits' => [['type' => 'magical', 'power' => 80]], 'effect' => 'counter_damage_down_50',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'field' => [
            'boss_lineage' => 'command', 'action_id' => 'world_law_severance', 'name' => '界律断令',
            'hits' => [['type' => 'physical', 'power' => 70]], 'effect' => 'field_remove_and_extension_block',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'command' => [
            'boss_lineage' => 'aim', 'action_id' => 'command_core_snipe', 'name' => '司令核狙撃',
            'hits' => [['type' => 'magical', 'power' => 75]], 'effect' => 'current_sp_down_8',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'aim' => [
            'boss_lineage' => 'counter', 'action_id' => 'black_mirror_counter', 'name' => '黒鏡返し',
            'hits' => [['type' => 'physical', 'power' => 60]], 'effect' => 'nonlethal_reflect_max_hp_8',
            'preparation_kind' => 'reflect', 'can_be_guarded' => true,
        ],
        'guardian' => [
            'boss_lineage' => 'break', 'action_id' => 'guardian_world_breaker', 'name' => '護界砕爪',
            'hits' => [['type' => 'physical', 'power' => 75]], 'effect' => 'defense_spirit_healing_down_25_two_actions',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'break' => [
            'boss_lineage' => 'transmute', 'action_id' => 'reverse_transmutation_scale', 'name' => '逆錬成鱗',
            'hits' => [['type' => 'magical', 'power' => 65]], 'effect' => 'cleanse_and_guard_per_debuff',
            'preparation_kind' => 'cleanse_guard', 'can_be_guarded' => true,
        ],
        'transmute' => [
            'boss_lineage' => 'dark', 'action_id' => 'corrosion_absorption_ring', 'name' => '腐蝕吸環',
            'hits' => [['type' => 'magical', 'power' => 70]], 'effect' => 'hp_sp_healing_down_50_two_actions',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'dark' => [
            'boss_lineage' => 'pierce', 'action_id' => 'blood_pact_piercing_horn', 'name' => '血盟穿角',
            'hits' => [['type' => 'physical', 'power' => 100, 'defense_ignore' => 0.50]], 'effect' => 'drain_healing_down_50_one_action',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'pierce' => [
            'boss_lineage' => 'hunt', 'action_id' => 'phantom_scale_hunt_mark', 'name' => '幻鱗狩印',
            'hits' => [['type' => 'physical', 'power' => 65]], 'effect' => 'next_direct_damage_down_30',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
        'hunt' => [
            'boss_lineage' => 'guardian', 'action_id' => 'purified_hunt_dragon_circle', 'name' => '浄狩竜陣',
            'hits' => [['type' => 'magical', 'power' => 60]], 'effect' => 'clear_marks_and_next_multihit_down_25',
            'preparation_kind' => null, 'can_be_guarded' => true,
        ],
    ];

    /** @var array<string, array{name:string,effect:string,category:string}> */
    private const COUNTERPLAY_ARTS = [
        '28:5:無拍子' => ['name' => '無拍子', 'effect' => 'counter_intercept', 'category' => 'defense'],
        '30:5:暗黒剣' => ['name' => '暗黒剣', 'effect' => 'eclipse_backlash', 'category' => 'offense'],
        '32:5:ドラゴンダイブ' => ['name' => 'ドラゴンダイブ', 'effect' => 'pierce_opening', 'category' => 'offense'],
        '53:5:星詠みの光' => ['name' => '星詠みの光', 'effect' => 'field_suppression', 'category' => 'intercept'],
        '54:5:影縫い乱舞' => ['name' => '影縫い乱舞', 'effect' => 'hunt_cancel', 'category' => 'intercept'],
        '4:5:狙い撃ち' => ['name' => '狙い撃ち', 'effect' => 'aim_sp_pressure', 'category' => 'intercept'],
        '15:5:ガーディアンブロウ' => ['name' => 'ガーディアンブロウ', 'effect' => 'ultimate_guard', 'category' => 'defense'],
        '15:9:不落要塞' => ['name' => '不落要塞', 'effect' => 'fortress_guard', 'category' => 'defense'],
        '49:5:大錬成爆装' => ['name' => '大錬成爆装', 'effect' => 'transmute_resource_slow', 'category' => 'intercept'],
        '33:5:羅刹連撃' => ['name' => '羅刹連撃', 'effect' => 'break_preparation', 'category' => 'intercept'],
        '48:5:王戦の号令' => ['name' => '王戦の号令', 'effect' => 'readiness_delay', 'category' => 'intercept'],
    ];

    public function __construct(private readonly float $stageAttackGrowth = 0.60)
    {
        if ($stageAttackGrowth < 0.0 || $stageAttackGrowth > 5.0) {
            throw new InvalidArgumentException('Raid stage attack growth is outside the prototype sweep range.');
        }
    }

    /** @return array{stage:int,stage_name:string,attack:int,magic:int,reserved_slots:array<int,string>,form_action_weight_bonus:int} */
    public function stageParameters(int $stage): array
    {
        $this->assertRange($stage, 1, self::MAX_STAGES, 'stage');
        $multiplier = 1.00 + ($this->stageAttackGrowth * ($stage - 1) / 19);
        $attack = (int) floor(2_200 * $multiplier);

        [$name, $slots, $bonus] = match (true) {
            $stage <= 4 => ['微睡', [6 => 'observation', 12 => 'observation', 18 => 'observation'], 0],
            $stage <= 8 => ['胎動', [6 => 'observation', 12 => 'observation', 18 => 'counter'], 4],
            $stage <= 12 => ['覚醒', [6 => 'observation', 12 => 'counter', 18 => 'counter'], 8],
            $stage <= 16 => ['侵界', [6 => 'counter', 12 => 'counter', 18 => 'counter'], 12],
            $stage <= 19 => ['暴界', [6 => 'counter', 12 => 'counter', 18 => 'counter'], 16],
            default => ['真醒', [6 => 'counter', 12 => 'counter', 18 => 'counter'], 20],
        };

        return [
            'stage' => $stage,
            'max_hp' => $this->stageMaxHp($stage),
            'stage_name' => $name,
            'attack' => $attack,
            'magic' => $attack,
            'reserved_slots' => $slots,
            'form_action_weight_bonus' => $bonus,
        ];
    }

    /** @return array{name:string,outgoing_multiplier:float,incoming_reduction:float,form_action:string,weights:array{basic_physical:int,basic_magical:int,form_action:int},image_path:string} */
    public function formParameters(string $form): array
    {
        if (! isset(self::FORMS[$form])) {
            throw new InvalidArgumentException("Unknown raid form: {$form}");
        }

        return self::FORMS[$form];
    }

    public function formForHp(int $currentHp, int $maxHp = self::BOSS_MAX_HP): string
    {
        if ($maxHp <= 0 || $currentHp <= 0 || $currentHp > $maxHp) {
            throw new InvalidArgumentException('Raid form snapshot requires 0 < current HP <= maximum HP.');
        }

        $ratio = $currentHp / $maxHp;

        return match (true) {
            $ratio > 0.70 => self::FORM_SEALED_SCALE,
            $ratio > 0.40 => self::FORM_SPLIT_WING,
            $ratio > 0.10 => self::FORM_LINEAGE_INVASION,
            default => self::FORM_EXPOSED_CORE,
        };
    }

    /** 試遊・simulationが同じ形態境界を使うための正規化開始HP。 */
    public function canonicalCycleCurrentHpForForm(string $form, int $stage = 1): int
    {
        $this->formParameters($form);

        return match ($form) {
            self::FORM_SEALED_SCALE => $this->stageMaxHp($stage),
            self::FORM_SPLIT_WING => (int) floor($this->stageMaxHp($stage) * 0.70),
            self::FORM_LINEAGE_INVASION => (int) floor($this->stageMaxHp($stage) * 0.40),
            self::FORM_EXPOSED_CORE => (int) floor($this->stageMaxHp($stage) * 0.10),
        };
    }

    /** @return array{key:string,start:int,end:int,outgoing_multiplier:float,incoming_reduction:float,action_cap_rate:float} */
    public function turnBand(int $turn): array
    {
        $this->assertRange($turn, 1, self::MAX_TURNS, 'turn');

        return match (true) {
            $turn <= 5 => ['key' => 'turns_1_5', 'start' => 1, 'end' => 5, 'outgoing_multiplier' => 0.60, 'incoming_reduction' => 0.00, 'action_cap_rate' => 0.06],
            $turn <= 10 => ['key' => 'turns_6_10', 'start' => 6, 'end' => 10, 'outgoing_multiplier' => 0.80, 'incoming_reduction' => 0.05, 'action_cap_rate' => 0.09],
            $turn <= 15 => ['key' => 'turns_11_15', 'start' => 11, 'end' => 15, 'outgoing_multiplier' => 1.00, 'incoming_reduction' => 0.10, 'action_cap_rate' => 0.13],
            $turn <= 19 => ['key' => 'turns_16_19', 'start' => 16, 'end' => 19, 'outgoing_multiplier' => 1.20, 'incoming_reduction' => 0.15, 'action_cap_rate' => 0.18],
            default => ['key' => 'turn_20', 'start' => 20, 'end' => 20, 'outgoing_multiplier' => 1.50, 'incoming_reduction' => 0.15, 'action_cap_rate' => 0.40],
        };
    }

    /** @return list<array{stage:int,form:string,turn_band:string,attack:int,magic:int,boss_outgoing_multiplier:float,boss_incoming_reduction:float,action_cap_rate:float,form_action_weight_bonus:int}> */
    public function stateCells(): array
    {
        $cells = [];
        $bandTurns = [1, 6, 11, 16, 20];

        foreach (range(1, self::MAX_STAGES) as $stage) {
            $stageParameters = $this->stageParameters($stage);
            foreach (array_keys(self::FORMS) as $form) {
                $formParameters = $this->formParameters($form);
                foreach ($bandTurns as $turn) {
                    $band = $this->turnBand($turn);
                    $cells[] = [
                        'stage' => $stage,
                        'form' => $form,
                        'turn_band' => $band['key'],
                        'attack' => $stageParameters['attack'],
                        'magic' => $stageParameters['magic'],
                        'boss_outgoing_multiplier' => $band['outgoing_multiplier'] * $formParameters['outgoing_multiplier'],
                        'boss_incoming_reduction' => min(self::BOSS_INCOMING_REDUCTION_CAP, $band['incoming_reduction'] + $formParameters['incoming_reduction']),
                        'action_cap_rate' => $band['action_cap_rate'],
                        'form_action_weight_bonus' => $stageParameters['form_action_weight_bonus'],
                    ];
                }
            }
        }

        return $cells;
    }

    /** @return array<string, array{name:string,hits:list<array{type:string,power:int}>,effect:?string,can_be_guarded:bool}> */
    public function basicActions(): array
    {
        return self::BASIC_ACTIONS;
    }

    /** @return array{name:string,hits:list<array{type:string,power:int}>,effect:?string,can_be_guarded:bool} */
    public function basicAction(string $actionId): array
    {
        if (! isset(self::BASIC_ACTIONS[$actionId])) {
            throw new InvalidArgumentException("Unknown raid basic action: {$actionId}");
        }

        return self::BASIC_ACTIONS[$actionId];
    }

    /** @return array<string, array{boss_lineage:string,action_id:string,name:string,hits:list<array{type:string,power:int,defense_ignore?:float}>,effect:string,preparation_kind:?string,can_be_guarded:bool}> */
    public function counterActions(): array
    {
        return self::COUNTER_ACTIONS;
    }

    /** @return array{boss_lineage:string,action_id:string,name:string,hits:list<array{type:string,power:int,defense_ignore?:float}>,effect:string,preparation_kind:?string,can_be_guarded:bool}|null */
    public function counterAction(?string $dominantLineage): ?array
    {
        if ($dominantLineage === null || ! isset(self::COUNTER_ACTIONS[$dominantLineage])) {
            return null;
        }

        return self::COUNTER_ACTIONS[$dominantLineage];
    }

    /** @return array<string, array{name:string,effect:string,category:string}> */
    public function counterplayArts(): array
    {
        return self::COUNTERPLAY_ARTS;
    }

    /** @return array{name:string,effect:string,category:string}|null */
    public function counterplayArt(string $identity): ?array
    {
        return self::COUNTERPLAY_ARTS[$identity] ?? null;
    }

    /** @return array{basic_physical:int,basic_magical:int,form_action:int} */
    public function actionWeights(int $stage, string $form): array
    {
        $stageParameters = $this->stageParameters($stage);
        $formParameters = $this->formParameters($form);
        $formWeight = min(100, $formParameters['weights']['form_action'] + $stageParameters['form_action_weight_bonus']);
        $remaining = 100 - $formWeight;
        $physical = intdiv($remaining, 2);

        return [
            'basic_physical' => $physical,
            'basic_magical' => $remaining - $physical,
            'form_action' => $formWeight,
        ];
    }

    public function reservedSlotKind(int $stage, int $turn, ?string $dominantLineage): string
    {
        $slots = $this->stageParameters($stage)['reserved_slots'];
        if (! isset($slots[$turn])) {
            throw new InvalidArgumentException('Reserved raid slots exist only on turns 6, 12, and 18.');
        }

        if ($slots[$turn] === 'observation' || $dominantLineage === null || $this->counterAction($dominantLineage) === null) {
            return 'observation';
        }

        return 'counter';
    }

    /** @return list<string> */
    public function formKeys(): array
    {
        return array_keys(self::FORMS);
    }

    /** @return list<string> */
    public function strategyKeys(): array
    {
        return [self::STRATEGY_BOSS_SET, ...$this->selectableStrategyKeys()];
    }

    /** @return list<string> gate ON時の選択肢。旧戦闘・比較simulationでも維持する。 */
    public function selectableStrategyKeys(): array
    {
        return [self::STRATEGY_ASSAULT, self::STRATEGY_INTERCEPT, self::STRATEGY_FORTIFY];
    }

    public static function raidKillerDamageRate(float $combinedRate): float
    {
        return min(
            self::BOSS_KILLER_DAMAGE_RATE_CAP,
            max(0.0, $combinedRate) * self::BOSS_KILLER_DAMAGE_RATE_MULTIPLIER,
        );
    }

    public static function coordinationDamageRate(int $uniqueParticipants): float
    {
        if ($uniqueParticipants < 2) {
            return 0.0;
        }

        return self::COORDINATION_DAMAGE_RATES[min(5, $uniqueParticipants)];
    }

    /** Phase 2と本番event行が同じ候補値を固定するためのsnapshot。 */
    public function rulesetSnapshot(): array
    {
        return [
            'version' => self::RULESET_VERSION,
            'stage_attack_growth' => $this->stageAttackGrowth,
            'fixed' => [
                'max_turns' => self::MAX_TURNS,
                'max_stages' => self::MAX_STAGES,
                'boss_max_hp' => self::BOSS_MAX_HP,
                'total_target_hp' => $this->totalTargetHp(),
                'boss_species_key' => self::BOSS_SPECIES_KEY,
                'boss_killer_damage_rate_multiplier' => self::BOSS_KILLER_DAMAGE_RATE_MULTIPLIER,
                'boss_killer_damage_rate_cap' => self::BOSS_KILLER_DAMAGE_RATE_CAP,
                'armor_species_resistance_enabled' => self::ARMOR_SPECIES_RESISTANCE_ENABLED,
                'armor_species_resistance_rate_cap' => self::ARMOR_SPECIES_RESISTANCE_RATE_CAP,
                'coordination_window_minutes' => self::COORDINATION_WINDOW_MINUTES,
                'coordination_damage_rates' => self::COORDINATION_DAMAGE_RATES,
                'boss_max_sp' => self::BOSS_MAX_SP,
                'boss_defense' => self::BOSS_DEFENSE,
                'boss_spirit' => self::BOSS_SPIRIT,
                'boss_agility' => self::BOSS_AGILITY,
                'boss_luck' => self::BOSS_LUCK,
                'virtual_max_hp' => self::VIRTUAL_MAX_HP,
                'defense_coefficient' => self::DEFENSE_COEFFICIENT,
                'critical_multiplier' => self::CRITICAL_MULTIPLIER,
                'variance' => [self::VARIANCE_MIN, self::VARIANCE_MAX],
                'incoming_reduction_cap' => self::BOSS_INCOMING_REDUCTION_CAP,
                'reservation_sp_cost' => self::RESERVATION_SP_COST,
                'action_sp_recovery' => self::ACTION_SP_RECOVERY,
                'ultimate_sp_cost' => self::ULTIMATE_SP_COST,
            ],
            'stages' => array_map(fn (int $stage): array => $this->stageParameters($stage), range(1, self::MAX_STAGES)),
            'forms' => self::FORMS,
            'turn_bands' => array_map(fn (int $turn): array => $this->turnBand($turn), [1, 6, 11, 16, 20]),
            'basic_actions' => self::BASIC_ACTIONS,
            'counter_actions' => self::COUNTER_ACTIONS,
            'counterplay_arts' => self::COUNTERPLAY_ARTS,
        ];
    }

    /** Phase 2が同じ候補値を再現するためのcode-side fingerprint。 */
    public function rulesetHash(): string
    {
        return hash('sha256', NationRaidJson::encode($this->rulesetSnapshot(), JSON_UNESCAPED_UNICODE));
    }

    /** HP以外が完全一致する旧均一HP回だけは、保存済みHPで継続できる。 */
    public function matchesCombatRulesetHash(string $hash): bool
    {
        if (hash_equals($this->rulesetHash(), $hash)) {
            return true;
        }
        $legacy = $this->rulesetSnapshot();
        $legacy['version'] = 'nation-raid-phase1-v4-equipment-resistance';
        $legacy['fixed']['boss_max_hp'] = 5_000_000;
        unset($legacy['fixed']['total_target_hp']);
        foreach ($legacy['stages'] as &$stage) {
            unset($stage['max_hp']);
        }
        unset($stage);

        return hash_equals(hash('sha256', NationRaidJson::encode($legacy, JSON_UNESCAPED_UNICODE)), $hash);
    }

    private function assertRange(int $value, int $min, int $max, string $label): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("Raid {$label} must be between {$min} and {$max}.");
        }
    }
}
