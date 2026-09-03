<?php

namespace Tests\Feature;

use App\Livewire\ChampCard;
use App\Livewire\CityHeader;
use App\Livewire\ColosseumRanking;
use App\Models\ArenaRanking;
use App\Models\ChampState;
use App\Models\Character;
use App\Models\CharacterIconDesignRequest;
use App\Models\User;
use App\Services\ArenaNpcRankingService;
use App\Services\CharacterIconSetService;
use App\Support\CharacterIconCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class CharacterIconSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_exclusive_icon_set_is_available_only_to_its_owner(): void
    {
        $owner = $this->createCharacter('所有者', '/images/chara/chara_001.webp');
        $other = $this->createCharacter('別の冒険者', '/images/chara/chara_002.webp');
        $service = app(CharacterIconSetService::class);

        $service->grant($owner, 'exclusive_000');
        $owner->refresh();

        $this->assertSame(
            '/images/chara/exclusive/exclusive_000/01_normal.webp',
            $owner->icon_path
        );
        $this->assertDatabaseHas('character_icon_entitlements', [
            'character_id' => $owner->id,
            'icon_set_key' => 'exclusive_000',
            'revoked_at' => null,
        ]);
        $this->assertTrue($service->canSelect($owner, $owner->icon_path));
        $this->assertFalse($service->canSelect($other, $owner->icon_path));
        $this->assertNotContains($owner->icon_path, CharacterIconCatalog::paths());
    }

    public function test_exclusive_icon_set_resolves_all_four_scenes(): void
    {
        $character = $this->createCharacter('四場面確認');
        $service = app(CharacterIconSetService::class);
        $service->grant($character, 'exclusive_000');

        $this->assertSame([
            'normal' => '/images/chara/exclusive/exclusive_000/01_normal.webp',
            'battle' => '/images/chara/exclusive/exclusive_000/03_battle.webp',
            'victory' => '/images/chara/exclusive/exclusive_000/02_victory.webp',
            'defeat' => '/images/chara/exclusive/exclusive_000/04_defeat.webp',
        ], $service->resolvedPaths($character));
    }

    public function test_character_can_own_and_select_multiple_exclusive_icon_sets(): void
    {
        $character = $this->createCharacter('追加セット所有者');
        $service = app(CharacterIconSetService::class);

        $service->grant($character, 'exclusive_030');
        $service->grant($character, 'exclusive_033');
        $character->refresh();

        $this->assertSame(
            '/images/chara/exclusive/exclusive_033/01_normal.webp',
            $character->icon_path
        );
        $this->assertDatabaseHas('character_icon_entitlements', [
            'character_id' => $character->id,
            'icon_set_key' => 'exclusive_030',
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('character_icon_entitlements', [
            'character_id' => $character->id,
            'icon_set_key' => 'exclusive_033',
            'revoked_at' => null,
        ]);
        $this->assertTrue($service->canSelect(
            $character,
            '/images/chara/exclusive/exclusive_030/01_normal.webp'
        ));
        $this->assertTrue($service->canSelect(
            $character,
            '/images/chara/exclusive/exclusive_033/01_normal.webp'
        ));
    }

    public function test_new_exclusive_icon_sets_have_complete_four_pose_assets(): void
    {
        $setKeys = [
            'exclusive_003',
            'exclusive_005',
            'exclusive_007',
            'exclusive_008',
            'exclusive_009',
            'exclusive_010',
            'exclusive_011',
            'exclusive_012',
            'exclusive_013',
            'exclusive_014',
            'exclusive_015',
            'exclusive_016',
            'exclusive_017',
            'exclusive_018',
            'exclusive_019',
            'exclusive_020',
            'exclusive_021',
            'exclusive_022',
            'exclusive_023',
            'exclusive_024',
            'exclusive_025',
            'exclusive_027',
            'exclusive_028',
            'exclusive_029',
            'exclusive_030',
            'exclusive_031',
            'exclusive_032',
            'exclusive_033',
            'exclusive_034',
            'exclusive_035',
            'exclusive_036',
            'exclusive_037',
            'exclusive_038',
            'exclusive_039',
            'exclusive_040',
            'exclusive_041',
            'exclusive_042',
            'exclusive_043',
            'exclusive_044',
        ];

        foreach ($setKeys as $setKey) {
            $basePath = "/images/chara/exclusive/{$setKey}";
            $paths = CharacterIconCatalog::pathsForSet($setKey);

            $this->assertSame([
                'normal' => "{$basePath}/01_normal.webp",
                'battle' => "{$basePath}/03_battle.webp",
                'victory' => "{$basePath}/02_victory.webp",
                'defeat' => "{$basePath}/04_defeat.webp",
            ], $paths);
            foreach ($paths as $path) {
                $this->assertFileExists(public_path(ltrim($path, '/')));
            }
            $this->assertNotContains($paths['normal'], CharacterIconCatalog::paths());
        }
    }

    public function test_owner_can_cycle_arena_showcase_and_every_ranking_view_uses_saved_pose(): void
    {
        $character = $this->createCharacter('展示ポーズ確認');
        $service = app(CharacterIconSetService::class);
        $service->grant($character, 'exclusive_000');
        ArenaRanking::query()->create([
            'character_id' => $character->id,
            'rank' => 1,
            'wins' => 0,
            'losses' => 0,
        ]);

        $this->assertSame([
            'path' => '/images/chara/exclusive/exclusive_000/01_normal.webp',
            'scene' => 'normal',
            'label' => '通常',
            'has_choices' => true,
        ], $service->arenaShowcase($character));

        $showcase = $service->cycleArenaShowcase($character);

        $this->assertSame('battle', $showcase['scene']);
        $this->assertSame(
            '/images/chara/exclusive/exclusive_000/03_battle.webp',
            $showcase['path']
        );
        $this->assertDatabaseHas('character_icon_entitlements', [
            'character_id' => $character->id,
            'icon_set_key' => 'exclusive_000',
            'arena_showcase_scene' => 'battle',
        ]);

        $publicEntry = app(ArenaNpcRankingService::class)
            ->rankingEntries(100)
            ->firstWhere('character.id', $character->id);

        $this->assertSame('battle', $publicEntry['showcase_scene']);
        $this->assertSame(
            '/images/chara/exclusive/exclusive_000/03_battle.webp',
            $publicEntry['image_path']
        );

        $this->assertSame('victory', $service->cycleArenaShowcase($character)['scene']);
        $this->assertSame('defeat', $service->cycleArenaShowcase($character)['scene']);
        $this->assertSame('normal', $service->cycleArenaShowcase($character)['scene']);
    }

    public function test_regular_icon_character_cannot_cycle_arena_showcase(): void
    {
        $character = $this->createCharacter('通常アイコン');
        $service = app(CharacterIconSetService::class);

        $this->assertFalse($service->arenaShowcase($character)['has_choices']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('4ポーズ対応の限定アイコン');

        $service->cycleArenaShowcase($character);
    }

    public function test_only_current_champ_can_cycle_exclusive_icon_on_champ_card(): void
    {
        $champion = $this->createCharacter('限定チャンプ');
        $viewer = $this->createCharacter('観戦者');
        app(CharacterIconSetService::class)->grant($champion, 'exclusive_000');
        $champion->refresh();
        $this->appointChamp($champion);

        Livewire::actingAs($viewer->user)
            ->test(ChampCard::class)
            ->assertDontSee('チャンプ画像のポーズを切り替える', escape: false)
            ->assertSee('01_normal.webp', escape: false)
            ->assertDontSee('03_battle.webp', escape: false)
            ->assertDontSee('02_victory.webp', escape: false)
            ->assertDontSee('04_defeat.webp', escape: false);

        Livewire::actingAs($champion->user)
            ->test(ChampCard::class)
            ->assertSee('チャンプ画像のポーズを切り替える', escape: false)
            ->assertSee('01_normal.webp', escape: false)
            ->assertSee('03_battle.webp', escape: false)
            ->assertSee('02_victory.webp', escape: false)
            ->assertSee('04_defeat.webp', escape: false)
            ->assertDontSee('画像を押すと', escape: false);
    }

    public function test_current_champ_regular_icon_remains_a_static_image(): void
    {
        $champion = $this->createCharacter('通常チャンプ');
        $viewer = $this->createCharacter('通常観戦者');
        $this->appointChamp($champion);

        Livewire::actingAs($viewer->user)
            ->test(ChampCard::class)
            ->assertDontSee('チャンプ画像のポーズを切り替える', escape: false)
            ->assertSee('chara_001.webp', escape: false);
    }

    public function test_ranking_pose_switch_is_local_and_keeps_the_saved_showcase(): void
    {
        $owner = $this->createCharacter('展示する冒険者');
        $viewer = $this->createCharacter('見る冒険者');
        $service = app(CharacterIconSetService::class);
        $service->grant($owner, 'exclusive_000');
        $service->cycleArenaShowcase($owner);
        ArenaRanking::query()->create([
            'character_id' => $owner->id,
            'rank' => 1,
            'wins' => 0,
            'losses' => 0,
        ]);
        ArenaRanking::query()->create([
            'character_id' => $viewer->id,
            'rank' => 2,
            'wins' => 0,
            'losses' => 0,
        ]);

        Livewire::actingAs($viewer->user)
            ->test(ColosseumRanking::class)
            ->assertSee('01_normal.webp', escape: false)
            ->assertSee('03_battle.webp', escape: false)
            ->assertSee('02_victory.webp', escape: false)
            ->assertSee('04_defeat.webp', escape: false)
            ->assertSee('@click.stop=', escape: false)
            ->assertDontSee('wire:click="cycleMyArenaShowcase"', escape: false);

        $this->assertDatabaseHas('character_icon_entitlements', [
            'character_id' => $owner->id,
            'icon_set_key' => 'exclusive_000',
            'arena_showcase_scene' => 'battle',
        ]);
    }

    public function test_header_icon_exposes_number_ordered_pose_paths_for_local_switching(): void
    {
        config(['features.six_hero_ui_enabled' => false]);
        $character = $this->createCharacter('ヘッダーポーズ確認', '/images/chara/chara_004.webp');

        $this->actingAs($character->user)
            ->withSession(['current_character_id' => $character->id]);

        Livewire::test(CityHeader::class)
            ->assertSeeHtml('data-header-character-pose-toggle')
            ->assertSeeInOrder([
                '01_normal.webp',
                '02_victory.webp',
                '03_battle.webp',
                '04_defeat.webp',
            ], escape: false)
            ->assertSeeHtml('x-bind:src="posePaths[poseIndex]"')
            ->assertDontSeeHtml('wire:click="cycleHeaderPose"');

        $this->assertDatabaseHas('characters', [
            'id' => $character->id,
            'icon_path' => '/images/chara/chara_004.webp',
        ]);
    }

    public function test_lightweight_ranking_does_not_load_character_detail_tables(): void
    {
        $character = $this->createCharacter('軽量番付の冒険者', '/images/chara/chara_004.webp');
        ArenaRanking::query()->create([
            'character_id' => $character->id,
            'rank' => 1,
            'wins' => 0,
            'losses' => 0,
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $entry = app(ArenaNpcRankingService::class)
            ->lightweightRankingEntries(100)
            ->firstWhere('character.id', $character->id);

        $this->assertNotNull($entry);
        $this->assertSame('軽量番付の冒険者', $entry['name']);
        $this->assertSame(
            ['id', 'name', 'level', 'icon_path'],
            array_keys($entry['character']->getAttributes())
        );
        $this->assertArrayNotHasKey('job', $entry);
        $this->assertArrayNotHasKey('power', $entry);
        $this->assertCount(4, $entry['pose_paths']);
        $this->assertStringContainsString('/01_normal.webp', $entry['pose_paths'][0]);
        $this->assertStringContainsString('/03_battle.webp', $entry['pose_paths'][1]);
        $this->assertStringContainsString('/02_victory.webp', $entry['pose_paths'][2]);
        $this->assertStringContainsString('/04_defeat.webp', $entry['pose_paths'][3]);

        $sql = implode("\n", $queries);
        $this->assertStringNotContainsString('character_items', $sql);
        $this->assertStringNotContainsString('character_jobs', $sql);
        $this->assertStringNotContainsString('character_monster_marks', $sql);
    }

    public function test_exclusive_icon_set_cannot_be_granted_to_a_second_character(): void
    {
        $owner = $this->createCharacter('最初の所有者');
        $other = $this->createCharacter('別の所有者候補');
        $service = app(CharacterIconSetService::class);
        $service->grant($owner, 'exclusive_000');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('別のプレイヤーへ付与済み');

        $service->grant($other, 'exclusive_000');
    }

    public function test_grant_command_can_link_and_complete_a_design_request(): void
    {
        $character = $this->createCharacter('三黒');
        $designRequest = CharacterIconDesignRequest::query()->create([
            'character_id' => $character->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->artisan('character-icon:grant', [
            'character' => '三黒',
            'set_key' => 'exclusive_000',
            '--request' => $designRequest->id,
            '--complete-request' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('character_icon_entitlements', [
            'character_id' => $character->id,
            'character_icon_design_request_id' => $designRequest->id,
            'icon_set_key' => 'exclusive_000',
        ]);
        $this->assertDatabaseHas('character_icon_design_requests', [
            'id' => $designRequest->id,
            'status' => 'completed',
        ]);
    }

    private function createCharacter(
        string $name,
        string $iconPath = CharacterIconCatalog::DEFAULT_ICON,
    ): Character {
        $user = User::factory()->create();

        return Character::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'icon_path' => $iconPath,
            'explore_stamina' => 0,
        ]);
    }

    private function appointChamp(Character $character): void
    {
        ChampState::query()->firstOrFail()->forceFill([
            'character_id' => $character->id,
            'player_name' => $character->name,
            'icon_path' => $character->icon_path,
            'job_name' => '戦士',
            'job_rank' => 1,
            'level' => 1,
            'current_hp' => 100,
            'max_hp' => 100,
            'current_mp' => 10,
            'max_mp' => 10,
            'atk' => 10,
            'def' => 10,
            'mag' => 10,
            'spr' => 10,
            'spd' => 10,
            'luk' => 10,
            'appointed_at' => now(),
        ])->save();
    }
}
