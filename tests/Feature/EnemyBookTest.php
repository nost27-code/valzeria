<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterEnemyDiscovery;
use App\Models\Enemy;
use App\Models\Material;
use App\Models\MaterialDrop;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\BattleLogService;
use App\Services\EnemyBookService;
use App\Services\EnemyDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnemyBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_enemy_book_masks_undiscovered_enemies_and_uses_two_column_grid(): void
    {
        [$user, $character] = $this->player();
        $area = $this->area();
        $defeated = $this->enemy($area, '討伐済みスライム');
        $encountered = $this->enemy($area, '遭遇済みウルフ');
        $undiscovered = $this->enemy($area, '未発見の影');
        config(['enemy_images' => [
            $defeated->name => 'images/enemy/test-defeated.webp',
            $encountered->name => 'images/enemy/test-encountered.webp',
            $undiscovered->name => 'images/enemy/test-undiscovered.webp',
        ]]);

        CharacterEnemyDiscovery::query()->create([
            'character_id' => $character->id,
            'enemy_id' => $defeated->id,
            'first_encountered_at' => now()->subDay(),
            'first_defeated_at' => now()->subHour(),
            'last_defeated_at' => now()->subHour(),
            'defeat_count' => 2,
        ]);
        CharacterEnemyDiscovery::query()->create([
            'character_id' => $character->id,
            'enemy_id' => $encountered->id,
            'first_encountered_at' => now(),
            'defeat_count' => 0,
        ]);
        $this->actingAs($user)
            ->withSession(['current_character_id' => $character->id])
            ->get(route('enemy-book.index'))
            ->assertOk()
            ->assertSee('エネミー図鑑')
            ->assertSee('討伐済みスライム')
            ->assertSee('遭遇済みウルフ')
            ->assertDontSee('未発見の影')
            ->assertSee('？？？')
            ->assertSee('data-enemy-book-shell', false)
            ->assertSee('rounded-2xl bg-[#f7f9fc] pt-1', false)
            ->assertDontSee('border-t-4', false)
            ->assertSee('data-enemy-book-detail-pane', false)
            ->assertSee('data-enemy-book-list-pane', false)
            ->assertSee('data-enemy-book-grid', false)
            ->assertSee('data-enemy-book-card', false)
            ->assertSee('grid grid-cols-2', false)
            ->assertSee('h-[47%]', false)
            ->assertSee('absolute left-0 top-0', false)
            ->assertSee('returning = true', false)
            ->assertSee(':aria-busy="returning"', false)
            ->assertSee('px-14 text-center', false)
            ->assertSee('h-dvh overflow-hidden', false)
            ->assertSee('grid grid-cols-7 gap-1', false)
            ->assertSee('h-40 w-40', false)
            ->assertSee('rounded-2xl bg-white shadow-[0_8px_18px', false)
            ->assertSee('selected.area_card_background_url', false)
            ->assertSee('statIcon(label)', false)
            ->assertSee('grid grid-cols-2 gap-x-3 gap-y-1', false)
            ->assertSee('drop.item_book_url', false)
            ->assertSee('underline decoration-[#d8a928]', false)
            ->assertDontSee('>↗<', false)
            ->assertSee('!selected.details_unlocked', false)
            ->assertSee('inset-y-1 left-0', false)
            ->assertSee('選択中', false)
            ->assertSee('border-[#d8dee8] bg-white', false)
            ->assertDontSee('基礎報酬')
            ->assertDontSee('行動・特徴')
            ->assertDontSee('図鑑メモ')
            ->assertDontSee('Lv / 区分');
    }

    public function test_enemy_detail_unlocks_only_after_defeat(): void
    {
        [$user, $character] = $this->player();
        $area = $this->area();
        $defeated = $this->enemy($area, '記録済みゴーレム');
        $encountered = $this->enemy($area, '未討伐コウモリ');
        $undiscovered = $this->enemy($area, '未知の魔物');
        config(['enemy_images' => [
            $defeated->name => 'images/enemy/test-defeated.webp',
            $encountered->name => 'images/enemy/test-encountered.webp',
            $undiscovered->name => 'images/enemy/test-undiscovered.webp',
        ]]);

        CharacterEnemyDiscovery::query()->create([
            'character_id' => $character->id,
            'enemy_id' => $defeated->id,
            'first_encountered_at' => now()->subDay(),
            'first_defeated_at' => now(),
            'last_defeated_at' => now(),
            'defeat_count' => 1,
        ]);
        CharacterEnemyDiscovery::query()->create([
            'character_id' => $character->id,
            'enemy_id' => $encountered->id,
            'first_encountered_at' => now(),
            'defeat_count' => 0,
        ]);
        $material = Material::query()->create([
            'material_code' => 'TEST_ENEMY_BOOK_DROP',
            'name' => '図鑑リンク素材',
            'category' => '地域素材',
            'rarity' => 'N',
            'material_type' => 'city_material',
        ]);
        MaterialDrop::query()->create([
            'enemy_id' => $defeated->id,
            'material_id' => $material->id,
            'drop_rate' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)->withSession(['current_character_id' => $character->id]);

        $this->get(route('enemy-book.show', $defeated))
            ->assertOk()
            ->assertJsonPath('state', 'defeated')
            ->assertJsonPath('details_unlocked', true)
            ->assertJsonPath('stats.0.label', 'HP')
            ->assertJsonPath('stats.1.label', '攻撃')
            ->assertJsonPath('stats.2.label', '防御')
            ->assertJsonPath('stats.3.label', '魔力')
            ->assertJsonPath('stats.4.label', '精神')
            ->assertJsonPath('stats.5.label', '敏捷')
            ->assertJsonPath('stats.6.label', '運')
            ->assertJsonMissingPath('rewards')
            ->assertJsonPath('species', '獣')
            ->assertJsonPath('area_name', $area->name)
            ->assertJsonPath('area_card_background_url', asset('images/card_bg/dungeon_01_01.webp'))
            ->assertJsonPath('drops.0.name', '図鑑リンク素材')
            ->assertJsonPath('drops.0.item_book_url', route('item-book.index') . '#item-book-material-TEST_ENEMY_BOOK_DROP');

        $this->get(route('enemy-book.show', $encountered))
            ->assertOk()
            ->assertJsonPath('state', 'encountered')
            ->assertJsonPath('name', '未討伐コウモリ')
            ->assertJsonPath('details_unlocked', false)
            ->assertJsonMissingPath('stats');

        $this->get(route('enemy-book.show', $undiscovered))
            ->assertOk()
            ->assertJsonPath('state', 'undiscovered')
            ->assertJsonPath('name', '？？？')
            ->assertJsonPath('image_url', null)
            ->assertJsonPath('details_unlocked', false)
            ->assertJsonMissingPath('area_name');
    }

    public function test_battle_log_records_encounter_and_victory_but_can_skip_special_events(): void
    {
        [, $character] = $this->player();
        $area = $this->area();
        $enemy = $this->enemy($area, '戦闘記録テスト敵');
        $specialEventSource = $this->enemy($area, '特殊イベント元敵');
        $service = app(BattleLogService::class);

        $service->addLog($character, $area->id, $enemy->id, 'normal', 'lose', 0, 0, 0, 0, '敗北した');

        $this->assertDatabaseHas('character_enemy_discoveries', [
            'character_id' => $character->id,
            'enemy_id' => $enemy->id,
            'defeat_count' => 0,
        ]);

        $service->addLog($character, $area->id, $enemy->id, 'normal', 'win', 1, 0, 1, 0, '勝利した');
        $service->addLog($character, $area->id, $enemy->id, 'normal', 'win', 1, 0, 1, 0, 'もう一度勝利した');

        $this->assertDatabaseHas('character_enemy_discoveries', [
            'character_id' => $character->id,
            'enemy_id' => $enemy->id,
            'defeat_count' => 2,
        ]);
        $this->assertNotNull(CharacterEnemyDiscovery::query()
            ->where('character_id', $character->id)
            ->where('enemy_id', $enemy->id)
            ->value('first_defeated_at'));

        $service->addLog($character, $area->id, $specialEventSource->id, 'normal', 'win', 0, 0, 0, 0, '宝箱を発見した', null, null, 0, [], false);

        $this->assertDatabaseMissing('character_enemy_discoveries', [
            'character_id' => $character->id,
            'enemy_id' => $specialEventSource->id,
        ]);
    }

    public function test_same_area_and_name_enemy_rows_share_one_book_entry_and_defeat_history(): void
    {
        [, $character] = $this->player();
        $area = $this->area();
        $canonical = $this->enemy($area, '重複ボス');
        $canonical->update(['is_boss' => true]);
        $legacy = $canonical->replicate();
        $legacy->save();

        CharacterEnemyDiscovery::query()->create([
            'character_id' => $character->id,
            'enemy_id' => $canonical->id,
            'first_encountered_at' => now()->subDays(2),
            'defeat_count' => 0,
        ]);
        CharacterEnemyDiscovery::query()->create([
            'character_id' => $character->id,
            'enemy_id' => $legacy->id,
            'first_encountered_at' => now()->subDay(),
            'first_defeated_at' => now()->subHour(),
            'last_defeated_at' => now()->subHour(),
            'defeat_count' => 2,
        ]);

        $service = app(EnemyBookService::class);
        $book = $service->bookFor($character);
        $entries = collect($book['entries'])->where('name', '重複ボス')->values();
        $entry = $entries->sole();

        $this->assertCount(1, $entries);
        $this->assertSame('defeated', $entry['state']);
        $this->assertSame($canonical->id, $entry['id']);
        $this->assertSame('defeated', $service->detailFor($character, $canonical)['state']);
        $this->assertSame(2, $service->detailFor($character, $canonical)['defeat_count']);
    }

    public function test_cleared_area_marks_boss_defeated_when_old_battle_log_is_missing(): void
    {
        [, $character] = $this->player();
        $area = $this->area();
        $boss = $this->enemy($area, '攻略記録だけが残るボス');
        $boss->update(['is_boss' => true]);

        DB::table('character_area_progresses')->insert([
            'character_id' => $character->id,
            'area_id' => $area->id,
            'is_unlocked' => true,
            'boss_defeated' => true,
            'boss_defeated_at' => now()->subMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(EnemyBookService::class);
        $book = $service->bookFor($character);
        $detail = $service->detailFor($character, $boss);

        $this->assertSame('defeated', collect($book['entries'])->firstWhere('name', '攻略記録だけが残るボス')['state']);
        $this->assertSame('defeated', $detail['state']);
        $this->assertSame(1, $detail['defeat_count']);
        $this->assertTrue($detail['details_unlocked']);
    }

    public function test_discovery_service_keeps_first_encounter_and_increments_defeats(): void
    {
        [, $character] = $this->player();
        $enemy = $this->enemy($this->area(), '発見サービス敵');
        $service = app(EnemyDiscoveryService::class);

        $service->recordBattle($character->id, $enemy->id, 'defeat');
        $firstEncounteredAt = CharacterEnemyDiscovery::query()
            ->where('character_id', $character->id)
            ->where('enemy_id', $enemy->id)
            ->value('first_encountered_at');

        $service->recordBattle($character->id, $enemy->id, 'victory');

        $record = CharacterEnemyDiscovery::query()
            ->where('character_id', $character->id)
            ->where('enemy_id', $enemy->id)
            ->firstOrFail();

        $this->assertSame((string) $firstEncounteredAt, (string) $record->first_encountered_at);
        $this->assertNotNull($record->first_defeated_at);
        $this->assertSame(1, $record->defeat_count);
    }

    private function player(): array
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => 'エネミー図鑑テスト',
        ]);
        $master = ValmonMaster::query()->create([
            'valmon_key' => 'enemy-book-test',
            'name' => '図鑑モン',
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

    private function area(): Area
    {
        return Area::query()->create([
            'name' => '図鑑の草原',
            'slug' => 'enemy-book-' . fake()->unique()->numerify('####'),
            'is_published' => true,
            'city_id' => 1,
            'unlock_order' => 1,
            'sort_order' => 1,
        ]);
    }

    private function enemy(Area $area, string $name): Enemy
    {
        return Enemy::query()->create([
            'area_id' => $area->id,
            'name' => $name,
            'level' => 10,
            'enemy_level' => 10,
            'max_hp' => 120,
            'str' => 20,
            'def' => 18,
            'agi' => 15,
            'mag' => 12,
            'spr' => 14,
            'luk' => 8,
            'exp_reward' => 30,
            'gold_reward' => 5,
            'job_exp_reward' => 2,
            'appearance_weight' => 10,
            'is_boss' => false,
            'sort_order' => Enemy::query()->count() + 1,
            'role' => '通常敵',
            'type_name' => '標準型',
            'element' => '無',
            'action_pattern' => '様子を見ながら攻撃する。',
            'species_key' => 'beast',
        ]);
    }
}
