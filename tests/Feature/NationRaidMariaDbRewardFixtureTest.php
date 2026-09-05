<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Material;
use App\Models\NationRaidPersonalReward;
use App\Models\User;
use App\Services\Nation\Raid\NationRaidRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/verify/support/NationRaidPhase4MariaDbRewardScenarios.php';

/** Fixture/assertion smoke only. SQLite success is NOT evidence of MariaDB concurrency. */
final class NationRaidMariaDbRewardFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_mariadb_reward_fixture_and_assertions_work_against_the_current_schema(): void
    {
        config(['features.nation_competitive_raid_enabled' => true]);
        $fixtures = $this->fixtures();
        [$event, $character] = $fixtures->makeFixture(finalize: true);
        $this->assertSame('completed', $event->status);
        $this->assertSame(8, NationRaidPersonalReward::where('event_id', $event->id)->count());
        $this->assertSame(15, $event->battleResults()->count());
        $this->assertSame([5, 5, 5], $event->battleResults()->get()->groupBy('raid_day')->map->count()->values()->all());
        $reward = $fixtures->reward($event, 'completion');
        $service = app(NationRaidRewardService::class);
        $service->claim($event, $character, $reward->id);
        $first = $reward->fresh()->balance_after_snapshot;
        $service->claim($event, $character, $reward->id);
        $fixtures->assertClaim($character, $reward);
        $this->assertSame($first, $reward->fresh()->balance_after_snapshot);
    }

    public function test_different_event_fixture_preserves_character_balances_and_independent_inventory_rights(): void
    {
        config(['features.nation_competitive_raid_enabled' => true]);
        $fixtures = $this->fixtures();
        [$first, $character] = $fixtures->makeFixture(finalize: true);
        $service = app(NationRaidRewardService::class);
        $service->claim($first, $character, $fixtures->reward($first, 'completion')->id);
        [$second] = $fixtures->makeFixture($character, finalize: true);
        $this->assertSame(5, (int) $character->fresh()->free_kiseki);
        $service->claim($first, $character, $fixtures->reward($first, 'damage250k')->id, 'enhance');
        $service->claim($second, $character, $fixtures->reward($second, 'damage1m')->id, 'enhance');
        $this->assertSame(8, $fixtures->fragments($character));
    }

    public function test_capacity_fixture_preserves_the_second_right_and_allows_a_later_retry(): void
    {
        config(['features.nation_competitive_raid_enabled' => true]);
        $fixtures = $this->fixtures();
        [$event, $character] = $fixtures->makeFixture(finalize: true);
        $material = Material::where('material_code', 'MAT_ENHANCE_FRAGMENT')->sole();
        $stock = CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 495]);
        $service = app(NationRaidRewardService::class);
        $first = $fixtures->reward($event, 'damage250k');
        $second = $fixtures->reward($event, 'damage1m');
        $service->claim($event, $character, $first->id, 'enhance');
        try {
            $service->claim($event, $character, $second->id, 'enhance');
            $this->fail('The second reward must not overfill the 500-slot inventory.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('素材倉庫がいっぱいです', $exception->getMessage());
        }
        $this->assertSame(498, $fixtures->fragments($character));
        $this->assertSame('pending', $second->refresh()->status);
        $this->assertNull($second->selection_key);
        $this->assertNull($second->balance_after_snapshot);
        $this->assertNull($second->claimed_at);
        $this->assertSame(1, $character->notifications()->where('type', 'nation_raid_reward_claimed')->count());
        // Fixture-only inventory rearrangement; claim/replay still use the production service.
        $stock->decrement('quantity', 3);
        $service->claim($event, $character, $second->id, 'enhance');
        $service->claim($event, $character, $second->id, 'enhance');
        $this->assertSame(500, $fixtures->fragments($character));
        $this->assertSame('claimed', $second->refresh()->status);
        $this->assertSame(500, $second->balance_after_snapshot['material']['quantity']);
        $this->assertSame(2, $character->notifications()->where('type', 'nation_raid_reward_claimed')->count());
    }

    private function fixtures(): object
    {
        // Reuse just the synthetic fixture/assertions inside PHPUnit's disposable database.
        // Do not instantiate/bypass the CLI harness, its server preflight or worker launcher.
        return new class
        {
            use \NationRaidPhase4MariaDbRewardScenarios {
                rewardFixture as public makeFixture;
                fixtureReward as public reward;
                assertCompletionClaim as public assertClaim;
                assertFixedBundleClaim as public assertFixedClaim;
                fragmentCount as public fragments;
            }

            private function character(): Character
            {
                return Character::create(['user_id' => User::factory()->create()->id, 'name' => '検証冒険者'.bin2hex(random_bytes(4))]);
            }

            private function check(bool $condition, string $message): void
            {
                if (! $condition) {
                    throw new \RuntimeException($message);
                }
            }
        };
    }

    public function test_fixed_bundle_mariadb_fixture_retains_all_post_balances_and_replay(): void
    {
        config(['features.nation_competitive_raid_enabled' => true]);
        $fixtures = $this->fixtures();
        [$event, $character] = $fixtures->makeFixture(finalize: true, fixed: true);
        $this->assertSame(2, $event->reward_policy_snapshot['version']);
        $reward = $fixtures->reward($event, 'milestone_1000000');
        app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        $fixtures->assertFixedClaim($character, $reward);
        $this->assertSame('claimed', $reward->fresh()->status);
    }
}
