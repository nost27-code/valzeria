<?php

namespace Tests\Feature;

use App\Livewire\Admin\ActionLogManager;
use App\Models\Character;
use App\Models\User;
use App\Models\WeaponTraitOperationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWeaponTraitActionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_weapon_trait_operation_is_visible_with_base_material_and_completed_weapon(): void
    {
        $user = User::factory()->create(['email' => 'trait-log@example.test']);
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '鍛冶ログ冒険者',
            'explore_stamina' => 0,
        ]);

        WeaponTraitOperationLog::query()->create([
            'character_id' => $character->id,
            'operation' => 'engraving_transfer',
            'base_character_item_id' => 101,
            'material_character_item_id' => 202,
            'before_snapshot' => ['display_name' => '[G] 力の銘Iの木の剣'],
            'material_snapshot' => ['display_name' => '[D] 魔力の銘IIの鉄の剣'],
            'after_snapshot' => ['display_name' => '[G] 魔力の銘IIの木の剣'],
            'gold_cost' => 10_000,
        ]);

        Livewire::test(ActionLogManager::class)
            ->set('eventType', 'weapon_trait')
            ->assertSee('銘・特攻鍛錬')
            ->assertSee('銘移し')
            ->assertSee('[G] 力の銘Iの木の剣')
            ->assertSee('[D] 魔力の銘IIの鉄の剣')
            ->assertSee('[G] 魔力の銘IIの木の剣')
            ->assertSee('10,000G');
    }
}
