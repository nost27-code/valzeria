<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterFieldPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFieldSpectatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_read_only_spectator_even_while_player_field_is_disabled(): void
    {
        config(['valzeria_field.enabled' => false]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.field.show'))
            ->assertOk()
            ->assertSee('フィールド観察')
            ->assertSee('管理者観察モード')
            ->assertSee('"spectator":true', false)
            ->assertSee('"spectator_multiplier":8', false)
            ->assertSee(route('admin.field.presence'), false)
            ->assertSee('超高速')
            ->assertSee('フィールド滞在者');

        $this->assertDatabaseCount('character_field_positions', 0);
        $this->get('/field')->assertRedirect(route('character.select'));
    }

    public function test_non_admin_cannot_open_or_poll_spectator(): void
    {
        $player = User::factory()->create(['role' => 'user']);

        $this->actingAs($player)->get(route('admin.field.show'))->assertRedirect('/admin/login');
        $this->actingAs($player)->postJson(route('admin.field.presence'), ['plane' => 'land'])->assertRedirect('/admin/login');
    }

    public function test_presence_lists_active_players_on_all_planes_without_writing_positions(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['role' => 'admin']);
        $land = $this->character('地上の冒険者');
        $land->forceFill(['icon_path' => '/images/chara/exclusive/exclusive_000/01_normal.webp'])->save();
        $sky = $this->character('天空の冒険者');
        $old = $this->character('退出済みの冒険者');

        CharacterFieldPosition::query()->create([
            'character_id' => $land->id,
            'plane' => 'land',
            'x' => 1000,
            'y' => 2000,
            'facing' => 2,
            'moved_at' => now(),
        ]);
        CharacterFieldPosition::query()->create([
            'character_id' => $sky->id,
            'plane' => 'sky',
            'x' => 1328000,
            'y' => 32000,
            'facing' => 1,
            'moved_at' => now(),
        ]);
        CharacterFieldPosition::query()->create([
            'character_id' => $old->id,
            'plane' => 'land',
            'x' => 3000,
            'y' => 4000,
            'facing' => 0,
            'moved_at' => now()->subSeconds((int) config('valzeria_field.sync.presence_seconds') + 1),
        ]);
        $before = CharacterFieldPosition::query()->orderBy('id')->get()->map(fn (CharacterFieldPosition $position): array => $position->getRawOriginal())->all();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.field.presence'), [
                'plane' => 'land',
                'x' => 999999,
                'y' => 999999,
                'facing' => 3,
            ])
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonCount(1, 'players')
            ->assertJsonPath('players.0.name', '地上の冒険者')
            ->assertJsonPath('players.0.icon', fn (string $icon): bool => str_contains(
                $icon,
                '/images/chara/exclusive/exclusive_000/05_field.webp?v=',
            ))
            ->assertJsonCount(2, 'all_players')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.land', 1)
            ->assertJsonPath('summary.sky', 1);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $after = CharacterFieldPosition::query()->orderBy('id')->get()->map(fn (CharacterFieldPosition $position): array => $position->getRawOriginal())->all();
        $this->assertSame($before, $after);
    }

    private function character(string $name): Character
    {
        $user = User::factory()->create();

        return Character::query()->create(['user_id' => $user->id, 'name' => $name]);
    }
}
