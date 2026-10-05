<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\TowerFloorMaster;
use App\Models\TowerRun;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\CharacterStatusService;
use App\Services\NamelessRelicBattleService;
use App\Services\Nation\Raid\NationRaidBattleInput;
use App\Services\Nation\Raid\NationRaidPlayerPreparationService;
use App\Services\Nation\Raid\NationRaidPlayerSnapshot;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\Simulation\NationRaidTurnByTurnActionProfileBridge;
use App\Services\TowerBattleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class NamelessBattleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true]);
        CharacterStatusService::clearRequestCache();
    }

    public function test_tower_attaches_specials_and_retains_tower_resources(): void
    {
        $character = $this->character(['special_opener', 'special_victory_hp', 'stat_str']);
        $run = new TowerRun(['tower_current_hp' => 500, 'tower_max_hp' => 10000,
            'tower_current_mp' => 30, 'tower_max_mp' => 1000]);
        $actor = (new ReflectionMethod(TowerBattleService::class, 'makePlayerActor'))
            ->invoke(app(TowerBattleService::class), $character, $run);
        $this->assertSame(500, $actor->hp);
        $this->assertSame(30, $actor->mp);
        $this->assertTrue($actor->namelessRelicsEnabled);
        $this->assertSame(.26, $actor->namelessRelicEffects['opener']);
        $this->assertGreaterThan(10000, $actor->str);
        $floor = new TowerFloorMaster(['floor' => 1, 'enemy_profile' => 'physical', 'enemy_name' => '塔検証敵']);
        $result = (new ReflectionMethod(TowerBattleService::class, 'runBattle'))
            ->invoke(app(TowerBattleService::class), $character, $run, $floor);
        $this->assertSame('victory', $result['result']);
        $this->assertStringContainsString('遺物の力でHPが', implode('\n', $result['logs']));
        $this->assertGreaterThan(500, $result['player_hp_after']);
        $this->assertSame(5000, $character->fresh()->current_hp);
    }

    public function test_saved_raid_relics_are_frozen_and_off_suppresses_them(): void
    {
        $character = $this->character(['special_opener', 'special_first_guard', 'stat_str']);
        $prepared = app(NationRaidPlayerPreparationService::class)->capture($character)['actor'];
        $this->assertSame(.26, $prepared['nameless_relics']['effects']['opener']);
        $this->assertGreaterThan(10000, $prepared['stats']['str']);
        $first = $this->resolve($character, $prepared);
        // Changing current equipment must not affect an admitted raid.
        PlayerRelic::query()->where('character_id', $character->id)->update(['rank' => 1]);
        $repeat = $this->resolve($character, $prepared);
        $this->assertSame($first->battleResult->toArray(), $repeat->battleResult->toArray());
        $this->assertSame($first->actions, $repeat->actions);
        $legacy = $prepared;
        unset($legacy['nameless_relics']);
        $without = $this->resolve($character, $legacy);
        $this->assertNotSame($first->actions[0]['damage_sources'], $without->actions[0]['damage_sources']);
        config(['nameless_relics.enabled' => false]);
        $off = $this->resolve($character, $prepared);
        $this->assertSame($without->battleResult->toArray(), $off->battleResult->toArray());
        $this->assertSame($without->actions, $off->actions);
        $this->assertSame(5000, $character->fresh()->current_hp);
    }

    public function test_off_capture_has_no_passive_or_special_effects(): void
    {
        $character = $this->character(['stat_str', 'special_opener', 'special_counter']);
        config(['nameless_relics.enabled' => false]);
        $prepared = app(NationRaidPlayerPreparationService::class)->capture($character);
        $this->assertFalse($prepared['actor']['nameless_relics']['enabled']);
        $this->assertSame([], $prepared['actor']['nameless_relics']['effects']);
        $this->assertArrayNotHasKey('relic_stat_bonuses', $prepared['actor']['stats']);
        $run = new TowerRun(['tower_current_hp' => 500, 'tower_current_mp' => 30]);
        $actor = (new ReflectionMethod(TowerBattleService::class, 'makePlayerActor'))
            ->invoke(app(TowerBattleService::class), $character, $run);
        $this->assertFalse($actor->namelessRelicsEnabled);
        $this->assertSame([], $actor->namelessRelicEffects);
    }

    public function test_finisher_uses_raid_virtual_hp_instead_of_the_damage_sink(): void
    {
        $player = new BattleActor('冒険者', true, ['hp' => 1000, 'max_hp' => 1000]);
        $boss = new BattleActor('レイド', false, ['hp' => 2000000000, 'max_hp' => 100000]);
        $player->namelessRelicEffects = ['finisher' => .20];
        $boss->namelessReferenceHp = 30000;
        $this->assertSame(120, app(NamelessRelicBattleService::class)->directDamage(100, $player, $boss));
        $boss->namelessReferenceHp = 30001;
        $this->assertSame(100, app(NamelessRelicBattleService::class)->directDamage(100, $player, $boss));
    }

    private function character(array $effects): Character
    {
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '遺物戦闘検証',
            'level' => 255, 'current_city_id' => 1, 'highest_city_id' => 1,
            'hp_base' => 10000, 'mp_base' => 1000, 'current_hp' => 5000, 'current_mp' => 300,
            'attack_base' => 10000, 'defense_base' => 1000, 'magic_base' => 1000,
            'spirit_base' => 1000, 'speed_base' => 1000, 'luck_base' => 100]);
        $body = PlayerNamelessEquipment::query()->create(['character_id' => $character->id, 'kind' => 'weapon',
            'equipment_type' => '剣', 'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => true]);
        foreach ($effects as $slot => $effect) {
            PlayerRelic::query()->create(['character_id' => $character->id, 'effect_key' => $effect,
                'rank' => 9, 'nameless_equipment_id' => $body->id, 'slot_number' => $slot + 1]);
        }
        return $character;
    }

    private function resolve(Character $character, array $prepared): \App\Services\Nation\Raid\Simulation\NationRaidTurnByTurnBridgeResult
    {
        return app(NationRaidTurnByTurnActionProfileBridge::class)->resolveProfile($character,
            new NationRaidBattleInput(stage: 1, cycleCurrentHp: 1000000, cycleMaxHp: 1000000,
                sourceCycleId: 'relic-test', dominantLineage: null, seed: 721,
                strategy: NationRaidRules::STRATEGY_BOSS_SET,
                player: new NationRaidPlayerSnapshot(maxHp: $prepared['stats']['max_hp'],
                    defense: $prepared['stats']['def'], spirit: $prepared['stats']['spr'],
                    maxSp: $prepared['stats']['max_mp'], counterplayEnabled: false)), $prepared);
    }
}
