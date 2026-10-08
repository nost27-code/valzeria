<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\Enemy;
use App\Models\User;
use App\Services\Battle\BattleResult;
use App\Services\BattleService;
use App\Services\ExplorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExplorationBattleAreaReuseTest extends TestCase
{
    use RefreshDatabase;

    public static function areaCases(): array
    {
        return ['ordinary' => [false, false], 'boss' => [true, false], 'different source area' => [false, true]];
    }

    #[DataProvider('areaCases')]
    public function test_battle_reuses_only_its_current_area(bool $boss, bool $foreignSource): void
    {
        $cityId = Area::query()->firstOrFail()->city_id;
        $area = Area::create(['city_id' => $cityId, 'name' => 'Area read', 'slug' => 'area-read',
            'recommended_level_min' => 1, 'recommended_level_max' => 1]);
        $source = $area;
        if ($foreignSource) {
            $source = $area->replicate();
            $source->slug = 'foreign-source-area';
            $source->save();
        }
        $enemyAttributes = ['name' => 'Area read enemy', 'level' => 1, 'max_hp' => 100, 'str' => 1,
            'def' => 1, 'agi' => 1, 'mag' => 1, 'spr' => 1, 'luk' => 1, 'exp_reward' => 1,
            'gold_reward' => 1, 'job_exp_reward' => 1, 'appearance_weight' => 1, 'is_boss' => $boss];
        Enemy::create([...$enemyAttributes, 'area_id' => $area->id]);
        $foreign = $foreignSource ? Enemy::create([...$enemyAttributes, 'area_id' => $source->id]) : null;
        $hero = Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Area read',
            'hp_base' => 100, 'current_hp' => 100, 'current_city_id' => $cityId, 'highest_city_id' => $cityId]);
        $timeout = new BattleResult;
        $timeout->result = 'timeout';
        $timeout->turnCount = 50;
        $timeout->logs = ['Area read probe'];
        $battle = $this->mock(BattleService::class);
        $battle->shouldReceive('executeBattle')->once()->andReturnUsing(function ($character, Enemy $enemy) use ($area, $source, $foreignSource, $timeout) {
            $this->assertSame(! $foreignSource, $enemy->relationLoaded('area'));
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                for ($i = 0; $i < 6; $i++) {
                    $this->assertSame((int) $source->id, (int) $enemy->area->id);
                }
                $areaReads = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], '"areas"'));
            } finally {
                DB::disableQueryLog();
            }
            $this->assertCount($foreignSource ? 1 : 0, $areaReads);
            return $timeout;
        });
        $service = app(AreaReadProbeExplorationService::class);
        $service->foreignEnemy = $foreign;
        $result = $service->explore($hero, $area->id, $boss, null, true);
        $this->assertSame('timeout', $result['result']);
    }
}

class AreaReadProbeExplorationService extends ExplorationService
{
    public ?Enemy $foreignEnemy = null;

    protected function rollSpecialEvent(Character $character, Area $area, Enemy $baseEnemy, $state): ?array
    {
        return $this->foreignEnemy ? ['type' => 'golden_goblin', 'enemy' => $this->foreignEnemy] : null;
    }
}
