<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterSubAreaExplorationState;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\ExplorationItemCarry;
use App\Models\Item;
use App\Models\SubArea;
use App\Models\SubAreaRoute;
use App\Models\User;
use App\Services\SubAreaExplorationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubAreaLogoutResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_preserves_active_sub_area_progress_and_item_carry(): void
    {
        [$character, $discovery, $area] = $this->createActiveSubArea();
        $item = Item::query()->create(['name' => '復帰試験薬', 'type' => 'consumable']);
        ExplorationItemCarry::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'item_id' => $item->id,
            'carried_count' => 10,
            'used_count' => 3,
        ]);

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('auth.logout'));

        $response->assertRedirect(route('auth.email.login'));
        $this->assertGuest();
        $this->assertDatabaseHas('character_sub_area_exploration_states', [
            'character_id' => $character->id,
            'sub_area_route_id' => $discovery->sub_area_route_id,
            'exploration_point' => 123,
            'chain_count' => 4,
            'danger_rate' => 35,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('exploration_item_carries', [
            'character_id' => $character->id,
            'item_id' => $item->id,
            'used_count' => 3,
        ]);
    }

    public function test_resume_redirects_to_the_same_entry_and_shows_saved_progress(): void
    {
        [$character, $discovery] = $this->createActiveSubArea();
        $this->withoutMiddleware(CheckCharacterSelected::class);

        $resumeResponse = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('battle.sub_area.resume'));

        $resumeResponse->assertRedirect(route('battle.sub_area.confirm', ['discovery' => $discovery]));
        $resumeResponse->assertSessionHas('message', '中断していた亜域探索へ戻りました。');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('battle.sub_area.confirm', ['discovery' => $discovery]))
            ->assertOk()
            ->assertSee('中断していた亜域探索')
            ->assertSee('探索を再開')
            ->assertSee('123')
            ->assertSee('35%');
        $this->assertSame(10, session("exploration_selected_count.{$character->id}"));
    }

    public function test_single_character_login_routes_active_sub_area_to_resume(): void
    {
        [$character] = $this->createActiveSubArea();

        $response = $this->actingAs($character->user)->get(route('character.select'));

        $response->assertRedirect(route('battle.sub_area.resume'));
        $response->assertSessionHas('current_character_id', $character->id);
        $response->assertSessionHas('current_location', 'dungeon');
    }

    public function test_explicit_return_ends_sub_area_and_clears_item_carry(): void
    {
        [$character, $discovery, $area] = $this->createActiveSubArea();
        $item = Item::query()->create(['name' => '帰還試験薬', 'type' => 'consumable']);
        ExplorationItemCarry::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'item_id' => $item->id,
            'carried_count' => 10,
            'used_count' => 1,
        ]);
        $this->withoutMiddleware(CheckCharacterSelected::class);

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('battle.resume.return'));

        $response->assertRedirect(route('home', ['skip_resume' => 1]));
        $this->assertDatabaseMissing('character_sub_area_exploration_states', [
            'character_id' => $character->id,
            'sub_area_route_id' => $discovery->sub_area_route_id,
        ]);
        $this->assertDatabaseMissing('exploration_item_carries', [
            'character_id' => $character->id,
            'item_id' => $item->id,
        ]);
    }

    public function test_existing_inactive_state_is_not_resumed(): void
    {
        [$character] = $this->createActiveSubArea();
        CharacterSubAreaExplorationState::query()
            ->where('character_id', $character->id)
            ->update(['is_active' => false]);
        $this->withoutMiddleware(CheckCharacterSelected::class);

        $response = $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('battle.sub_area.resume'));

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('message', '進行中の亜域探索はありません。');
    }

    public function test_logout_without_active_sub_area_keeps_existing_return_behavior(): void
    {
        [$character, $discovery, $area] = $this->createActiveSubArea();
        CharacterSubAreaExplorationState::query()
            ->where('character_id', $character->id)
            ->update(['is_active' => false]);
        $item = Item::query()->create(['name' => '通常帰還試験薬', 'type' => 'consumable']);
        ExplorationItemCarry::query()->create([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'item_id' => $item->id,
            'carried_count' => 10,
            'used_count' => 2,
        ]);

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id])
            ->post(route('auth.logout'))
            ->assertRedirect(route('auth.email.login'));

        $this->assertDatabaseHas('character_sub_area_exploration_states', [
            'character_id' => $character->id,
            'sub_area_route_id' => $discovery->sub_area_route_id,
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('exploration_item_carries', [
            'character_id' => $character->id,
            'item_id' => $item->id,
        ]);
    }

    private function createActiveSubArea(): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => '亜域復帰試験者',
            'hp_base' => 100,
            'current_hp' => 100,
            'money' => 0,
        ]);
        $area = Area::query()->create([
            'name' => '亜域復帰試験入口',
            'slug' => 'sub-area-logout-resume-test',
        ]);
        $subArea = SubArea::query()->create([
            'name' => '亜域復帰試験場',
            'description' => 'ログアウト復帰を確認する亜域です。',
            'is_enabled' => true,
        ]);
        $route = SubAreaRoute::query()->create([
            'sub_area_id' => $subArea->id,
            'source_area_id' => $area->id,
            'route_name' => '復帰試験路',
            'entrance_description' => '再開先となる入口です。',
            'is_enabled' => true,
        ]);
        $discovery = CharacterSubAreaRouteDiscovery::query()->create([
            'character_id' => $character->id,
            'sub_area_route_id' => $route->id,
            'discovered_at' => now(),
        ]);
        app(SubAreaExplorationStateService::class)
            ->getOrStart($character, $discovery)
            ->forceFill([
                'exploration_point' => 123,
                'chain_count' => 4,
                'danger_rate' => 35,
                'selected_explore_count' => 10,
            ])->save();

        return [$character, $discovery, $area];
    }
}
