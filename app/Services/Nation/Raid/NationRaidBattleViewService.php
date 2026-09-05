<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Services\CharacterIconSetService;

/** 試遊と正式出撃の戦闘ログ・編成・装備表示を共有する。 */
final readonly class NationRaidBattleViewService
{
    private const VISIBLE_SUPPORTER_LIMIT = 6;

    /** @var array<string, string> */
    private const FORM_ORDINALS = [
        NationRaidRules::FORM_SEALED_SCALE => '第一形態',
        NationRaidRules::FORM_SPLIT_WING => '第二形態',
        NationRaidRules::FORM_LINEAGE_INVASION => '第三形態',
        NationRaidRules::FORM_EXPOSED_CORE => '第四形態',
    ];

    /** @var array<string, array{label:string,description:string}> */
    private const STRATEGIES = [
        NationRaidRules::STRATEGY_ASSAULT => [
            'label' => '猛攻',
            'description' => '攻撃・消費・奥義系の戦技を優先します。',
        ],
        NationRaidRules::STRATEGY_INTERCEPT => [
            'label' => '迎撃',
            'description' => '予告へ応じる対抗戦技を優先します。',
        ],
        NationRaidRules::STRATEGY_FORTIFY => [
            'label' => '堅守',
            'description' => '回復・防御・浄化系の戦技を優先します。',
        ],
    ];

    public function __construct(
        private CharacterIconSetService $iconSetService,
        private NationRaidTrialBattleLogPresenter $battleLogPresenter,
        private NationRaidRules $rules,
        private NationRaidStrategyPolicy $strategyPolicy,
    ) {}

    public function encounter(int $stage, int $hp, int $maxHp, ?string $lineage): array
    {
        $form = $this->rules->formForHp($hp, $maxHp);
        return [
            'stage' => $stage,
            'stage_name' => $this->rules->stageParameters($stage)['stage_name'],
            'current_hp' => $hp, 'max_hp' => $maxHp,
            'form' => ['key' => $form, 'ordinal' => self::FORM_ORDINALS[$form], ...$this->rules->formParameters($form)],
            'dominant_lineage' => $lineage,
            'dominant_lineage_label' => $lineage === null ? '系譜観測（対抗系譜なし）'
                : $this->lineageLabel($lineage),
        ];
    }

    public function lineageLabel(string $lineage): string
    {
        $mapping = app(\App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter::class)->mappings();
        $canonical = array_search($lineage, $mapping, true);

        return app(\App\Services\JobArtLineageCatalog::class)->nameForKey($canonical === false ? null : $canonical) ?? '系譜観測';
    }

    public function result(
        NationRaidBattleResult $battle, array $player, array $encounter,
        array $playerBattleLogs, array $coordination, string $bossName,
        int $staminaCost, ?array $stamina = null,
    ): array {
        $stage = $battle->stage;
        $form = $battle->form;
        $strategy = $battle->strategy;
        $seed = $battle->seed;
        $dominantLineage = $encounter['dominant_lineage'];
        $formParameters = $this->rules->formParameters($form);
        $cycleCurrentHp = $encounter['current_hp'];
        $coordinationDamage = (int) floor($battle->calculatedBossDamage * (float) $coordination['bonus_rate']);
        $bossDamage = $battle->calculatedBossDamage + $coordinationDamage;
        $lastTurn = $battle->turns[array_key_last($battle->turns)] ?? [];
        return [
            'schema_version' => 'nation-raid-battle-view-v1',
            'boss_name' => $bossName,
            'boss_species_label' => '竜',
            'stage' => $stage,
            'stage_name' => $encounter['stage_name'],
            'form' => [
                'key' => $form,
                'ordinal' => self::FORM_ORDINALS[$form],
                'name' => $formParameters['name'],
                'image_path' => $formParameters['image_path'],
                'starting_hp' => $cycleCurrentHp,
                'max_hp' => $encounter['max_hp'],
            ],
            'strategy' => $strategy,
            'strategy_label' => $strategy === NationRaidRules::STRATEGY_BOSS_SET ? 'ボス戦セット' : self::STRATEGIES[$strategy]['label'],
            'dominant_lineage' => $dominantLineage,
            'dominant_lineage_label' => $encounter['dominant_lineage_label'],
            'seed' => $seed,
            'ruleset_hash' => $battle->rulesetHash,
            'character' => $player['character'],
            'abilities' => $player['abilities'],
            'equipment' => $player['equipment'],
            'raid_resistance_rate' => $player['raid_resistance_rate'],
            'boss_set' => $player['boss_set'],
            'counterplay_enabled' => $player['counterplay_enabled'],
            'outcome' => $battle->outcome,
            'outcome_label' => $battle->outcome === 'survived' ? '20ターン生還' : '戦闘不能',
            'turns_completed' => $battle->turnsCompleted,
            'player_max_hp' => $player['abilities']['max_hp'],
            'player_remaining_hp' => $battle->playerRemainingHp,
            'player_max_sp' => $player['abilities']['max_sp'],
            'player_remaining_sp' => (int) ($lastTurn['player_sp_after'] ?? $player['abilities']['max_sp']),
            'calculated_boss_damage' => $battle->calculatedBossDamage,
            'coordination_damage' => $coordinationDamage,
            'shared_hp_damage' => $bossDamage,
            'boss_remaining_hp' => max(0, $cycleCurrentHp - $bossDamage),
            'coordination' => $coordination,
            'max_one_action_damage' => $battle->maxOneActionDamage,
            'boss_virtual_remaining_hp' => $battle->bossVirtualRemainingHp,
            't20_starting_sp' => $battle->t20StartingSp,
            'ultimate_denial_reasons' => $battle->ultimateDenialReasons,
            'exploration_stamina_cost' => $staminaCost,
            'exploration_stamina' => $stamina,
            'player_battle_logs' => $playerBattleLogs,
            'battle_log' => $this->battleLogPresenter->present(
                $battle,
                $playerBattleLogs,
                $player['character']['name'],
                $bossName,
            ),
            'turns' => $this->turnRows($battle->turns),
        ];
    }

    /** @return list<array{stage:int,name:string,label:string,attack:int}> */
    public function stages(): array
    {
        return array_map(function (int $stage): array {
            $parameters = $this->rules->stageParameters($stage);

            return [
                'stage' => $stage,
                'name' => $parameters['stage_name'],
                'label' => "第{$stage}再臨《{$parameters['stage_name']}》",
                'attack' => $parameters['attack'],
            ];
        }, range(1, NationRaidRules::MAX_STAGES));
    }

    /** @return list<array{key:string,label:string,description:string}> */
    public function strategies(): array
    {
        if (! $this->strategyPolicy->enabled()) {
            return [];
        }
        $rows = [];
        foreach (self::STRATEGIES as $key => $strategy) {
            $rows[] = ['key' => $key, ...$strategy];
        }

        return $rows;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function coordinationPresentation(Character $character, array $state): array
    {
        $participantIds = array_values(array_filter(
            array_map('intval', is_array($state['participant_ids'] ?? null) ? $state['participant_ids'] : []),
            static fn (int $id): bool => $id > 0,
        ));
        $characters = $participantIds === []
            ? collect()
            : Character::query()->whereIn('id', $participantIds)->get()->keyBy('id');
        $participants = [];
        foreach ($participantIds as $participantId) {
            $participant = $characters->get($participantId);
            if (! $participant instanceof Character) {
                continue;
            }
            $participants[] = [
                'character_id' => $participantId,
                'name' => (string) $participant->name,
                'battle_image_path' => $this->iconSetService->pathFor($participant, 'battle'),
                'is_current_character' => $participantId === (int) $character->id,
            ];
        }

        $supporters = array_values(array_filter(
            $participants,
            static fn (array $participant): bool => ! $participant['is_current_character'],
        ));
        $visibleSupporters = array_slice($supporters, 0, self::VISIBLE_SUPPORTER_LIMIT);
        $leftSupporters = [];
        $rightSupporters = [];
        foreach ($visibleSupporters as $index => $supporter) {
            if ($index % 2 === 0) {
                $leftSupporters[] = $supporter;
            } else {
                $rightSupporters[] = $supporter;
            }
        }

        return [
            ...$state,
            'participants' => $participants,
            'left_supporters' => $leftSupporters,
            'right_supporters' => $rightSupporters,
            'hidden_supporter_count' => max(0, count($supporters) - count($visibleSupporters)),
        ];
    }

    /** @param list<array<string,mixed>> $turns @return list<array<string,mixed>> */
    private function turnRows(array $turns): array
    {
        $actionNames = ['lineage_observation' => '系譜観測'];
        foreach ($this->rules->basicActions() as $id => $action) {
            $actionNames[$id] = $action['name'];
        }
        foreach ($this->rules->counterActions() as $action) {
            $actionNames[$action['action_id']] = $action['name'];
        }

        return array_map(function (array $turn) use ($actionNames): array {
            $identity = $turn['selected_counterplay_identity'] ?? null;
            $enemyDamage = is_array($turn['enemy_damage'] ?? null) ? $turn['enemy_damage'] : [];
            $beforeCap = (int) ($enemyDamage['beforeCap'] ?? 0);
            $afterCap = (int) ($enemyDamage['afterCap'] ?? 0);
            $actionId = $turn['enemy_action_id'] ?? null;

            return [
                'turn' => (int) $turn['turn'],
                'player_damage' => (int) ($turn['player_damage']['total_damage'] ?? 0),
                'counterplay_name' => is_string($identity)
                    ? ($this->rules->counterplayArt($identity)['name'] ?? null)
                    : null,
                'enemy_action_name' => is_string($actionId) ? ($actionNames[$actionId] ?? $actionId) : '行動遅延',
                'enemy_damage' => (int) ($enemyDamage['finalDamage'] ?? 0),
                'damage_cap' => (int) ($enemyDamage['cap'] ?? 0),
                'cap_hit' => $beforeCap > $afterCap,
                'player_self_damage' => (int) ($turn['player_self_damage'] ?? 0),
                'player_hp_after' => (int) ($turn['player_hp_after'] ?? 0),
                'player_sp_after' => (int) ($turn['player_sp_after'] ?? 0),
                'boss_sp_after' => (int) ($turn['boss_sp_after'] ?? 0),
                'pending_kind' => $turn['pending_kind'] ?? null,
                'note' => $turn['note'] ?? null,
            ];
        }, $turns);
    }
}
