<?php

namespace Tests\Unit;

use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageApplicationService;
use App\Services\JobArtV2ResourceService;
use App\Services\JobArtV2UltimateCounterplayService;
use App\Services\Nation\Raid\NationRaidBattleEngine;
use App\Services\Nation\Raid\NationRaidDamageResolver;
use App\Services\Nation\Raid\NationRaidEnemyDamageResult;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidSeededRandom;
use App\Services\Nation\Raid\NationRaidTrialBattleLogPresenter;
use App\Services\Nation\Raid\Simulation\NationRaidExistingPlayerDefenseApplier;
use App\Services\Nation\Raid\Simulation\NationRaidTurnByTurnActionProfileBridge;
use ReflectionMethod;
use Tests\TestCase;

final class NamelessRelicRaidDamageLogTest extends TestCase
{
    public function test_raid_species_trace_keeps_armor_and_legacy_defense(): void
    {
        [$player, $boss, $state] = $this->actors();
        $player->armorResistSpeciesKey = 'dragon';
        $player->armorSpeciesDamageReductionRate = .2;
        $player->namelessResistEffects = [['species_key' => 'dragon', 'damage_rate' => .2]];
        $player->isDefending = true;
        $live = (new ReflectionMethod(NationRaidTurnByTurnActionProfileBridge::class, 'livePlayerState'))
            ->invoke(app(NationRaidTurnByTurnActionProfileBridge::class), $player, $boss, $state);
        $this->assertEqualsWithDelta(.675, $live->finalDamageReductionRate, .000001);
        $this->assertEqualsWithDelta(.6, $live->finalDamageReductionRateWithoutRelics, .000001);
    }

    public function test_raid_display_uses_same_random_cap_and_telegraph_override(): void
    {
        $rules = app(NationRaidRules::class);
        $resolver = app(NationRaidDamageResolver::class);
        $snapshot = new NationRaidPlayerSnapshot(maxHp: 1000, defense: 0, spirit: 0,
            enemyHitChancePercent: 100, enemyCriticalChancePercent: 0,
            finalDamageReductionRate: .35, finalDamageReductionRateWithoutRelics: .2);
        $action = $rules->basicAction('split_wing_combo');
        foreach ([null, .5] as $override) {
            $random = new NationRaidSeededRandom(721);
            $damage = $resolver->resolveEnemyAction($action, 1, NationRaidRules::FORM_SPLIT_WING, 11,
                $snapshot, $random, telegraphReductionOverride: $override);
            $expectedRate = $override ?? .2;
            $this->assertSame((int) floor($damage->afterCap * (1 - $expectedRate)), $damage->finalDamageWithoutRelicMitigation);
            $oldSnapshot = new NationRaidPlayerSnapshot(maxHp: 1000, defense: 0, spirit: 0,
                enemyHitChancePercent: 100, enemyCriticalChancePercent: 0, finalDamageReductionRate: .35);
            $oldRandom = new NationRaidSeededRandom(721);
            $old = $resolver->resolveEnemyAction($action, 1, NationRaidRules::FORM_SPLIT_WING, 11,
                $oldSnapshot, $oldRandom, telegraphReductionOverride: $override);
            $actual = $damage->toArray();
            unset($actual['finalDamageWithoutRelicMitigation']);
            $this->assertSame($old->toArray(), $actual);
            $this->assertSame($oldRandom->nextInt(1, 10000), $random->nextInt(1, 10000));
        }
    }

    public function test_raid_reports_combined_mitigation_per_hit_and_raw_response_only_as_trace(): void
    {
        [$player, $boss, $state] = $this->actors();
        $player->namelessRelicEffects = ['first_guard' => .2, 'counter' => 1, 'mirror_guard' => .12];
        $damage = new NationRaidEnemyDamageResult(200, 1000, 200, .35, 130, [
            ['index' => 1, 'type' => 'physical', 'outcome' => 'hit', 'damage' => 100],
            ['index' => 2, 'type' => 'physical', 'outcome' => 'hit', 'damage' => 100],
        ], [], finalDamageWithoutRelicMitigation: 160);
        $result = $this->applier($player, $boss, $state)->apply($damage, 'double-hit', 1000, 100);
        $this->assertSame(45 * 2, $result->damage->finalDamage);
        $this->assertSame(910, $result->playerHp);
        $this->assertSame([['hit_index' => 1, 'damage' => 35], ['hit_index' => 2, 'damage' => 35]], $result->defenseTrace['relic_mitigation_hits']);
        $this->assertSame([['label' => '返刃', 'damage' => 11], ['label' => '鏡守', 'damage' => 1]], $result->defenseTrace['relic_responses']);
        $this->assertSame(12, $result->counterDamage);
    }

