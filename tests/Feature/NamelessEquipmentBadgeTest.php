<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\LeftSidebar;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerNamelessEquipment;
use App\Models\User;
use App\Services\BattleEquipmentSummaryService;
use App\Services\NamelessTownService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NamelessEquipmentBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
    }

    public function test_owned_list_includes_both_kinds_with_prefix_and_does_not_leak_other_players(): void
    {
        $character = $this->character();
        $weapon = $this->body($character, customName: '青空の剣');
        $armor = $this->body($character, 'armor');
        $this->body($this->character(), customName: '他人の遺装');
        $before = $weapon->fresh()->getAttributes();
        $this->login($character);

        $this->get(route('equipment.index'))->assertOk()
            ->assertSee('［遺装］ 青空の剣 +4')->assertSee('［遺装］ 名もなき鎧 +4')
            ->assertDontSee('他人の遺装')->assertSee('color:#1d4ed8', false);
        $this->get(route('nameless-workshop.index', ['equipment' => $weapon->id]))->assertOk()
            ->assertSee('data-equipment-rank="nameless"', false)
            ->assertSee('background-color:#171b20;color:#e8c66a;border:1px solid #b89548', false)
            ->assertSee('<span class="renamed-equipment">青空の剣 +4</span>', false)
            ->assertSee('［遺装］ 名もなき鎧 +4');
        $this->assertSame($before, $weapon->fresh()->getAttributes());
        $this->assertSame('名もなき鎧', $armor->displayName());
    }

    public function test_sidebar_shows_equipped_nameless_body_and_preserves_ordinary_grade(): void
    {
        $character = $this->character();
        $weapon = $this->body($character, customName: '星巡りの剣', equipped: true);
        $this->body($character, customName: '控えの剣');
        $item = Item::query()->create(['name' => '通常の鎧', 'type' => 'armor', 'armor_rank' => 'SSS', 'is_active' => true]);
        CharacterItem::query()->create(['character_id' => $character->id, 'item_id' => $item->id, 'is_equipped' => true, 'equipped_slot' => 'armor']);
        $this->login($character);

        Livewire::test(LeftSidebar::class)->assertSee('星巡りの剣 +4')->assertSee('遺装')
            ->assertSee('通常の鎧')->assertSee('SSS')->assertDontSee('控えの剣')
            ->assertSee('color:#1d4ed8', false);
        $summary = app(BattleEquipmentSummaryService::class)->forEnemy($character, 'dragon');
        $this->assertSame(['遺装', 'SSS'], array_column($summary, 'rank'));
        $this->assertSame('星巡りの剣 +4', $summary[0]['name']);
        $this->assertTrue($summary[0]['is_renamed']);
        $this->assertSame($weapon->imagePath(), $summary[0]['icon']);
    }

    public function test_equipped_armor_uses_same_classification_and_unequipped_bodies_are_hidden(): void
    {
        $character = $this->character();
        $this->body($character, equipped: true);
        $armor = $this->body($character, 'armor', '夜明けの鎧', true);
        $this->body($character, 'armor', '控えの鎧');
        $this->login($character);
        Livewire::test(LeftSidebar::class)->assertSee('名もなき剣 +4')->assertSee('夜明けの鎧 +4')->assertDontSee('控えの鎧');
        $summary = app(BattleEquipmentSummaryService::class)->forEnemy($character, 'beast');
        $this->assertSame(['武器', '防具'], array_column($summary, 'slot'));
        $this->assertSame(['遺装', '遺装'], array_column($summary, 'rank'));
        $this->assertSame($armor->imagePath(), $summary[1]['icon']);
    }

    public function test_disabled_feature_does_not_expose_equipped_nameless_bodies_in_all_environments(): void
    {
        $character = $this->character();
        $this->body($character, customName: '非公開の剣', equipped: true);
        $this->login($character);
        foreach (['testing', 'production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            config(['nameless_relics.enabled' => false]);
            $this->assertTrue(app(NamelessWorkshopService::class)->ownedEquipmentForDisplay($character)->isEmpty());
            $this->assertSame([], app(BattleEquipmentSummaryService::class)->forEnemy($character, 'dragon'));
            Livewire::test(LeftSidebar::class)->assertDontSee('非公開の剣')->assertDontSee('data-equipment-rank="nameless"', false);
            $this->get(route('equipment.index'))->assertOk()->assertDontSee('［遺装］');
        }
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        return Character::query()->create([
            'user_id' => User::factory()->create()->id, 'name' => '表示検証', 'current_city_id' => $town->id,
            'hp_base' => 1000, 'mp_base' => 100, 'current_hp' => 1000, 'current_mp' => 100,
            'attack_base' => 100, 'magic_base' => 100, 'defense_base' => 100, 'spirit_base' => 100,
        ]);
    }

    private function body(Character $character, string $kind = 'weapon', ?string $customName = null, bool $equipped = false): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::query()->create([
            'character_id' => $character->id, 'kind' => $kind, 'acquisition_source' => 'starter',
            'equipment_type' => $kind === 'weapon' ? '剣' : '鎧', 'custom_name' => $customName,
            'forge_level' => 4, 'growth_exp' => 42, 'base_power' => 5, 'power_per_level' => 5,
            'is_equipped' => $equipped,
        ]);
    }
}
