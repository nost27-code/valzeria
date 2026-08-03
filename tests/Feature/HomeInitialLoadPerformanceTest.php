<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Livewire\ChatLog;
use App\Models\Character;
use App\Models\ChampState;
use App\Models\User;
use App\Services\ChampBattleService;
use App\Services\GameSettingService;
use App\Services\SchemaStateService;
use App\Services\StorageCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HomeInitialLoadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_defers_noncritical_components_but_renders_cached_weekly_ranking_immediately(): void
    {
        $appLayout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $facilityLayout = file_get_contents(resource_path('views/components/layouts/facility.blade.php'));
        $mainTabs = file_get_contents(resource_path('views/livewire/main-screen-shell.blade.php'));
        $rankingPlaceholder = file_get_contents(resource_path('views/livewire/ranking-widget-placeholder.blade.php'));

        $this->assertIsString($appLayout);
        $this->assertIsString($facilityLayout);
        $this->assertIsString($mainTabs);
        $this->assertIsString($rankingPlaceholder);

        foreach (['home-action-panel', 'left-sidebar'] as $component) {
            $this->assertStringContainsString("<livewire:{$component} lazy.bundle=\"on-load\" />", $appLayout);
        }

        foreach (['champ-card', 'chat-log'] as $component) {
            $this->assertStringContainsString("<livewire:{$component} lazy=\"on-load\" />", $appLayout);
            $this->assertStringNotContainsString("<livewire:{$component} lazy.bundle=\"on-load\" />", $appLayout);
        }

        $this->assertStringContainsString('<livewire:star-tree-tower-ranking-widget />', $appLayout);
        $this->assertStringNotContainsString('<livewire:star-tree-tower-ranking-widget lazy=', $appLayout);
        $this->assertStringNotContainsString('<livewire:star-tree-tower-ranking-widget lazy.bundle="on-load" />', $appLayout);
        $this->assertStringContainsString('<livewire:chat-log lazy="on-load" />', $facilityLayout);
        $this->assertStringNotContainsString('<livewire:chat-log lazy.bundle="on-load" />', $facilityLayout);
        $this->assertStringNotContainsString('lazy=', $mainTabs);
        $this->assertStringContainsString('週間勝利', $rankingPlaceholder);
        $this->assertStringContainsString('闘技場', $rankingPlaceholder);
        $this->assertStringNotContainsString('星樹の塔', $rankingPlaceholder);
        $this->assertStringNotContainsString('読み込み中', $rankingPlaceholder);
        $this->assertStringNotContainsString('animate-pulse', $rankingPlaceholder);

        $rankingWidget = file_get_contents(resource_path('views/livewire/star-tree-tower-ranking-widget.blade.php'));
        $this->assertIsString($rankingWidget);
        $this->assertStringContainsString('wire:init="loadArenaEntries"', $rankingWidget);
        $this->assertStringContainsString('集計 {{ $weeklyWinData[\'updated_at_label\'] }}時点', $rankingWidget);
        $this->assertStringContainsString('wire:click="openWeeklyWinPlayerModal(0)"', $rankingWidget);
        $this->assertStringNotContainsString('wire:click="refreshWeeklyRanking"', $rankingWidget);
        $this->assertStringContainsString('週間番付を最新の集計に更新する', $rankingWidget);

        $champPosition = strpos($appLayout, '<livewire:champ-card');
        $rankingPosition = strpos($appLayout, '<livewire:star-tree-tower-ranking-widget');
        $facilityPosition = strpos($appLayout, 'data-main-content');

        $this->assertIsInt($champPosition);
        $this->assertIsInt($rankingPosition);
        $this->assertIsInt($facilityPosition);
        $this->assertLessThan($rankingPosition, $champPosition);
        $this->assertLessThan($facilityPosition, $rankingPosition);
    }

    public function test_schema_checks_are_reused_within_the_same_request(): void
    {
        $schemaQueries = 0;
        DB::listen(function ($query) use (&$schemaQueries): void {
            if (str_contains($query->sql, 'sqlite_master') && str_contains($query->sql, 'game_settings')) {
                $schemaQueries++;
            }
        });

        $schema = app(SchemaStateService::class);
        $this->assertTrue($schema->hasTable('game_settings'));
        $this->assertTrue($schema->hasTable('game_settings'));
        $this->assertTrue(app(SchemaStateService::class)->hasTable('game_settings'));

        Cache::forget('game_settings.all');
        app(GameSettingService::class)->getInt('exploration.stamina_max', 500);
        app(GameSettingService::class)->getBool('auth.registration_open', true);

        $this->assertSame(1, $schemaQueries);
    }

    public function test_column_listing_is_loaded_once_per_table(): void
    {
        $schemaQueries = 0;
        DB::listen(function ($query) use (&$schemaQueries): void {
            if (str_contains($query->sql, 'character_notifications')) {
                $schemaQueries++;
            }
        });

        $schema = app(SchemaStateService::class);
        $this->assertTrue($schema->hasColumn('character_notifications', 'category'));
        $firstLookupQueries = $schemaQueries;

        $this->assertTrue($schema->hasColumn('character_notifications', 'action_label'));
        $this->assertTrue($schema->hasColumns('character_notifications', ['priority', 'expires_at']));
        $this->assertSame($firstLookupQueries, $schemaQueries);
    }

    public function test_home_initial_response_skips_deferred_component_queries(): void
    {
        $user = User::factory()->create();
        $cityId = DB::table('cities')->value('id');
        $jobId = DB::table('job_classes')->value('id');
        $characterId = DB::table('characters')->insertGetId([
            'user_id' => $user->id,
            'name' => 'ホーム初期表示性能テスト',
            'current_city_id' => $cityId,
            'highest_city_id' => $cityId,
            'current_job_id' => $jobId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->withoutMiddleware(CheckCharacterSelected::class)
            ->actingAs($user)
            ->withSession(['current_character_id' => $characterId, 'current_location' => 'home'])
            ->get('/home?skip_resume=1');

        $response->assertOk();
        $response->assertSee('次やることを読み込み中');
        $response->assertSee('冒険者情報を読み込み中');
        $response->assertSee('チャットを読み込み中');
        $response->assertDontSee('週間番付を読み込み中');
        $this->assertLessThan(100, $queries);
    }

    public function test_storage_summary_counts_city_clear_bonus_only_once(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '倉庫集計性能テスト',
            'current_city_id' => DB::table('cities')->value('id'),
            'highest_city_id' => DB::table('cities')->value('id'),
            'current_job_id' => DB::table('job_classes')->value('id'),
        ]);

        $cityClearQueries = 0;
        DB::listen(function ($query) use (&$cityClearQueries): void {
            if (str_contains($query->sql, 'character_titles')) {
                $cityClearQueries++;
            }
        });

        app(StorageCapacityService::class)->summary($character);

        $this->assertSame(1, $cityClearQueries);
    }

    public function test_chat_reuses_the_character_resolved_during_mount(): void
    {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => 'チャット集計性能テスト',
            'current_city_id' => DB::table('cities')->value('id'),
            'highest_city_id' => DB::table('cities')->value('id'),
            'current_job_id' => DB::table('job_classes')->value('id'),
        ]);
        $this->actingAs($user);
        $this->app['session']->start();
        session(['current_character_id' => $character->id]);

        $currentCharacterQueries = 0;
        DB::listen(function ($query) use (&$currentCharacterQueries): void {
            if ((str_starts_with($query->sql, 'select * from "characters"')
                    || str_starts_with($query->sql, 'select * from `characters`'))
                && str_contains($query->sql, 'user_id')) {
                $currentCharacterQueries++;
            }
        });

        Livewire::test(ChatLog::class)
            ->assertSet('currentCharacterId', $character->id);

        $this->assertSame(1, $currentCharacterQueries);
    }

    public function test_champ_summary_reuses_the_loaded_champ_character_for_its_icon(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'チャンプ集計性能テスト',
            'icon_path' => '/images/chara/chara_002.webp',
            'current_city_id' => DB::table('cities')->value('id'),
            'highest_city_id' => DB::table('cities')->value('id'),
            'current_job_id' => DB::table('job_classes')->value('id'),
        ]);
        ChampState::query()->firstOrFail()->forceFill([
            'character_id' => $character->id,
            'player_name' => $character->name,
            'icon_path' => '/images/chara/chara_001.webp',
        ])->save();

        $champCharacterQueries = 0;
        DB::listen(function ($query) use (&$champCharacterQueries): void {
            if (preg_match('/^select (?:\*|[`"]icon_path[`"]) from [`"]characters[`"] /i', $query->sql) === 1) {
                $champCharacterQueries++;
            }
        });

        $summary = app(ChampBattleService::class)->summary($character);

        $this->assertTrue($summary['champ']->relationLoaded('character'));
        $this->assertSame('/images/chara/chara_002.webp', $summary['champ']->icon_path);
        $this->assertSame(1, $champCharacterQueries);
    }
}