    public function test_raid_response_display_applies_boss_reduction_counter_penalty_and_remaining_hp(): void
    {
        $engine = app(NationRaidBattleEngine::class);
        $method = new ReflectionMethod($engine, 'relicResponseHpLosses');
        $runtime = ['counter_damage_turns' => 1, 'counter_damage_multiplier' => .5,
            'next_direct_multiplier' => .25, 'next_multihit_multiplier' => .25];
        $responses = [['label' => '返刃', 'damage' => 40], ['label' => '鏡守', 'damage' => 10]];
        // Non-relic counter=20, plus the existing boss guard reduction 10%.
        $display = $method->invoke($engine, $responses, 70, 1000, 1, NationRaidRules::FORM_SEALED_SCALE, $runtime, .1);
        $this->assertSame([['label' => '返刃', 'damage' => 18], ['label' => '鏡守', 'damage' => 4]], $display);
        $lethal = $method->invoke($engine, $responses, 70, 12, 1, NationRaidRules::FORM_SEALED_SCALE, $runtime, .1);
        $this->assertSame([['label' => '返刃', 'damage' => 3], ['label' => '鏡守', 'damage' => 0]], $lethal);
        $this->assertSame(.25, $runtime['next_direct_multiplier']);
        $this->assertSame(.25, $runtime['next_multihit_multiplier']);
    }

    public function test_raid_presenter_uses_final_metadata_and_ignores_legacy_response_text(): void
    {
        $presenter = app(NationRaidTrialBattleLogPresenter::class);
        $messages = (new ReflectionMethod($presenter, 'defenseMessages'))->invoke($presenter, [
            'relic_mitigation_hits' => [['hit_index' => 1, 'damage' => 1234], ['hit_index' => 2, 'damage' => 20]],
            'relic_response_hp_losses' => [['label' => '返刃', 'damage' => 3], ['label' => '鏡守', 'damage' => 0]],
        ]);
        $this->assertSame(['【遺物軽減】被ダメージを1,234軽減した。（1撃目）',
            '【遺物軽減】被ダメージを20軽減した。（2撃目）',
            '【返刃】相手に3ダメージを与えた。', '【鏡守】相手に0ダメージを与えた。'], $messages);
        $logs = (new ReflectionMethod($presenter, 'playerActionLogs'))->invoke($presenter,
            ['【返刃】冒険者 の遺物が反撃した。', '【鏡守】冒険者 の遺物が反撃した。'], '冒険者', '敵', true);
        $this->assertSame([], $logs);
        $oldLogs = ['【返刃】冒険者 の遺物が反撃した。', '【鏡守】軽減した攻撃の一部が返った。'];
        $this->assertSame($oldLogs, (new ReflectionMethod($presenter, 'playerActionLogs'))->invoke($presenter, $oldLogs, '冒険者', '敵'));
    }

    private function actors(): array
    {
        $stats = ['hp' => 1000, 'max_hp' => 1000, 'mp' => 100, 'max_mp' => 100, 'str' => 100, 'def' => 100, 'agi' => 100, 'mag' => 100, 'spr' => 100];
        $player = new BattleActor('冒険者', true, $stats);
        $boss = new BattleActor('敵', false, $stats);
        $boss->speciesKeys = ['dragon'];
        $player->namelessRelicsEnabled = $boss->namelessRelicsEnabled = true;

        return [$player, $boss, new BattleState($player, $boss, 'raid')];
    }

    private function applier(BattleActor $player, BattleActor $boss, BattleState $state): NationRaidExistingPlayerDefenseApplier
    {
        return new NationRaidExistingPlayerDefenseApplier($player, $boss, $state,
            app(DamageApplicationService::class), app(JobArtV2ResourceService::class), app(JobArtV2UltimateCounterplayService::class));
    }
}
