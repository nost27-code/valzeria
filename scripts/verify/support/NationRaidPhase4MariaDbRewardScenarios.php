<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterMaterial;
use App\Models\CharacterNotification;
use App\Models\KisekiTransaction;
use App\Models\Material;
use App\Models\Nation;
use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use App\Models\NationRaidNationReward;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPersonalReward;
use App\Services\Nation\Raid\NationRaidEventService;
use App\Services\Nation\Raid\NationRaidRewardService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Only composed into the preflight-guarded CLI harness, never an application service. */
trait NationRaidPhase4MariaDbRewardScenarios
{
    private function rewardFinalization(): array
    {
        [$this->event, $character, $nation] = $this->rewardFixture();
        $rows = $this->race([['op' => 'reward_finalize'], ['op' => 'reward_finalize']]);
        $this->outcomes($rows, ['completed', 'completed']);
        $this->check(count(array_unique(array_column($rows, 'standings_hash'))) === 1, 'Finalization returned different ranks.');
        $this->check(NationRaidPersonalReward::where('event_id', $this->event->id)->count() === 8, 'Personal entitlements duplicated/lost.');
        $this->check(NationRaidNationReward::where('event_id', $this->event->id)->where('status', 'claimed')->count() === 3,
            'Nation rewards duplicated/lost.');
        $this->check((int) $nation->refresh()->treasury_points === 1000 && (int) $nation->development_exp === 0,
            'Nation points or development EXP changed more than once.');
        $this->check(DB::table('nation_resource_transactions')->where('nation_id', $nation->id)->where('transaction_type', 'raid_reward')->count() === 1,
            'Nation reward ledger duplicated.');
        $this->check(DB::table('nation_activity_logs')->where('nation_id', $nation->id)->where('event_type', 'raid_reward')->count() === 3,
            'Nation reward chronology duplicated.');
        $this->check(DB::table('nation_achievements')->where('nation_id', $nation->id)->count() === 1, 'Nation achievement duplicated.');
        $this->check($this->rewardNotificationCount($character, 'nation_raid_rewards_ready') === 1, 'Ready notification duplicated.');
        $this->check(KisekiTransaction::where('character_id', $character->id)->count() === 0
            && CharacterConsumableItem::where('character_id', $character->id)->count() === 0, 'Finalization granted personal assets early.');
        $snapshot = $this->event->refresh()->final_standings_snapshot;
        app(NationRaidEventService::class)->completeFinalization($this->event);
        $this->check($snapshot === $this->event->refresh()->final_standings_snapshot, 'Finalization replay changed the frozen result.');

        return ['workers' => $rows, 'personal_rights' => 8, 'nation_rights' => 3,
            'nation_points' => 1000, 'nation_ledger_rows' => 1, 'ready_notifications' => 1,
            'reward_policy_hash' => $this->event->reward_policy_hash];
    }

    private function sameRewardClaim(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true);
        $reward = $this->fixtureReward($this->event, 'completion');
        $job = $this->claimJob($reward, $character);
        $rows = $this->race([$job, $job]);
        $this->outcomes($rows, ['claimed', 'claimed']);
        $this->check($rows[0]['balance'] === $rows[1]['balance'], 'Duplicate claim returned different balances.');
        $this->assertCompletionClaim($character, $reward);
        // A response lost after commit must remain replayable without another grant.
        $replay = app(NationRaidRewardService::class)->claim($this->event, $character, $reward->id);
        $this->check($replay->balance_after_snapshot === $rows[0]['balance'], 'Claim replay did not return its original balance.');
        $this->assertCompletionClaim($character, $reward);

