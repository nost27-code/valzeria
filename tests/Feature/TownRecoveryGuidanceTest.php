<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterItemDailySupply;
use App\Models\City;
use App\Models\Item;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\ExplorationStateService;
use App\Services\HomeActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TownRecoveryGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(CheckCharacterSelected::class);
    }

    public function test_recovery_item_can_be_used_from_the_supply_depot(): void
    {
        $character = $this->character(['current_hp' => 20]);
        $herb = $this->giveItem($character, '薬草');

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id, 'current_location' => 'town'])
            ->post(route('shop.items.use', $herb));

        $response->assertRedirect(route('shop.items'))
            ->assertSessionHas('status', '薬草を使用し、HPが50/100まで回復しました。');

        $this->assertSame(50, (int) $character->fresh()->current_hp);
        $this->assertDatabaseMissing('character_items', [
            'character_id' => $character->id,
            'item_id' => $herb->id,
        ]);
    }

    public function test_town_use_does_not_consume_an_item_when_the_target_resource_is_full(): void
    {
        $character = $this->character(['current_hp' => 100]);
        $herb = $this->giveItem($character, '薬草');

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id, 'current_location' => 'town'])
            ->post(route('shop.items.use', $herb));

        $response->assertRedirect(route('shop.items'))
            ->assertSessionHas('error', 'HPはすでに全快です。');

        $this->assertDatabaseHas('character_items', [
            'character_id' => $character->id,
            'item_id' => $herb->id,
        ]);
    }

    public function test_rapid_duplicate_town_use_is_rejected_without_consuming_another_item(): void
    {
        $character = $this->character(['current_hp' => 20]);
        $herb = $this->giveItem($character, '薬草');
        $this->giveItem($character, '薬草');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id, 'current_location' => 'town'])
            ->post(route('shop.items.use', $herb))
            ->assertSessionHas('status');

        $this->post(route('shop.items.use', $herb))
            ->assertRedirect(route('shop.items'))
            ->assertSessionHas('error', '回復アイテムを使用中です。少し待ってからもう一度お試しください。');

        $this->assertSame(50, (int) $character->fresh()->current_hp);
        $this->assertSame(1, CharacterItem::query()
            ->where('character_id', $character->id)
            ->where('item_id', $herb->id)
            ->count());
    }

    public function test_magic_water_recovers_sp_without_spending_gold(): void
    {
        $character = $this->character(['current_mp' => 10, 'money' => 123]);
        $magicWater = $this->giveItem($character, '魔力水');

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id, 'current_location' => 'town'])
            ->post(route('shop.items.use', $magicWater));

        $response->assertRedirect(route('shop.items'))
            ->assertSessionHas('status', '魔力水を使用し、SPが40/100まで回復しました。');

        $character->refresh();
        $this->assertSame(40, (int) $character->current_mp);
        $this->assertSame(123, (int) $character->money);
        $this->assertDatabaseMissing('character_items', [
            'character_id' => $character->id,
            'item_id' => $magicWater->id,
        ]);
    }

    public function test_town_use_is_rejected_during_an_active_exploration(): void
    {
        $character = $this->character(['current_hp' => 20]);
        $herb = $this->giveItem($character, '薬草');
        $area = Area::query()->create([
            'name' => '街回復制御試験地',
            'slug' => 'town-recovery-guard-test',
            'city_id' => City::query()->findOrFail(1)->id,
            'recommended_level_min' => 1,
            'recommended_level_max' => 10,
        ]);
        $state = app(ExplorationStateService::class)->getOrStart($character, $area->id);
        $state->forceFill(['chain_count' => 1])->save();

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id, 'current_location' => 'dungeon'])
            ->post(route('shop.items.use', $herb));

        $response->assertRedirect(route('shop.items'))
            ->assertSessionHas('error', '探索中は戦闘結果から回復アイテムを使ってください。');

        $this->assertSame(20, (int) $character->fresh()->current_hp);
        $this->assertDatabaseHas('character_items', [
            'character_id' => $character->id,
            'item_id' => $herb->id,
        ]);
    }

    public function test_low_hp_action_prioritizes_an_owned_recovery_item(): void
    {
        $character = $this->character(['current_hp' => 20]);
        $this->giveItem($character, '薬草');

        $action = $this->recoveryActionFor($character);

        $this->assertSame('recovery_item_available', $action['key']);
        $this->assertSame('回復アイテムを持っています', $action['title']);
        $this->assertSame(route('shop.items').'#recovery-items', $action['action_url']);
    }

    public function test_supply_depot_explains_free_recovery_and_exposes_town_use(): void
    {
        $character = $this->character(['current_hp' => 20]);
        $herb = $this->giveItem($character, '薬草');

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id, 'current_location' => 'town'])
            ->get(route('shop.items'));

        $response->assertOk()
            ->assertSee('回復アイテムの無料配布')
            ->assertSee('受け取った回復アイテムは、この補給所か探索結果から使えます。')
            ->assertSee('薬草を使う')
            ->assertSee(route('shop.items.use', $herb), false);
    }

    public function test_low_hp_action_points_to_bank_rescue_or_sales_after_daily_supply_is_exhausted(): void
    {
        $character = $this->character(['current_hp' => 20, 'money' => 0, 'bank_gold' => 500]);
        $this->exhaustDailySupply($character);

        $bankAction = $this->recoveryActionFor($character);
        $this->assertSame('recovery_gold_in_bank', $bankAction['key']);
        $this->assertSame(route('bank.index'), $bankAction['action_url']);

        $character->forceFill(['bank_gold' => 0, 'inn_rescue_streak' => 0])->save();
        $rescueAction = $this->recoveryActionFor($character->fresh());
        $this->assertSame('recovery_inn_rescue_available', $rescueAction['key']);
        $this->assertSame('town', $rescueAction['tab']);

        $character->forceFill(['inn_rescue_streak' => 2])->save();
        $saleAction = $this->recoveryActionFor($character->fresh());
        $this->assertSame('recovery_gold_needed', $saleAction['key']);
        $this->assertSame(route('inventory.index', ['tab' => 'material']), $saleAction['action_url']);
    }

    private function recoveryActionFor(Character $character): array
    {
        return collect(app(HomeActionService::class)->getActions(
            $character,
            20,
            ['max_hp' => 100, 'max_mp' => 100],
        ))->first(fn (array $action): bool => str_starts_with((string) ($action['key'] ?? ''), 'recovery_'));
    }

    private function character(array $overrides = []): Character
    {
        $character = Character::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'name' => '回復導線確認者',
            'level' => 21,
            'hp_base' => 100,
            'mp_base' => 100,
            'current_hp' => 100,
            'current_mp' => 100,
            'money' => 0,
            'bank_gold' => 0,
        ], $overrides));

        CharacterStatusService::clearRequestCache((int) $character->id);

        return $character;
    }

    private function giveItem(Character $character, string $name): Item
    {
        $item = Item::query()->where('type', 'consumable')->where('name', $name)->firstOrFail();
        RateLimiter::clear("town-recovery-item:{$character->id}:{$item->id}");

        CharacterItem::query()->create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => false,
        ]);

        return $item;
    }

    private function exhaustDailySupply(Character $character): void
    {
        Item::query()
            ->where('type', 'consumable')
            ->whereIn('name', ['薬草', '回復薬', '魔力水'])
            ->get()
            ->each(function (Item $item) use ($character): void {
                CharacterItemDailySupply::query()->create([
                    'character_id' => $character->id,
                    'item_id' => $item->id,
                    'claimed_on' => now()->toDateString(),
                    'supplied_count' => 10,
                    'stocked_count' => 0,
                ]);
            });
    }
}
