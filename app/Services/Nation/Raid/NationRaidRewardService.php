<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\CharacterConsumableItem;
use App\Models\CharacterMaterial;
use App\Models\KisekiTransaction;
use App\Models\Material;
use App\Models\Nation;
use App\Models\NationRaidEvent;
use App\Models\NationRaidNationReward;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPersonalReward;
use App\Models\NationResourceTransaction;
use App\Models\Title;
use App\Services\CharacterNotificationService;
use App\Services\Nation\NationAchievementService;
use App\Services\Nation\NationActivityLogService;
use App\Services\StorageCapacityService;
use App\Services\TitleService;
use Illuminate\Support\Facades\DB;

/** 達成時または終了時に権利を固定。個人の受取と残高・既存台帳・通知は常に同じtransaction。 */
final readonly class NationRaidRewardService
{
    public function __construct(private NationRaidRewardPolicy $policies, private CharacterNotificationService $notifications,
        private StorageCapacityService $storage, private TitleService $titles, private NationRaidTransactionRunner $transactions,
        private NationRaidPersonalRewardCatalog $catalog, private NationRaidRules $rules,
        private NationRaidRewardIdentity $identities) {}

    /** settlementのevent/participation lock内で、順位に依存しない達成済み権利だけを作る。 */
    public function prepareImmediateLocked(NationRaidEvent $event, NationRaidParticipation $participation): int
    {
        throw_unless(DB::transactionLevel() > 0
            && in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING], true),
            \LogicException::class, 'Immediate reward preparation requires a locked running event.');
        if (! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)) {
            return 0;
        }

        $policy = $this->policies->forEvent($event);
        $player = [
            'participation_id' => (int) $participation->id,
            'account_id' => (int) $participation->account_id,
            'character_id' => (int) ($participation->character_id_snapshot ?? $participation->character_id),
            'name' => (string) $participation->character_name_snapshot,
            'damage' => (int) $participation->personal_damage_total,
            'resolved_sorties' => (int) $participation->resolved_sorties,
            'rank' => null,
        ];
        $created = $this->storeMetPersonalRewards($event, $policy, $player, null, true);
        if ($created > 0) {
            $character = Character::query()->whereKey($player['character_id'])->where('user_id', $player['account_id'])->first();
            if ($character) {
                try {
                    $this->notifications->create($character, 'system', 'nation_raid_rewards_ready', 'レイド報酬を獲得した！',
                        '達成した戦果を受け取れます。', '報酬を確認', route('nation-raid.rewards', $event), ['event_id' => $event->id]);
                } catch (\Throwable $exception) {
                    report($exception); // 通知失敗で戦闘・権利を巻き戻さない。
                }
            }
        }

        return $created;
    }

    /** 第10再臨・討滅の全体条件が初めて成立したtransactionだけで既参加者を追認する。 */
    public function prepareImmediateGlobalLocked(NationRaidEvent $event): int
    {
        $created = 0;
        foreach (NationRaidParticipation::query()->where('event_id', $event->id)->orderBy('id')->lockForUpdate()->get() as $participation) {
            $created += $this->prepareImmediateLocked($event, $participation);
        }

        return $created;
    }

    /** coordinator → eventをlock済みのfinalization transaction専用。 */
    public function prepareLocked(NationRaidEvent $event, array $standings): void
    {
        throw_unless(DB::transactionLevel() > 0 && $event->status === NationRaidEvent::STATUS_FINALIZING,
            \LogicException::class, 'Reward preparation requires a locked finalizing event.');
        $policy = $this->policies->forEvent($event);
        throw_if(NationRaidNationReward::where('event_id', $event->id)->exists(),
            \DomainException::class, '終了未確定の報酬履歴が存在します。運営による整合性確認が必要です。');
        $maxRanks = collect($standings['max_action'])->keyBy('participation_id');
        foreach ($standings['personal_total'] as $player) {
            $maxRank = $maxRanks[$player['participation_id']]['rank'] ?? null;
            $created = $this->storeMetPersonalRewards($event, $policy, $player, $maxRank, false);
            $hasGrant = collect($this->catalog->definitions($event, $policy, $player, $maxRank))->contains('met', true);
            if (! $hasGrant) {
                continue;
            }
            throw_unless((int) $player['character_id'] > 0, \DomainException::class, '報酬受取人の開始時記録がありません。');
            $character = Character::query()->whereKey($player['character_id'])->where('user_id', $player['account_id'])->first();
            if ($character && $created > 0) {
                // prepareLockedはcompletedと同時commit。個別受取はまだ行わない。
                $notification = $this->notifications->create($character, 'system', 'nation_raid_rewards_ready', 'レイドの戦果が届いた！',
                    $event->boss_name.'との戦いが終結。報酬を確認しよう。', '報酬を確認', route('nation-raid.rewards', $event), ['event_id' => $event->id]);
                throw_unless($notification, \RuntimeException::class, '報酬通知を保存できません。');
            }
        }
        // NationをID順でlockし、共同目標・納品・発展EXPの経路を通さない。
        foreach (collect($standings['nation_total'])->sortBy('nation_id') as $row) {
            if ($row['eligible_participant_count'] < 1) {
                continue;
            }
            $points = 0;
            if ($row['denominator'] > 0) {
                foreach ($policy['nation_thresholds'] as $threshold => $amount) {
                    if (intdiv($row['personal_damage'], $row['denominator']) >= $threshold) {
                        $points = max($points, $amount);
                    }
                }
                // minの前に大きい人数の積を計算しない。
                $points = min($points, min($points, $row['denominator']) * $policy['nation_reference_cap'],
                    min($points, $row['eligible_participant_count']) * $policy['nation_qualified_cap']);
            }
            $grants = $points > 0 ? ['resources' => ['label' => '国家資材', 'points' => $points]] : [];
            if ($row['rank'] <= 3) {
                $metal = [1 => '金', 2 => '銀', 3 => '銅'][$row['rank']];
                $grants['flag'] = ['label' => $this->identities->nationFlagPrefix($event).$metal,
                    'rank' => $row['rank'], 'decoration' => true];
            }
            if ($event->completed_at !== null) {
                $grants['participation_honor'] = $this->identities->participationHonor($event);
            }
            $nation = Nation::whereKey($row['nation_id'])->lockForUpdate()->first();
            foreach ($grants as $key => $payload) {
                $payload['policy_hash'] = $event->reward_policy_hash;
                $reward = NationRaidNationReward::firstOrCreate([
                    'event_id' => $event->id, 'nation_id_snapshot' => $row['nation_id'], 'reward_key' => $key,
                ], ['nation_id' => $nation?->id, 'nation_name_snapshot' => $row['name'], 'reward_snapshot' => $payload,
                    'status' => NationRaidNationReward::STATUS_PENDING,
                    'idempotency_key' => hash('sha256', "raid:{$event->id}:nation:{$row['nation_id']}:{$key}")]);
                throw_unless($reward->reward_snapshot === $payload, \DomainException::class, '保存済みの国家報酬権利が一致しません。');
                if ($nation && $reward->status === NationRaidNationReward::STATUS_PENDING) {
                    $this->grantNationLocked($event, $nation, $reward);
                }
            }
        }
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $player */
    private function storeMetPersonalRewards(
        NationRaidEvent $event,
        array $policy,
        array $player,
        ?int $maxRank,
        bool $immediateOnly,
    ): int {
        throw_unless((int) ($player['character_id'] ?? 0) > 0, \DomainException::class, '報酬受取人の開始時記録がありません。');
        $character = Character::query()->whereKey($player['character_id'])->where('user_id', $player['account_id'])->first();
        $created = 0;
        foreach ($this->catalog->definitions($event, $policy, $player, $maxRank) as $key => $definition) {
            if (! $definition['met'] || ($immediateOnly && $definition['availability_type'] !== NationRaidPersonalRewardCatalog::AVAILABILITY_IMMEDIATE)) {
                continue;
            }
            $rank = $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)
                ? match ($key) {
                    'personal_first', 'personal_top3' => $player['rank'] ?? null,
                    'max_first', 'max_top3' => $maxRank,
                    default => null,
                }
            : (in_array($key, ['max_first', 'max_top3'], true) ? $maxRank : ($player['rank'] ?? null));
            $payload = [...$definition['payload'], 'policy_hash' => $event->reward_policy_hash,
                'character_name' => $player['name'], 'rank' => $rank];
            $attributes = [
                'account_id_snapshot' => $player['account_id'],
                'character_id' => $character?->id,
                'reward_snapshot' => $payload,
                'idempotency_key' => hash('sha256', "raid:{$event->id}:personal:{$player['character_id']}:{$key}"),
                'status' => NationRaidPersonalReward::STATUS_PENDING,
                'availability_type' => $definition['availability_type'],
                'available_at' => now(),
            ];
            $reward = NationRaidPersonalReward::query()->firstOrCreate([
                'event_id' => $event->id,
                'character_id_snapshot' => $player['character_id'],
                'reward_key' => $key,
            ], $attributes);
            throw_unless(
                $reward->account_id_snapshot === (int) $player['account_id']
                    && $reward->reward_snapshot === $payload
                    && hash_equals((string) $reward->idempotency_key, $attributes['idempotency_key'])
                    && ($reward->availability_type === $definition['availability_type']
                        || ! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)),
                \DomainException::class,
                '保存済みの個人報酬権利が一致しません。',
            );
            $created += (int) $reward->wasRecentlyCreated;
        }

        return $created;
    }

    public function claim(NationRaidEvent $reference, Character $actor, int $rewardId, ?string $selection = null): NationRaidPersonalReward
    {
        throw_unless(config('features.nation_competitive_raid_enabled', false), \DomainException::class, '国家対抗レイドは現在準備中です。');

        return $this->transactions->run(function () use ($reference, $actor, $rewardId, $selection): NationRaidPersonalReward {
            // RRのconsistent readを作る前に所有者をlockする。先行claimのcommitを待ってから
            // event/在庫を読むことで、容量判定と受取後残高が古いread viewに固定されるのを防ぐ。
            $character = Character::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            // completedとpolicyは不変。eventの排他lockで別人の受取を直列化しない。
            // 所有者の在庫はCharacter → entitlementの順で保護する。
            $event = NationRaidEvent::whereKey($reference->id)
                ->firstOrFail(['id', 'status', 'reward_policy_snapshot', 'reward_policy_hash']);
            $this->policies->forEvent($event);
            $reward = NationRaidPersonalReward::whereKey($rewardId)->where('event_id', $event->id)->lockForUpdate()->first();
            throw_unless($reward && $reward->account_id_snapshot === (int) $character->user_id
                && $reward->character_id_snapshot === (int) $character->id && (int) $actor->user_id === (int) $character->user_id,
                \DomainException::class, 'この報酬の受取人ではありません。');
            $availableImmediately = $reward->availability_type === NationRaidPersonalRewardCatalog::AVAILABILITY_IMMEDIATE
                && $reward->available_at !== null && $reward->available_at->lte(now());
            throw_unless($event->status === NationRaidEvent::STATUS_COMPLETED || $availableImmediately,
                \DomainException::class, 'この報酬は戦果の最終確定後に受け取れます。');
            throw_if($character->is_frozen || $character->isExcludedFromPublicLogs(), \DomainException::class, 'この冒険者は報酬を受け取れません。');
            if ($reward->status === NationRaidPersonalReward::STATUS_CLAIMED) {
                throw_unless($reward->selection_key === $selection, \DomainException::class, '受取済みの報酬は選択を変更できません。');

                return $reward;
            }
            throw_unless($reward->status === NationRaidPersonalReward::STATUS_PENDING, \DomainException::class, '報酬状態を確認できません。');
            $payload = $reward->reward_snapshot;
            throw_unless(($payload['policy_hash'] ?? null) === $event->reward_policy_hash, \DomainException::class, '報酬条件が一致しません。');
            $balance = [];
            throw_if(isset($payload['choices'], $payload['fixed_material']), \DomainException::class, '報酬形式が一致しません。');
            if (isset($payload['choices'])) {
                throw_unless(is_string($selection) && isset($payload['choices'][$selection]), \DomainException::class, '受け取る報酬を選択してください。');
                $choice = $payload['choices'][$selection];
                if ($choice['kind'] === 'material') {
                    $balance['material'] = $this->addMaterial($character, $choice);
                } else {
                    $balance['consumable'] = $this->addConsumable($character, $choice['item_key'], $choice['quantity']);
                }
            } else {
                throw_unless($selection === null, \DomainException::class, 'この報酬は選択式ではありません。');
            }
            if (isset($payload['fixed_material'])) {
                $balance['material'] = $this->addMaterial($character, $payload['fixed_material']);
            }
            if (isset($payload['bottles'])) {
                $bottleBalance = $this->addConsumable($character, 'explore_stamina_small_bottle', $payload['bottles']);
                if (isset($payload['fixed_material'])) {
                    $balance['consumables']['explore_stamina_small_bottle'] = $bottleBalance;
                } else {
                    $balance['consumable'] = $bottleBalance;
                }
            }
            if (isset($payload['talismans'])) {
                $balance['consumables']['experience_talisman'] = $this->addConsumable($character, 'experience_talisman', $payload['talismans']);
            }
            if (isset($payload['free_kiseki'])) {
                throw_if(KisekiTransaction::where('source_type', 'nation_raid_personal_reward')->where('source_id', $reward->id)->exists(),
                    \DomainException::class, '報酬の台帳を確認する必要があります。');
                $character->free_kiseki = (int) $character->free_kiseki + $payload['free_kiseki'];
                $character->kiseki = (int) $character->paid_kiseki + (int) $character->free_kiseki;
                $character->save();
                $ledger = KisekiTransaction::create(['character_id' => $character->id, 'kiseki_type' => 'free',
                    'amount' => $payload['free_kiseki'], 'transaction_type' => 'nation_raid_reward',
                    'source_type' => 'nation_raid_personal_reward', 'source_id' => $reward->id, 'description' => '国家対抗レイド・'.$payload['label']]);
                $balance['kiseki'] = ['free' => (int) $character->free_kiseki, 'paid' => (int) $character->paid_kiseki,
                    'total' => (int) $character->kiseki, 'transaction_id' => $ledger->id];
            }
            if (isset($payload['title'])) {
                $titleTargetId = $payload['title_target_id'] ?? $reward->reward_key;
                throw_unless(is_string($titleTargetId) && $titleTargetId !== '', \DomainException::class, '報酬称号の参照先が不正です。');
                $title = Title::where('unlock_type', 'nation_raid_honor')->where('target_type', 'raid_reward')->where('target_id', $titleTargetId)->sole();
                throw_unless($title->name === $payload['title'], \DomainException::class, '報酬称号が一致しません。');
                $balance['character_title_id'] = $this->titles->unlockTitle($character, $title->id)->id;
            }
            $reward->update(['status' => NationRaidPersonalReward::STATUS_CLAIMED, 'selection_key' => $selection,
                'balance_after_snapshot' => $balance, 'claimed_at' => now()]);
            $notification = $this->notifications->create($character, 'system', 'nation_raid_reward_claimed', 'レイド報酬を受け取った！',
                $payload['label'], '戦果を確認', route('nation-raid.rewards', $event), ['event_id' => $event->id, 'reward_id' => $reward->id, 'balance_after' => $balance]);
            throw_unless($notification, \RuntimeException::class, '報酬通知を保存できません。');

            return $reward->fresh();
        });
    }

    private function grantNationLocked(NationRaidEvent $event, Nation $nation, NationRaidNationReward $reward): void
    {
        $payload = $reward->reward_snapshot;
        $balance = [];
        if (isset($payload['points'])) {
            throw_if(NationResourceTransaction::where('idempotency_key', $reward->idempotency_key)->exists(), \DomainException::class, '国家報酬台帳を確認する必要があります。');
            $nation->treasury_points = (int) $nation->treasury_points + $payload['points'];
            $nation->save();
            $tx = NationResourceTransaction::create(['nation_id' => $nation->id, 'transaction_type' => 'raid_reward',
                'quantity' => 0, 'points_delta' => $payload['points'], 'balance_after' => $nation->treasury_points,
                'development_exp_delta' => 0, 'idempotency_key' => $reward->idempotency_key,
                'metadata' => ['event_id' => $event->id, 'reward_id' => $reward->id]]);
            $reward->nation_resource_transaction_id = $tx->id;
            $balance['treasury_points'] = (int) $nation->treasury_points;
        }
        if (isset($payload['achievement'])) {
            app(NationAchievementService::class)->unlock($nation, $payload['achievement'], ['event_id' => $event->id]);
        }
        app(NationActivityLogService::class)->record($nation, 'raid_reward', null, null,
            ['event_id' => $event->id, 'reward_id' => $reward->id, 'reward_label' => $payload['label'], 'points' => $payload['points'] ?? null]);
        $reward->fill(['status' => NationRaidNationReward::STATUS_CLAIMED, 'balance_after_snapshot' => $balance, 'claimed_at' => now()])->save();
    }

    private function addConsumable(Character $character, string $key, int $quantity): array
    {
        throw_unless(in_array($key, ['explore_stamina_small_bottle', 'experience_talisman'], true) && $quantity > 0,
            \DomainException::class, '報酬アイテムが不正です。');
        $row = CharacterConsumableItem::firstOrCreate(['character_id' => $character->id, 'item_key' => $key], ['quantity' => 0]);
        $row->increment('quantity', $quantity);

        return ['item_key' => $key, 'quantity' => (int) $row->fresh()->quantity];
    }

    private function addMaterial(Character $character, array $grant): array
    {
        throw_unless(in_array($grant['code'] ?? null, ['MAT_ENHANCE_FRAGMENT', '5007', 'ACC0007'], true)
            && is_int($grant['quantity'] ?? null) && $grant['quantity'] > 0, \DomainException::class, '報酬素材が不正です。');
        $material = Material::where('material_code', $grant['code'])->sole();
        $summary = $this->storage->summary($character);
        throw_if($summary['material_limit'] < $summary['material_total'] + $grant['quantity'],
            \DomainException::class, '素材倉庫がいっぱいです。整理してから受け取ってください。報酬は保管されています。');
        $stock = CharacterMaterial::firstOrCreate(['character_id' => $character->id, 'material_id' => $material->id], ['quantity' => 0]);
        $stock->increment('quantity', $grant['quantity']);

        return ['id' => $material->id, 'code' => $grant['code'], 'quantity' => (int) $stock->fresh()->quantity];
    }
}
