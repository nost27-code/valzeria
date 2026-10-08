<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\GameSetting;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NamelessRepeatExplorationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\NamelessRuinRewardReferences;
    use \Tests\Support\SharedStorageFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installRuinRewardReference();
        config(['gold.battle.normal_drop_rate' => 0, 'gold.battle.boss_drop_rate' => 0]);
        config(['nameless_relics.enabled' => true, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.drop_chance_bps' => 0, 'nameless_relics.boss_drop_chance_bps' => 0,
            'nameless_relics.relic_goblin_encounter_bps' => 0, 'nameless_relics.cleared_boss_encounter_bps' => 0,
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
        $this->withoutVite();
    }

    public function test_result_form_repeats_one_ten_custom_and_fifty_runs_in_the_same_zone_and_depth_once(): void
    {
        foreach ([1, 10, 25, 50] as $count) {
            $character = $this->character();
            $this->login($character);
            $uuid = (string) Str::uuid();
            $url = route('nameless-workshop.result', ['uuid' => $uuid]);
            $payload = ['zone' => 'sand', 'depth' => 1, 'boss' => 0, 'batch_count' => $count, 'request_uuid' => $uuid];
            $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect($url)
                ->assertSessionHas('nameless_exploration_selected_count.'.$character->id, $count);
            $response = $this->get($url)->assertOk()->assertSee('data-nameless-repeat-form', false)
                ->assertSee('直接入力（2〜50回）')->assertSee('images/icon/icon_005.webp', false)
                ->assertDontSee('遺物を整理する')->assertDontSee('>探索力を回復する</button>', false)
                ->assertDontSee('探索タブへ')->assertDontSee('街へ戻る・回復する');
            $form = $this->repeatForm($response->getContent());
            $this->assertStringContainsString('name="zone" value="sand"', $form);
            $this->assertStringContainsString('name="depth" value="1"', $form);
            $this->assertStringContainsString('name="batch_count" value="'.$count.'"', $form);
            $this->assertStringContainsString('name="boss" value="0"', $form);
            preg_match('/name="request_uuid" value="([^"]+)"/', $form, $matches);
            $this->assertNotSame($uuid, $matches[1]);
            $payload['request_uuid'] = $matches[1];
            $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect();
            $this->post(route('nameless-workshop.act', 'fight'), $payload)->assertRedirect();
            $this->assertSame(100 - 2 * $count, (int) $character->fresh()->explore_stamina);
            $this->assertSame(2, NamelessWorkshopOperation::query()->where('character_id', $character->id)->count());
            $this->assertSame(1, (int) NamelessRuinProgress::query()->where('character_id', $character->id)->value('unlocked_depth'));
            $this->assertSame(0, PlayerRelic::query()->where('character_id', $character->id)->count());
            $this->assertSame(10000, (int) $character->fresh()->money);
            $this->assertSame(2 * $count, (int) $character->fresh()->wins);
            $operations = NamelessWorkshopOperation::query()->where('character_id', $character->id)->get();
            $this->assertSame(2 * $count * 17, $operations->sum(fn ($op) => (int) $op->result['exp_gained']));
            $this->assertSame(2 * $count * 2, $operations->sum(fn ($op) => (int) $op->result['job_exp_gained']));
        }
    }

    public function test_count_survives_the_exploration_tab_and_resets_on_both_town_return_paths(): void
    {
        $character = $this->character();
        $this->login($character);
        $key = 'nameless_exploration_selected_count.'.$character->id;
        $this->withSession([$key => 25])->get(route('nameless-workshop.return', ['tab' => 'dungeon']))
            ->assertRedirect()->assertSessionHas($key, 25);
        $this->get(route('nameless-workshop.return', ['tab' => 'town']))->assertRedirect()->assertSessionMissing($key);
        $this->withSession([$key => 50])->post(route('battle.return'))->assertRedirect()->assertSessionMissing($key);
    }

    public function test_boss_defeat_timeout_and_invalid_or_other_town_results_have_no_normal_repeat_form(): void
    {
        $character = $this->character();
        $this->login($character);
        $service = app(NamelessRuinService::class);
        $uuid = (string) Str::uuid();
        $result = $service->fight($character, 'sand', 1, false, $uuid);
        $operation = NamelessWorkshopOperation::query()->where('request_uuid', $uuid)->sole();
        foreach ([['boss' => true], ['battle_result' => 'defeat', 'result' => 'defeat'],
            ['battle_result' => 'timeout', 'result' => 'timeout']] as $override) {
            $operation->update(['result' => array_replace($result, $override)]);
            $response = $this->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk();
            $this->assertSame(0, preg_match('/<form[^>]*data-nameless-repeat-form/', $response->getContent()));
        }
        foreach ([['zone_key' => 'unknown'], ['depth' => 2], ['depth' => 0]] as $override) {
            $this->assertNull($service->repeatExploration($character, array_replace($result, $override)));
        }
        $character->current_city_id = 0;
        $this->assertNull($service->repeatExploration($character, $result));
        // A cleared-boss encounter in normal exploration is still a normal repeat.
        $character->refresh();
        $this->assertNotNull($service->repeatExploration($character, $result + ['encounter_kind' => 'cleared_boss']));
    }

    public function test_full_bag_keeps_earned_rewards_and_disables_the_next_exploration_until_sorted(): void
    {
        config(['nameless_relics.drop_chance_bps' => 10000]);
        $character = $this->character();
        $this->login($character);
        $this->reserveMaterialSlots($character, 1);
        $uuid = (string) Str::uuid();
        $this->post(route('nameless-workshop.act', 'fight'), ['zone' => 'sand', 'depth' => 1,
            'boss' => 0, 'batch_count' => 10, 'request_uuid' => $uuid])->assertRedirect();
        $operation = NamelessWorkshopOperation::query()->where('request_uuid', $uuid)->sole();
        $this->assertSame(1, $operation->result['batch_explore']['completed']);
        $this->assertSame(99, (int) $character->fresh()->explore_stamina);
        $this->assertSame(1, PlayerRelic::query()->where('character_id', $character->id)->count());
        $response = $this->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk()->assertSee('所持枠がいっぱい');
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*disabled/s', $this->repeatForm($response->getContent()));
        $this->assertStringContainsString('name="batch_count" value="10"', $this->repeatForm($response->getContent()));
        $this->post(route('nameless-workshop.act', 'fight'), ['zone' => 'sand', 'depth' => 1,
            'boss' => 0, 'batch_count' => 1, 'request_uuid' => (string) Str::uuid()])->assertSessionHas('error');
        $this->assertSame(99, (int) $character->fresh()->explore_stamina);
    }

    public function test_insufficient_stamina_and_low_hp_are_rejected_without_another_operation(): void
    {
        $character = $this->character();
        $this->login($character);
        $uuid = (string) Str::uuid();
        app(NamelessRuinService::class)->fight($character, 'sand', 1, false, $uuid);
        foreach ([['explore_stamina' => 9], ['explore_stamina' => 100, 'current_hp' => 1]] as $state) {
            $character->refresh()->update($state);
            $response = $this->withSession(['nameless_exploration_selected_count.'.$character->id => 10])
                ->get(route('nameless-workshop.result', ['uuid' => $uuid]))->assertOk();
            $form = $this->repeatForm($response->getContent());
            if (isset($state['current_hp'])) {
                $this->assertMatchesRegularExpression('/<button type="submit"[^>]*\sdisabled(?:\s|=|>)/s', $form);
            } else {
                $this->assertDoesNotMatchRegularExpression('/<button type="submit"[^>]*\sdisabled(?:\s|=|>)/s', $form);
                $this->assertStringContainsString('探索力不足', $form);
                $this->assertStringContainsString('valzeria-stamina-recovery-open', $form);
            }
            $this->post(route('nameless-workshop.act', 'fight'), ['zone' => 'sand', 'depth' => 1,
                'boss' => 0, 'batch_count' => 10, 'request_uuid' => (string) Str::uuid()])->assertSessionHas('error');
            $this->assertSame($state['explore_stamina'], (int) $character->fresh()->explore_stamina);
        }
        $this->assertSame(1, NamelessWorkshopOperation::query()->where('character_id', $character->id)->count());
    }

    private function repeatForm(string $html): string
    {
        $this->assertSame(1, preg_match('/<form[^>]*data-nameless-repeat-form.*?<\/form>/s', $html, $matches));
        return $matches[0];
    }

    private function login(Character $character): void
    {
        $this->actingAs($character->user)->withSession(['current_character_id' => $character->id])
            ->withoutMiddleware(CheckCharacterSelected::class);
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '再探索検証',
            'current_city_id' => $town->id, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now(),
            'hp_base' => 1000000, 'mp_base' => 1000, 'current_hp' => 1000000, 'current_mp' => 500,
            'attack_base' => 1000000, 'magic_base' => 1000000, 'defense_base' => 100000, 'spirit_base' => 100000,
            'speed_base' => 1000000, 'luck_base' => 1000, 'money' => 10000]);
        NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => 'sand', 'unlocked_depth' => 1]);
        return $character;
    }
}
