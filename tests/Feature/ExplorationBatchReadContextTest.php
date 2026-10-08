<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\PlayerNamelessEquipment;
use App\Models\PlayerRelic;
use App\Models\User;
use App\Services\CharacterStatusService;
use App\Services\ExplorationBatchReadContext;
use App\Services\NamelessWorkshopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ExplorationBatchReadContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_arrays_are_isolated_by_character_and_scope_and_writes_invalidate_only_dependencies(): void
    {
        $hero = $this->hero();
        $other = $this->hero();
        $context = app(ExplorationBatchReadContext::class);
        $calls = 0;
        $read = function () use (&$calls): array { return ['value' => ++$calls]; };
        $context->withLockedCharacter($hero, function () use ($context, $hero, $other, $read): void {
            $first = $context->remember($hero, 'owned', ['characters'], $read);
            $first['value'] = 999;
            $this->assertSame(['value' => 1], $context->remember($hero, 'owned', ['characters'], $read));
            $this->assertSame(['value' => 2], $context->remember($other, 'owned', ['characters'], $read));
            DB::table('users')->where('id', $hero->user_id)->update(['name' => 'Unrelated']);
            $this->assertSame(['value' => 1], $context->remember($hero, 'owned', ['characters'], $read));
            DB::table('characters')->where('id', $hero->id)->update(['money' => 7]);
            $this->assertSame(['value' => 3], $context->remember($hero, 'owned', ['characters'], $read));
        });
        $this->assertSame(['value' => 4], $context->remember($hero, 'owned', ['characters'], $read));
    }

    public function test_rollback_and_exception_discard_values_and_unknown_mutations_fail_conservatively(): void
    {
        $hero = $this->hero();
        $context = app(ExplorationBatchReadContext::class);
        $read = fn (): array => ['money' => (int) DB::table('characters')->where('id', $hero->id)->value('money')];
        try {
            $context->withLockedCharacter($hero, function () use ($context, $hero, $read): void {
                $before = $context->remember($hero, 'money', ['characters'], $read);
                DB::beginTransaction();
                DB::table('characters')->where('id', $hero->id)->update(['money' => 999]);
                $this->assertSame(999, $context->remember($hero, 'money', ['characters'], $read)['money']);
                DB::rollBack();
                $this->assertSame($before, $context->remember($hero, 'money', ['characters'], $read));
                $context->invalidateSql('/* compatibility path */ UPDATE characters SET money = ?');
                $this->assertSame($before, $context->remember($hero, 'money', ['characters'], $read));
                throw new RuntimeException('Synthetic failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic failure', $exception->getMessage());
        }
        $this->assertFalse($context->activeFor($hero));
        DB::table('characters')->where('id', $hero->id)->update(['money' => 33]);
        $this->assertSame(['money' => 33], $context->remember($hero, 'money', ['characters'], $read));
    }

    public function test_final_stats_share_unchanged_inputs_but_growth_unsaved_attributes_and_explicit_clear_stay_fresh(): void
    {
        config(['nameless_relics.enabled' => false]);
        $hero = $this->hero();
        $status = app(CharacterStatusService::class);
        app(ExplorationBatchReadContext::class)->withLockedCharacter($hero, function () use ($hero, $status): void {
            DB::enableQueryLog();
            $before = $status->getFinalStats($hero);
            DB::flushQueryLog();
            CharacterStatusService::clearRequestCache(discardBatchRead: false);
            $hero->current_hp = 1;
            $this->assertSame($before, $status->getFinalStats($hero));
            $this->assertCount(0, DB::getQueryLog());
            $hero->hp_base += 10;
            $this->assertSame($before['max_hp'] + 10, $status->getFinalStats($hero)['max_hp']);
            DB::flushQueryLog();
            CharacterStatusService::clearRequestCache((int) $hero->id);
            $status->getFinalStats($hero);
            $this->assertNotEmpty(DB::getQueryLog());
            DB::disableQueryLog();
        });
    }

    public function test_mutation_during_first_preparation_does_not_repopulate_a_stale_value(): void
    {
        $hero = $this->hero();
        $context = app(ExplorationBatchReadContext::class);
        $context->withLockedCharacter($hero, function () use ($context, $hero): void {
            $context->remember($hero, 'first-money', ['characters'], function () use ($hero): array {
                $before = ['money' => (int) $hero->money];
                DB::table('characters')->where('id', $hero->id)->update(['money' => 55]);

                return $before;
            });
            $this->assertSame(['money' => 55], $context->remember($hero, 'first-money', ['characters'],
                fn (): array => ['money' => (int) $hero->fresh()->money]));
        });
    }

    public function test_prefixed_parent_deletion_invalidates_derived_owned_values_for_foreign_key_cascades(): void
    {
        $hero = $this->hero();
        $context = app(ExplorationBatchReadContext::class);
        $context->withLockedCharacter($hero, function () use ($context, $hero): void {
            $owned = ['relics' => 1];
            $this->assertSame($owned, $context->remember($hero, 'cascade-owned', ['player_relics'], fn (): array => $owned));
            // The parent event must evict child values even without a child-table event.
            $context->invalidateSql('delete from "fixture_users" where id = ?', 'fixture_');
            $owned = ['relics' => 0];
            $this->assertSame($owned, $context->remember($hero, 'cascade-owned', ['player_relics'], fn (): array => $owned));
        });
    }

    public function test_ordinary_equipment_and_master_changes_are_reflected_in_the_next_calculation(): void
    {
        config(['nameless_relics.enabled' => false]);
        $hero = $this->hero();
        $item = \App\Models\Item::create(['name' => 'Batch equipment fixture', 'external_item_id' => 'BATCH_READ_TEST',
            'type' => 'weapon', 'rarity' => 'EPIC', 'weapon_rank' => 'EPIC', 'weapon_category' => 'sword',
            'is_active' => true, 'str_bonus' => 100]);
        $owned = \App\Models\CharacterItem::create(['character_id' => $hero->id, 'item_id' => $item->id,
            'is_equipped' => true, 'equipped_slot' => 'weapon', 'is_stored' => false]);
        $status = app(CharacterStatusService::class);
        app(ExplorationBatchReadContext::class)->withLockedCharacter($hero, function () use ($hero, $item, $owned, $status): void {
            $before = $status->getFinalStats($hero);
            $this->assertGreaterThan(0, $before['weapon_offense']['str']);
            DB::table('items')->where('id', $item->id)->update(['str_bonus' => 200]);
            $this->assertGreaterThan($before['weapon_offense']['str'], $status->getFinalStats($hero)['weapon_offense']['str']);
            DB::table('character_items')->where('id', $owned->id)->update(['is_equipped' => false]);
            $this->assertSame(0, $status->getFinalStats($hero)['weapon_offense']['str']);
        });
    }

    public function test_nameless_inputs_are_bulk_read_and_next_mutation_and_readiness_gate_are_fresh(): void
    {
        config(['nameless_relics.enabled' => true]);
        $hero = $this->hero();
        $weapon = PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'weapon', 'equipment_type' => '剣', 'is_equipped' => true]);
        PlayerNamelessEquipment::create(['character_id' => $hero->id, 'kind' => 'armor', 'equipment_type' => '鎧', 'is_equipped' => true]);
        $relic = PlayerRelic::create(['character_id' => $hero->id, 'effect_key' => 'stat_hp', 'rank' => 1, 'nameless_equipment_id' => $weapon->id, 'slot_number' => 1]);
        $workshop = app(NamelessWorkshopService::class);
        $status = app(CharacterStatusService::class);
        $expected = [$workshop->equippedWeaponOffense($hero), $workshop->equippedArmorDefense($hero), $workshop->equippedFixedBonuses($hero)];
        app(ExplorationBatchReadContext::class)->withLockedCharacter($hero, function () use ($hero, $weapon, $relic, $workshop, $status, $expected): void {
            DB::enableQueryLog();
            $this->assertSame($expected, [$workshop->equippedWeaponOffense($hero), $workshop->equippedArmorDefense($hero), $workshop->equippedFixedBonuses($hero)]);
            $bodyReads = array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select * from "player_nameless_equipments"'));
            $this->assertCount(1, $bodyReads);
            $before = $status->getFinalStats($hero);
            config(['nameless_relics.enabled' => false]);
            $this->assertLessThan($before['max_hp'], $status->getFinalStats($hero)['max_hp']);
            config(['nameless_relics.enabled' => true]);
            $this->assertSame($before, $status->getFinalStats($hero));
            DB::table('player_relics')->where('id', $relic->id)->update(['rank' => 2]);
            $this->assertGreaterThan($before['max_hp'], $status->getFinalStats($hero)['max_hp']);
            $weapon->update(['forge_level' => 10]);
            $this->assertNotSame($expected[0], $workshop->equippedWeaponOffense($hero));
            DB::table('migrations')->where('migration', \App\Services\NamelessPreparationService::MIGRATIONS[0])->delete();
            $this->assertFalse($workshop->ready());
            $this->assertSame(['str' => 0, 'mag' => 0], $workshop->equippedWeaponOffense($hero));
            DB::disableQueryLog();
        });
    }

    public function test_mark_threshold_and_current_job_updates_invalidate_final_stats_without_a_manual_clear(): void
    {
        config(['nameless_relics.enabled' => false]);
        $hero = $this->hero();
        $job = \App\Models\JobClass::query()->firstOrFail();
        $job->update(['bonus_hp' => 10]);
        $hero->update(['current_job_id' => $job->id]);
        $history = \App\Models\CharacterJob::create(['character_id' => $hero->id, 'job_class_id' => $job->id, 'job_level' => 1, 'job_exp' => 0]);
        $enemy = \App\Models\Enemy::create(['area_id' => \App\Models\Area::query()->firstOrFail()->id,
            'name' => 'Batch mark threshold fixture', 'is_boss' => false]);
        $mark = \App\Models\MonsterMark::create(['enemy_id' => $enemy->id, 'mark_name' => 'Batch threshold mark',
            'bonus_stat' => 'hp', 'bonus_per_level' => 10, 'max_level' => 4, 'is_active' => true]);
        $owned = \App\Models\CharacterMonsterMark::create(['character_id' => $hero->id, 'monster_mark_id' => $mark->id, 'quantity' => 14]);
        $status = app(CharacterStatusService::class);
        app(ExplorationBatchReadContext::class)->withLockedCharacter($hero, function () use ($hero, $history, $owned, $status): void {
            $before = $status->getFinalStats($hero);
            DB::table('character_monster_marks')->where('id', $owned->id)->update(['quantity' => 15]);
            $afterMark = $status->getFinalStats($hero);
            $this->assertGreaterThan($before['max_hp'], $afterMark['max_hp']);
            DB::table('character_jobs')->where('id', $history->id)->update(['job_level' => 2]);
            $this->assertSame($afterMark['max_hp'] + 5, $status->getFinalStats($hero)['max_hp']);
        });
    }

    private function hero(): Character
    {
        return Character::create(['user_id' => User::factory()->create()->id, 'name' => 'Batch read comparison',
            'hp_base' => 100, 'mp_base' => 20, 'current_hp' => 100, 'current_mp' => 20]);
    }
}
