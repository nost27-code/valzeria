<?php

namespace Tests\Feature;

use App\Livewire\AdventurerCardModal;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerNamelessEquipment;
use App\Models\User;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdventurerCardNamelessEquipmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'favorite_weapons.enabled' => false, 'job_master_badges.enabled' => false]);
    }

    public function test_other_player_card_displays_all_nameless_slots_and_never_mutates_assets(): void
    {
        $viewer = $this->character('閲覧者');
        $target = $this->character('表示対象');
        $weapon = $this->body($target, 'weapon', '剣', '星巡りの剣', 20);
        $armor = $this->body($target, 'armor', '鎧', null, 0);
        $accessory = $this->body($target, 'accessory', '首飾り', '黎明の首飾り', 99);
        $this->body($target, 'weapon', '剣', '控えの剣', 4, false);
        $this->body($viewer, 'weapon', '剣', '閲覧者の剣', 4);
        $before = PlayerNamelessEquipment::orderBy('id')->get()->map->getRawOriginal()->all();
        $heroBefore = $target->fresh()->getRawOriginal();
        $this->actingAs($viewer->user)->withSession(['current_character_id' => $viewer->id]);

        $component = Livewire::test(AdventurerCardModal::class)->call('openPlayerModal', $target->id)
            ->assertSet('playerInfo.is_self', false)
            ->assertSet('playerInfo.equipment.weapon.name', '星巡りの剣 +20')
            ->assertSet('playerInfo.equipment.armor.name', '名もなき鎧 +0')
            ->assertSet('playerInfo.equipment.accessory.name', '黎明の首飾り +99');
        foreach (['weapon', 'armor', 'accessory'] as $slot) {
            $component->assertSet('playerInfo.equipment.'.$slot.'.rank', '遺装');
        }
        $this->assertStringNotContainsString('控えの剣', json_encode($component->get('playerInfo')['equipment'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('閲覧者の剣', json_encode($component->get('playerInfo')['equipment'], JSON_UNESCAPED_UNICODE));
        $this->assertSame($before, PlayerNamelessEquipment::orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($heroBefore, $target->fresh()->getRawOriginal());

        $weapon->update(['custom_name' => '新しい剣', 'forge_level' => 21]);
        $component->call('closePlayerModal')->call('openPlayerModal', $target->id)
            ->assertSet('playerInfo.equipment.weapon.name', '新しい剣 +21');
    }

    public function test_self_card_and_mixed_ordinary_equipment_keep_existing_ranks_and_empty_slots(): void
    {
        $hero = $this->character('本人');
        $this->body($hero, 'weapon', '杖', null, 4);
        $item = Item::create(['name' => '通常の鎧', 'type' => 'armor', 'armor_rank' => 'SSS', 'is_active' => true]);
        CharacterItem::create(['character_id' => $hero->id, 'item_id' => $item->id, 'equipped_slot' => 'armor', 'is_equipped' => true]);
        $this->actingAs($hero->user)->withSession(['current_character_id' => $hero->id]);
        Livewire::test(AdventurerCardModal::class)->call('openCurrentCharacterPreview')
            ->assertSet('playerInfo.is_self', true)
            ->assertSet('playerInfo.equipment.weapon.name', '名もなき杖 +4')
            ->assertSet('playerInfo.equipment.armor.name', '通常の鎧')
            ->assertSet('playerInfo.equipment.armor.rank', 'SSS')
            ->assertSet('playerInfo.equipment.accessory.name', 'なし');
    }

    public function test_off_feature_hides_nameless_equipment_without_changing_ownership(): void
    {
        $hero = $this->character('機能OFF');
        $gear = $this->body($hero, 'weapon', '剣', '非公開の剣', 9);
        $before = $gear->fresh()->getRawOriginal();
        config(['nameless_relics.enabled' => false]);
        Livewire::test(AdventurerCardModal::class)->call('openPlayerModal', $hero->id)
            ->assertSet('playerInfo.equipment.weapon.name', 'なし');
        $this->assertSame($before, $gear->fresh()->getRawOriginal());
    }

    public function test_additional_unequipped_bodies_do_not_add_queries_or_change_the_selected_body(): void
    {
        $hero = $this->character('大量所持');
        $this->body($hero, 'weapon', '剣', '装備中の剣', 4);
        // Compare warmed request dependencies on both sides, not cold vs warm caches.
        (new AdventurerCardModal())->openPlayerModal($hero->id);
        CharacterStatusService::clearRequestCache();
        DB::flushQueryLog();
        $hydrated = 0;
        PlayerNamelessEquipment::retrieved(function () use (&$hydrated) { $hydrated++; });
        DB::enableQueryLog();
        $first = new AdventurerCardModal(); $first->openPlayerModal($hero->id);
        $oneQueries = count(DB::getQueryLog());
        $oneHydrated = $hydrated;
        DB::disableQueryLog();
        $rows = [];
        for ($i = 0; $i < 150; $i++) $rows[] = ['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => false];
        PlayerNamelessEquipment::insert($rows);
        CharacterStatusService::clearRequestCache();
        $hydrated = 0;
        DB::flushQueryLog(); DB::enableQueryLog();
        $second = new AdventurerCardModal(); $second->openPlayerModal($hero->id);
        $manyQueries = count(DB::getQueryLog()); DB::disableQueryLog();
        $this->assertSame($oneQueries, $manyQueries);
        $this->assertSame($oneHydrated, $hydrated);
        $this->assertSame($first->playerInfo['equipment'], $second->playerInfo['equipment']);
        $this->assertSame('装備中の剣 +4', $second->playerInfo['equipment']['weapon']['name']);
    }

    public function test_incomplete_schema_hides_nameless_equipment_without_changing_ownership(): void
    {
        $hero = $this->character('構造未準備');
        $gear = $this->body($hero, 'weapon', '剣', '未準備の剣', 9);
        $before = $gear->fresh()->getRawOriginal();
        DB::table('migrations')->where('migration', \App\Services\NamelessPreparationService::MIGRATIONS[0])->delete();
        Livewire::test(AdventurerCardModal::class)->call('openPlayerModal', $hero->id)
            ->assertSet('playerInfo.equipment.weapon.name', 'なし');
        $this->assertSame($before, $gear->fresh()->getRawOriginal());
    }

    private function character(string $name): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => $name]);
    }

    private function body(Character $hero, string $kind, string $type, ?string $name, int $level, bool $equipped = true): PlayerNamelessEquipment
    {
        return PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => $kind, 'equipment_type' => $type,
            'custom_name' => $name, 'forge_level' => $level, 'is_equipped' => $equipped, 'acquisition_source' => 'ruin_drop']);
    }
}
