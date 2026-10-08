<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\GameSetting;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NamelessNextBossChallengeTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\NamelessRuinRewardReferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRuinRewardReference();
        config(['gold.battle.normal_drop_rate' => 0, 'gold.battle.boss_drop_rate' => 0]);
        config(['nameless_relics.enabled' => true, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.boss_drop_chance_bps' => 0, 'app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
        $this->withoutVite();
    }

    public function test_result_links_to_the_next_depth_and_post_replay_fights_only_once(): void
    {
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        $firstUuid = (string) Str::uuid();
        $service->fight($character, 'sand', 1, true, $firstUuid);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
        $response = $this->get(route('nameless-workshop.result', ['uuid' => $firstUuid]));
        $response->assertOk()->assertSee('次のボスに挑む')->assertSee('深度2');
        $challenge = $response->viewData('nextBossChallenge');
        $this->assertSame(['sand', 2, 3, null], [$challenge['zone'], $challenge['depth'], $challenge['cost'], $challenge['blocked_reason']]);
        preg_match('/data-next-nameless-boss.*?<input[^>]*name="request_uuid" value="([^"]+)"/s', $response->getContent(), $matches);
        $this->assertTrue(Str::isUuid($matches[1] ?? ''));
        $payload = ['zone' => 'sand', 'depth' => 2, 'boss' => 1, 'batch_count' => 1, 'request_uuid' => $matches[1]];
        $resultUrl = route('nameless-workshop.result', ['uuid' => $matches[1]]);
        $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect($resultUrl);
        $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect($resultUrl);
        $this->assertSame(94, $character->fresh()->explore_stamina);
        $this->assertSame(3, (int) NamelessRuinProgress::query()->where('character_id', $character->id)->where('zone_key', 'sand')->value('unlocked_depth'));
        $this->assertSame(2, NamelessWorkshopOperation::query()->count());
        $this->assertSame(10000, $character->fresh()->money);
        $this->get($resultUrl)->assertOk()->assertSee('次のボスに挑む')->assertSee('深度3');
        $this->get(route('nameless-workshop.result', ['uuid' => $firstUuid]))->assertOk()->assertDontSee('次のボスに挑む');
    }

    public function test_only_a_current_advancing_boss_victory_offers_the_next_boss(): void
    {
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        $progress = NamelessRuinProgress::query()->where('character_id', $character->id)->firstOrFail();
        $progress->update(['unlocked_depth' => 2]);
        $result = ['zone_key' => 'sand', 'depth' => 1, 'boss' => true, 'advanced' => true, 'battle_result' => 'victory'];
        $this->assertSame(2, $service->nextBossChallenge($character, $result)['depth']);
        foreach ([['boss' => false], ['advanced' => false], ['battle_result' => 'defeat'], ['battle_result' => 'timeout'], ['depth' => 100], ['zone_key' => 'unknown']] as $override) {
            $this->assertNull($service->nextBossChallenge($character, array_replace($result, $override)));
        }
        $progress->update(['unlocked_depth' => 100]);
        $this->assertSame(100, $service->nextBossChallenge($character, array_replace($result, ['depth' => 99]))['depth']);
        $character->current_city_id = null;
        $this->assertNull($service->nextBossChallenge($character, array_replace($result, ['depth' => 99])));
    }

    public function test_unavailable_next_challenge_is_disabled_and_server_rejects_insufficient_stamina(): void
    {
        $character = $this->character();
        $uuid = (string) Str::uuid();
        app(NamelessRuinService::class)->fight($character, 'sand', 1, true, $uuid);
        $character->refresh()->update(['explore_stamina' => 2, 'explore_stamina_updated_at' => now()]);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $response = $this->get(route('nameless-workshop.result', ['uuid' => $uuid]));
        $response->assertOk()->assertSee('探索力が足りません');
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*type="submit"[^>]*\sdisabled(?:\s|=|>)/s', $response->getContent());
        $response->assertSee('探索力不足');
        $this->post(route('nameless-workshop.act', 'fight'), ['zone' => 'sand', 'depth' => 2, 'boss' => 1, 'request_uuid' => (string) Str::uuid()])
            ->assertSessionHas('error');
        $this->assertSame(2, $character->fresh()->explore_stamina);
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
        foreach ([['is_frozen' => true], ['current_hp' => 0], ['exploration_cooldown_until' => now()->addMinute()]] as $state) {
            $character->forceFill(array_replace(['is_frozen' => false, 'current_hp' => 1000000, 'exploration_cooldown_until' => null], $state));
            $result = NamelessWorkshopOperation::query()->firstOrFail()->result;
            $this->assertNotNull(app(NamelessRuinService::class)->nextBossChallenge($character, $result)['blocked_reason']);
        }
    }

    public function test_full_relic_inventory_blocks_next_boss_without_consuming_stamina(): void
    {
        $character = $this->character();
        $uuid = (string) Str::uuid();
        $service = app(NamelessRuinService::class);
        $result = $service->fight($character, 'sand', 1, true, $uuid);
        $limit = app(\App\Services\StorageCapacityService::class)->materialLimit($character);
        \App\Models\PlayerRelic::query()->insert(array_fill(0, $limit, ['character_id' => $character->id,
            'effect_key' => array_key_first(config('nameless_relic_effects')), 'rank' => 1]));
        $this->assertStringContainsString('素材倉庫の所持枠', $service->nextBossChallenge($character->fresh(), $result)['blocked_reason']);
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])->withoutMiddleware(CheckCharacterSelected::class);
        $this->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk()->assertSee('素材倉庫の所持枠がいっぱい');
        $this->post(route('nameless-workshop.act', 'fight'), ['zone' => 'sand', 'depth' => 2, 'boss' => 1, 'request_uuid' => (string) Str::uuid()])
            ->assertSessionHas('error');
        $this->assertSame(97, $character->fresh()->explore_stamina);
        $this->assertSame(1, NamelessWorkshopOperation::query()->count());
    }

    public function test_recovery_items_are_available_on_normal_and_boss_results_and_allow_the_next_boss(): void
    {
        foreach ([false, true] as $boss) {
            $character = $this->character();
            $uuid = (string) Str::uuid();
            app(NamelessRuinService::class)->fight($character, 'sand', 1, $boss, $uuid);
            $character->refresh()->update(['explore_stamina' => 0, 'explore_stamina_updated_at' => now()]);
            \App\Models\CharacterConsumableItem::query()->create([
                'character_id' => $character->id, 'item_key' => 'explore_stamina_small_bottle', 'quantity' => 1,
            ]);
            $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
                ->withoutMiddleware(CheckCharacterSelected::class);
            $url = route('nameless-workshop.result', ['uuid' => $uuid]);
            $response = $this->get($url)->assertOk()->assertSee('探索力不足')->assertDontSee('>探索力を回復する</button>', false)
                ->assertSee('探索力の小瓶')->assertSee('探索力の薬')->assertSee('輝石で購入して使う');
            $this->assertSame(1, substr_count($response->getContent(), 'id="batch-stamina-modal"'));
            $this->assertMatchesRegularExpression('/data-item-key="explore_stamina_small_bottle"\s+data-use-url="[^"]+"\s+data-quantity="1"/', $response->getContent());
            $this->postJson(route('inventory.support-items.use', ['itemKey' => 'explore_stamina_small_bottle']))
                ->assertOk()->assertJsonPath('success', true)->assertJsonPath('stamina.current', 50);
            $this->assertSame(0, (int) \App\Models\CharacterConsumableItem::query()->where('character_id', $character->id)
                ->where('item_key', 'explore_stamina_small_bottle')->value('quantity'));
            $this->assertSame(1 + (int) $boss, (int) NamelessRuinProgress::query()->where('character_id', $character->id)->value('unlocked_depth'));
            $this->postJson(route('inventory.support-items.use', ['itemKey' => 'explore_stamina_small_bottle']))
                ->assertStatus(422)->assertJsonPath('success', false);
            $this->assertSame(50, $character->fresh()->explore_stamina);
            if ($boss) {
                $response = $this->get($url)->assertOk()->assertSee('次のボスに挑む');
                $this->assertSame(1, preg_match('/<form[^>]*data-next-nameless-boss.*?<\/form>/s', $response->getContent(), $matches));
                $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|=|>)/', $matches[0]);
            }
        }
    }

    public function test_multiple_ruin_cards_share_one_recovery_modal_with_owned_counts(): void
    {
        $character = $this->character();
        \App\Models\CharacterConsumableItem::query()->create([
            'character_id' => $character->id, 'item_key' => 'explore_stamina_potion', 'quantity' => 2,
        ]);
        $html = \Illuminate\Support\Facades\Blade::render(
            "@foreach(['sand', 'water'] as \$zoneKey) @include('nameless-workshop.explore-form') @endforeach",
            ['character' => $character, 'zoneName' => '検証遺跡', 'unlocked' => 1]
        );
        $this->assertSame(2, substr_count($html, '>探索力を回復する</button>'));
        $this->assertSame(1, substr_count($html, 'id="batch-stamina-modal"'));
        $this->assertMatchesRegularExpression('/data-item-key="explore_stamina_potion"\s+data-use-url="[^"]+"\s+data-quantity="2"/', $html);
        $this->assertStringContainsString('valzeria-stamina-recovery-open', $html);
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '次のボス検証',
            'current_city_id' => $town->id, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now(),
            'hp_base' => 1000000, 'mp_base' => 1000, 'current_hp' => 1000000, 'current_mp' => 500,
            'attack_base' => 1000000, 'magic_base' => 1000000, 'defense_base' => 100000, 'spirit_base' => 100000,
            'speed_base' => 1000000, 'luck_base' => 1000, 'money' => 10000]);
        NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 1]);
        return $character;
    }
}
