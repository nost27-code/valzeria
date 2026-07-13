<?php

namespace Tests\Feature;

use App\Livewire\Admin\PlayerControlManager;
use App\Models\AdminItemGrantLog;
use App\Models\Character;
use App\Models\CharacterItem;
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
}
