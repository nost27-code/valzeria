<?php

namespace Tests\Feature;

use App\Livewire\CityHeader;
use App\Models\Character;
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
