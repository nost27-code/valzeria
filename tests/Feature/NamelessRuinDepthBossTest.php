<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\EnemyAction;
use App\Models\GameSetting;
use App\Models\NamelessRuinProgress;
use App\Models\NamelessWorkshopOperation;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\BattleService;
use App\Services\GameSettingService;
use App\Services\NamelessRuinService;
use App\Services\NamelessTownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class NamelessRuinDepthBossTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nameless_relics.enabled' => true, 'nameless_relics.equipment_drop_chance_bps' => 0,
            'nameless_relics.relic_goblin_encounter_bps' => 0, 'nameless_relics.cleared_boss_encounter_bps' => 0]);
        GameSetting::query()->updateOrCreate(['setting_key' => 'exploration.mode'], ['value' => 'stamina', 'value_type' => 'string']);
        app(GameSettingService::class)->flush();
    }

    public function test_all_depths_select_one_of_four_distinct_bosses_with_valid_art_and_relic_advice(): void
    {
        $service = app(NamelessRuinService::class);
        $names = $portraits = [];
        foreach ($service->zones() as $zone) {
            $this->assertCount(4, $zone['bosses']);
            $this->assertCount(5, $zone['enemies']); // 既存の通常敵4体と最奥の参照を保持。
            foreach (range(1, 100) as $depth) {
                $index = $depth < 25 ? 0 : ($depth < 50 ? 1 : ($depth < 75 ? 2 : 3));
                $this->assertSame($zone['bosses'][$index]['name'], $service->bossForDepth($zone, $depth)['name']);
            }
            foreach ($zone['bosses'] as $boss) {
                $names[] = $boss['name'];
                $portraits[] = $boss['image'];
                $this->assertSame($boss['image'], config('enemy_images.'.$boss['name']));
                $this->assertFileExists(public_path($boss['image']));
                $image = getimagesize(public_path($boss['image']));
                $this->assertSame([300, 300, 'image/webp'], [$image[0], $image[1], $image['mime']]);
                $this->assertNotEmpty($boss['trait']);
                $this->assertNotEmpty($boss['counter']);
                foreach ($boss['recommended_relics'] as $relic) {
                    $this->assertNotEmpty($relic['name'], $relic['key']);
                    $this->assertNotEmpty($relic['zone'], $relic['key']);
                }
                $this->assertFalse($service->bossActions($boss)->first()->exists);
            }
        }
        $this->assertCount(24, array_unique($names));
        $this->assertCount(24, array_unique($portraits));
        foreach ([0, 101] as $depth) {
            try {
                $service->bossForDepth($service->zones()['sand'], $depth);
                $this->fail('範囲外の深度が受理されました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('範囲外', $exception->getMessage());
            }
        }
    }

    public function test_every_boss_can_be_fought_and_replayed_without_changing_reward_or_progress_rules(): void
    {
        config(['nameless_relics.boss_drop_chance_bps' => 10000]);
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        $enemyRows = Enemy::query()->count();
        $actionRows = EnemyAction::query()->count();
        foreach ($service->zones() as $key => $zone) {
            foreach ([1, 25, 50, 75, 100] as $depth) {
                $progress = NamelessRuinProgress::query()->where('character_id', $character->id)->where('zone_key', $key)->firstOrFail();
                $progress->update(['unlocked_depth' => $depth]);
                $uuid = (string) Str::uuid();
                $result = $service->fight($character, $key, $depth, true, $uuid);
                $this->assertSame('victory', $result['battle_result']);
                $this->assertSame($service->bossForDepth($zone, $depth)['name'], $result['enemy_name']);
                $this->assertSame($service->bossForDepth($zone, $depth)['image'], $result['enemy_image_path']);
                $this->assertSame($depth < 100, $result['advanced']);
                $this->assertSame(min(100, $depth + 1), (int) $progress->fresh()->unlocked_depth);
                $this->assertSame($result, $service->fight($character, $key, $depth, true, $uuid));
                $this->assertNotEmpty($result['boss_guide']['counter']);
                $this->assertSame([0, 0, 0], [$result['exp_gained'], $result['job_exp_gained'], $result['gold_gained']]);
                $this->assertSame($depth === 50 ? $zone['next_zone_name'] : null, $result['unlocked_zone_name']);
            }
        }
        $this->assertSame(10, $character->fresh()->explore_stamina);
        $this->assertSame(30, PlayerRelic::query()->count());
        $this->assertSame(30, NamelessWorkshopOperation::query()->count());
        $this->assertSame(10000, $character->fresh()->money);
        $this->assertSame(500, $character->fresh()->current_mp);
        $this->assertSame(0, (int) $character->fresh()->wins);
        $this->assertSame($enemyRows, Enemy::query()->count());
        $this->assertSame($actionRows, EnemyAction::query()->count());
    }

    public function test_cleared_boss_reappearance_matches_selected_depth_and_keeps_progress_and_normal_cost(): void
    {
        config(['nameless_relics.cleared_boss_encounter_bps' => 10000, 'nameless_relics.boss_drop_chance_bps' => 0]);
        $character = $this->character();
        $service = app(NamelessRuinService::class);
        foreach ($service->zones() as $key => $zone) {
            // 深度100の実撃破台帳を作った後、過去深度を含めて再遭遇する。
            $service->fight($character, $key, 100, true, (string) Str::uuid());
            foreach ([24, 25, 49, 50, 74, 75, 99, 100] as $depth) {
                $result = $service->fight($character, $key, $depth, false, (string) Str::uuid());
                $this->assertSame('cleared_boss', $result['encounter_kind']);
                $this->assertSame($service->bossForDepth($zone, $depth)['name'], $result['enemy_name']);
                $this->assertTrue($result['enemy']['is_boss']);
                $this->assertFalse($result['boss']);
                $this->assertFalse($result['advanced']);
                $this->assertSame(100, $result['unlocked_depth']);
            }
        }
        $this->assertSame(34, $character->fresh()->explore_stamina);
        $this->assertSame(0, PlayerRelic::query()->count());
    }

    public function test_each_signature_uses_existing_battle_execution_and_telegraphs_before_damage(): void
    {
        $service = app(NamelessRuinService::class);
        $method = new ReflectionMethod(BattleService::class, 'executeEnemyAction');
        foreach ($service->zones() as $zone) {
            foreach ($zone['bosses'] as $boss) {
                $model = new Enemy(['name' => $boss['name'], 'species_key' => $boss['species'], 'is_boss' => true]);
                $actions = $service->bossActions($boss);
                $action = $actions->first();
                // 抽選の成否に依存せず実行経路を検証。実マスタの30%抽選は変更しない。
                $action->forceFill(['guarantee_first_use' => true, 'trigger_turn' => 2]);
                $model->setRelation('actions', $actions);
                $enemy = new BattleActor($boss['name'], false, ['max_hp' => 10000, 'str' => 1000, 'mag' => 1000, 'agi' => 10000, 'luk' => 0], $model);
                $player = new BattleActor('検証者', true, ['max_hp' => 100000, 'def' => 100, 'spr' => 100, 'agi' => 1]);
                $state = new BattleState($player, $enemy, 'boss');
                $state->turnCount = 2;
                $method->invoke(app(BattleService::class), $enemy, $player, $state);
                $this->assertSame(1, $state->enemyActionUseCounts[1]);
                if ($action->is_telegraphed) {
                    $this->assertSame(100000, $player->hp);
                    $this->assertSame(1, $state->pendingEnemyActionId);
                    $this->assertStringContainsString('気配を見せた', implode('\n', $state->logs));
                    $state->turnCount++;
                    $method->invoke(app(BattleService::class), $enemy, $player, $state);
                    $this->assertNull($state->pendingEnemyActionId);
                    $this->assertSame(1, $state->enemyActionUseCounts[1]);
                }
                $this->assertStringContainsString('【敵技】', implode('\n', $state->logs));
                $this->assertStringContainsString($boss['technique'], implode('\n', $state->logs));
            }
        }
    }

    public function test_unbeaten_normal_exploration_never_selects_a_boss(): void
    {
        $service = app(NamelessRuinService::class);
        foreach ($service->zones() as $zone) {
            foreach ([1, 25, 50, 75, 100] as $depth) {
                config(['nameless_relics.cleared_boss_encounter_bps' => 10000]);
                $encounter = $service->encounterForTicket($zone, false, 10, 1, $depth);
                $this->assertSame('normal', $encounter['kind']);
                $this->assertFalse($encounter['is_boss']);
                $this->assertContains($encounter['definition']['name'], array_column(array_slice($zone['enemies'], 0, 4), 'name'));
            }
        }
    }

    private function character(): Character
    {
        $town = app(NamelessTownService::class)->installLocalTown();
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '深度別ボス検証',
            'current_city_id' => $town->id, 'explore_stamina' => 100, 'explore_stamina_updated_at' => now(),
            'hp_base' => 1000000, 'mp_base' => 1000, 'current_hp' => 1000000, 'current_mp' => 500,
            'attack_base' => 1000000, 'magic_base' => 1000000, 'defense_base' => 100000, 'spirit_base' => 100000,
            'speed_base' => 1000000, 'luck_base' => 1000, 'money' => 10000]);
        foreach (array_keys(config('nameless_ruins')) as $key) {
            NamelessRuinProgress::query()->create(['character_id' => $character->id, 'zone_key' => $key, 'unlocked_depth' => 100]);
        }
        return $character;
    }
}
