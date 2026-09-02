<?php

namespace App\Services\Admin;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\EnemyAction;
use App\Models\JobClass;
use App\Models\Skill;
use App\Services\Battle\BattleResult;
use App\Services\Battle\ScopedBattleRandomizer;
use App\Services\BattleService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentPermissionService;
use InvalidArgumentException;
use JsonException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class ValzeriaLabReplayService extends BattleService
{
    public const SNAPSHOT_SCHEMA = 'valzeria-lab-battle-snapshot/v1';

    public const MAX_JSON_BYTES = 524_288;

    public const MAX_SEED = 2_147_483_647;

    private const MAX_TURNS = 50;

    /**
     * Import execution safety limit. This is deliberately above the current
     * multi-hit masters and is not a gameplay balance rule.
     */
    private const MAX_HIT_COUNT = 20;

    private const STAT_KEYS = ['max_hp', 'max_mp', 'str', 'def', 'agi', 'mag', 'spr', 'luk'];

    private const ENEMY_STAT_KEYS = [
        'base_hp', 'base_str', 'base_def', 'max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk',
        'bonus_hp', 'bonus_str', 'bonus_def', 'danger_rate', 'danger_label',
        'durability_hp_multiplier', 'durability_def_spr_multiplier',
        'durability_atk_mag_multiplier', 'durability_tier',
    ];

    private const ENEMY_ATTRIBUTE_KEYS = [
        'name', 'level', 'max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk',
        'exp_reward', 'gold_reward', 'appearance_weight', 'is_boss', 'sort_order',
        'job_exp_reward', 'role', 'type_name', 'element', 'action_pattern', 'drop_type',
        'enemy_level', 'family_key', 'variant_key', 'role_key', 'stat_generation_version',
        'is_stat_locked', 'manual_adjustment_note', 'species_key', 'map_biome_tags',
        'map_min_level', 'map_max_level', 'map_normal_eligible', 'map_boss_eligible',
        'map_base_weight', 'max_mp', 'normal_attack_type', 'species_keys',
        'skip_danger_bonus', 'skip_durability_bonus', 'force_zero_defense',
        'region_depth_dungeon_key', 'region_depth_danger_rate',
    ];

    private const ENEMY_ACTION_ATTRIBUTE_KEYS = [
        'action_key', 'name', 'action_type', 'selection_weight', 'power_percent', 'hit_count',
        'effect_percent', 'duration_turns', 'cooldown_turns', 'max_uses_per_battle',
        'trigger_turn', 'trigger_key', 'trigger_value', 'can_use_on_first_turn',
        'is_telegraphed', 'telegraph_turns', 'can_be_guarded', 'guard_reduction_rate',
        'cancel_on_enemy_death', 'guarantee_first_use', 'sort_order',
    ];

    private const SKILL_ATTRIBUTE_KEYS = [
        'name', 'description', 'job_id', 'trigger_rate', 'damage_type', 'power_multiplier',
        'hit_count', 'heal_percent', 'self_damage_percent', 'gold_bonus_percent',
        'drop_bonus_percent', 'def_ignore_percent', 'damage_reduction_percent',
        'enemy_def_down_percent', 'enemy_spr_down_percent', 'enemy_spd_down_percent',
        'mp_recover_percent', 'mp_cost', 'activation_rate', 'sp_cost_base', 'sp_cost_rate',
        'skill_type', 'learn_rank', 'art_cost', 'art_category', 'limit_group',
        'effect_template', 'element', 'power', 'duration_turns', 'cooldown_turns',
        'max_uses_per_battle', 'inherit_on_master', 'inherit_policy', 'inherited_rate',
        'pve_enabled', 'boss_enabled', 'champ_enabled', 'reward_scope', 'sort_order',
        'memo', 'sp_cost_fixed', 'activation_phrase', 'activation_description',
        'enemy_atk_down_percent', 'enemy_mag_down_percent', 'rare_bonus_percent',
        'drain_hp_rate', 'extra_hit_chance_percent', 'luk_power_rate', 'hybrid_scaling',
        'self_buff_percent',
    ];

    private const SKILL_RUNTIME_KEYS = [
        'slot_no', 'job_art_effective_cost', 'job_art_activation_policy',
        'job_art_slot_condition', 'job_art_rate', 'job_art_origin', 'job_art_strategy',
    ];

    private const FORBIDDEN_KEYS = [
        'user_id', 'character_id', 'email', 'password', 'password_hash', 'remember_token',
        'auth_token', 'api_token', 'access_token', 'refresh_token', 'session_id', 'ip_address',
    ];

    /**
     * @return array<string, mixed>
     */
    public function capture(Character $character, Enemy $enemy, string $battleType, int $seed): array
    {
        $battleType = $this->validateBattleType($battleType);
        $this->assertSeed($seed);

        $character->refresh();
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = $this->statusService->getFinalStats($character);
        $currentJob = $character->currentJob()->first();
        $equippedItems = $character->characterItems()
            ->where('is_equipped', true)
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->orderBy('id')
            ->get();
        $weapon = $equippedItems->first(fn ($entry): bool => $entry->item?->type === 'weapon');
        $armor = $equippedItems->first(fn ($entry): bool => $entry->item?->type === 'armor');
        $permissionService = app(EquipmentPermissionService::class);

        $jobArtContext = $battleType === 'boss' ? 'boss' : 'pve';
        $jobArts = $this->jobArtService->battleArtsFor($character, $jobArtContext);
        $jobArtStrategy = $this->jobArtService->battleStrategy(
            $character,
            $this->jobArtService->battleSlotContext($jobArtContext),
        );

        $snapshot = [
            'schema' => self::SNAPSHOT_SCHEMA,
            'seed' => $seed,
            'battle_type' => $battleType,
            'character' => [
                'label' => '匿名冒険者',
                'level' => max(1, (int) $character->level),
                'starting_hp' => max(1, (int) $character->current_hp),
                'starting_sp' => max(0, (int) ($character->current_mp ?? 0)),
                'stats' => $this->onlyStats($stats),
                'sp_power_reference' => max(0, (int) ($stats['pre_equipment']['mp'] ?? 0)),
                'job' => $currentJob ? [
                    'master_id' => (int) $currentJob->id,
                    'key' => (string) $currentJob->key,
                    'name' => (string) $currentJob->name,
                    'normal_attack_type' => $currentJob->normal_attack_type,
                ] : null,
                'activation_policy' => (string) ($character->job_art_activation_policy ?: 'normal'),
                'strategy' => $jobArtStrategy,
                'combat_effects' => [
                    'weapon_killer_effects' => $weapon
                        ? $permissionService->effectiveKillerEffects($character, $weapon)
                        : [],
                    'weapon_killer_species_key' => $weapon?->killer_species_key,
                    'weapon_killer_damage_rate' => $weapon
                        ? $permissionService->effectiveKillerDamageRate($character, $weapon)
                        : 0.0,
                    'armor_resist_species_key' => $armor?->resist_species_key,
                    'armor_species_damage_reduction_rate' => $armor
                        ? $permissionService->effectiveSpeciesDamageReductionRate($character, $armor)
                        : 0.0,
                ],
                'equipment' => $equippedItems
                    ->filter(fn ($entry): bool => $entry->item !== null)
                    ->map(fn ($entry): array => [
                        'slot' => (string) ($entry->equipped_slot ?: $entry->item->type),
                        'type' => (string) $entry->item->type,
                        'name' => $entry->displayName(),
                        'enhance_level' => max(0, (int) ($entry->enhance_level ?? 0)),
                        'quality' => $entry->affix_quality,
                        'effective_stats' => $this->onlyStats(
                            $this->statusService->equipmentStatsFor($character, $entry),
                            equipment: true,
                        ),
                    ])
                    ->values()
                    ->all(),
                'job_arts' => $jobArts
                    ->map(fn (Skill $skill): array => [
                        'master_id' => (int) $skill->id,
                        'attributes' => $this->modelAttributes($skill, self::SKILL_ATTRIBUTE_KEYS),
                        'runtime' => $this->modelAttributes($skill, self::SKILL_RUNTIME_KEYS),
                    ])
                    ->values()
                    ->all(),
            ],
            'enemy' => $this->enemySnapshot($character, $enemy, $battleType),
            'engine' => [
                'service' => BattleService::class,
                'max_turns' => self::MAX_TURNS,
                'persistence' => false,
            ],
        ];

        $this->validateSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * Build the same validated battle input from a Lab-owned, in-memory character state.
     * No Character or inventory row is created.
     *
     * @param array{
     *   level:int,
     *   current_hp:int,
     *   current_sp:int,
     *   stats:array<string,int>,
     *   job:array{master_id:int,key:string,name:string,normal_attack_type:?string}|null,
     *   equipment:list<array<string,mixed>>
     * } $characterState
     * @return array<string, mixed>
     */
    public function captureSynthetic(array $characterState, Enemy $enemy, string $battleType, int $seed): array
    {
        $battleType = $this->validateBattleType($battleType);
        $this->assertSeed($seed);
        $stats = $characterState['stats'] ?? [];
        $job = $characterState['job'] ?? null;

        $character = new Character([
            'name' => '匿名冒険者',
            'level' => (int) ($characterState['level'] ?? 1),
            'current_job_id' => $job['master_id'] ?? null,
            'hp_base' => (int) ($stats['max_hp'] ?? 1),
            'mp_base' => (int) ($stats['max_mp'] ?? 0),
            'attack_base' => (int) ($stats['str'] ?? 1),
            'defense_base' => (int) ($stats['def'] ?? 0),
            'speed_base' => (int) ($stats['agi'] ?? 1),
            'magic_base' => (int) ($stats['mag'] ?? 0),
            'spirit_base' => (int) ($stats['spr'] ?? 0),
            'luck_base' => (int) ($stats['luk'] ?? 0),
            'current_hp' => (int) ($characterState['current_hp'] ?? 1),
            'current_mp' => (int) ($characterState['current_sp'] ?? 0),
        ]);
        $character->exists = false;

        $snapshot = [
            'schema' => self::SNAPSHOT_SCHEMA,
            'seed' => $seed,
            'battle_type' => $battleType,
            'character' => [
                'label' => '匿名冒険者',
                'level' => max(1, (int) ($characterState['level'] ?? 1)),
                'starting_hp' => max(1, (int) ($characterState['current_hp'] ?? 1)),
                'starting_sp' => max(0, (int) ($characterState['current_sp'] ?? 0)),
                'stats' => $this->onlyStats($stats),
                'sp_power_reference' => max(0, (int) ($stats['max_mp'] ?? 0)),
                'job' => $job,
                'activation_policy' => 'normal',
                'strategy' => [],
                'combat_effects' => [
                    'weapon_killer_effects' => [],
                    'weapon_killer_species_key' => null,
                    'weapon_killer_damage_rate' => 0.0,
                    'armor_resist_species_key' => null,
                    'armor_species_damage_reduction_rate' => 0.0,
                ],
                'equipment' => array_values((array) ($characterState['equipment'] ?? [])),
                'job_arts' => [],
            ],
            'enemy' => $this->enemySnapshot($character, $enemy, $battleType),
            'engine' => [
                'service' => BattleService::class,
                'max_turns' => self::MAX_TURNS,
                'persistence' => false,
            ],
        ];

        $this->validateSnapshot($snapshot);

        return $snapshot;
    }

    public function encode(array $snapshot): string
    {
        $this->validateSnapshot($snapshot);

        try {
            return json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('スナップショットJSONを生成できませんでした。', previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('JSONは1文字以上512KB以下にしてください。');
        }

        try {
            $snapshot = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('JSONの形式が正しくありません。', previous: $exception);
        }

        if (! is_array($snapshot)) {
            throw new InvalidArgumentException('JSONのルートはobjectにしてください。');
        }

        $this->validateSnapshot($snapshot);

        return $snapshot;
    }

    public function executeSnapshot(array $snapshot, ?int $seed = null): BattleResult
    {
        $this->validateSnapshot($snapshot);
        $seed ??= (int) $snapshot['seed'];
        $this->assertSeed($seed);

        $character = $this->hydrateCharacter($snapshot['character']);
        $enemy = $this->hydrateEnemy($snapshot['enemy']);
        $preparedPlayer = $this->preparedPlayer($snapshot['character']);
        $randomizer = new Randomizer(new Mt19937($seed));
        $previousRandomizer = $this->battleRandomizer;
        $previousDamageCalculator = $this->damageCalculator;

        try {
            return ScopedBattleRandomizer::run(
                $randomizer,
                function () use ($randomizer, $character, $enemy, $preparedPlayer, $snapshot): BattleResult {
                    $this->useScopedBattleRandomizer($randomizer);

                    return parent::executeBattle($character, $enemy, 0, [
                        'persist_character_state' => false,
                        'rewards_enabled' => true,
                        'exploration_support_enabled' => false,
                        'auto_unequip_invalid_items' => false,
                        'valmon_assist_enabled' => false,
                        'battle_type' => (string) $snapshot['battle_type'],
                        'job_art_context' => (string) $snapshot['battle_type'],
                        'max_turns' => (int) $snapshot['engine']['max_turns'],
                        'prepared_player_actor' => $preparedPlayer,
                        'prepared_enemy_stats' => $snapshot['enemy']['stats'],
                    ]);
                },
            );
        } finally {
            $this->battleRandomizer = $previousRandomizer;
            $this->damageCalculator = $previousDamageCalculator;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentResult(BattleResult $result): array
    {
        return [
            'result' => $result->result,
            'result_label' => match ($result->result) {
                'victory' => '勝利',
                'defeat' => '敗北',
                default => '時間切れ',
            },
            'turn_count' => $result->turnCount,
            'hp_before' => $result->playerHpBefore,
            'hp_after' => $result->playerHpAfter,
            'sp_before' => $result->playerMpBefore,
            'sp_after' => $result->playerMpAfter,
            'damage_dealt' => $result->damageDealt,
            'damage_taken' => $result->damageTaken,
            'exp' => $result->exp,
            'gold' => $result->gold,
            'job_exp' => $result->jobExp,
            'drops' => $result->drops,
            'drop_bonus_percent' => $result->dropBonusPercent,
            'rare_bonus_percent' => $result->rareBonusPercent,
            'logs' => $result->logs,
            'enemy_stat_display' => $result->enemyStatDisplay,
            'job_art_hud' => $result->jobArtV2Hud,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function validateSnapshot(array $snapshot): void
    {
        $this->assertAllowedKeys($snapshot, ['schema', 'seed', 'battle_type', 'character', 'enemy', 'engine'], 'root');
        if (($snapshot['schema'] ?? null) !== self::SNAPSHOT_SCHEMA) {
            throw new InvalidArgumentException('対応していないスナップショットschemaです。');
        }

        $this->assertSeed($snapshot['seed'] ?? null);
        $this->validateBattleType($snapshot['battle_type'] ?? null);
        if (! is_array($snapshot['character'] ?? null)
            || ! is_array($snapshot['enemy'] ?? null)
            || ! is_array($snapshot['engine'] ?? null)
        ) {
            throw new InvalidArgumentException('character、enemy、engineはobjectにしてください。');
        }

        $character = $snapshot['character'];
        $this->assertAllowedKeys($character, [
            'label', 'level', 'starting_hp', 'starting_sp', 'stats', 'sp_power_reference',
            'job', 'activation_policy', 'strategy', 'combat_effects', 'equipment', 'job_arts',
        ], 'character');
        if (($character['label'] ?? null) !== '匿名冒険者') {
            throw new InvalidArgumentException('character.labelは匿名冒険者に固定してください。');
        }
        $this->assertIntegerRange($character['level'] ?? null, 1, 255, 'character.level');
        $this->assertIntegerRange($character['starting_hp'] ?? null, 1, 1_000_000_000_000, 'character.starting_hp');
        $this->assertIntegerRange($character['starting_sp'] ?? null, 0, 1_000_000_000_000, 'character.starting_sp');
        $this->assertIntegerRange($character['sp_power_reference'] ?? null, 0, 1_000_000_000_000, 'character.sp_power_reference');
        $this->validateStatMap($character['stats'] ?? null, 'character.stats');
        $this->validateJob($character['job'] ?? null);
        if (! is_string($character['activation_policy'] ?? null)
            || ! in_array($character['activation_policy'], ['aggressive', 'normal', 'conserve', 'boss_only'], true)
        ) {
            throw new InvalidArgumentException('character.activation_policyが正しくありません。');
        }
        if (! is_array($character['strategy'] ?? null)
            || ! is_array($character['combat_effects'] ?? null)
            || ! is_array($character['equipment'] ?? null)
            || ! is_array($character['job_arts'] ?? null)
        ) {
            throw new InvalidArgumentException('character内の配列形式が正しくありません。');
        }
        if (count($character['equipment']) > 16 || count($character['job_arts']) > 5) {
            throw new InvalidArgumentException('装備または戦技の件数が上限を超えています。');
        }
        $this->validateCombatEffects($character['combat_effects']);
        foreach ($character['equipment'] as $equipment) {
            $this->validateEquipment($equipment);
        }
        foreach ($character['job_arts'] as $jobArt) {
            $this->validateJobArt($jobArt);
        }

        $enemy = $snapshot['enemy'];
        $this->assertAllowedKeys($enemy, ['master_id', 'attributes', 'area', 'stats', 'actions'], 'enemy');
        $this->assertIntegerRange($enemy['master_id'] ?? null, 1, self::MAX_SEED, 'enemy.master_id');
        if (! is_array($enemy['attributes'] ?? null) || ! is_array($enemy['actions'] ?? null)) {
            throw new InvalidArgumentException('enemy.attributesまたはenemy.actionsが正しくありません。');
        }
        $this->assertAllowedKeys($enemy['attributes'], self::ENEMY_ATTRIBUTE_KEYS, 'enemy.attributes');
        foreach (['name', 'level', 'max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk', 'exp_reward', 'gold_reward', 'is_boss'] as $key) {
            if (! array_key_exists($key, $enemy['attributes'])) {
                throw new InvalidArgumentException("enemy.attributes.{$key}がありません。");
            }
        }
        if (! is_string($enemy['attributes']['name']) || $enemy['attributes']['name'] === '') {
            throw new InvalidArgumentException('敵名が正しくありません。');
        }
        if ((bool) $enemy['attributes']['is_boss'] !== ($snapshot['battle_type'] === 'boss')) {
            throw new InvalidArgumentException('敵のboss状態とbattle_typeが一致しません。');
        }
        $this->validateEnemyStats($enemy['stats'] ?? null);
        $this->validateArea($enemy['area'] ?? null);
        if (count($enemy['actions']) > 100) {
            throw new InvalidArgumentException('敵行動の件数が上限を超えています。');
        }
        foreach ($enemy['actions'] as $action) {
            if (! is_array($action)) {
                throw new InvalidArgumentException('敵行動はobjectにしてください。');
            }
            $this->assertAllowedKeys($action, ['master_id', 'attributes'], 'enemy.actions[]');
            $this->assertIntegerRange($action['master_id'] ?? null, 1, self::MAX_SEED, 'enemy.actions[].master_id');
            if (! is_array($action['attributes'] ?? null)) {
                throw new InvalidArgumentException('敵行動attributesはobjectにしてください。');
            }
            $this->assertAllowedKeys($action['attributes'], self::ENEMY_ACTION_ATTRIBUTE_KEYS, 'enemy.actions[].attributes');
            $this->validateOptionalHitCount($action['attributes'], 'enemy.actions[].attributes.hit_count');
        }

        $engine = $snapshot['engine'];
        $this->assertAllowedKeys($engine, ['service', 'max_turns', 'persistence'], 'engine');
        if (($engine['service'] ?? null) !== BattleService::class
            || ($engine['persistence'] ?? null) !== false
            || ($engine['max_turns'] ?? null) !== self::MAX_TURNS
        ) {
            throw new InvalidArgumentException('engine設定は非永続の現行BattleServiceに固定してください。');
        }

        $this->assertNoForbiddenKeys($snapshot);
        $this->assertSafeStrings($snapshot);
    }

    /** @return array<string, int> */
    private function onlyStats(array $stats, bool $equipment = false): array
    {
        $output = [];
        foreach (self::STAT_KEYS as $key) {
            $sourceKey = $equipment
                ? match ($key) {
                    'max_hp' => 'hp',
                    'max_mp' => 'mp',
                    default => $key,
                }
                : $key;
            $minimum = in_array($key, ['max_hp', 'str', 'agi'], true) && ! $equipment ? 1 : 0;
            $output[$key] = max($minimum, (int) ($stats[$sourceKey] ?? 0));
        }

        return $output;
    }

    /** @return array<string, mixed> */
    private function onlyEnemyStats(array $stats): array
    {
        $output = [];
        foreach (self::ENEMY_STAT_KEYS as $key) {
            $output[$key] = $stats[$key] ?? match ($key) {
                'danger_label' => '安定',
                'durability_tier' => 'standard',
                'durability_hp_multiplier', 'durability_def_spr_multiplier', 'durability_atk_mag_multiplier' => 1.0,
                default => 0,
            };
        }

        return $output;
    }

    /** @return array<string, mixed> */
    private function enemySnapshot(Character $character, Enemy $enemy, string $battleType): array
    {
        $enemy->loadMissing(['actions', 'area.city']);
        $battleEnemy = clone $enemy;
        $battleEnemy->setAttribute('is_boss', $battleType === 'boss');
        $battleEnemy->setRelation('actions', $enemy->actions);
        $battleEnemy->setRelation('area', $enemy->area);

        return [
            'master_id' => (int) $enemy->id,
            'attributes' => $this->modelAttributes($battleEnemy, self::ENEMY_ATTRIBUTE_KEYS),
            'area' => $enemy->area ? [
                'master_id' => (int) $enemy->area->id,
                'name' => (string) $enemy->area->name,
                'city_master_id' => $enemy->area->city ? (int) $enemy->area->city->id : null,
                'city_name' => $enemy->area->city?->name,
            ] : null,
            'stats' => $this->onlyEnemyStats($this->enemyBattleStats($character, $battleEnemy)),
            'actions' => $enemy->actions
                ->map(fn (EnemyAction $action): array => [
                    'master_id' => (int) $action->id,
                    'attributes' => $this->modelAttributes($action, self::ENEMY_ACTION_ATTRIBUTE_KEYS),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function modelAttributes(object $model, array $keys): array
    {
        $attributes = [];
        foreach ($keys as $key) {
            if ($model->getAttribute($key) !== null) {
                $attributes[$key] = $model->getAttribute($key);
            }
        }

        return $attributes;
    }

    /** @param array<string, mixed> $character */
    private function hydrateCharacter(array $character): Character
    {
        $stats = $character['stats'];
        $job = $character['job'];
        $model = new Character([
            'name' => '匿名冒険者',
            'level' => (int) $character['level'],
            'current_job_id' => $job['master_id'] ?? null,
            'hp_base' => (int) $stats['max_hp'],
            'mp_base' => (int) $stats['max_mp'],
            'attack_base' => (int) $stats['str'],
            'defense_base' => (int) $stats['def'],
            'speed_base' => (int) $stats['agi'],
            'magic_base' => (int) $stats['mag'],
            'spirit_base' => (int) $stats['spr'],
            'luck_base' => (int) $stats['luk'],
            'current_hp' => (int) $character['starting_hp'],
            'current_mp' => (int) $character['starting_sp'],
            'job_art_activation_policy' => (string) $character['activation_policy'],
        ]);

        $jobModel = $job ? $this->hydrateJob($job) : null;
        $model->setRelation('currentJob', $jobModel);
        $model->setRelation('jobClass', $jobModel);

        return $model;
    }

    /** @param array<string, mixed> $enemy */
    private function hydrateEnemy(array $enemy): Enemy
    {
        $model = new Enemy($enemy['attributes']);
        $model->setAttribute('id', (int) $enemy['master_id']);
        $model->exists = false;

        $area = null;
        if ($enemy['area'] !== null) {
            $area = new Area([
                'name' => $enemy['area']['name'],
                'city_id' => $enemy['area']['city_master_id'],
            ]);
            $area->setAttribute('id', (int) $enemy['area']['master_id']);
            $area->exists = false;
        }
        $model->setRelation('area', $area);
        $model->setRelation('actions', collect($enemy['actions'])->map(function (array $action): EnemyAction {
            $model = new EnemyAction($action['attributes']);
            $model->setAttribute('id', (int) $action['master_id']);
            $model->exists = false;

            return $model;
        }));

        return $model;
    }

    /** @param array<string, mixed> $character */
    private function preparedPlayer(array $character): array
    {
        $combatEffects = $character['combat_effects'];
        $job = $character['job'];

        return [
            'name' => '匿名冒険者',
            'stats' => $character['stats'],
            'starting_hp' => (int) $character['starting_hp'],
            'starting_mp' => (int) $character['starting_sp'],
            'normal_attack_type' => $job['normal_attack_type'] ?? null,
            'current_job_id' => $job['master_id'] ?? null,
            'job_key' => $job['key'] ?? null,
            'sp_power_reference' => (int) $character['sp_power_reference'],
            'sp_scaling_eligible' => true,
            'weapon_killer_effects' => $combatEffects['weapon_killer_effects'],
            'weapon_killer_species_key' => $combatEffects['weapon_killer_species_key'],
            'weapon_killer_damage_rate' => $combatEffects['weapon_killer_damage_rate'],
            'armor_resist_species_key' => $combatEffects['armor_resist_species_key'],
            'armor_species_damage_reduction_rate' => $combatEffects['armor_species_damage_reduction_rate'],
            'job_art_activation_policy' => $character['activation_policy'],
            'job_art_strategy' => $character['strategy'],
            'job_arts' => collect($character['job_arts'])->map(function (array $jobArt): Skill {
                $skill = new Skill($jobArt['attributes']);
                $skill->setAttribute('id', (int) $jobArt['master_id']);
                foreach ($jobArt['runtime'] as $key => $value) {
                    $skill->setAttribute($key, $value);
                }
                $skill->exists = false;

                return $skill;
            })->all(),
        ];
    }

    /** @param array<string, mixed> $job */
    private function hydrateJob(array $job): JobClass
    {
        $model = new JobClass([
            'key' => $job['key'],
            'name' => $job['name'],
            'normal_attack_type' => $job['normal_attack_type'],
        ]);
        $model->setAttribute('id', (int) $job['master_id']);
        $model->exists = false;

        return $model;
    }

    private function validateBattleType(mixed $battleType): string
    {
        if (! is_string($battleType) || ! in_array($battleType, ['pve', 'boss'], true)) {
            throw new InvalidArgumentException('戦闘種別は通常戦またはボス戦にしてください。');
        }

        return $battleType;
    }

    private function assertSeed(mixed $seed): void
    {
        $this->assertIntegerRange($seed, 0, self::MAX_SEED, 'seed');
    }

    private function assertIntegerRange(mixed $value, int $min, int $max, string $path): void
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("{$path}は{$min}〜{$max}の整数にしてください。");
        }
    }

    private function validateStatMap(mixed $stats, string $path, bool $allowZero = false): void
    {
        if (! is_array($stats)) {
            throw new InvalidArgumentException("{$path}はobjectにしてください。");
        }
        $this->assertAllowedKeys($stats, self::STAT_KEYS, $path);
        foreach (self::STAT_KEYS as $key) {
            $minimum = ! $allowZero && in_array($key, ['max_hp', 'str', 'agi'], true) ? 1 : 0;
            $this->assertIntegerRange($stats[$key] ?? null, $minimum, 1_000_000_000_000, "{$path}.{$key}");
        }
    }

    private function validateEnemyStats(mixed $stats): void
    {
        if (! is_array($stats)) {
            throw new InvalidArgumentException('enemy.statsはobjectにしてください。');
        }
        $this->assertAllowedKeys($stats, self::ENEMY_STAT_KEYS, 'enemy.stats');
        foreach (self::ENEMY_STAT_KEYS as $key) {
            if (! array_key_exists($key, $stats)) {
                throw new InvalidArgumentException("enemy.stats.{$key}がありません。");
            }
        }
        foreach (['base_hp', 'base_str', 'base_def', 'max_hp', 'str', 'def', 'agi', 'mag', 'spr', 'luk', 'bonus_hp', 'bonus_str', 'bonus_def', 'danger_rate'] as $key) {
            if (! is_int($stats[$key]) || $stats[$key] < 0 || $stats[$key] > 1_000_000_000_000) {
                throw new InvalidArgumentException("enemy.stats.{$key}が正しくありません。");
            }
        }
        foreach (['durability_hp_multiplier', 'durability_def_spr_multiplier', 'durability_atk_mag_multiplier'] as $key) {
            if (! is_int($stats[$key]) && ! is_float($stats[$key])) {
                throw new InvalidArgumentException("enemy.stats.{$key}が正しくありません。");
            }
        }
        if (! is_string($stats['danger_label']) || ! is_string($stats['durability_tier'])) {
            throw new InvalidArgumentException('enemy.statsの表示値が正しくありません。');
        }
    }

    private function validateJob(mixed $job): void
    {
        if ($job === null) {
            return;
        }
        if (! is_array($job)) {
            throw new InvalidArgumentException('character.jobはobjectまたはnullにしてください。');
        }
        $this->assertAllowedKeys($job, ['master_id', 'key', 'name', 'normal_attack_type'], 'character.job');
        $this->assertIntegerRange($job['master_id'] ?? null, 1, self::MAX_SEED, 'character.job.master_id');
        if (! is_string($job['key'] ?? null) || ! is_string($job['name'] ?? null)) {
            throw new InvalidArgumentException('character.jobのkeyまたはnameが正しくありません。');
        }
        if (($job['normal_attack_type'] ?? null) !== null
            && ! in_array($job['normal_attack_type'], ['physical', 'magical', 'adaptive'], true)
        ) {
            throw new InvalidArgumentException('character.job.normal_attack_typeが正しくありません。');
        }
    }

    private function validateCombatEffects(array $effects): void
    {
        $keys = [
            'weapon_killer_effects', 'weapon_killer_species_key', 'weapon_killer_damage_rate',
            'armor_resist_species_key', 'armor_species_damage_reduction_rate',
        ];
        $this->assertAllowedKeys($effects, $keys, 'character.combat_effects');
        foreach ($keys as $key) {
            if (! array_key_exists($key, $effects)) {
                throw new InvalidArgumentException("character.combat_effects.{$key}がありません。");
            }
        }
        if (! is_array($effects['weapon_killer_effects']) || count($effects['weapon_killer_effects']) > 8) {
            throw new InvalidArgumentException('特攻効果が正しくありません。');
        }
        foreach ($effects['weapon_killer_effects'] as $effect) {
            if (! is_array($effect)) {
                throw new InvalidArgumentException('特攻効果はobjectにしてください。');
            }
            $this->assertAllowedKeys($effect, ['source', 'species_key', 'damage_rate'], 'weapon_killer_effects[]');
        }
        foreach (['weapon_killer_damage_rate', 'armor_species_damage_reduction_rate'] as $key) {
            if (! is_int($effects[$key]) && ! is_float($effects[$key])) {
                throw new InvalidArgumentException("character.combat_effects.{$key}が正しくありません。");
            }
        }
    }

    private function validateEquipment(mixed $equipment): void
    {
        if (! is_array($equipment)) {
            throw new InvalidArgumentException('装備はobjectにしてください。');
        }
        $this->assertAllowedKeys($equipment, ['slot', 'type', 'name', 'enhance_level', 'quality', 'effective_stats'], 'equipment[]');
        if (! is_string($equipment['slot'] ?? null)
            || ! is_string($equipment['type'] ?? null)
            || ! is_string($equipment['name'] ?? null)
        ) {
            throw new InvalidArgumentException('装備表示値が正しくありません。');
        }
        $this->assertIntegerRange($equipment['enhance_level'] ?? null, 0, 100, 'equipment[].enhance_level');
        $this->validateStatMap($equipment['effective_stats'] ?? null, 'equipment[].effective_stats', allowZero: true);
    }

    private function validateJobArt(mixed $jobArt): void
    {
        if (! is_array($jobArt)) {
            throw new InvalidArgumentException('戦技はobjectにしてください。');
        }
        $this->assertAllowedKeys($jobArt, ['master_id', 'attributes', 'runtime'], 'job_arts[]');
        $this->assertIntegerRange($jobArt['master_id'] ?? null, 1, self::MAX_SEED, 'job_arts[].master_id');
        if (! is_array($jobArt['attributes'] ?? null) || ! is_array($jobArt['runtime'] ?? null)) {
            throw new InvalidArgumentException('戦技attributesまたはruntimeが正しくありません。');
        }
        $this->assertAllowedKeys($jobArt['attributes'], self::SKILL_ATTRIBUTE_KEYS, 'job_arts[].attributes');
        $this->assertAllowedKeys($jobArt['runtime'], self::SKILL_RUNTIME_KEYS, 'job_arts[].runtime');
        if (! is_string($jobArt['attributes']['name'] ?? null)) {
            throw new InvalidArgumentException('戦技名がありません。');
        }
        $this->validateOptionalHitCount($jobArt['attributes'], 'job_arts[].attributes.hit_count');
    }

    /** @param array<string, mixed> $attributes */
    private function validateOptionalHitCount(array $attributes, string $path): void
    {
        if (! array_key_exists('hit_count', $attributes)) {
            return;
        }

        $this->assertIntegerRange($attributes['hit_count'], 0, self::MAX_HIT_COUNT, $path);
    }

    private function validateArea(mixed $area): void
    {
        if ($area === null) {
            return;
        }
        if (! is_array($area)) {
            throw new InvalidArgumentException('enemy.areaはobjectまたはnullにしてください。');
        }
        $this->assertAllowedKeys($area, ['master_id', 'name', 'city_master_id', 'city_name'], 'enemy.area');
        $this->assertIntegerRange($area['master_id'] ?? null, 1, self::MAX_SEED, 'enemy.area.master_id');
        if (! is_string($area['name'] ?? null)) {
            throw new InvalidArgumentException('enemy.area.nameが正しくありません。');
        }
        if (($area['city_master_id'] ?? null) !== null) {
            $this->assertIntegerRange($area['city_master_id'], 1, self::MAX_SEED, 'enemy.area.city_master_id');
        }
        if (($area['city_name'] ?? null) !== null && ! is_string($area['city_name'])) {
            throw new InvalidArgumentException('enemy.area.city_nameが正しくありません。');
        }
    }

    private function assertAllowedKeys(array $value, array $allowed, string $path): void
    {
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$path}に未対応キーがあります: ".implode(', ', $unknown));
        }
    }

    private function assertNoForbiddenKeys(array $value, string $path = 'root'): void
    {
        foreach ($value as $key => $nested) {
            $keyString = (string) $key;
            if (in_array(strtolower($keyString), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException("{$path}.{$keyString}は個人・認証情報のため使用できません。");
            }
            if (is_array($nested)) {
                $this->assertNoForbiddenKeys($nested, "{$path}.{$keyString}");
            }
        }
    }

    private function assertSafeStrings(array $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('JSONの入れ子が深すぎます。');
        }
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $this->assertSafeStrings($nested, $depth + 1);
                continue;
            }
            if (is_string($nested)) {
                if (mb_strlen($nested) > 10_000
                    || str_contains($nested, '<')
                    || str_contains($nested, '>')
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $nested) === 1
                ) {
                    throw new InvalidArgumentException('JSON内の文字列に表示できない文字またはHTML記号があります。');
                }
                continue;
            }
            if ($nested !== null && ! is_int($nested) && ! is_float($nested) && ! is_bool($nested)) {
                throw new InvalidArgumentException('JSONには文字列・数値・真偽値・nullだけを使用してください。');
            }
        }
    }
}
