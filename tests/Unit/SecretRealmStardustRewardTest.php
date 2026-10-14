<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Enemy;
use App\Models\Material;
use App\Services\DropService;
use App\Services\ExplorationStateService;
use App\Services\ItemBookService;
use App\Services\SecretRealmService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class SecretRealmStardustRewardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'epic_reward_test', 'database.connections.epic_reward_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        Schema::create('materials', function (Blueprint $table): void {
            $table->id();
            $table->string('material_code')->unique();
            $table->string('name');
            $table->string('rarity')->nullable();
            $table->string('main_use')->nullable();
            $table->string('obtain_method')->nullable();
            $table->string('usage_summary')->nullable();
            $table->string('acquisition_summary')->nullable();
            $table->text('acquisition_tags')->nullable();
            $table->string('drop_timing')->nullable();
            $table->timestamps();
        });
        Schema::create('character_materials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('character_id');
            $table->unsignedInteger('material_id');
            $table->unsignedInteger('quantity');
            $table->unique(['character_id', 'material_id']);
            $table->timestamps();
        });
        Material::create(['material_code' => 'MAT_BR_WPN_HOLY_SECRET_SHARD', 'name' => '聖剣の秘境晶片']);
        Material::create(['material_code' => 'ACC0005', 'name' => '星屑の宝材', 'rarity' => 'SSSR']);
        $stateService = $this->mock(ExplorationStateService::class);
        $stateService->shouldReceive('recordMaterialLoot');
        $stateService->shouldReceive('invalidate');
    }

    protected function tearDown(): void
    {
        mt_srand();
        DB::purge('epic_reward_test');
        parent::tearDown();
    }

    public function test_late_city_lords_add_one_stardust_and_preserve_shard_rewards(): void
    {
        foreach ([8, 9, 10] as $cityId) {
            $reward = $this->rewardWithRoll($cityId, 5);
            $this->assertCount(1, array_filter($reward['drops'], fn (array $drop): bool => $drop['material_code'] === 'ACC0005'));
            $this->assertNotEmpty(array_filter($reward['drops'], fn (array $drop): bool => str_ends_with($drop['material_code'], '_SECRET_SHARD')));
            $this->assertContains('【秘境主報酬】星屑の宝材を 1 個手に入れた。', $reward['logs']);
        }
        $this->assertSame(3, $this->stardustQuantity());
    }

    public function test_roll_above_five_does_not_grant_stardust(): void
    {
        $reward = $this->rewardWithRoll(8, 6);
        $this->assertSame(0, $this->stardustQuantity());
        $this->assertNotEmpty($reward['drops']);
        $this->assertCount(1, $reward['logs']);
    }

    public function test_early_and_other_city_lords_do_not_grant_stardust(): void
    {
        foreach ([1, 7, 11] as $cityId) {
            $reward = $this->rewardWithRoll($cityId, 1);
            $this->assertNotEmpty($reward['drops']);
        }
        $this->assertSame(0, $this->stardustQuantity());
    }

    public function test_missing_stardust_master_preserves_shard_reward(): void
    {
        Material::where('material_code', 'ACC0005')->delete();
        $reward = $this->rewardWithRoll(8, 1);
        $this->assertNotEmpty($reward['drops']);
        $this->assertCount(1, $reward['logs']);
    }

    public function test_stardust_is_visible_and_pending_crests_stay_hidden(): void
    {
        $method = new ReflectionMethod(ItemBookService::class, 'isHiddenMaterial');
        $service = app(ItemBookService::class);
        $this->assertFalse($method->invoke($service, Material::where('material_code', 'ACC0005')->firstOrFail()));
        $this->assertTrue($method->invoke($service, new Material(['name' => '聖剣の極印'])));
        $this->assertTrue($method->invoke($service, new Material(['name' => '伝説の武器紋章'])));
    }

    public function test_master_update_is_repeatable_and_preserves_owned_stardust(): void
    {
        $this->rewardWithRoll(8, 1);
        $migration = require database_path('migrations/2026_10_14_130000_enable_stardust_secret_realm_lord_reward.php');
        $migration->up();
        $migration->up();
        $material = Material::where('material_code', 'ACC0005')->firstOrFail();
        $this->assertStringContainsString('5%', $material->obtain_method);
        $this->assertStringContainsString('SSS→EPIC', $material->main_use);
        $this->assertSame(1, $this->stardustQuantity());
        $migration->down();
        $this->assertSame(1, $this->stardustQuantity());
    }

    public function test_reward_failure_rolls_back_shards_and_stardust_together(): void
    {
        $stateService = $this->mock(ExplorationStateService::class);
        $stateService->shouldReceive('invalidate');
        $stateService->shouldReceive('recordMaterialLoot')
            ->andReturnUsing(function (Character $character, Enemy $enemy, Material $material): void {
                if ($material->material_code === 'ACC0005') {
                    throw new \RuntimeException('報酬記録失敗');
                }
            });

        try {
            DB::transaction(fn (): array => $this->rewardWithRoll(8, 1));
            $this->fail('報酬記録失敗が発生しませんでした。');
        } catch (\RuntimeException $exception) {
            $this->assertSame('報酬記録失敗', $exception->getMessage());
        }
        $this->assertSame(0, CharacterMaterial::count());
    }

    private function rewardWithRoll(int $cityId, int $roll): array
    {
        // 秘境晶片の個数抽選の後に来る、追加報酬の5%境界を固定する。
        for ($seed = 1; $seed <= 10000; $seed++) {
            mt_srand($seed);
            rand(1, 3);
            if (rand(1, 100) === $roll) {
                mt_srand($seed);
                $character = new Character();
                $character->id = 1;

                return app(SecretRealmService::class)->grantLordRewards(
                    $character,
                    new Area(['city_id' => $cityId]),
                    new Enemy(),
                    ['codes' => ['MAT_BR_WPN_HOLY_SECRET']],
                    app(DropService::class)
                );
            }
        }
        $this->fail('追加報酬抽選を固定するseedが見つかりません。');
    }

    private function stardustQuantity(): int
    {
        return (int) CharacterMaterial::where('character_id', 1)
            ->where('material_id', Material::where('material_code', 'ACC0005')->value('id'))
            ->value('quantity');
    }
}
