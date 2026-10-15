<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\NationRaidBattleResult;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\Nation\Raid\NationRaidPlayerPreparationService;
use App\Services\Nation\Raid\NationRaidRelicRules;
use App\Services\Nation\Raid\NationRaidRules;
use App\Services\Nation\Raid\NationRaidSortieCombatService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NationRaidRelicContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_sortie_keeps_saved_relics_and_rules_when_live_equipment_and_activation_change(): void
    {
        config(['nameless_relics.enabled' => true]);
        $owner = $this->owner();
        $body = PlayerNamelessEquipment::query()->create([
            'character_id' => $owner->id, 'kind' => 'weapon', 'equipment_type' => '剣',
            'base_power' => 5, 'power_per_level' => 5, 'is_equipped' => true,
        ]);
        foreach (['special_opener', 'special_first_guard', 'stat_str'] as $slot => $effect) {
            PlayerRelic::query()->create([
                'character_id' => $owner->id, 'effect_key' => $effect, 'rank' => 9,
                'nameless_equipment_id' => $body->id, 'slot_number' => $slot + 1,
            ]);
        }
        $player = app(NationRaidPlayerPreparationService::class)->capture($owner);
        $this->assertSame(.26, $player['actor']['nameless_relics']['effects']['opener']);
        $contract = app(NationRaidRelicRules::class)->current();
        $hash = app(NationRaidRules::class)->rulesetHash();
        // 受付済みデータを再現し、戦闘解決自体の保存副作用も検査する。
        $sortie = new NationRaidBattleResult([
            'character_id' => $owner->id, 'strategy' => NationRaidRules::STRATEGY_BOSS_SET,
            'dominant_lineage' => null,
            'summary' => ['admission' => [
                'player' => $player, 'relic_rules' => $contract, 'ruleset_hash' => $hash,
                'cycle_id' => 'saved-relic-contract-test', 'engine_seed' => 721,
                'encounter' => ['stage' => 1, 'current_hp' => 1000000, 'max_hp' => 1000000],
            ]],
        ]);
        $before = $owner->fresh()->getAttributes();
        $count = NationRaidBattleResult::query()->count();
        $service = app(NationRaidSortieCombatService::class);
        $first = $service->resolve($sortie);

        PlayerRelic::query()->where('character_id', $owner->id)->update(['rank' => 1]);
        config(['nameless_relics.enabled' => false]);
        $liveConfig = config('nameless_relics');
        $liveEffects = config('nameless_relic_effects');
        $second = $service->resolve($sortie);

        $this->assertSame($first, $second);
        $this->assertSame($hash, $second['engine_result']['rulesetHash']);
        $this->assertSame($liveConfig, config('nameless_relics'));
        $this->assertSame($liveEffects, config('nameless_relic_effects'));
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertSame($count, NationRaidBattleResult::query()->count());
        $this->assertSame([1], PlayerRelic::query()->where('character_id', $owner->id)
            ->distinct()->pluck('rank')->all());
    }

    public function test_new_admission_rejects_a_contract_after_global_relic_activation_changes(): void
    {
        config(['nameless_relics.enabled' => true]);
        $rules = app(NationRaidRelicRules::class);
        $snapshot = ['nameless_relic_combat' => $rules->current()];
        $rules->assertCurrent($snapshot);

        config(['nameless_relics.enabled' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('再シミュレーションと新しい開催承認が必要です');
        $rules->assertCurrent($snapshot);
    }

    #[DataProvider('resistanceCases')]
    public function test_preparation_applies_only_current_boss_species_resistance(
        bool $relicsEnabled,
        string $species,
        float $expectedRate,
    ): void
    {
        config(['nameless_relics.enabled' => $relicsEnabled]);
        $this->assertSame('machine', NationRaidRules::BOSS_SPECIES_KEY);
        $owner = $this->owner();
        $armor = Item::query()->create([
            'name' => '契約検証耐性鎧', 'type' => 'armor', 'armor_rank' => 'G',
            'def_bonus' => 0, 'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $owner->id, 'item_id' => $armor->id,
            'is_equipped' => true, 'equipped_slot' => 'armor',
            'resist_species_key' => $species, 'species_damage_reduction_rate' => .25,
        ]);
        CharacterStatusService::clearRequestCache();

        $prepared = app(NationRaidPlayerPreparationService::class)->capture($owner->fresh());

        $this->assertEqualsWithDelta($expectedRate, $prepared['raid_resistance_rate'], .000001);
    }

    public static function resistanceCases(): iterable
    {
        yield 'relics off, matching machine' => [false, 'machine', .25];
        yield 'relics off, mismatching dragon' => [false, 'dragon', 0.0];
        yield 'relics on, matching machine' => [true, 'machine', .25];
        yield 'relics on, mismatching dragon' => [true, 'dragon', 0.0];
    }

    private function owner(): Character
    {
        return Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => 'レイド契約検証', 'level' => 255,
            'current_city_id' => 1, 'highest_city_id' => 1, 'hp_base' => 10000, 'mp_base' => 1000,
            'current_hp' => 5000, 'current_mp' => 300, 'attack_base' => 10000,
            'defense_base' => 1000, 'magic_base' => 1000, 'spirit_base' => 1000,
            'speed_base' => 1000, 'luck_base' => 100,
        ]);
    }
}
