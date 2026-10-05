<?php

namespace Tests\Feature;

use App\Http\Middleware\CommitExplorationRequest;
use App\Models\Character;
use App\Models\GameSetting;
use App\Models\User;
use App\Services\GameSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GameSettingExplorationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_commit_middleware_reuses_one_snapshot_and_replay_does_not_execute_operation(): void
    {
        [$character, $settings] = $this->fixture();
        $middleware = app(CommitExplorationRequest::class);
        $token = (string) Str::uuid();
        DB::enableQueryLog();
        $response = $middleware->handle($this->request($character, $token), function () use ($settings) {
            for ($i = 0; $i < 50; $i++) {
                $this->assertSame(1.0, app(GameSettingService::class)->getFloat('snapshot.integration', 0));
                if ($i === 10) {
                    DB::table('game_settings')->where('setting_key', 'snapshot.integration')->update(['value' => '2']);
                }
                $settings->withFreshSnapshot(fn () => $this->assertSame(1.0, $settings->getFloat('snapshot.integration', 0)));
            }

            return redirect('/snapshot-result');
        });
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(url('/snapshot-result'), $response->getTargetUrl());
        $this->assertCount(1, $this->settingReads($queries));
        $this->assertSame(0, $this->cacheQueries($queries));
        $this->assertDatabaseCount('exploration_requests', 1);

        $middleware->handle($this->request($character, $token), fn () => $this->fail('Replay executed exploration'));
        $middleware->handle($this->request($character, (string) Str::uuid()), function () use ($settings) {
            $this->assertSame(2.0, $settings->getFloat('snapshot.integration', 0));

            return redirect('/snapshot-result');
        });
        $this->assertDatabaseCount('exploration_requests', 2);
    }

    public function test_failed_request_discards_snapshot_and_preserves_retry(): void
    {
        [$character, $settings] = $this->fixture();
        $middleware = app(CommitExplorationRequest::class);
        $token = (string) Str::uuid();
        try {
            $middleware->handle($this->request($character, $token), function () use ($settings, $character) {
                $this->assertSame(1.0, $settings->getFloat('snapshot.integration', 0));
                $character->update(['money' => 123]);
                session(['snapshot.failed' => true]);
                throw new RuntimeException('failed request');
            });
            $this->fail('Expected failed request');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed request', $exception->getMessage());
        }
        $this->assertSame(0, (int) $character->fresh()->money);
        $this->assertFalse(session()->has('snapshot.failed'));
        $this->assertDatabaseCount('exploration_requests', 0);
        DB::table('game_settings')->where('setting_key', 'snapshot.integration')->update(['value' => '3']);
        $middleware->handle($this->request($character, $token), function () use ($settings) {
            $this->assertSame(3.0, $settings->getFloat('snapshot.integration', 0));

            return redirect('/snapshot-result');
        });
        $this->assertDatabaseCount('exploration_requests', 1);
    }

    private function fixture(): array
    {
        config(['cache.default' => 'database', 'cache.prefix' => 'snapshot-integration-']);
        Cache::purge('database');
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '設定スナップショット試験', 'money' => 0]);
        GameSetting::create(['setting_key' => 'snapshot.integration', 'label' => '試験設定', 'value' => '1', 'value_type' => 'float']);
        Cache::put('game_settings.all', ['snapshot.integration' => ['value' => '9']], 60);

        return [$character, app(GameSettingService::class)];
    }

    private function request(Character $character, string $token): Request
    {
        $request = Request::create('/battle/areas/1/explore', 'POST', ['exploration_request_id' => $token, 'batch_count' => 50]);
        $request->setLaravelSession(app('session')->driver());
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $character->user);
        session(['current_character_id' => $character->id]);

        return $request;
    }

    private function settingReads(array $queries): array
    {
        return array_filter($queries, fn ($query) => preg_match('/select .*from ["`]?game_settings["`]?/i', $query['query']));
    }

    private function cacheQueries(array $queries): int
    {
        return count(array_filter($queries, fn ($query) => preg_match('/(?:from|into|update)\s+["`]?cache["`]?\b/i', $query['query'])));
    }
}
