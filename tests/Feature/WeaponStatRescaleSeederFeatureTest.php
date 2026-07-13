<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\CharacterStatusService;
use Database\Seeders\WeaponStatRescaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeaponStatRescaleSeederFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_rescales_from_base_and_does_not_compound_on_rerun(): void
    {
        $item = Item::query()->create([
            'name' => '再スケール試験剣',
            'type' => 'weapon',
            'weapon_rank' => 'EPIC',
            'str_bonus' => 200,
            'mag_bonus' => 100,
            'is_active' => true,
        ]);

        $this->seed(WeaponStatRescaleSeeder::class);
        $item->refresh();

        $this->assertSame(200, $item->str_bonus_base);
        $this->assertSame(100, $item->mag_bonus_base);
        $this->assertSame(500, $item->str_bonus); // 200 * 2.5
        $this->assertSame(250, $item->mag_bonus); // 100 * 2.5

        // 複数回実行しても基準値・最終値ともに変化しない（冪等）
        $this->seed(WeaponStatRescaleSeeder::class);
        $this->seed(WeaponStatRescaleSeeder::class);
        $item->refresh();

        $this->assertSame(200, $item->str_bonus_base);
        $this->assertSame(100, $item->mag_bonus_base);
        $this->assertSame(500, $item->str_bonus);
        $this->assertSame(250, $item->mag_bonus);
    }

    public function test_seeder_applies_rank_specific_multipliers(): void
    {
        $g = Item::query()->create(['name' => 'G武器', 'type' => 'weapon', 'weapon_rank' => 'G', 'str_bonus' => 10, 'is_active' => true]);
        $a = Item::query()->create(['name' => 'A武器', 'type' => 'weapon', 'weapon_rank' => 'A', 'str_bonus' => 100, 'is_active' => true]);
        $s = Item::query()->create(['name' => 'S武器', 'type' => 'weapon', 'weapon_rank' => 'S', 'str_bonus' => 100, 'is_active' => true]);
        $b = Item::query()->create(['name' => 'B武器', 'type' => 'weapon', 'weapon_rank' => 'B', 'str_bonus' => 100, 'is_active' => true]);

        $this->seed(WeaponStatRescaleSeeder::class);

        $this->assertSame(10, $g->refresh()->str_bonus);
        $this->assertSame(180, $a->refresh()->str_bonus);
        $this->assertSame(250, $s->refresh()->str_bonus);
        $this->assertSame(100, $b->refresh()->str_bonus);
    }

    public function test_seeder_does_not_touch_non_weapon_items(): void
    {
        $armor = Item::query()->create(['name' => '防具', 'type' => 'armor', 'armor_rank' => 'EPIC', 'def_bonus' => 100, 'is_active' => true]);

        $this->seed(WeaponStatRescaleSeeder::class);

        $armor->refresh();
        $this->assertNull($armor->str_bonus_base);
        $this->assertSame(0, (int) $armor->str_bonus);
        $this->assertSame(100, (int) $armor->def_bonus);
    }

    public function test_existing_owners_share_the_same_rescaled_item_with_no_new_vs_old_gap(): void
    {
        $item = Item::query()->create([
            'name' => '共有武器', 'type' => 'weapon', 'weapon_rank' => 'EPIC',
            'str_bonus' => 200, 'is_active' => true,
        ]);

        $characterA = $this->character('A冒険者', 500);
        $characterB = $this->character('B冒険者', 500);

        CharacterItem::query()->create(['character_id' => $characterA->id, 'item_id' => $item->id, 'is_equipped' => true, 'equipped_slot' => 'weapon']);
        CharacterItem::query()->create(['character_id' => $characterB->id, 'item_id' => $item->id, 'is_equipped' => true, 'equipped_slot' => 'weapon']);

        $this->seed(WeaponStatRescaleSeeder::class);

        CharacterStatusService::clearRequestCache($characterA->id);
        CharacterStatusService::clearRequestCache($characterB->id);
        $statusService = app(CharacterStatusService::class);

        $statsA = $statusService->getFinalStats($characterA);
        $statsB = $statusService->getFinalStats($characterB);

        $this->assertSame($statsA['str'], $statsB['str']);
        // 500(基礎) + 500(再スケール後固定値) + floor(500 * 0.16)(比例補正) = 1080
        $this->assertSame(1080, $statsA['str']);
    }

    private function character(string $name, int $attackBase): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'explore_stamina' => 0,
            'hp_base' => 100, 'mp_base' => 0,
            'attack_base' => $attackBase, 'defense_base' => 0,
            'speed_base' => 0, 'magic_base' => 0,
            'spirit_base' => 0, 'luck_base' => 0,
        ]);
    }
}
