<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\EquipmentAffixPrefix;
use App\Models\Item;
use App\Models\User;
use App\Services\EquipmentMarketAppraisalService;
use App\Services\EquipmentMarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentMarketSnapshotRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_active_snapshots_reflects_updated_item_master_values(): void
    {
        $character = $this->createCharacter(100_000);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();

        $item = Item::query()->create([
            'name' => '市場試験剣', 'type' => 'weapon', 'weapon_category' => 'sword',
            'weapon_rank' => 'S', 'str_bonus' => 100, 'is_active' => true, 'is_tradeable' => true,
        ]);
        $characterItem = CharacterItem::query()->create([
            'character_id' => $character->id, 'item_id' => $item->id,
            'affix_prefix_id' => $prefix->id, 'affix_prefix_level' => 2,
            'is_equipped' => false, 'is_locked' => false, 'is_tradeable' => true,
        ]);

        $service = app(EquipmentMarketService::class);
        $appraisal = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem);
        $listing = $service->listWeapon($character, $characterItem, $appraisal['appraisal_price']);

        $this->assertStringContainsString('攻撃 +100', $listing->item_snapshot['base_performance_lines'][0] ?? '');

        // 武器の再スケール（例: S=×2.5）が items テーブルへ反映されたと仮定する
        $item->update(['str_bonus' => 250]);

        $updated = $service->refreshActiveSnapshots();
        $this->assertSame(1, $updated);

        $listing->refresh();
        $lines = implode(' / ', $listing->item_snapshot['base_performance_lines'] ?? []);
        $this->assertStringContainsString('攻撃 +250', $lines);
        $this->assertStringNotContainsString('攻撃 +100', $lines);
        // stats.str は固定値(250) + 銘IIによる加算(ceil(250*0.12)=30) の合計
        $this->assertSame(280, $listing->item_snapshot['stats']['str'] ?? null);
    }

    public function test_refresh_active_snapshots_skips_non_active_listings(): void
    {
        $character = $this->createCharacter(100_000);
        $prefix = EquipmentAffixPrefix::query()->where('affix_key', 'power')->firstOrFail();

        $item = Item::query()->create([
            'name' => '市場試験剣2', 'type' => 'weapon', 'weapon_category' => 'sword',
            'weapon_rank' => 'S', 'str_bonus' => 100, 'is_active' => true, 'is_tradeable' => true,
        ]);
        $characterItem = CharacterItem::query()->create([
            'character_id' => $character->id, 'item_id' => $item->id,
            'affix_prefix_id' => $prefix->id, 'affix_prefix_level' => 2,
            'is_equipped' => false, 'is_locked' => false, 'is_tradeable' => true,
        ]);

        $service = app(EquipmentMarketService::class);
        $appraisal = app(EquipmentMarketAppraisalService::class)->appraisal($characterItem);
        $listing = $service->listWeapon($character, $characterItem, $appraisal['appraisal_price']);
        $service->cancelListing($character, $listing);

        $item->update(['str_bonus' => 250]);
        $updated = $service->refreshActiveSnapshots();

        $this->assertSame(0, $updated);
        $listing->refresh();
        $this->assertSame('cancelled', $listing->status);
    }

    private function createCharacter(int $money): Character
    {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => '市場テスト冒険者',
            'money' => $money,
            'explore_stamina' => 0,
        ]);
    }
}
