<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\FieldChatMessage;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\ExplorationService;
use App\Services\ExplorationStaminaService;
use App\Services\Field\FieldEncounterService;
use App\Services\Field\ValzeriaFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ValzeriaFieldInteractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['valzeria_field.enabled' => true]);
    }

    public function test_touching_a_monster_runs_exactly_one_normal_exploration(): void
    {
        $character = $this->adventurer('戦う人');
        $area = Area::query()->firstOrCreate(
            ['id' => 1],
            ['name' => 'はじまりの草原', 'slug' => 'field-battle-grassland', 'city_id' => 1],
        );
        $enemy = new Enemy(['name' => 'スライム']);
        $this->mock(ExplorationService::class, fn ($mock) => $mock
            ->shouldReceive('explore')
            ->once()
            ->withArgs(fn (Character $actual, int $areaId): bool => $actual->is($character) && $areaId === 1)
            ->andReturn([
                'success' => true,
                'result' => 'victory',
                'log' => '【戦闘開始】戦う人 は スライム と遭遇した！<br><br>--- ターン 1 ---<br><span class="text-red-600" onclick="alert(1)">8</span> のダメージ！<script>alert(1)</script> スライムを倒した！',
                'enemy' => $enemy,
                'exp_gained' => 8,
                'gold_gained' => 5,
                'job_exp_gained' => 1,
                'level_up_details' => [],
                'material_drop' => [],
                'equipment_drops' => [],
            ]));
        [$x, $y] = $this->pointInTerritory(1);

        $this->assertSame(1, app(ExplorationStaminaService::class)->cost());

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('field.battle', $area), ['plane' => 'land', 'x' => $x, 'y' => $y])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('outcome', 'victory')
            ->assertJsonPath('exp', 8)
            ->assertJsonCount(2, 'turns');

        $this->assertStringContainsString('<span class="text-red-600">8</span>', $response->json('turns.1.html'));
        $this->assertStringNotContainsString('onclick', $response->json('turns.1.html'));
        $this->assertStringNotContainsString('<script', $response->json('turns.1.html'));
        $this->assertSame(1, session('field_exploration_area_id'));
    }

    public function test_reached_town_facility_can_be_opened_from_inside_the_town(): void
    {
        $character = $this->adventurer('施設を使う人');
        $city = app(ValzeriaFieldService::class)->cityDefinitions()->get(1);
        $x = ($city['tx'] + intdiv($city['w'], 2)) * 32;
        $y = ($city['ty'] + intdiv($city['h'], 2)) * 32;

        $this->actingAs($character->user)
            ->withSession([
                'current_character_id' => $character->id,
                'field_exploration_area_id' => 1,
            ])
            ->post(route('field.facility', ['city' => 1, 'slug' => 'inn']), [
                'plane' => 'land',
                'x' => $x,
                'y' => $y,
            ])
            ->assertRedirect(route('home'))
            ->assertSessionHas('current_location', 'town')
            ->assertSessionMissing('field_exploration_area_id')
            ->assertSessionHas('message', '街に戻り、探索を終えた。');
    }

    public function test_nearby_chat_is_delivered_and_unreleased_actions_have_no_routes(): void
    {
        Cache::flush();
        $speaker = $this->adventurer('話す人');
        $listener = $this->adventurer('聞く人');
        $far = $this->adventurer('遠くの人');
        $at = ['plane' => 'land', 'x' => 320000, 'y' => 1150000];

        $this->actingAs($speaker->user)
            ->withSession(['current_character_id' => $speaker->id])
            ->postJson(route('field.chat'), [...$at, 'body' => "こんにちは！\n一緒に行こう"])
            ->assertOk()
            ->assertJsonPath('said.body', 'こんにちは！ 一緒に行こう');

        $this->actingAs($listener->user)
            ->withSession(['current_character_id' => $listener->id])
            ->postJson(route('field.sync'), [
                'plane' => 'land',
                'x' => $at['x'] + 20 * 32,
                'y' => $at['y'],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'chat')
            ->assertJsonPath('chat.0.name', '話す人')
            ->assertJsonPath('chat.0.body', 'こんにちは！ 一緒に行こう');

        $this->actingAs($far->user)
            ->withSession(['current_character_id' => $far->id])
            ->postJson(route('field.sync'), [
                'plane' => 'land',
                'x' => $at['x'] + 200 * 32,
                'y' => $at['y'],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'chat');

        $this->assertSame(['こんにちは！ 一緒に行こう'], FieldChatMessage::query()->pluck('body')->all());
        $this->assertFalse(Route::has('field.spot'));
        $this->assertFalse(Route::has('field.area'));
        $this->assertFalse(Route::has('field.teleport'));
    }

    public function test_chat_rejects_rapid_long_and_frozen_messages(): void
    {
        Cache::flush();
        $speaker = $this->adventurer('発言制限の人');
        $at = ['plane' => 'land', 'x' => 320000, 'y' => 1150000];
        $this->actingAs($speaker->user)->withSession(['current_character_id' => $speaker->id]);

        $this->postJson(route('field.chat'), [...$at, 'body' => str_repeat('あ', 101)])->assertUnprocessable();
        $this->postJson(route('field.chat'), [...$at, 'body' => 'ひとつめ'])->assertOk();
        $this->postJson(route('field.chat'), [...$at, 'body' => 'ふたつめ'])->assertUnprocessable();

        Cache::flush();
        $speaker->forceFill(['is_frozen' => true])->save();
        $this->postJson(route('field.chat'), [...$at, 'body' => '凍結中'])->assertUnprocessable();

        $this->assertSame(['ひとつめ'], FieldChatMessage::query()->pluck('body')->all());
    }

    /** @return array{0: int, 1: int} */
    private function pointInTerritory(int $areaId): array
    {
        $encounters = app(FieldEncounterService::class);
        $entrance = collect(config('valzeria_field.entrances'))->firstWhere('area_id', $areaId);
        [$entranceX, $entranceY] = app(ValzeriaFieldService::class)->toTile('land', $entrance['at']);

        foreach ([[30, 30], [-30, 30], [30, -30], [-30, -30], [60, 0]] as [$dx, $dy]) {
            if ($encounters->territoryAt('land', $entranceX + $dx, $entranceY + $dy) === $areaId) {
                return [($entranceX + $dx) * 32 + 16, ($entranceY + $dy) * 32 + 16];
            }
        }

        $this->fail('縄張りの中の点が見つかりません。');
    }

    private function adventurer(string $name): Character
    {
        $user = User::factory()->create();
        $character = Character::query()->create(['user_id' => $user->id, 'name' => $name]);
        $character->forceFill([
            'current_city_id' => 1,
            'highest_city_id' => 1,
            'current_hp' => 100,
        ])->save();
        $master = ValmonMaster::query()->create([
            'valmon_key' => 'field-interaction-'.$character->id,
            'name' => '試験ヴァルモン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return $character->fresh();
    }
}