        return ['workers' => $rows, 'bottles' => 2, 'free_kiseki' => 5, 'paid_kiseki' => 7, 'ledger_rows' => 1, 'claimed_notifications' => 1];
    }

    private function competingRewardChoices(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true);
        $reward = $this->fixtureReward($this->event, 'damage1m');
        $rows = $this->race([
            $this->claimJob($reward, $character, 'enhance'),
            $this->claimJob($reward, $character, 'talisman'),
        ]);
        $this->outcomes($rows, ['claimed', 'choice_conflict']);
        $reward->refresh();
        $enhance = $reward->selection_key === 'enhance';
        $this->check(in_array($reward->selection_key, ['enhance', 'talisman'], true), 'No choice won the race.');
        $this->check($this->fragmentCount($character) === ($enhance ? 5 : 0), 'Losing fragment choice was granted.');
        $this->check($this->consumableCount($character, 'experience_talisman') === ($enhance ? 0 : 1), 'Losing talisman choice was granted.');
        $this->check($this->rewardNotificationCount($character, 'nation_raid_reward_claimed') === 1, 'Choice claim notified twice.');

        return ['workers' => $rows, 'winning_selection' => $reward->selection_key, 'exactly_one_choice_granted' => true];
    }

    private function sameFixedBundleClaim(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true, fixed: true);
        $reward = $this->fixtureReward($this->event, 'milestone_1000000');
        $job = $this->claimJob($reward, $character);
        $rows = $this->race([$job, $job]);
        $this->outcomes($rows, ['claimed', 'claimed']);
        $this->check($rows[0]['balance'] === $rows[1]['balance'], 'Fixed bundle replay returned different balances.');
        $this->assertFixedBundleClaim($character, $reward);
        app(NationRaidRewardService::class)->claim($this->event, $character, $reward->id);
        $this->assertFixedBundleClaim($character, $reward);

        return ['workers' => $rows, 'fragments' => 3, 'bottles' => 1, 'talismans' => 1,
            'free_kiseki' => 3, 'paid_kiseki' => 7, 'ledger_rows' => 1, 'claimed_notifications' => 1];
    }

    private function assertFixedBundleClaim(Character $character, NationRaidPersonalReward $reward): void
    {
        $character->refresh();
        $reward->refresh();
        $this->check($this->fragmentCount($character) === 3, 'Fixed bundle duplicated/lost fragments.');
        $this->check($this->consumableCount($character, 'explore_stamina_small_bottle') === 1
            && $this->consumableCount($character, 'experience_talisman') === 1, 'Fixed bundle duplicated/lost consumables.');
        $this->check([(int) $character->free_kiseki, (int) $character->paid_kiseki, (int) $character->kiseki] === [3, 7, 10],
            'Fixed bundle changed currency more than once.');
        $ledger = KisekiTransaction::where('source_type', 'nation_raid_personal_reward')->where('source_id', $reward->id)->sole();
        $this->check($ledger->amount === 3 && $ledger->kiseki_type === 'free', 'Fixed bundle ledger is incorrect.');
        $balance = $reward->balance_after_snapshot;
        $this->check($reward->status === 'claimed' && $reward->selection_key === null
            && $balance['material']['quantity'] === 3 && $balance['kiseki']['transaction_id'] === $ledger->id
            && count($balance['consumables']) === 2, 'Fixed bundle post-balances are incomplete.');
        $this->check($this->rewardNotificationCount($character, 'nation_raid_reward_claimed') === 1,
            'Fixed bundle claim notification duplicated/lost.');
    }

    private function differentRewardClaims(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true);
        [$other] = $this->rewardFixture($character, finalize: true);
        $first = $this->fixtureReward($this->event, 'damage250k');
        $second = $this->fixtureReward($other, 'damage1m');
        // Force the second transaction to reach its owner read before the first commits.
        // A common start barrier alone can accidentally run both claims sequentially.
        $rows = $this->inventoryClaimRace($first, $second, $character);
        $this->outcomes($rows, ['claimed', 'claimed']);
        $this->check($this->fragmentCount($character) === 8, 'Concurrent independent rights lost or duplicated inventory.');
        $this->check(CharacterMaterial::where('character_id', $character->id)->count() === 1, 'Concurrent first insert duplicated an inventory row.');
        $this->check($first->refresh()->status === 'claimed' && $second->refresh()->status === 'claimed', 'An independent right was lost.');
        $this->check($this->rewardNotificationCount($character, 'nation_raid_reward_claimed') === 2, 'Independent claim notifications differ.');
        $balances = array_map(fn ($row) => $row['balance']['material']['quantity'], $rows);
        sort($balances);
        $this->check($balances === [3, 8], 'Inventory balance snapshots are not serializable.');

        return ['workers' => $rows, 'material_quantity' => 8, 'inventory_rows' => 1];
    }

    private function capacityAfterOwnerLock(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true);
        $first = $this->fixtureReward($this->event, 'damage250k');
        $second = $this->fixtureReward($this->event, 'damage1m');
        $material = Material::where('material_code', 'MAT_ENHANCE_FRAGMENT')->sole();
        CharacterMaterial::create(['character_id' => $character->id, 'material_id' => $material->id, 'quantity' => 495]);
        $this->check(app(\App\Services\StorageCapacityService::class)->summary($character)['material_limit'] === 500,
            'Capacity regression requires an exact 500-slot fixture.');
        $rows = $this->inventoryClaimRace($first, $second, $character);
        $this->outcomes($rows, ['claimed', 'storage_full']);
        $this->check($this->fragmentCount($character) === 498, 'Stale RR read view overfilled inventory (495 + 3 + 5).');
        $this->check($first->refresh()->status === 'claimed' && $first->balance_after_snapshot['material']['quantity'] === 498,
            'First capacity claim lost its committed balance.');
        $this->check($second->refresh()->status === 'pending' && $second->selection_key === null
            && $second->claimed_at === null && $second->balance_after_snapshot === null, 'Full storage consumed the second right.');
        $this->check($this->rewardNotificationCount($character, 'nation_raid_reward_claimed') === 1,
            'Rejected capacity claim emitted a notification.');

        return ['workers' => $rows, 'initial_quantity' => 495, 'material_limit' => 500,
            'material_quantity' => 498, 'second_right_preserved' => true];
    }

    private function inventoryClaimRace(NationRaidPersonalReward $first, NationRaidPersonalReward $second, Character $character): array
    {
        $rows = $this->race([
            [...$this->claimJob($first, $character, 'enhance'), 'op' => 'reward_claim_hold_inventory'],
            [...$this->claimJob($second, $character, 'enhance'), 'op' => 'reward_claim_after_inventory_staged'],
        ]);
        $this->check($rows[1]['owner_read_barrier_reached'] ?? false, 'Second claim missed the owner-read barrier.');

        return $rows;
    }

    private function rewardLockTimeout(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true);
        $reward = $this->fixtureReward($this->event, 'completion');
        $job = [...$this->claimJob($reward, $character), 'op' => 'reward_claim_timeout'];
        DB::beginTransaction();
        try {
            Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $rows = $this->race([$job, $job]);
            $this->outcomes($rows, ['lock_timeout', 'lock_timeout']);
            foreach ($rows as $row) {
                $this->check($row['database_error'] === 1205 && $row['transaction_level'] === 0 && $row['timeout_restored'],
                    'Real reward lock timeout did not roll back and restore the worker connection.');
                $this->check($row['attempts'] === [1, 2, 3] && $row['timeouts'] === [3, 3, 3]
                    && $row['wait_levels'] === [0, 0], 'Reward did not use the production retry/session policy.');
            }
        } finally {
            DB::rollBack();
        }
        $this->check($reward->refresh()->status === 'pending' && $reward->balance_after_snapshot === null && $reward->claimed_at === null,
            'Lock timeout consumed the reward right.');
        $this->check($this->consumableCount($character, 'explore_stamina_small_bottle') === 0
            && (int) $character->refresh()->free_kiseki === 0
            && KisekiTransaction::where('character_id', $character->id)->count() === 0
            && $this->rewardNotificationCount($character, 'nation_raid_reward_claimed') === 0, 'Lock timeout partially granted assets.');
        $normal = $this->claimJob($reward, $character);
        $this->outcomes($this->race([$normal, $normal]), ['claimed', 'claimed']);
        $this->assertCompletionClaim($character, $reward);

        return ['workers' => $rows, 'production_lock_wait_seconds' => 3, 'right_preserved' => true, 'retry_granted_once' => true];
    }

    private function independentRewardOwners(): array
    {
        [$this->event, $first] = $this->rewardFixture(finalize: true);
        $firstReward = $this->fixtureReward($this->event, 'participation');
        $second = $this->character();
        $secondReward = NationRaidPersonalReward::create(['event_id' => $this->event->id,
            'character_id' => $second->id, 'account_id_snapshot' => $second->user_id, 'character_id_snapshot' => $second->id,
            'reward_key' => 'participation', 'status' => 'pending', 'idempotency_key' => bin2hex(random_bytes(32)),
            'reward_snapshot' => $firstReward->reward_snapshot]);
        $rows = $this->race([
            [...$this->claimJob($firstReward, $first), 'op' => 'reward_claim_hold_owner'],
            [...$this->claimJob($secondReward, $second), 'op' => 'reward_claim_after_peer_lock'],
        ]);
        $this->outcomes($rows, ['claimed', 'claimed']);
        foreach ([$first, $second] as $character) {
            $this->check($this->consumableCount($character, 'explore_stamina_small_bottle') === 3, 'Independent owner lost reward.');
        }

        return ['workers' => $rows, 'second_claim_completed_while_first_held_owner_lock' => true];
    }

    private function rewardRollbackRace(): array
    {
        [$this->event, $character] = $this->rewardFixture(finalize: true);
        $reward = $this->fixtureReward($this->event, 'completion');
        $job = $this->claimJob($reward, $character);
        $rows = $this->race([
            [...$job, 'op' => 'reward_claim_fail_notification'],
            [...$job, 'op' => 'reward_claim_after_failure'],
        ]);
        $this->outcomes($rows, ['injected_rollback', 'claimed']);
        $this->assertCompletionClaim($character, $reward);

        return ['workers' => $rows, 'injection' => 'Application notification failure after inventory/ledger/right writes; not a mocked SQL error.',
            'rollback_then_retry_granted_once' => true];
    }

    private function rewardWorker(array $job, string $directory): array
    {
        if ($job['op'] === 'reward_finalize') {
            $event = app(NationRaidEventService::class)->completeFinalization($this->event);

            return ['outcome' => $event->status, 'standings_hash' => $event->final_standings_hash];
        }
        $dispatcher = CharacterNotification::getEventDispatcher();
        $observed = new NationRaidPhase4ObservedTransactions;
        $ownerBarrierActive = false;
        $ownerBarrierReached = false;
        if ($job['op'] === 'reward_claim_timeout') {
            app()->instance(\App\Services\Nation\Raid\NationRaidTransactionRunner::class, $observed);
        }
        try {
            if ($job['op'] === 'reward_claim_hold_inventory') {
                CharacterNotification::setEventDispatcher(clone $dispatcher);
                CharacterNotification::creating(function (CharacterNotification $notification) use ($directory): void {
                    if ($notification->type === 'nation_raid_reward_claimed') {
                        $this->check(DB::transactionLevel() === 1, 'Inventory barrier must be inside the reward transaction.');
                        touch($directory.'/reward-inventory-staged');
                        $this->wait(fn () => is_file($directory.'/reward-owner-read-requested'), 15, 'Peer did not reach its owner read.');
                    }
                });
            }
            if ($job['op'] === 'reward_claim_after_inventory_staged') {
                $this->wait(fn () => is_file($directory.'/reward-inventory-staged'), 15, 'First claim did not stage its inventory.');
                $ownerBarrierActive = true;
                // Before-query hook adds no DB reads and hence cannot create an artificial RR snapshot.
                // This disposable CLI worker owns the connection; finally disables the hook before exit.
                DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use ($job, $directory, &$ownerBarrierActive, &$ownerBarrierReached): void {
                    if ($ownerBarrierActive && $connection->transactionLevel() === 1
                        && preg_match('/^select\b.*\bfrom `characters`(?:\s|$)/i', $sql)
                        && (int) ($bindings[0] ?? 0) === (int) $job['character']) {
                        $this->check(str_ends_with(strtolower($sql), 'for update'), 'First owner read must take an exclusive row lock.');
                        $ownerBarrierActive = false;
                        $ownerBarrierReached = true;
                        touch($directory.'/reward-owner-read-requested');
                    }
                });
            }
            if ($job['op'] === 'reward_claim_after_peer_lock') {
                $this->wait(fn () => is_file($directory.'/reward-owner-held'), 20, 'Owner lock did not arrive.');
            }
            if ($job['op'] === 'reward_claim_hold_owner') {
                CharacterNotification::setEventDispatcher(clone $dispatcher);
                CharacterNotification::creating(function ($notification) use ($directory): void {
                    if ($notification->type === 'nation_raid_reward_claimed') {
                        touch($directory.'/reward-owner-held');
                        $this->wait(fn () => is_file($directory.'/reward-other-owner-completed'), 20, 'Different owners still serialize on the event lock.');
                    }
                });
            }
            if ($job['op'] === 'reward_claim_after_failure') {
                $this->wait(fn () => is_file($directory.'/reward-writes-staged'), 15, 'Failed claim did not stage its asset writes.');
                touch($directory.'/reward-peer-started');
            }
            if ($job['op'] === 'reward_claim_fail_notification') {
                CharacterNotification::setEventDispatcher(clone $dispatcher);
                CharacterNotification::creating(function (CharacterNotification $notification) use ($directory): void {
                    if ($notification->type === 'nation_raid_reward_claimed') {
                        $this->check(DB::transactionLevel() === 1, 'Injected failure must be inside the reward transaction.');
                        touch($directory.'/reward-writes-staged');
                        $this->wait(fn () => is_file($directory.'/reward-peer-started'), 15, 'Retry worker did not arrive.');
                        throw new RuntimeException('Synthetic reward notification failure');
                    }
                });
            }
            $reward = app(NationRaidRewardService::class)->claim($this->event,
                Character::findOrFail($job['character']), $job['reward'], $job['selection'] ?? null);
            if ($job['op'] === 'reward_claim_after_peer_lock') {
                touch($directory.'/reward-other-owner-completed');
            }

            return ['outcome' => $reward->status, 'selection' => $reward->selection_key, 'balance' => $reward->balance_after_snapshot,
                'owner_read_barrier_reached' => $ownerBarrierReached];
        } catch (QueryException $exception) {
            if ($job['op'] !== 'reward_claim_timeout' || (int) ($exception->errorInfo[1] ?? 0) !== 1205) {
                throw $exception;
            }

            return ['outcome' => 'lock_timeout', 'database_error' => 1205, 'attempts' => $observed->attempts,
                'timeouts' => $observed->timeouts, 'wait_levels' => $observed->waitLevels];
        } catch (DomainException $exception) {
            if ($job['op'] === 'reward_claim_after_inventory_staged'
                && $exception->getMessage() === '素材倉庫がいっぱいです。整理してから受け取ってください。報酬は保管されています。') {
                return ['outcome' => 'storage_full', 'owner_read_barrier_reached' => $ownerBarrierReached];
            }
            if ($exception->getMessage() !== '受取済みの報酬は選択を変更できません。') {
                throw $exception;
            }

            return ['outcome' => 'choice_conflict'];
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Synthetic reward notification failure') {
                throw $exception;
            }

            return ['outcome' => 'injected_rollback'];
        } finally {
            $ownerBarrierActive = false;
            CharacterNotification::setEventDispatcher($dispatcher);
        }
    }

    /** Synthetic resolved results isolate finalization/rewards from combat balance and scheduling. */
    private function rewardFixture(?Character $character = null, bool $finalize = false, bool $fixed = false): array
    {
        $character ??= $this->character();
        if (! NationRaidParticipation::where('character_id_snapshot', $character->id)->exists()) {
            $character->update(['free_kiseki' => 0, 'paid_kiseki' => 7, 'kiseki' => 7]);
        }
        Material::firstOrCreate(['material_code' => 'MAT_ENHANCE_FRAGMENT'], ['name' => '強化石の欠片', 'category' => 'enhance']);
        $currentPolicy = config('nation_raid_rewards');
        try {
            config()->set('nation_raid_rewards', $fixed ? $currentPolicy : require base_path('scripts/verify/fixtures/nation_raid_rewards_v1.php'));
            $event = app(NationRaidEventService::class)->createDraft('reward-ci-'.bin2hex(random_bytes(8)), '隔離報酬競合検証・公開不可', now()->subDays(8));
        } finally {
            config()->set('nation_raid_rewards', $currentPolicy);
        }
        $event->update(['status' => 'finalizing', 'activated_at' => $event->starts_at,
            'stage10_reached_at' => now()->subDays(3), 'completed_at' => now()->subDays(2)]);
        DB::table('nation_raid_daily_lineage_snapshots')->where('event_id', $event->id)->update(['determined_at' => now()]);
        $nation = Nation::create(['name' => '報酬検証'.bin2hex(random_bytes(5)), 'nation_type' => 'kingdom',
            'status' => 'active', 'founded_at' => now(), 'treasury_points' => 0, 'development_exp' => 0]);
        $participation = NationRaidParticipation::create(['event_id' => $event->id, 'account_id' => $character->user_id,
            'character_id' => $character->id, 'character_id_snapshot' => $character->id,
            'nation_id' => $nation->id, 'nation_id_snapshot' => $nation->id, 'is_nation_eligible' => true,
            'reference_active_count' => 1, 'character_name_snapshot' => $character->name, 'nation_name_snapshot' => $nation->name]);
        foreach (range(1, 15) as $i) {
            $day = intdiv($i - 1, 5) + 1;
            $at = $event->starts_at->copy()->addDays($day - 1)->addMinutes($i);
            NationRaidBattleResult::create(['event_id' => $event->id, 'participation_id' => $participation->id,
                'account_id' => $character->user_id, 'character_id' => $character->id, 'nation_id' => $nation->id,
                'battle_token' => bin2hex(random_bytes(32)), 'sortie_seed' => str_repeat('a', 64), 'status' => 'resolved',
                'raid_day' => $day, 'day_sortie_no' => ($i - 1) % 5 + 1, 'event_sortie_no' => $i,
                'target_cycle_no' => 1, 'target_cycle_kind' => 'main', 'target_stage_no' => 1,
                'target_form' => 'sealed_scale', 'target_parameter_snapshot' => [], 'boss_species_key' => 'dragon',
                'strategy' => 'assault', 'applied_damage_total' => 150_000, 'nation_damage_total' => 150_000,
                'coordination_damage_total' => 0, 'max_action_damage' => 20_000,
                'started_at' => $at, 'resolved_at' => $at, 'resolution_deadline_at' => $at->copy()->addMinutes(10)]);
        }
        if ($finalize) {
            app(NationRaidEventService::class)->completeFinalization($event);
        }

        return [$event->refresh(), $character, $nation];
    }

    private function fixtureReward(NationRaidEvent $event, string $key): NationRaidPersonalReward
    {
        return NationRaidPersonalReward::where('event_id', $event->id)->where('reward_key', $key)->sole();
    }

    private function claimJob(NationRaidPersonalReward $reward, Character $character, ?string $selection = null): array
    {
        return ['op' => 'reward_claim', 'event' => $reward->event_id, 'character' => $character->id,
            'reward' => $reward->id, 'selection' => $selection];
    }

    private function consumableCount(Character $character, string $key): int
    {
        return (int) CharacterConsumableItem::where('character_id', $character->id)->where('item_key', $key)->sum('quantity');
    }

    private function fragmentCount(Character $character): int
    {
        return (int) CharacterMaterial::where('character_id', $character->id)
            ->where('material_id', Material::where('material_code', 'MAT_ENHANCE_FRAGMENT')->sole()->id)->sum('quantity');
    }

    private function rewardNotificationCount(Character $character, string $type): int
    {
        return CharacterNotification::where('character_id', $character->id)->where('type', $type)->count();
    }

    private function assertCompletionClaim(Character $character, NationRaidPersonalReward $reward): void
    {
        $character->refresh();
        $reward->refresh();
        $this->check([(int) $character->free_kiseki, (int) $character->paid_kiseki, (int) $character->kiseki] === [5, 7, 12],
            'Concurrent claim lost/duplicated Kiseki or changed paid Kiseki.');
        $this->check($this->consumableCount($character, 'explore_stamina_small_bottle') === 2, 'Concurrent claim duplicated bottles.');
        $ledgers = KisekiTransaction::where('source_type', 'nation_raid_personal_reward')->where('source_id', $reward->id)->get();
        $this->check($ledgers->count() === 1 && $ledgers->first()->amount === 5 && $ledgers->first()->kiseki_type === 'free',
            'Concurrent claim ledger does not match the exact grant.');
        $this->check($reward->status === 'claimed' && $reward->claimed_at !== null
            && $reward->balance_after_snapshot['kiseki']['transaction_id'] === $ledgers->first()->id, 'Claim record and ledger disagree.');
        $this->check($this->rewardNotificationCount($character, 'nation_raid_reward_claimed') === 1, 'Claim notification duplicated/lost.');
    }
}
