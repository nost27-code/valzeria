<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlayerControlManager;
use App\Models\AdminItemGrantLog;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterNotification;
use App\Models\EquipmentAffixPrefix;
use App\Models\EquipmentAffixSuffix;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAffixedWeaponGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_grant_a_weapon_with_engraving_and_slayer(): void
    {
        $admin = User::factory()->create();
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'explore_stamina' => 0,
        ]);
        $item = Item::query()->create([
            'name' => '管理配布の剣',
            'type' => 'weapon',
            'rarity' => 'S',
            'weapon_category' => 'sword',
            'weapon_rank' => 'S',
            'str_bonus' => 100,
            'affix_enabled' => true,
            'is_active' => true,
        ]);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();
        $suffix = EquipmentAffixSuffix::query()
            ->where('item_type', 'weapon')
            ->where('effect_type', 'killer_damage')
            ->where('species_key', 'dragon')
            ->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('grantType', 'weapon')
            ->set('grantTargetId', (string) $item->id)
            ->set('grantAffixPrefixId', (string) $prefix->id)
            ->set('grantAffixPrefixLevel', 4)
            ->set('grantAffixSuffixId', (string) $suffix->id)
            ->set('grantAffixSuffixLevel', 4)
            ->set('grantAffixQuality', 'excellent')
            ->call('grantItem')
            ->assertHasNoErrors();

        $granted = CharacterItem::query()
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->where('character_id', $recipient->id)
            ->sole();
        $this->assertSame($prefix->id, $granted->affix_prefix_id);
        $this->assertSame(4, $granted->affix_prefix_level);
        $this->assertSame($suffix->id, $granted->affix_suffix_id);
        $this->assertSame(4, $granted->affix_suffix_level);
        $this->assertSame('excellent', $granted->affix_quality);
        $this->assertGreaterThan(0, $granted->affix_str_bonus);
        $this->assertGreaterThan(0, $granted->killer_damage_rate);

        $grantLog = AdminItemGrantLog::query()->sole();
        $this->assertSame($granted->displayName(), $grantLog->target_name);
        $this->assertSame($prefix->name, data_get($grantLog->metadata, 'affix.engraving.name'));
        $this->assertSame($suffix->name, data_get($grantLog->metadata, 'affix.slayer.name'));
        $this->assertSame([$granted->id], data_get($grantLog->metadata, 'character_item_ids'));

        $notification = CharacterNotification::query()
            ->where('character_id', $recipient->id)
            ->where('type', 'admin_item_grant')
            ->sole();
        $this->assertSame('管理人からアイテムが送られました', $notification->title);
        $this->assertStringContainsString($granted->displayName(), (string) $notification->body);
        $this->assertSame('weapon', data_get($notification->data, 'grant_type'));
    }

    public function test_admin_cannot_grant_an_affix_above_the_weapon_rank_cap(): void
    {
        $admin = User::factory()->create();
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'explore_stamina' => 0,
        ]);
        $item = Item::query()->create([
            'name' => '管理配布の短剣',
            'type' => 'weapon',
            'rarity' => 'A',
            'weapon_category' => 'dagger',
            'weapon_rank' => 'A',
            'str_bonus' => 100,
            'affix_enabled' => true,
            'is_active' => true,
        ]);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('grantType', 'weapon')
            ->set('grantTargetId', (string) $item->id)
            ->set('grantAffixPrefixId', (string) $prefix->id)
            ->set('grantAffixPrefixLevel', 4)
            ->call('grantItem')
            ->assertHasErrors(['grantAffixPrefixLevel']);

        $this->assertDatabaseCount('character_items', 0);
        $this->assertDatabaseCount('admin_item_grant_logs', 0);
    }

    public function test_admin_can_grant_an_epic_weapon_with_level_five_affixes(): void
    {
        $admin = User::factory()->create();
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'explore_stamina' => 0,
        ]);
        $item = Item::query()->create([
            'name' => '管理配布のEPIC剣',
            'type' => 'weapon',
            'rarity' => 'EPIC',
            'weapon_category' => 'sword',
            'weapon_rank' => 'EPIC',
            'str_bonus' => 500,
            'affix_enabled' => true,
            'is_active' => true,
        ]);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();
        $suffix = EquipmentAffixSuffix::query()
            ->where('item_type', 'weapon')
            ->where('effect_type', 'killer_damage')
            ->where('species_key', 'dragon')
            ->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('grantType', 'weapon')
            ->set('grantTargetId', (string) $item->id)
            ->set('grantAffixPrefixId', (string) $prefix->id)
            ->set('grantAffixPrefixLevel', 5)
            ->set('grantAffixSuffixId', (string) $suffix->id)
            ->set('grantAffixSuffixLevel', 5)
            ->set('grantAffixQuality', 'excellent')
            ->call('grantItem')
            ->assertHasNoErrors();

        $granted = CharacterItem::query()->where('character_id', $recipient->id)->sole();
        $this->assertSame(5, $granted->affix_prefix_level);
        $this->assertSame(5, $granted->affix_suffix_level);
        $this->assertSame('excellent', $granted->affix_quality);
    }

    public function test_admin_can_grant_affixes_to_a_ranked_weapon_when_random_drop_affixes_are_disabled(): void
    {
        $admin = User::factory()->create();
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'explore_stamina' => 0,
        ]);
        $item = Item::query()->create([
            'name' => '通常送付の剣',
            'type' => 'weapon',
            'rarity' => 'S',
            'weapon_category' => 'sword',
            'weapon_rank' => 'S',
            'str_bonus' => 50,
            'affix_enabled' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('grantType', 'weapon')
            ->set('grantTargetId', (string) $item->id)
            ->assertSee('銘・特攻可')
            ->assertSee('銘の段階')
            ->assertDontSee('通常のみ')
            ->set('grantAffixPrefixId', (string) EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail()->id)
            ->set('grantAffixPrefixLevel', 4)
            ->call('grantItem')
            ->assertHasNoErrors();

        $granted = CharacterItem::query()->where('character_id', $recipient->id)->sole();
        $this->assertSame(4, $granted->affix_prefix_level);
    }

    public function test_admin_rejects_affixes_for_a_special_weapon_that_is_fixed_to_normal_quality(): void
    {
        $admin = User::factory()->create();
        $recipient = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '配布先冒険者',
            'explore_stamina' => 0,
        ]);
        $item = Item::query()->create([
            'name' => '星樹の特別剣',
            'type' => 'weapon',
            'rarity' => 'special',
            'display_rank' => 'SS+相当',
            'weapon_category' => 'sword',
            'weapon_rank' => 'SPECIAL',
            'str_bonus' => 300,
            'affix_enabled' => false,
            'is_active' => true,
        ]);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(PlayerControlManager::class)
            ->call('selectCharacter', $recipient->id)
            ->set('grantType', 'weapon')
            ->set('grantTargetId', (string) $item->id)
            ->assertSee('銘・特攻対象外')
            ->assertSee('この武器は仕様上、銘・特攻の付与対象外です。')
            ->assertDontSee('銘の段階')
            ->set('grantAffixPrefixId', (string) $prefix->id)
            ->call('grantItem')
            ->assertHasErrors(['grantTargetId']);

        $this->assertDatabaseCount('character_items', 0);
        $this->assertDatabaseCount('admin_item_grant_logs', 0);
    }
}
