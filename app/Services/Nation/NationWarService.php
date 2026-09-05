<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\NationWar;
use App\Models\NationWarFacility;
use App\Models\NationWarParticipant;
use App\Models\NationWarSide;
use Illuminate\Support\Facades\DB;

final class NationWarService
{
    public function declare(NationMembership $actor, Nation $defender): NationWar
    {
        $settings = app(NationWarSettingsService::class);
        throw_unless($settings->featureEnabled(), \DomainException::class, '国家機能は準備中です。');
        throw_unless($settings->declarationEnabled(), \DomainException::class, '宣戦布告は現在停止中です。');
        throw_unless($settings->calibrated(), \DomainException::class, '基準Dが未校正のため宣戦布告できません。');
        app(NationRoleService::class)->authorize($actor, 'declare_war');

        return DB::transaction(function () use ($actor, $defender, $settings): NationWar {
            $coordinatorService = app(CompetitionEventCoordinatorService::class);
            $coordinator = $coordinatorService->lock();
            $startsAt = now()->addDays($settings->preparationDays());
            $endsAt = $startsAt->copy()->addDays($settings->durationDays());
            $coordinatorService->assertNationWarWindowAvailable($startsAt, $endsAt);
            $ids = [$actor->nation_id, $defender->id]; sort($ids);
            $nations = Nation::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            $attacker = $nations[$actor->nation_id] ?? null;
            $lockedDefender = $nations[$defender->id] ?? null;
            throw_unless($attacker && $lockedDefender, \DomainException::class, '国家が見つかりません。');
            throw_unless(
                $attacker->status === Nation::STATUS_ACTIVE && $lockedDefender->status === Nation::STATUS_ACTIVE,
                \DomainException::class,
                '解散手続き中または解散済みの国家は国家戦を開始できません。',
            );
            throw_if(
                $attacker->is_hidden || $lockedDefender->is_hidden,
                \DomainException::class,
                'この国家は国家戦へ参加できません。',
            );
            throw_if($attacker->id === $lockedDefender->id, \DomainException::class, '自国へ宣戦布告できません。');
            $protectionDays = $settings->foundedProtectionDays();
            throw_if($attacker->founded_at->gt(now()->subDays($protectionDays)) || $lockedDefender->founded_at->gt(now()->subDays($protectionDays)), \DomainException::class, "建国から{$protectionDays}日間は国家戦を開始できません。");
            throw_if($attacker->loss_protected_until?->isFuture() || $lockedDefender->loss_protected_until?->isFuture(), \DomainException::class, '敗戦保護期間中の国家が含まれます。');

            $reserve = false;
            foreach ([$attacker->id, $lockedDefender->id] as $nationId) {
                $live = NationWar::whereIn('status', NationWar::LIVE_STATUSES)
                    ->where(fn ($q) => $q->where('declaring_nation_id', $nationId)->orWhere('defending_nation_id', $nationId))
                    ->lockForUpdate()->get();
                throw_if($live->where('status', 'preparing')->isNotEmpty(), \DomainException::class, '準備期間中は次戦予約できません。');
                throw_if($live->where('status', 'active')->count() > 1, \DomainException::class, '進行中の国家戦データが不正です。');
                throw_if($live->where('status', 'reserved')->isNotEmpty(), \DomainException::class, '次戦予約は1件までです。');
                $reserve = $reserve || $live->where('status', 'active')->isNotEmpty();
            }

            $war = NationWar::create([
                'declaring_nation_id' => $attacker->id, 'defending_nation_id' => $lockedDefender->id,
                'status' => $reserve ? 'reserved' : 'preparing', 'declared_at' => now(), 'preparation_starts_at' => now(),
                'starts_at' => $startsAt, 'ends_at' => $endsAt,
            ]);
            $this->freezeSide($war, $attacker, 'attacker', ! $reserve);
            $this->freezeSide($war, $lockedDefender, 'defender', ! $reserve);

            $coordinatorService->refreshLocked($coordinator);
            return $war->load(['sides.nation', 'participants', 'facilities']);
        }, 3);
    }

    public function allocateResources(NationMembership $actor, NationWar $war, int $points): NationWarSide
    {
        throw_if($points < 1, \DomainException::class, '配分ポイントは1以上で指定してください。');
        app(NationRoleService::class)->authorize($actor, 'allocate_war_resources');

        return DB::transaction(function () use ($actor, $war, $points): NationWarSide {
            $side = NationWarSide::where('nation_war_id', $war->id)->where('nation_id', $actor->nation_id)->lockForUpdate()->first();
            throw_unless($side, \DomainException::class, 'この国家戦の当事国ではありません。');
            throw_if($side->pool_refunded || $war->status === 'resolved' || $war->status === 'cancelled', \DomainException::class, '終了した国家戦へは配分できません。');
            $nation = Nation::findOrFail($actor->nation_id);
            app(NationResourceService::class)->spend($nation, $points, 'war_pool_allocation', [], $war->id);
            $side->increment('resource_pool_points', $points);
            return $side->refresh();
        }, 3);
    }

    public function refundUnusedPool(NationWarSide $side): int
    {
        return DB::transaction(function () use ($side): int {
            $locked = NationWarSide::whereKey($side->id)->lockForUpdate()->firstOrFail();
            if ($locked->pool_refunded) return 0;
            $unused = max(0, (int) $locked->resource_pool_points - (int) $locked->resource_spent_points);
            if ($unused > 0) app(NationResourceService::class)->credit(Nation::findOrFail($locked->nation_id), $unused, 'war_pool_refund', [], $locked->nation_war_id);
            $locked->update(['pool_refunded' => true]);
            return $unused;
        }, 3);
    }

    private function freezeSide(NationWar $war, Nation $nation, string $sideName, bool $initializeFacilities): void
    {
        $members = app(NationWarActiveMemberService::class)->members($nation);
        $activeCount = max(1, $members->count());
        NationWarSide::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'side' => $sideName, 'active_member_count' => $members->count()]);
        foreach ($members as $membership) {
            NationWarParticipant::create(['nation_war_id' => $war->id, 'nation_id' => $nation->id, 'character_id' => $membership->character_id, 'frozen_at' => now()]);
        }
        if ($initializeFacilities) $this->initializeFacilities($war, $nation, $activeCount);
    }

    public function initializeFacilities(NationWar $war, Nation $nation, ?int $activeCount = null): void
    {
        if (NationWarFacility::where('nation_war_id', $war->id)->where('nation_id', $nation->id)->exists()) return;
        $activeCount ??= max(1, (int) NationWarSide::where('nation_war_id', $war->id)->where('nation_id', $nation->id)->value('active_member_count'));
        $hp = app(NationWarHpCalculator::class);
        foreach ($nation->facilities()->get() as $facility) {
            $maxHp = $hp->maxHp($facility->facility_type, $facility->level, $activeCount);
            $startingHp = max(0, (int) floor($maxHp * ($facility->condition_bps / 10000)));
            NationWarFacility::create([
                'nation_war_id' => $war->id, 'nation_id' => $nation->id, 'facility_type' => $facility->facility_type,
                'level' => $facility->level, 'opening_max_hp' => $maxHp, 'max_hp' => $maxHp,
                'current_hp' => $startingHp, 'min_hp' => $startingHp,
                'status' => $startingHp > 0 ? 'active' : 'destroyed', 'destroyed_at' => $startingHp > 0 ? null : now(),
            ]);
        }
    }
}
