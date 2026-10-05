<?php

namespace Tests\Feature;

use App\Services\GameSettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GameSettingSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('game_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('value');
            $table->string('value_type');
            $table->timestamps();
        });
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
        config(['cache.default' => 'database', 'cache.prefix' => 'snapshot-test-']);
        Cache::purge('database');
        DB::table('game_settings')->insert([
            ['setting_key' => 'test.rate', 'value' => '1.25', 'value_type' => 'float'],
            ['setting_key' => 'test.mode', 'value' => 'stamina', 'value_type' => 'string'],
            ['setting_key' => 'test.enabled', 'value' => 'true', 'value_type' => 'bool'],
        ]);
    }

    public function test_batch_uses_one_fresh_read_and_never_accesses_shared_database_cache(): void
    {
        Cache::put('game_settings.all', ['test.rate' => ['value' => '9']], 60);
        DB::table('cache')->update(['expiration' => time() - 60]);
        DB::beginTransaction();
        DB::enableQueryLog();
        app(GameSettingService::class)->withFreshSnapshot(function () {
            for ($i = 0; $i < 50; $i++) {
                $settings = app(GameSettingService::class);
                $this->assertSame(1.25, $settings->getFloat('test.rate', 0));
                $this->assertSame(1, $settings->getInt('test.rate', 0));
                $this->assertSame('stamina', $settings->getString('test.mode', 'cooldown'));
                $this->assertTrue($settings->getBool('test.enabled', false));
                $this->assertSame(7, $settings->getInt('missing', 7));
            }
        });
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::rollBack();
        $reads = array_filter($queries, fn ($query) => preg_match('/select .*from ["`]?game_settings["`]?/i', $query['query']));
        $this->assertCount(1, $reads);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|into|update)\s+["`]?cache["`]?\b/i', $query['query']);
        }
        $this->assertSame(1, DB::table('cache')->count());
        $this->assertLessThan(time(), DB::table('cache')->value('expiration'));
    }

    public function test_next_operation_reads_changed_db_settings_even_when_shared_cache_is_stale(): void
    {
        $settings = app(GameSettingService::class);
        Cache::put('game_settings.all', ['test.rate' => ['value' => '9']], 60);
        $this->assertSame(9.0, $settings->getFloat('test.rate', 0));
        $settings->withFreshSnapshot(function () use ($settings) {
            $this->assertSame(1.25, $settings->getFloat('test.rate', 0));
            DB::table('game_settings')->where('setting_key', 'test.rate')->update(['value' => '2']);
            $this->assertSame(1.25, app(GameSettingService::class)->getFloat('test.rate', 0));
        });
        $settings->withFreshSnapshot(fn () => $this->assertSame(2.0, $settings->getFloat('test.rate', 0)));
        $this->assertSame(9.0, $settings->getFloat('test.rate', 0));
    }

    public function test_exception_discards_uncommitted_snapshot(): void
    {
        $settings = app(GameSettingService::class);
        try {
            $settings->withFreshSnapshot(fn () => DB::transaction(function () use ($settings) {
                DB::table('game_settings')->where('setting_key', 'test.rate')->update(['value' => '2']);
                $this->assertSame(2.0, $settings->getFloat('test.rate', 0));
                throw new RuntimeException('abort batch');
            }));
            $this->fail('Expected aborted batch');
        } catch (RuntimeException $exception) {
            $this->assertSame('abort batch', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('cache')->count());
        $settings->withFreshSnapshot(fn () => $this->assertSame(1.25, $settings->getFloat('test.rate', 0)));
        $this->assertSame(1.25, $settings->getFloat('test.rate', 0));
    }

    public function test_nested_operations_share_snapshot_and_explicit_settings_changes_invalidate_it(): void
    {
        $settings = app(GameSettingService::class);
        $settings->withFreshSnapshot(function () use ($settings) {
            $this->assertSame(1.25, $settings->getFloat('test.rate', 0));
            $settings->withFreshSnapshot(function () use ($settings) {
                $this->assertSame(1.25, $settings->getFloat('test.rate', 0));
            });
            DB::table('game_settings')->where('setting_key', 'test.rate')->update(['value' => '2']);
            $this->assertSame(1.25, $settings->getFloat('test.rate', 0));
            app(GameSettingService::class)->flush();
            $this->assertSame(2.0, $settings->getFloat('test.rate', 0));
            app(GameSettingService::class)->set('test.rate', '3');
            $this->assertSame(3.0, $settings->getFloat('test.rate', 0));
        });
    }

    public function test_empty_settings_are_reused_and_scoped_instances_do_not_survive_new_lifecycle(): void
    {
        DB::table('game_settings')->delete();
        $settings = app(GameSettingService::class);
        DB::enableQueryLog();
        $settings->withFreshSnapshot(function () use ($settings) {
            $this->assertSame([], $settings->all());
            $this->assertSame([], $settings->all());
        });
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn ($query) => preg_match('/select .*from ["`]?game_settings["`]?/i', $query['query'])));
        $this->app->forgetScopedInstances();
        $this->assertNotSame($settings, app(GameSettingService::class));
    }
}
