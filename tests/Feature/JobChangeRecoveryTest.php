<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMonsterMark;
use App\Models\Enemy;
use App\Models\Item;
use App\Models\JobClass;
use App\Models\MonsterMark;
use App\Models\MonsterMarkRefinement;
use App\Models\User;
use App\Services\CharacterJobChangeService;
use App\Services\CharacterStatusService;
use App\Services\MonsterMarkAlchemyService;
use App\Services\PlayerLifecycleEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class JobChangeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_refined_hp_and_sp_survive_job_change_and_fully_recover_with_a_warm_cache(): void
    {
        [$character, $target, $owned] = $this->refinedCharacter();
        $status = app(CharacterStatusService::class);
        $before = $status->getFinalStats($character);
        $ledger = MonsterMarkRefinement::where('character_id', $character->id)->get()->toArray();
        $service = app(CharacterJobChangeService::class);

        $this->assertTrue($service->changeJob($character, $target), $service->getLastFailureMessage());
        $after = $status->getFinalStats($character->fresh());
        $this->assertLessThan($before['max_hp'], $after['max_hp']);
        $this->assertSame(500, (int) $character->hp_base);
        $this->assertSame(200, (int) $character->mp_base);
        $this->assertSame(10, $after['monster_mark_refinement_bonuses']['hp']);
        $this->assertSame(5, $after['monster_mark_refinement_bonuses']['mp']);
        $this->assertSame($after['max_hp'], (int) $character->current_hp);
        $this->assertSame($after['max_mp'], (int) $character->current_mp);
        $this->assertGreaterThan(510, $after['max_hp']);
        $this->assertGreaterThan(205, $after['max_mp']);
        $this->assertSame($ledger, MonsterMarkRefinement::where('character_id', $character->id)->get()->toArray());
        $this->assertSame(40, (int) $owned->fresh()->spent_quantity);
        $this->assertSame(55, (int) $owned->fresh()->quantity);
        CharacterStatusService::clearRequestCache((int) $character->id);
        $this->assertSame($after, $status->getFinalStats($character->fresh()));
    }

    public function test_recovery_uses_the_maximum_after_incompatible_equipment_is_removed(): void
    {
        [$character, $target] = $this->refinedCharacter();
        config(['equipment_proficiency.non_proficient.enabled' => false]);
        $armor = Item::create(['name' => '転職確認用鎧', 'type' => 'armor',
            'armor_category' => 'heavy_armor', 'hp_bonus' => 900, 'mp_bonus' => 900, 'is_active' => true]);
        $ownedArmor = CharacterItem::create(['character_id' => $character->id, 'item_id' => $armor->id,
            'is_equipped' => true, 'equipped_slot' => 'armor']);
        app(CharacterStatusService::class)->getFinalStats($character);
        $service = app(CharacterJobChangeService::class);

        $this->assertTrue($service->changeJob($character, $target), $service->getLastFailureMessage());
        $this->assertFalse($ownedArmor->fresh()->is_equipped);
        $this->assertCount(1, $service->getLastUnequipMessages());
        CharacterStatusService::clearRequestCache((int) $character->id);
        $stats = app(CharacterStatusService::class)->getFinalStats($character->fresh());
        $this->assertSame($stats['max_hp'], (int) $character->current_hp);
        $this->assertSame($stats['max_mp'], (int) $character->current_mp);
        $this->assertLessThan(900, $stats['max_hp']);
    }

    public function test_late_failure_rolls_back_job_change_recovery_and_clears_cached_stats(): void
    {
        [$character, $target, $owned] = $this->refinedCharacter();
        $before = $character->fresh()->getAttributes();
        $beforeStats = app(CharacterStatusService::class)->getFinalStats($character);
        $this->mock(PlayerLifecycleEventService::class)->shouldReceive('recordFirstJobChange')
            ->once()->andThrow(new RuntimeException('job change rollback test'));

        $this->assertFalse(app(CharacterJobChangeService::class)->changeJob($character, $target));
        $this->assertSame($before, $character->fresh()->getAttributes());
        $this->assertSame($beforeStats, app(CharacterStatusService::class)->getFinalStats($character->fresh()));
        $this->assertDatabaseMissing('job_change_logs', ['character_id' => $character->id]);
        $this->assertSame(2, MonsterMarkRefinement::where('character_id', $character->id)->count());
        $this->assertSame(40, (int) $owned->fresh()->spent_quantity);
    }

    private function refinedCharacter(): array
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-29T09:00:00+09:00'));
        $target = JobClass::create(['key' => 'recovery-test', 'name' => '回復確認職', 'rank' => 'normal',
            'bonus_hp' => 40, 'bonus_mp' => 20, 'is_hidden' => false, 'is_active' => true]);
        $character = Character::create([
            'user_id' => User::factory()->create()->id, 'name' => '転職回復確認',
            'level' => 30, 'exp' => 0, 'current_job_id' => null, 'bonus_points' => 0,
            'hp_base' => 1000, 'mp_base' => 400, 'attack_base' => 100, 'defense_base' => 100,
            'magic_base' => 100, 'spirit_base' => 100, 'speed_base' => 100, 'luck_base' => 100,
            'current_hp' => 1, 'current_mp' => 0, 'explore_stamina' => 0, 'reincarnation_count' => 0,
        ]);
        $enemy = Enemy::create(['area_id' => Area::query()->value('id'), 'name' => '回復確認の魔物',
            'is_boss' => false, 'role' => '通常']);
        $mark = MonsterMark::create(['enemy_id' => $enemy->id, 'mark_name' => '回復確認の印',
            'bonus_stat' => 'hp', 'bonus_per_level' => 1, 'is_active' => true]);
        $owned = CharacterMonsterMark::create(['character_id' => $character->id,
            'monster_mark_id' => $mark->id, 'quantity' => 55, 'spent_quantity' => 0, 'unlocked_level' => 4]);
        $alchemy = app(MonsterMarkAlchemyService::class);
        $alchemy->refine($character, 'hp', (string) Str::uuid());
        $alchemy->refine($character, 'mp', (string) Str::uuid());

        return [$character->fresh(), $target, $owned];
    }
}
