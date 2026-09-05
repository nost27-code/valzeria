<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckCharacterSelected;
use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterMaterial;
use App\Models\KisekiTransaction;
use App\Models\Material;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPersonalReward;
use App\Models\User;
use App\Services\CharacterNotificationService;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRankingService;
use App\Services\Nation\Raid\NationRaidRewardPolicy;
use App\Services\Nation\Raid\NationRaidRewardScreenService;
use App\Services\Nation\Raid\NationRaidRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NationRaidFixedRewardTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_policy_is_reusable_fixed_template_with_exact_totals(): void
    {
        [$event, $character] = $this->scenario();
        $this->assertSame(2, $event->reward_policy_snapshot['version']);
        $this->assertSame(5, $event->reward_policy_snapshot['participation_minimum_resolved_sorties']);
        $this->assertSame(15, $event->reward_policy_snapshot['minimum_resolved_sorties']);
        app(NationRaidEventService::class)->completeFinalization($event);
        $milestones = NationRaidPersonalReward::where('event_id', $event->id)->where('reward_key', 'like', 'milestone_%')->get();
        $this->assertCount(9, $milestones);
        $this->assertSame([10_000, 50_000, 100_000, 250_000, 500_000, 750_000, 1_000_000, 2_000_000, 5_000_000],
            array_column($event->reward_policy_snapshot['milestones'], 'damage'));
        $payloads = $milestones->pluck('reward_snapshot');
        $this->assertSame(27, $payloads->sum('free_kiseki'));
        $this->assertSame(5, $payloads->sum('bottles'));
        $this->assertSame(2, $payloads->sum('talismans'));
        $this->assertSame(['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007', 'MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007', 'MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], $payloads->pluck('fixed_material.code')->all());
        $this->assertSame([1, 1, 1, 2, 2, 2, 3, 3, 3], $payloads->pluck('fixed_material.quantity')->all());
        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('choices', $payload);
            $this->assertSame(3, $payload['free_kiseki']);
        }
        $this->assertSame(11, NationRaidPersonalReward::where('event_id', $event->id)->get()->sum(fn ($r) => $r->reward_snapshot['bottles'] ?? 0));
        $this->assertSame(32, NationRaidPersonalReward::where('event_id', $event->id)->get()->sum(fn ($r) => $r->reward_snapshot['free_kiseki'] ?? 0));
        $this->assertSame(0, CharacterConsumableItem::where('character_id', $character->id)->count());
        $this->assertSame(0, KisekiTransaction::count());
    }

    #[DataProvider('sortieCounts')]
    public function test_participation_uses_five_resolved_sorties_only_and_other_qualification_stays_fifteen(int $sorties): void
    {
        [$event, $character] = $this->scenario($sorties);
        // An extra refunded sortie must never count towards either threshold.
        $last = $event->battleResults()->latest('id')->first();
        $refund = $last->replicate(['battle_token']);
        $refund->fill(['battle_token' => bin2hex(random_bytes(32)), 'event_sortie_no' => 35,
            'raid_day' => 7, 'day_sortie_no' => 5, 'status' => 'refunded'])->save();
        $preview = $this->screen($event, $character);
        $this->assertSame($sorties, $preview['resolved_sorties']);
        $this->assertSame($sorties >= 5 ? 'awaiting' : 'unmet', collect($preview['rows'])->firstWhere('key', 'participation')['state']);
        $this->assertSame($sorties >= 15 ? 'awaiting' : 'unmet', collect($preview['rows'])->firstWhere('key', 'milestone_10000')['state']);
        app(NationRaidEventService::class)->completeFinalization($event);
        $this->assertSame($sorties >= 5 ? 1 : 0, NationRaidPersonalReward::where('event_id', $event->id)->where('reward_key', 'participation')->count());
        $this->assertSame($sorties >= 15 ? 9 : 0, NationRaidPersonalReward::where('event_id', $event->id)->where('reward_key', 'like', 'milestone_%')->count());
        $this->assertSame($sorties >= 15, app(NationRaidRankingService::class)->standings($event->fresh())['personal_total'][0]['qualified']);
        if ($sorties >= 5) {
            $claim = app(NationRaidRewardService::class)->claim($event, $character, $this->reward($event, 'participation')->id);
            $this->assertSame(1, $claim->reward_snapshot['bottles']);
            $this->assertSame(1, CharacterConsumableItem::where('character_id', $character->id)->value('quantity'));
        }
    }

    public static function sortieCounts(): array
    {
        return [[4], [5], [14], [15]];
    }

    public function test_reward_presentation_groups_icons_and_progress_without_unlocking_damage_only_goals(): void
    {
        [$event, $character] = $this->scenario(3);
        $event->update(['status' => 'active', 'stage10_reached_at' => null, 'completed_at' => null]);
        $event->battleResults()->oldest('id')->first()->update(['applied_damage_total' => 412_932]);
        // Display targets must come from the event's frozen policy, not today's template.
        config()->set('nation_raid_rewards.milestones.4.damage', 600_000);
        $response = $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class)
            ->get(route('nation-raid.rewards', $event))->assertOk();
        $screen = $response->viewData('rewardScreen');
        $rows = collect($screen['rows'])->keyBy('key');
        $this->assertSame(['participation', 'damage', 'server', 'honor'], array_keys($screen['groups']));
        $this->assertSame([1, 9, 2, 4], array_values(array_map(fn ($group) => count($group['rows']), $screen['groups'])));
        $this->assertSame('50万ダメージ', $screen['next_damage_goal']['display_label']);
        $this->assertSame(87_068, $screen['next_damage_goal']['remaining']);
        $this->assertSame(60, $rows['participation']['meter']['percent']);
        $this->assertSame(100, $rows['milestone_10000']['meter']['percent']);
        $this->assertSame(82, $rows['milestone_500000']['meter']['percent']);
        $this->assertSame('unmet', $rows['milestone_10000']['state']);
        $this->assertStringContainsString('出撃あと12回', $rows['milestone_10000']['progress']);
        $this->assertSame(['images/icon/icon_094.webp', 'images/icon/kiseki.webp'], array_column($rows['milestone_10000']['items'], 'icon'));
        $this->assertSame('images/icon/icon_097.webp', $rows['milestone_50000']['items'][0]['icon']);
        $this->assertSame('images/icon/icon_100.webp', $rows['milestone_100000']['items'][0]['icon']);
        foreach ($rows as $row) {
            $this->assertSame($row['contents'], array_column($row['items'], 'label'));
            foreach ($row['items'] as $item) {
                $this->assertFileExists(public_path($item['icon']));
            }
        }
        $response->assertSee('次のダメージ目標')->assertSee('あと87,068')->assertSee('3 / 5回')
            ->assertSee('有効出撃15回')->assertSee('data-raid-reward-group="honor"', false)
            ->assertSee('aria-valuenow="412932"', false)->assertDontSee('data-raid-claim-button', false);
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        $this->assertDatabaseCount('character_consumable_items', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);
        $this->assertSame('active', $event->fresh()->status);
        $this->assertSame(412_932, (int) $event->battleResults()->sum('applied_damage_total'));
    }

    public function test_reward_progress_is_clamped_and_next_damage_goal_disappears_when_all_targets_are_met(): void
    {
        [$event, $character] = $this->scenario();
        $event->battleResults()->oldest('id')->first()->update(['applied_damage_total' => 6_000_000]);
        $screen = $this->screen($event, $character);
        $this->assertNull($screen['next_damage_goal']);
        foreach ($screen['rows'] as $row) {
            if ($row['meter'] !== null) {
                $this->assertSame(100, $row['meter']['percent']);
                $this->assertSame($row['meter']['max'], $row['meter']['value']);
            }
        }
        $outsider = Character::create(['user_id' => User::factory()->create()->id, 'name' => '未参加冒険者']);
        $empty = $this->screen($event, $outsider);
        $this->assertSame(10_000, $empty['next_damage_goal']['remaining']);
        foreach ($empty['rows'] as $row) {
            $this->assertSame('unmet', $row['state']);
            if ($row['meter'] !== null) {
                $this->assertSame(0, $row['meter']['percent']);
                $this->assertSame(0, $row['meter']['value']);
            }
        }
    }

    public function test_all_milestone_boundaries_and_preview_match_final_rights_without_early_claim(): void
    {
        [$event, $character] = $this->scenario();
        $battle = $event->battleResults()->oldest('id')->first();
        foreach ($event->reward_policy_snapshot['milestones'] as $milestone) {
            $target = $milestone['damage'];
            $battle->update(['applied_damage_total' => $target - 1]);
            $this->assertSame('unmet', collect($this->screen($event, $character)['rows'])->firstWhere('key', 'milestone_'.$target)['state']);
            $battle->update(['applied_damage_total' => $target]);
            $this->assertSame('awaiting', collect($this->screen($event, $character)['rows'])->firstWhere('key', 'milestone_'.$target)['state']);
        }
        $preview = collect($this->screen($event, $character)['rows'])->keyBy('key');
        $this->assertDatabaseCount('nation_raid_personal_rewards', 0);
        config()->set('nation_raid_rewards.bottles.participation', 99);
        app(NationRaidEventService::class)->completeFinalization($event);
        $final = collect($this->screen($event->fresh(), $character)['rows'])->keyBy('key');
        $this->assertCount(16, $final);
        foreach (NationRaidPersonalReward::where('event_id', $event->id)->get() as $reward) {
            $this->assertSame($preview[$reward->reward_key]['contents'], $final[$reward->reward_key]['contents']);
            $this->assertSame('claimable', $final[$reward->reward_key]['state']);
        }
        $this->actingAs($character->user)->withoutMiddleware(CheckCharacterSelected::class)
            ->get(route('nation-raid.rewards', $event))->assertOk()->assertSee('入手')->assertDontSee('<select', false)
            ->assertSee('有効出撃5回')->assertSee('探索力の小瓶 ×1');
    }

    public function test_fixed_bundle_claim_is_atomic_replay_safe_and_rejects_selection(): void
    {
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $reward = $this->reward($event, 'milestone_1000000');
        try {
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id, 'enhance');
            $this->fail('Fixed reward accepted a choice.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('選択式ではありません', $exception->getMessage());
        }
        $this->assertSame(0, KisekiTransaction::count());
        $claim = app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        $replay = app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        $this->assertSame($claim->balance_after_snapshot, $replay->balance_after_snapshot);
        $this->assertSame(3, CharacterMaterial::where('character_id', $character->id)->sum('quantity'));
        $this->assertSame(['experience_talisman' => 1, 'explore_stamina_small_bottle' => 1],
            CharacterConsumableItem::where('character_id', $character->id)->orderBy('item_key')->pluck('quantity', 'item_key')->all());
        $this->assertSame([3, 7, 10], [(int) $character->fresh()->free_kiseki, (int) $character->fresh()->paid_kiseki, (int) $character->fresh()->kiseki]);
        $this->assertCount(2, $claim->balance_after_snapshot['consumables']);
        $this->assertDatabaseCount('kiseki_transactions', 1);
        $this->assertDatabaseHas('kiseki_transactions', ['source_id' => $reward->id, 'amount' => 3,
            'description' => '国家対抗レイド・1,000,000ダメージ到達報酬']);
    }

    public function test_receiving_all_fixed_milestones_preserves_exact_inventory_and_currency_totals(): void
    {
        [$event, $character] = $this->scenario();
        $character->update(['level' => 255]);
        app(NationRaidEventService::class)->completeFinalization($event);
        foreach (NationRaidPersonalReward::where('event_id', $event->id)->get() as $reward) {
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
        }
        $this->assertSame([6, 6, 6], CharacterMaterial::where('character_id', $character->id)->pluck('quantity')->sort()->values()->all());
        $this->assertSame(11, CharacterConsumableItem::where('character_id', $character->id)->where('item_key', 'explore_stamina_small_bottle')->value('quantity'));
        $this->assertSame(2, CharacterConsumableItem::where('character_id', $character->id)->where('item_key', 'experience_talisman')->value('quantity'));
        $this->assertSame(32, (int) $character->fresh()->free_kiseki);
        $this->assertSame(7, (int) $character->fresh()->paid_kiseki);
        $this->assertSame(39, (int) $character->fresh()->kiseki);
        $this->assertSame(10, KisekiTransaction::where('character_id', $character->id)->count());
        $this->assertSame(32, (int) KisekiTransaction::where('character_id', $character->id)->sum('amount'));
        $this->assertSame(0, NationRaidPersonalReward::where('event_id', $event->id)->where('status', 'pending')->count());
    }

    public function test_legacy_frozen_policy_remains_claimable_alongside_new_drafts(): void
    {
        $current = config('nation_raid_rewards');
        config()->set('nation_raid_rewards', require base_path('scripts/verify/fixtures/nation_raid_rewards_v1.php'));
        [$old, $oldCharacter] = $this->scenario();
        $oldHash = $old->reward_policy_hash;
        config()->set('nation_raid_rewards', $current);
        [$new] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($old);
        $this->assertSame($oldHash, $old->fresh()->reward_policy_hash);
        $this->assertSame(1, app(NationRaidRewardPolicy::class)->forEvent($old)['version']);
        $this->assertSame(2, app(NationRaidRewardPolicy::class)->forEvent($new)['version']);
        app(NationRaidRewardService::class)->claim($old, $oldCharacter, $this->reward($old, 'damage250k')->id, 'enhance');
        app(NationRaidRewardService::class)->claim($old, $oldCharacter, $this->reward($old, 'participation')->id);
        $this->assertSame(3, (int) CharacterMaterial::where('character_id', $oldCharacter->id)->sum('quantity'));
        $this->assertSame(3, CharacterConsumableItem::where('character_id', $oldCharacter->id)->value('quantity'));
    }

    #[DataProvider('invalidPolicyFields')]
    public function test_invalid_fixed_policy_fails_before_a_draft_is_created(string $field, mixed $value): void
    {
        config()->set('nation_raid_rewards.'.$field, $value);
        try {
            app(NationRaidEventService::class)->createDraft('invalid-fixed-policy', '不正報酬検証', now());
            $this->fail('Invalid fixed policy accepted.');
        } catch (\DomainException) {
            $this->assertDatabaseCount('nation_raid_events', 0);
        }
    }

    public static function invalidPolicyFields(): array
    {
        return [
            ['version', 3], ['participation_minimum_resolved_sorties', 0], ['participation_minimum_resolved_sorties', 16],
            ['milestones', []], ['milestones.1.damage', 10_000], ['milestones.1.damage', 5_000],
            ['milestones.0.damage', '10000'], ['milestones.0.fragment', 'unknown'], ['milestones.0.quantity', 0],
            ['milestones.0.bottles', -1], ['milestones.0.talismans', -1], ['milestones.0.free_kiseki', 0],
            ['milestones.0.quantity', '1'], ['milestones.0.free_kiseki', 3.0], ['milestones.0.bottles', '1'],
            ['milestones.0.talismans', true], ['fragment_quantities', ['damage250k' => 3]],
        ];
    }

    public function test_full_storage_or_late_notification_failure_keeps_entire_bundle_pending(): void
    {
        [$event, $character] = $this->scenario();
        app(NationRaidEventService::class)->completeFinalization($event);
        $reward = $this->reward($event, 'milestone_1000000');
        $stock = CharacterMaterial::create(['character_id' => $character->id,
            'material_id' => Material::where('material_code', 'MAT_ENHANCE_FRAGMENT')->sole()->id, 'quantity' => 499]);
        try {
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
            $this->fail('Full warehouse accepted material.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('倉庫', $exception->getMessage());
        }
        $this->assertSame(499, (int) $stock->fresh()->quantity);
        $stock->update(['quantity' => 0]);
        $this->instance(CharacterNotificationService::class, Mockery::mock(CharacterNotificationService::class)
            ->shouldReceive('create')->once()->andThrow(new \RuntimeException('late failure'))->getMock());
        try {
            app(NationRaidRewardService::class)->claim($event, $character, $reward->id);
            $this->fail('Injected failure did not occur.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('late failure', $exception->getMessage());
        }
        $this->assertSame('pending', $reward->fresh()->status);
        $this->assertSame(0, (int) $stock->fresh()->quantity);
        $this->assertSame(0, CharacterConsumableItem::where('character_id', $character->id)->count());
        $this->assertSame(0, (int) $character->fresh()->free_kiseki);
        $this->assertSame(7, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    private function scenario(int $sorties = 15): array
    {
        config()->set('features.nation_competitive_raid_enabled', true);
        foreach (['MAT_ENHANCE_FRAGMENT' => '強化石の欠片', '5007' => '守護石の欠片', 'ACC0007' => '調律石の欠片'] as $code => $name) {
            Material::firstOrCreate(['material_code' => (string) $code], ['name' => $name, 'category' => 'enhance']);
        }
        $character = Character::create(['user_id' => User::factory()->create()->id, 'name' => '固定報酬冒険者',
            'free_kiseki' => 0, 'paid_kiseki' => 7, 'kiseki' => 7]);
        $event = app(NationRaidEventService::class)->createDraft('fixed-'.bin2hex(random_bytes(8)), '固定報酬検証', now()->subDays(8));
        $event->update(['status' => 'finalizing', 'activated_at' => $event->starts_at,
            'stage10_reached_at' => now()->subDays(3), 'completed_at' => now()->subDays(2)]);
        DB::table('nation_raid_daily_lineage_snapshots')->where('event_id', $event->id)->update(['determined_at' => now()]);
        $participation = NationRaidParticipation::create(['event_id' => $event->id, 'account_id' => $character->user_id,
            'character_id' => $character->id, 'character_id_snapshot' => $character->id, 'is_nation_eligible' => false,
            'reference_active_count' => 0, 'character_name_snapshot' => $character->name]);
        foreach (range(1, $sorties) as $i) {
            NationRaidBattleResult::create(['event_id' => $event->id, 'participation_id' => $participation->id,
                'account_id' => $character->user_id, 'character_id' => $character->id,
                'battle_token' => bin2hex(random_bytes(32)), 'sortie_seed' => str_repeat('a', 64), 'status' => 'resolved',
                'raid_day' => intdiv($i - 1, 5) + 1, 'day_sortie_no' => ($i - 1) % 5 + 1, 'event_sortie_no' => $i,
                'target_cycle_no' => 1, 'target_cycle_kind' => 'main', 'target_stage_no' => 1, 'target_form' => 'sealed_scale',
                'target_parameter_snapshot' => [], 'boss_species_key' => 'dragon', 'strategy' => 'assault',
                'applied_damage_total' => $i === 1 ? 5_000_000 : 0, 'coordination_damage_total' => 0,
                'nation_damage_total' => 0, 'max_action_damage' => $i === 1 ? 1 : 0,
                'started_at' => $event->starts_at, 'resolved_at' => $event->starts_at,
                'resolution_deadline_at' => $event->starts_at->copy()->addMinutes(10)]);
        }

        return [$event->fresh(), $character];
    }

    private function screen(NationRaidEvent $event, Character $character): array
    {
        return app(NationRaidRewardScreenService::class)->build($event, $character,
            app(NationRaidRankingService::class)->standings($event), NationRaidPersonalReward::where('event_id', $event->id)->get());
    }

    private function reward(NationRaidEvent $event, string $key): NationRaidPersonalReward
    {
        return NationRaidPersonalReward::where('event_id', $event->id)->where('reward_key', $key)->sole();
    }
}
