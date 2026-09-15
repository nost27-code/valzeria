<?php

namespace App\Services\Nation\Raid;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationRaidEvent;
use App\Models\NationRaidInvasionDamage;
use App\Models\NationRaidNationPreparation;
use App\Models\NationRaidParticipation;
use App\Models\NationRaidPreparationContribution;
use App\Models\NationRaidPreparationMember;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** 開催72時間前の活動国民を固定し、通常探索勝利による兵站準備を記録する。 */
final readonly class NationRaidPreparationService
{
    public function __construct(
        private NationRaidParticipationSnapshotService $participations,
        private NationRaidRules $rules,
    ) {}

    public function preparationStartsAt(NationRaidEvent $event): CarbonImmutable
    {
        $hours = (int) ($event->ruleset_snapshot['raid_cycle']['logistics_preparation']['duration_hours']
            ?? config('nation_raid.logistics_preparation.duration_hours', 72));

        return CarbonImmutable::instance($event->starts_at)->subHours($hours);
    }

    /** coordinatorとeventをlockしたtransaction内で、未固定の準備対象だけを一度固定する。 */
    public function freezeLocked(NationRaidEvent $event, DateTimeInterface $at): int
    {
        throw_unless(DB::transactionLevel() > 0, \LogicException::class, 'Raid preparation freeze requires a transaction.');
        if (! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot) || $event->preparation_frozen_at !== null) {
            return 0;
        }

        $at = CarbonImmutable::instance($at);
        throw_if($at->lt($this->preparationStartsAt($event)), \DomainException::class, '兵站準備期間へ到達していません。');
        throw_unless(in_array($event->status, [NationRaidEvent::STATUS_SCHEDULED, NationRaidEvent::STATUS_ACTIVE], true),
            \DomainException::class, 'この開催回の兵站準備対象は固定できません。');

        $activeSince = $at->subDays((int) $event->ruleset_snapshot['raid_cycle']['logistics_preparation']['active_window_days']);
        $eligible = $this->participations->normalCharacters()
            ->filter(function (Character $character) use ($activeSince, $at): bool {
                $nation = $character->nationMembership?->nation;

                return $nation instanceof Nation
                    && $nation->status === Nation::STATUS_ACTIVE
                    && $character->last_battle_at !== null
                    && $character->last_battle_at->gte($activeSince)
                    && $character->last_battle_at->lte($at);
            })
            ->groupBy(fn (Character $character): int => (int) $character->nationMembership->nation_id);

        $requiredPerMember = (int) $event->ruleset_snapshot['raid_cycle']['logistics_preparation']['required_contributions_per_active_member'];
        $baseGrant = (int) $event->ruleset_snapshot['raid_cycle']['free_sorties']['daily_grant'];
        $baseCap = (int) $event->ruleset_snapshot['raid_cycle']['free_sorties']['balance_cap'];
        $created = 0;
        foreach ($eligible->sortKeys() as $nationId => $characters) {
            $nation = $characters->first()->nationMembership->nation;
            $preparation = NationRaidNationPreparation::query()->create([
                'event_id' => $event->id,
                'nation_id_snapshot' => $nationId,
                'nation_id' => $nationId,
                'nation_name_snapshot' => $nation->display_name,
                'reference_active_count' => $characters->count(),
                'contribution_target' => $characters->count() * $requiredPerMember,
                'earned_daily_free_grant' => $baseGrant,
                'earned_free_balance_cap' => $baseCap,
                'applied_daily_free_grant' => $baseGrant,
                'applied_free_balance_cap' => $baseCap,
                'frozen_at' => $at,
            ]);
            foreach ($characters->sortBy('user_id') as $character) {
                NationRaidPreparationMember::query()->create([
                    'event_id' => $event->id,
                    'preparation_id' => $preparation->id,
                    'account_id_snapshot' => $character->user_id,
                    'character_id_snapshot' => $character->id,
                    'character_id' => $character->id,
                    'nation_id_snapshot' => $nationId,
                ]);
                $created++;
            }
        }

        $event->preparation_frozen_at = $at;
        $event->state_version = (int) $event->state_version + 1;
        $event->save();

        return $created;
    }

    /** 開始時に準備度と無料枠ボーナスを固定する。未復興国は基礎枠だけを適用する。 */
    public function finalizeLocked(NationRaidEvent $event, DateTimeInterface $at): void
    {
        throw_unless(DB::transactionLevel() > 0, \LogicException::class, 'Raid preparation finalization requires a transaction.');
        if (! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)) {
            return;
        }
        if ($event->preparation_frozen_at === null) {
            $this->freezeLocked($event, $at);
        }

        $free = $event->ruleset_snapshot['raid_cycle']['free_sorties'];
        foreach (NationRaidNationPreparation::query()->where('event_id', $event->id)->orderBy('nation_id_snapshot')->lockForUpdate()->get() as $row) {
            $readiness = $this->readiness((int) $row->contribution_count, (int) $row->contribution_target);
            $earnedGrant = $readiness >= (int) $free['readiness_daily_grant_threshold_percent']
                ? (int) $free['readiness_daily_grant']
                : (int) $free['daily_grant'];
            $earnedCap = $readiness >= (int) $free['readiness_balance_cap_threshold_percent']
                ? (int) $free['readiness_balance_cap']
                : (int) $free['balance_cap'];
            $held = $row->nation_id !== null && NationRaidInvasionDamage::query()
                ->where('nation_id', $row->nation_id)->outstanding()->exists();
            $row->update([
                'readiness_percent' => $readiness,
                'earned_daily_free_grant' => $earnedGrant,
                'earned_free_balance_cap' => $earnedCap,
                'applied_daily_free_grant' => $held ? (int) $free['daily_grant'] : $earnedGrant,
                'applied_free_balance_cap' => $held ? (int) $free['balance_cap'] : $earnedCap,
                'benefits_held' => $held,
                'finalized_at' => CarbonImmutable::instance($at),
            ]);
        }
    }

    /** @return array{recorded:bool,readiness_percent:?int,preparation_day:?int} */
    public function recordNormalExplorationVictory(Character $character, BattleLog $battleLog, ?DateTimeInterface $at = null): array
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($character, $battleLog, $at): array {
            if ((int) $battleLog->character_id !== (int) $character->id
                || $battleLog->battle_type !== 'normal'
                || ! in_array($battleLog->result, BattleLog::WIN_RESULTS, true)) {
                return ['recorded' => false, 'readiness_percent' => null, 'preparation_day' => null];
            }
            $event = NationRaidEvent::query()
                ->where('status', NationRaidEvent::STATUS_SCHEDULED)
                ->whereNotNull('preparation_frozen_at')
                ->where('starts_at', '>', $at)
                ->orderBy('starts_at')
                // 国家をまたぐ通常探索をevent排他lockで直列化しない。開始/取消とはshared lockで競合を閉じる。
                ->sharedLock()
                ->first();
            if (! $event || ! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)
                || $at->lt($this->preparationStartsAt($event))) {
                return ['recorded' => false, 'readiness_percent' => null, 'preparation_day' => null];
            }
            if (NationRaidPreparationContribution::query()->where('battle_log_id', $battleLog->id)->exists()) {
                return ['recorded' => false, 'readiness_percent' => null, 'preparation_day' => null];
            }

            $member = NationRaidPreparationMember::query()
                ->where('event_id', $event->id)
                ->where('character_id_snapshot', $character->id)
                ->where('account_id_snapshot', $character->user_id)
                ->lockForUpdate()
                ->first();
            if (! $member) {
                return ['recorded' => false, 'readiness_percent' => null, 'preparation_day' => null];
            }
            $preparation = NationRaidNationPreparation::query()->whereKey($member->preparation_id)->lockForUpdate()->firstOrFail();
            $day = intdiv((int) $this->preparationStartsAt($event)->diffInSeconds($at), 86_400) + 1;
            $maxDays = (int) $event->ruleset_snapshot['raid_cycle']['logistics_preparation']['max_contributions_per_member'];
            if ($day < 1 || $day > $maxDays || (int) $member->contribution_count >= $maxDays
                || NationRaidPreparationContribution::query()->where('event_id', $event->id)
                    ->where('preparation_member_id', $member->id)->where('preparation_day', $day)->exists()) {
                return ['recorded' => false, 'readiness_percent' => (int) $preparation->readiness_percent, 'preparation_day' => $day];
            }

            NationRaidPreparationContribution::query()->create([
                'event_id' => $event->id,
                'preparation_id' => $preparation->id,
                'preparation_member_id' => $member->id,
                'battle_log_id' => $battleLog->id,
                'preparation_day' => $day,
                'contributed_on' => $at->toDateString(),
            ]);
            $member->increment('contribution_count');
            $preparation->increment('contribution_count');
            $readiness = $this->readiness((int) $preparation->fresh()->contribution_count, (int) $preparation->contribution_target);
            $free = $event->ruleset_snapshot['raid_cycle']['free_sorties'];
            $preparation->update([
                'readiness_percent' => $readiness,
                'earned_daily_free_grant' => $readiness >= (int) $free['readiness_daily_grant_threshold_percent']
                    ? (int) $free['readiness_daily_grant']
                    : (int) $free['daily_grant'],
                'earned_free_balance_cap' => $readiness >= (int) $free['readiness_balance_cap_threshold_percent']
                    ? (int) $free['readiness_balance_cap']
                    : (int) $free['balance_cap'],
            ]);

            return ['recorded' => true, 'readiness_percent' => $readiness, 'preparation_day' => $day];
        }, 3);
    }

    public function forCharacter(NationRaidEvent $event, Character $character): ?array
    {
        $member = null;
        if ($event->status === NationRaidEvent::STATUS_SCHEDULED) {
            $member = NationRaidPreparationMember::query()->where('event_id', $event->id)
                ->where('character_id_snapshot', $character->id)->first();
            $row = $member ? NationRaidNationPreparation::query()->find($member->preparation_id) : null;
        } else {
            $participation = NationRaidParticipation::query()->where('event_id', $event->id)
                ->where('account_id', $character->user_id)
                ->where('character_id_snapshot', $character->id)->first();
            if (! $participation?->is_nation_eligible || $participation->nation_id_snapshot === null) {
                return null;
            }
            $row = NationRaidNationPreparation::query()->where('event_id', $event->id)
                ->where('nation_id_snapshot', $participation->nation_id_snapshot)->first();
            if ($row) {
                $member = NationRaidPreparationMember::query()->where('preparation_id', $row->id)
                    ->where('character_id_snapshot', $character->id)->first();
            }
        }
        if (! $row) {
            return null;
        }

        return [
            'nation_name' => $row->nation_name_snapshot,
            'reference_active_count' => (int) $row->reference_active_count,
            'contribution_count' => (int) $row->contribution_count,
            'contribution_target' => (int) $row->contribution_target,
            'readiness_percent' => (int) $row->readiness_percent,
            'own_contribution_count' => (int) ($member?->contribution_count ?? 0),
            'own_contribution_max' => (int) $event->ruleset_snapshot['raid_cycle']['logistics_preparation']['max_contributions_per_member'],
            'benefits_held' => (bool) $row->benefits_held,
            'daily_free_grant' => (int) ($row->finalized_at ? $row->applied_daily_free_grant : $row->earned_daily_free_grant),
            'free_balance_cap' => (int) ($row->finalized_at ? $row->applied_free_balance_cap : $row->earned_free_balance_cap),
        ];
    }

    private function readiness(int $count, int $target): int
    {
        return $target < 1 ? 0 : min(100, intdiv(max(0, $count) * 100, $target));
    }
}
