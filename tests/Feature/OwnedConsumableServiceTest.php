<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\OwnedConsumableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnedConsumableServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_changed_after_lookup_is_not_returned_as_owned_stock(): void
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '在庫ロック試験']);
        $other = Character::create(['user_id' => User::factory()->create()->id, 'name' => '別の在庫ロック試験']);
        $herb = Item::query()->where('name', '薬草')->where('type', 'consumable')->firstOrFail();
        $owned = CharacterItem::create(['character_id' => $character->id, 'item_id' => $herb->id]);
        $changed = false;
        DB::listen(function ($query) use (&$changed, $owned, $other) {
            if (! $changed && preg_match('/^select ["`]?id["`]? from ["`]?character_items/i', $query->sql)) {
                $changed = true;
                DB::table('character_items')->where('id', $owned->id)->update(['character_id' => $other->id]);
            }
        });

        $result = DB::transaction(function () use ($character, $herb) {
            Character::whereKey($character->id)->lockForUpdate()->firstOrFail();

            return app(OwnedConsumableService::class)->lockFirst((int) $character->id, (int) $herb->id);
        });

        $this->assertTrue($changed);
        $this->assertNull($result);
        $this->assertDatabaseHas('character_items', ['id' => $owned->id, 'character_id' => $other->id]);
    }

    public function test_inventory_choice_preserves_the_callers_order_and_excludes_equipped_items(): void
    {
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '消費順序試験']);
        $herb = Item::query()->where('name', '薬草')->where('type', 'consumable')->firstOrFail();
        $first = CharacterItem::create(['character_id' => $character->id, 'item_id' => $herb->id]);
        $older = CharacterItem::create(['character_id' => $character->id, 'item_id' => $herb->id]);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $equipped = CharacterItem::create(['character_id' => $character->id, 'item_id' => $herb->id, 'is_equipped' => true]);
        $equipped->forceFill(['created_at' => now()->subDays(2)])->save();

        DB::transaction(function () use ($character, $herb, $first, $older) {
            Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $service = app(OwnedConsumableService::class);
            $this->assertSame($first->id, $service->lockFirst((int) $character->id, (int) $herb->id)?->id);
            $this->assertSame($older->id, $service->lockFirst((int) $character->id, (int) $herb->id, 'created_at')?->id);
        });
    }
}
