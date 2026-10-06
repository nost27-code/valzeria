<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\EquipmentComparisonService;
use App\Services\NamelessPreparationService;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquipmentRelicPreviewBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_rates_and_previews_match_live_checks_for_both_slots(): void
    {
        [$character, $items] = $this->fixture(4);
        $items[0]->update(['is_equipped' => true, 'equipped_slot' => 'weapon']);
        $items[1]->update(['is_equipped' => true, 'equipped_slot' => 'armor']);
        $this->relic($character, $items[0], 'stat_str', 3);
        $this->relic($character, $items[1], 'stat_def', 4);
        $this->relic($character, $items[2], 'stat_str', 9);
        $this->relic($character, $items[3], 'stat_spr', 6);
        $body = PlayerNamelessEquipment::create(['character_id' => $character->id, 'kind' => 'accessory', 'equipment_type' => '指輪', 'forge_level' => 4, 'is_equipped' => true]);
        PlayerRelic::create(['character_id' => $character->id, 'nameless_equipment_id' => $body->id, 'slot_number' => 1, 'effect_key' => 'stat_mag', 'rank' => 5]);
        $items = $character->characterItems()->with('item')->get();
        $before = $this->assets($character);
        $rates = app(EquipmentComparisonService::class)->relicRatesForDisplay($character, $items);
        $status = app(CharacterStatusService::class);
        foreach ($items as $item) {
            $slot = $item->item->type;
            $this->assertSame(app(NamelessWorkshopService::class)->equippedStatRates($character, $slot, $item), $rates[$item->id]);
            $method = $slot.'EffectivePreview';
            $this->assertSame($status->{$method}($character, $item), $status->{$method}($character, $item, $rates[$item->id]));
        }
        $this->assertSame($before, $this->assets($character));
    }

    public function test_list_growth_does_not_repeat_relic_queries_and_prepared_rows_make_no_queries(): void
    {
        [$character] = $this->fixture(60);
        $items = $character->characterItems()->with('item')->get();
        $service = app(EquipmentComparisonService::class);
        DB::enableQueryLog();
        try {
            $service->relicRatesForDisplay($character, $items->take(2));
            $small = count(DB::getQueryLog());
            DB::flushQueryLog();
            $rates = $service->relicRatesForDisplay($character, $items);
            $this->assertLessThanOrEqual($small + 2, count(DB::getQueryLog()));
            $status = app(CharacterStatusService::class);
            $status->getFinalStats($character);
            DB::flushQueryLog();
            foreach ($items as $item) {
                $method = $item->item->type.'EffectivePreview';
                $status->{$method}($character, $item, $rates[$item->id]);
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_new_display_rechecks_changed_relics_and_schema_without_retained_snapshot(): void
    {
        [$character, $items] = $this->fixture(2);
        $relic = $this->relic($character, $items[0], 'stat_str', 1);
        $service = app(EquipmentComparisonService::class);
        $read = fn () => $service->relicRatesForDisplay($character, $character->characterItems()->with('item')->get());
        $first = $read();
        $relic->update(['rank' => 9]);
        $this->assertNotSame($first[$items[0]->id], $read()[$items[0]->id]);
        DB::beginTransaction();
        try {
            DB::table('migrations')->where('migration', NamelessPreparationService::MIGRATIONS[0])->delete();
            $this->assertSame([], $read()[$items[0]->id]);
            $this->assertFalse(app(NamelessWorkshopService::class)->ready());
        } finally {
            DB::rollBack();
        }
        $this->assertNotSame([], $read()[$items[0]->id]);
    }

    public function test_off_display_is_empty_without_schema_queries_and_matches_live_preview(): void
    {
        [$character, $items] = $this->fixture(2);
        $this->relic($character, $items[0], 'stat_str', 9);
        config(['nameless_relics.enabled' => false]);
        CharacterStatusService::clearRequestCache();
        DB::enableQueryLog();
        try {
            $rates = app(EquipmentComparisonService::class)->relicRatesForDisplay($character, $items);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        foreach ($items as $item) {
            $this->assertSame([], $rates[$item->id]);
            $status = app(CharacterStatusService::class);
            $method = $item->item->type.'EffectivePreview';
            $this->assertSame($status->{$method}($character, $item), $status->{$method}($character, $item, $rates[$item->id]));
        }
    }

    private function fixture(int $count): array
    {
        config(['nameless_relics.enabled' => true]);
        CharacterStatusService::clearRequestCache();
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '一括比較検証', 'attack_base' => 1000, 'magic_base' => 800, 'defense_base' => 900, 'spirit_base' => 700]);
        foreach (['weapon', 'armor'] as $slot) {
            $masters[$slot] = Item::create(['name' => '比較'.$slot, 'type' => $slot, 'rarity' => 'EPIC', $slot.'_rank' => 'EPIC', 'str_bonus' => 100, 'def_bonus' => 100]);
        }
        for ($i = 0; $i < $count; $i++) {
            CharacterItem::create(['character_id' => $character->id, 'item_id' => $masters[$i % 2 ? 'armor' : 'weapon']->id, 'is_equipped' => false, 'is_stored' => false]);
        }

        return [$character, $character->characterItems()->with('item')->get()];
    }

    private function relic(Character $character, CharacterItem $item, string $effect, int $rank): PlayerRelic
    {
        return PlayerRelic::create(['character_id' => $character->id, 'character_item_id' => $item->id, 'slot_number' => 1, 'effect_key' => $effect, 'rank' => $rank]);
    }

    private function assets(Character $character): string
    {
        return hash('sha256', json_encode([$character->fresh()->getAttributes(), $character->characterItems()->get()->toArray(), PlayerRelic::where('character_id', $character->id)->get()->toArray(), PlayerNamelessEquipment::where('character_id', $character->id)->get()->toArray()]));
    }
}
