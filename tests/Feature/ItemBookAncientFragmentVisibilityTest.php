<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Material;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\ItemBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemBookAncientFragmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_ANCIENT_FRAGMENT_CODES = [
        'MAT_BR_WPN_HOLY_ANCIENT',
        'MAT_BR_WPN_DARK_ANCIENT',
        'MAT_BR_WPN_GALE_ANCIENT',
        'MAT_BR_ARM_HEAVY_ANCIENT',
        'MAT_BR_ARM_ARCANE_ANCIENT',
        'MAT_BR_ARM_LIGHT_ANCIENT',
        'MAT_BR_ARM_TRAVELER_ANCIENT',
    ];

    private const DEPRECATED_ANCIENT_FRAGMENT_CODES = [
        'MAT_BR_ACC_POWER_ANCIENT',
        'MAT_BR_ACC_GUARD_ANCIENT',
        'MAT_BR_ACC_MAGIC_ANCIENT',
        'MAT_BR_ACC_PRAYER_ANCIENT',
        'MAT_BR_ACC_WIND_ANCIENT',
        'MAT_BR_ACC_LUCK_ANCIENT',
        'MAT_BR_ACC_BALANCE_ANCIENT',
    ];

    public function test_item_book_shows_all_active_ancient_fragments_and_hides_deprecated_ones(): void
    {
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id,
            'name' => '古代片図鑑テスト',
        ]);
        $valmonMaster = ValmonMaster::create([
            'valmon_key' => 'item-book-ancient-fragment-test',
            'name' => '図鑑確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        $this->assertSame(
            count(self::ACTIVE_ANCIENT_FRAGMENT_CODES),
            Material::query()->whereIn('material_code', self::ACTIVE_ANCIENT_FRAGMENT_CODES)->count()
        );
        $this->assertSame(
            count(self::DEPRECATED_ANCIENT_FRAGMENT_CODES),
            Material::query()->whereIn('material_code', self::DEPRECATED_ANCIENT_FRAGMENT_CODES)->count()
        );

        $visibleCodes = collect(app(ItemBookService::class)->materialBookFor($character)['materials'])
            ->pluck('code');

        $this->assertEqualsCanonicalizing(
            self::ACTIVE_ANCIENT_FRAGMENT_CODES,
            $visibleCodes->intersect(self::ACTIVE_ANCIENT_FRAGMENT_CODES)->values()->all()
        );
        $this->assertSame(
            [],
            $visibleCodes->intersect(self::DEPRECATED_ANCIENT_FRAGMENT_CODES)->values()->all()
        );

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('item-book.index'))
            ->assertOk()
            ->assertSee(['聖剣の古代片', '魔剣の古代片', '迅刃の古代片'])
            ->assertSee(['重装の古代片', '魔装の古代片', '軽装の古代片', '旅装の古代片'])
            ->assertDontSee(['腕力の古代片', '守護の古代片', '魔力の古代片', '祈祷の古代片', '疾風の古代片', '幸運の古代片', '均衡の古代片']);
    }
}
