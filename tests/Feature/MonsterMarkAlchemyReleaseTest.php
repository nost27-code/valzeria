<?php

namespace Tests\Feature;

use App\Livewire\MainScreen;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\MonsterMark;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\MonsterMarkAlchemyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class MonsterMarkAlchemyReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_boundary_controls_menu_book_routes_and_refinement(): void
    {
        $this->withoutVite();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $user = User::factory()->create();
        $character = Character::create([
            'user_id' => $user->id, 'name' => '開業時刻確認', 'level' => 30,
            'hp_base' => 100, 'mp_base' => 30, 'attack_base' => 10, 'defense_base' => 10,
            'magic_base' => 10, 'spirit_base' => 10, 'speed_base' => 10, 'luck_base' => 10,
            'current_hp' => 100, 'current_mp' => 30, 'explore_stamina' => 0,
        ]);
        $valmon = ValmonMaster::create(['valmon_key' => 'alchemy-release-test', 'name' => '確認モン',
            'rarity' => 'normal', 'is_active' => true]);
        PlayerValmon::create(['character_id' => $character->id, 'valmon_master_id' => $valmon->id,
            'is_partner' => true, 'obtained_at' => now()]);
        $enemy = Enemy::create(['area_id' => Area::query()->value('id'), 'name' => '開業確認の魔物',
            'is_boss' => false, 'role' => '通常']);
        $mark = MonsterMark::create(['enemy_id' => $enemy->id, 'mark_name' => '開業確認の印',
            'bonus_stat' => 'str', 'bonus_per_level' => 1, 'is_active' => true]);
        $owned = CharacterMonsterMark::create(['character_id' => $character->id,
            'monster_mark_id' => $mark->id, 'quantity' => 35, 'spent_quantity' => 0, 'unlocked_level' => 4]);
        $this->actingAs($user)->withSession(['current_character_id' => $character->id]);
        $service = app(MonsterMarkAlchemyService::class);
        $menu = new ReflectionMethod(MainScreen::class, 'homeMenuItems');
        $request = ['stat' => 'hp', 'request_token' => (string) Str::uuid()];

        $this->travelTo(CarbonImmutable::parse('2026-09-29T08:59:59+09:00'));
        $this->assertFalse($service->isOpen());
        $this->assertNull(collect($menu->invoke(new MainScreen))->firstWhere('route', 'monster-mark-alchemy.index'));
        $this->get(route('monster-marks.index'))->assertOk()->assertDontSee('余剰印を錬成する');
        $this->get(route('monster-mark-alchemy.index'))->assertNotFound();
        $this->post(route('monster-mark-alchemy.refine'), $request)->assertNotFound();
        try {
            $service->refine($character, $request['stat'], $request['request_token']);
            $this->fail('Opening time must be checked even for direct service calls.');
        } catch (RuntimeException $exception) {
            $this->assertSame('印錬成所はまだ開業していません。', $exception->getMessage());
        }
        $this->assertSame(0, (int) $owned->fresh()->spent_quantity);
        $this->assertDatabaseMissing('monster_mark_refinements', ['character_id' => $character->id]);

        // 同じserviceインスタンスでも時刻到達時に解放される（結果をキャッシュしない）。
        $this->travelTo(CarbonImmutable::parse('2026-09-29T00:00:00Z'));
        $this->assertTrue($service->isOpen());
        $this->assertNotNull(collect($menu->invoke(new MainScreen))->firstWhere('route', 'monster-mark-alchemy.index'));
        $this->get(route('monster-marks.index'))->assertOk()->assertSee('余剰印を錬成する');
        $this->get(route('monster-mark-alchemy.index'))->assertOk();
        $this->post(route('monster-mark-alchemy.refine'), $request)
            ->assertRedirect(route('monster-mark-alchemy.index'))->assertSessionHas('status');
        $this->assertSame(20, (int) $owned->fresh()->spent_quantity);
        $this->assertSame(10, $service->bonusesFor($character)['hp']);
    }
}
