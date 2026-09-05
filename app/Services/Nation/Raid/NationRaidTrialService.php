<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Services\ExplorationStaminaService;
use App\Services\Nation\Raid\Simulation\NationRaidTurnByTurnActionProfileBridge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 共有進行や報酬は書き込まず、探索力だけを消費して20ターン試すローカル専用窓口。 */
final readonly class NationRaidTrialService
{
    public const BOSS_NAME = '十系喰らいの黒天竜 ヴァルグレイド';

    public function __construct(
        private NationRaidPlayerPreparationService $preparation,
        private NationRaidBattleViewService $view,
        private ExplorationStaminaService $staminaService,
        private NationRaidTurnByTurnActionProfileBridge $bridge,
        private NationRaidTrialCoordinationService $coordinationService,
        private NationRaidRules $rules,
        private NationRaidStrategyPolicy $strategies,
    ) {}

    public function isEnabled(): bool
    {
        return app()->environment('local')
            && (bool) config('features.nation_competitive_raid_enabled', false);
    }

    public function screen(Character $character): array
    {
        $player = $this->preparation->capture($character);

        return [
            'boss_name' => self::BOSS_NAME,
            'boss_max_hp' => NationRaidRules::BOSS_MAX_HP,
            'boss_species_label' => '竜',
            'max_turns' => NationRaidRules::MAX_TURNS,
            'stages' => $this->view->stages(),
            'strategies' => $this->view->strategies(),
            'encounter' => $this->currentEncounter(),
            'character' => $player['character'],
            'abilities' => $player['abilities'],
            'equipment' => $player['equipment'],
            'boss_set' => $player['boss_set'],
            'counterplay_enabled' => $player['counterplay_enabled'],
            'coordination' => $this->view->coordinationPresentation($character, $this->coordinationService->snapshot($character)),
            'sortie_stamina_cost' => $this->sortieStaminaCost(),
            'exploration_stamina' => $this->staminaService->summary($character),
        ];
    }

    public function fight(Character $character, string $strategy): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('レイドのローカル試遊版は現在利用できません。');
        }
        $strategy = $this->strategies->forNewSortie($strategy);
        if (! in_array($strategy, $this->rules->strategyKeys(), true)) {
            throw new RuntimeException('選択された作戦を確認できません。');
        }
        $lock = Cache::lock('nation-raid-trial-sortie:'.(int) $character->id, 30);
        if (! $lock->get()) {
            throw new RuntimeException('出撃処理中です。少し待ってからもう一度お試しください。');
        }
        try {
            return DB::transaction(function () use ($character, $strategy): array {
                $staminaCost = $this->sortieStaminaCost();
                $stamina = $this->staminaService->consumeRequired(
                    $character, $staminaCost, "レイドボスへの出撃には探索力{$staminaCost}が必要です。",
                );
                if (! ($stamina['ok'] ?? false)) {
                    throw new RuntimeException((string) ($stamina['error'] ?? '探索力が足りません。'));
                }
                $player = $this->preparation->capture($character);
                $encounter = $this->currentEncounter();
                $seed = random_int(1, 2_147_483_647);
                $bridge = $this->bridge->resolveProfile($character, new NationRaidBattleInput(
                    stage: $encounter['stage'], cycleCurrentHp: $encounter['current_hp'],
                    cycleMaxHp: NationRaidRules::BOSS_MAX_HP, sourceCycleId: 'local-trial-'.$seed,
                    dominantLineage: null, seed: $seed, strategy: $strategy,
                    player: new NationRaidPlayerSnapshot(
                        maxHp: $player['abilities']['max_hp'], defense: $player['abilities']['defense'],
                        spirit: $player['abilities']['spirit'], maxSp: $player['abilities']['max_sp'],
                        finalDamageReductionRate: $player['raid_resistance_rate'],
                        counterplayEnabled: $player['counterplay_enabled'],
                        bossSetExactIdentities: $player['boss_set_exact_identities'],
                    ),
                ));
                $coordination = $this->view->coordinationPresentation($character, $this->coordinationService->register($character));

                return array_replace($this->view->result(
                    $bridge->battleResult, $player, $encounter, $bridge->playerBattleLogs,
                    $coordination, self::BOSS_NAME, $staminaCost, $stamina['stamina'],
                ), ['schema_version' => 'nation-raid-local-trial-result-v3']);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    public function strategyKeys(): array
    {
        return $this->rules->strategyKeys();
    }

    private function currentEncounter(): array
    {
        return $this->view->encounter(1, NationRaidRules::BOSS_MAX_HP, NationRaidRules::BOSS_MAX_HP, null);
    }

    private function sortieStaminaCost(): int
    {
        return max(1, (int) config('nation_raid.event.sortie_stamina_cost', 10));
    }
}
