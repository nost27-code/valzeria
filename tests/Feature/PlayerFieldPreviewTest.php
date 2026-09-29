<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterFieldPosition;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\Field\ValzeriaFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerFieldPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_open_walking_only_field_preview(): void
    {
        config(['valzeria_field.enabled' => true]);
        [$user, $character] = $this->player('公開確認の冒険者');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('field.show'))
            ->assertOk()
            ->assertSee('宝箱などは一切ありません。開発中のため、急遽メンテナンスに入る場合があります。')
            ->assertSee('"walkingOnly":true', false)
            ->assertSee('"spawn_chance":0', false)
            ->assertSee('"chest_chance":0', false)
            ->assertSee('"spot":""', false)
            ->assertSee(route('field.sync'), false);

        $this->assertDatabaseHas('character_field_positions', ['character_id' => $character->id]);
    }

    public function test_sync_saves_position_and_returns_only_presence_data(): void
    {
        $this->freezeTime();
        config(['valzeria_field.enabled' => true]);
        [$user, $character] = $this->player('移動する冒険者');
        [, $other] = $this->player('近くの冒険者');
        [$plane, $x, $y] = app(ValzeriaFieldService::class)->spawnPoint(1);
        CharacterFieldPosition::query()->create([
            'character_id' => $other->id,
            'plane' => $plane,
            'x' => $x + 100,
            'y' => $y + 100,
            'facing' => 1,
            'moved_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('field.sync'), [
                'plane' => $plane,
                'x' => $x,
                'y' => $y,
                'facing' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('players.0.name', '近くの冒険者')
            ->assertJsonCount(0, 'messages')
            ->assertJsonCount(0, 'chat')
            ->assertJsonPath('zone', null);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('character_field_positions', [
            'character_id' => $character->id,
            'plane' => $plane,
            'x' => $x,
            'y' => $y,
            'facing' => 2,
        ]);
        $this->assertDatabaseMissing('player_lifecycle_events', [
            'user_id' => $user->id,
            'event_name' => 'login',
        ]);
    }

    public function test_player_field_can_be_closed_for_emergency_maintenance(): void
    {
        config(['valzeria_field.enabled' => false]);
        [$user, $character] = $this->player('メンテナンス確認');

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('field.show'))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->postJson(route('field.sync'), ['plane' => 'land', 'x' => 1, 'y' => 1, 'facing' => 0])
            ->assertNotFound();
    }

    public function test_guest_cannot_open_player_field(): void
    {
        $this->get(route('field.show'))->assertRedirect();
    }

    /** @return array{0: User, 1: Character} */
    private function player(string $name): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create(['user_id' => $user->id, 'name' => $name]);
        $master = ValmonMaster::query()->create([
            'valmon_key' => 'field-preview-'.$character->id,
            'name' => 'フィールド確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $master->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character];
    }
}
