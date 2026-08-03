<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\User;
use App\Services\GameSettingService;
use App\Services\SchemaStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeInitialLoadPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_defers_noncritical_components_in_one_on_load_bundle(): void
    {
        $appLayout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));
        $facilityLayout = file_get_contents(resource_path('views/components/layouts/facility.blade.php'));
        $mainTabs = file_get_contents(resource_path('views/livewire/main-screen-shell.blade.php'));

        $this->assertIsString($appLayout);
        $this->assertIsString($facilityLayout);
        $this->assertIsString($mainTabs);

        foreach (['home-action-panel', 'left-sidebar', 'champ-card', 'star-tree-tower-ranking-widget', 'chat-log'] as $component) {
            $this->assertStringContainsString("<livewire:{$component} lazy.bundle=\"on-load\" />", $appLayout);
        }

        $this->assertStringContainsString('<livewire:chat-log lazy.bundle="on-load" />', $facilityLayout);
        $this->assertStringNotContainsString('lazy=', $mainTabs);
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
        $this->assertLessThan(100, $queries);
    }
}
