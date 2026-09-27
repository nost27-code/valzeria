<?php

namespace Tests\Feature;

use App\Services\GameSettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GameSettingTransactionCacheTest extends TestCase
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
        config(['cache.default' => 'database', 'cache.prefix' => 'transaction-test-']);
        Cache::purge('database');
        DB::table('game_settings')->insert(['setting_key' => 'test.rate', 'value' => '1', 'value_type' => 'float']);
    }

    public function test_expired_database_cache_is_not_read_or_written_inside_a_gameplay_transaction(): void
    {
        Cache::put('game_settings.all', ['test.rate' => ['value' => '9']], 60);
        DB::table('cache')->update(['expiration' => time() - 60]);
        DB::beginTransaction();
        DB::enableQueryLog();
        $this->assertSame(1.0, app(GameSettingService::class)->getFloat('test.rate', 0));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::rollBack();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/(?:from|into|update)\s+["`]?cache["`]?\b/i', $query['query']);
        }
        $this->assertSame(1, DB::table('cache')->count());
        $this->assertLessThan(time(), DB::table('cache')->value('expiration'));
    }

    public function test_rolled_back_settings_never_escape_into_shared_cache(): void
    {
        DB::beginTransaction();
        DB::table('game_settings')->update(['value' => '2']);
        $this->assertSame(2.0, app(GameSettingService::class)->getFloat('test.rate', 0));
        $this->assertSame(0, DB::table('cache')->count());
        DB::rollBack();
        $this->assertSame(1.0, app(GameSettingService::class)->getFloat('test.rate', 0));
        $this->assertSame(1, DB::table('cache')->count());
        app(GameSettingService::class)->set('test.rate', '3');
        $this->assertSame(3.0, app(GameSettingService::class)->getFloat('test.rate', 0));
    }
}
