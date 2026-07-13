<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\ChampState;
use App\Models\CharacterItem;
use App\Models\Item;
use App\Models\User;
use App\Services\ChampBattleService;
use App\Services\CharacterStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChampStatsRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_current_champ_stats_recomputes_from_live_character_data(): void
    {
        $character = $this->character(attackBase: 1000);
        $weapon = Item::query()->create([
            'name' => 'チャンプ試験剣', 'type' => 'weapon', 'weapon_rank' => 'EPIC',
            'str_bonus' => 500, 'is_active' => true,
        ]);
        CharacterItem::query()->create([
            'character_id' => $character->id, 'item_id' => $weapon->id,
            'is_equipped' => true, 'equipped_slot' => 'weapon',
        ]);

        // champ_statesはシングルトンテーブル（マイグレーションで初期NPC行が1件だけ投入される）。
        // appointNewChamp()と同様に、既存の1行をプレイヤーで上書きする形でテストする。
        $champ = ChampState::query()->firstOrFail();
        $champ->fill([
            'character_id' => $character->id,
            'player_name' => $character->name,
            'job_rank' => 1,
            'level' => 1,
            'current_hp' => 1,
            'max_hp' => 1,
            'atk' => 1,
            'def' => 1,
            'mag' => 1,
            'spr' => 1,
            'spd' => 1,
            'luk' => 1,
            'defense_count' => 0,
            'appointed_at' => now(),
        ])->save();

        CharacterStatusService::clearRequestCache($character->id);
        $expected = app(CharacterStatusService::class)->getFinalStats($character);

        $refreshed = app(ChampBattleService::class)->refreshCurrentChampStats();

        $this->assertNotNull($refreshed);
        $this->assertSame($expected['str'], $refreshed->atk);
        $this->assertSame($expected['mag'], $refreshed->mag);
        $this->assertSame($expected['max_hp'], $refreshed->max_hp);

        $champ->refresh();
        $this->assertSame($expected['str'], $champ->atk);
        // 装備更新前のcurrent_hp(1)は保持され、最大HPだけが更新される（回復はしない）
        $this->assertSame(1, $champ->current_hp);
    }

    public function test_refresh_returns_null_when_champ_has_no_owning_character(): void
    {
        // マイグレーションで投入される初期champ_states行はcharacter_id=nullのNPCのまま。
        $this->assertNull(ChampState::query()->firstOrFail()->character_id);

        $result = app(ChampBattleService::class)->refreshCurrentChampStats();

        $this->assertNull($result);
    }

    private function character(int $attackBase): Character
    {
        $user = User::factory()->create();

        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => 'チャンプ試験冒険者',
            'explore_stamina' => 0,
            'hp_base' => 1000, 'mp_base' => 0,
            'attack_base' => $attackBase, 'defense_base' => 0,
            'speed_base' => 0, 'magic_base' => 0,
            'spirit_base' => 0, 'luck_base' => 0,
        ]);

        CharacterStatusService::clearRequestCache($character->id);

        return $character;
    }
}
