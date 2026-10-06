<?php

namespace Tests\Feature;

use App\Livewire\CityHeader;
use App\Models\Character;
use App\Models\NamelessWorkshopOperation;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class AdventurerCardAdventureRecordsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 28, 12, 0, 0, 'Asia/Tokyo'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_adventure_records_are_refreshed_after_ten_minutes(): void
    {
        $character = Character::query()->create([
            'user_id' => User::factory()->create()->id,
            'name' => '記録キャッシュ確認',
        ]);
        $recordsMethod = new ReflectionMethod(CityHeader::class, 'adventureRecords');
        $cityHeader = new CityHeader();

        $this->assertSame('0', $this->recordValue(
            $recordsMethod->invoke($cityHeader, $character),
            '戦闘回数'
        ));

        [$areaId, $enemyId] = $this->battleMasterIds();
        DB::table('battle_logs')->insert([
            'character_id' => $character->id,
            'area_id' => $areaId,
            'enemy_id' => $enemyId,
            'battle_type' => 'normal',
            'result' => 'win',
            'exp_gained' => 1,
            'gold_gained' => 0,
            'job_exp_gained' => 1,
            'level_up_count' => 0,
            'log_text' => '冒険の記録キャッシュ確認',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(now()->addMinutes(9)->addSeconds(59));
        $this->assertSame('0', $this->recordValue(
            $recordsMethod->invoke($cityHeader, $character),
            '戦闘回数'
        ));

        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertSame('1', $this->recordValue(
            $recordsMethod->invoke($cityHeader, $character),
            '戦闘回数'
        ));
    }


    public function test_card_combines_normal_and_old_ruin_battles_and_counts_encounter_bosses_when_off(): void
    {
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '遺跡記録確認']);
        [$area, $enemy] = $this->battleMasterIds();
        foreach (['win', 'lose'] as $result) {
            DB::table('battle_logs')->insert(['character_id' => $character->id, 'area_id' => $area, 'enemy_id' => $enemy,
                'battle_type' => 'normal', 'result' => $result, 'exp_gained' => 1, 'gold_gained' => 0, 'job_exp_gained' => 0,
                'level_up_count' => 0, 'log_text' => 'fixture', 'turn_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([
            ['result' => 'victory', 'turn_count' => 1, 'boss' => true, 'exp_gained' => 0],
            ['result' => 'timeout', 'turn_count' => 20, 'batch_explore' => ['runs' => [
                ['index' => 1, 'result' => 'victory', 'turn_count' => 1],
                ['index' => 2, 'result' => 'win', 'turn_count' => 2],
                ['index' => 3, 'result' => 'timeout', 'turn_count' => 20],
                ['index' => 4, 'result' => 'victory', 'turn_count' => 0],
            ]], 'rare_encounters' => [['index' => 2, 'kind' => 'cleared_boss'], ['index' => 2, 'kind' => 'cleared_boss']]],
        ] as $snapshot) {
            NamelessWorkshopOperation::query()->create(['character_id' => $character->id, 'request_uuid' => (string) Str::uuid(),
                'action' => 'ruin', 'payload_hash' => hash('sha256', 'fixture'), 'result' => $snapshot]);
        }
        NamelessWorkshopOperation::query()->create(['character_id' => $character->id, 'request_uuid' => (string) Str::uuid(),
            'action' => 'forge', 'payload_hash' => hash('sha256', 'forge'), 'result' => ['result' => 'victory', 'turn_count' => 1]]);
        config(['nameless_relics.enabled' => false]);
        $method = new ReflectionMethod(CityHeader::class, 'buildAdventureRecords');
        $rows = $method->invoke(new CityHeader(), $character);
        foreach (['戦闘回数' => '6', '勝利数' => '4', '敗北数' => '2', '勝率' => '66', 'ボス討伐数' => '2'] as $label => $expected) {
            $this->assertSame($expected, $this->recordValue($rows, $label));
        }
        $this->assertSame(0, (int) $character->fresh()->wins);
    }

    public function test_card_keeps_normal_records_when_ruin_schema_is_unavailable(): void
    {
        $character = Character::query()->create(['user_id' => User::factory()->create()->id, 'name' => '未準備記録確認']);
        [$area, $enemy] = $this->battleMasterIds();
        DB::table('battle_logs')->insert(['character_id' => $character->id, 'area_id' => $area, 'enemy_id' => $enemy,
            'battle_type' => 'normal', 'result' => 'win', 'exp_gained' => 1, 'gold_gained' => 0, 'job_exp_gained' => 0,
            'level_up_count' => 0, 'log_text' => 'fixture', 'created_at' => now(), 'updated_at' => now()]);
        Schema::shouldReceive('hasColumns')->once()->with('nameless_workshop_operations', ['id', 'character_id', 'action', 'result', 'created_at'])->andReturn(false);
        $rows = (new ReflectionMethod(CityHeader::class, 'buildAdventureRecords'))->invoke(new CityHeader(), $character);
        $this->assertSame('1', $this->recordValue($rows, '戦闘回数'));
        $this->assertSame('1', $this->recordValue($rows, '勝利数'));
    }

    /** @param array<int, array{label: string, value: string, unit: string}> $records */
    private function recordValue(array $records, string $label): string
    {
        return (string) collect($records)->firstWhere('label', $label)['value'];
    }

    /** @return array{int, int} */
    private function battleMasterIds(): array
    {
        $areaId = DB::table('areas')->insertGetId([
            'name' => '冒険記録キャッシュ確認地域',
            'slug' => 'adventure-record-cache-test-area',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enemyId = DB::table('enemies')->insertGetId([
            'area_id' => $areaId,
            'name' => '冒険記録キャッシュ確認敵',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $areaId, (int) $enemyId];
    }
}
