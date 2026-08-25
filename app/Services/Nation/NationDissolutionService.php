<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use App\Models\NationWar;
use Illuminate\Support\Facades\DB;

final class NationDissolutionService
{
    public function __construct(
        private readonly NationCommunitySettingsService $settings,
        private readonly NationRoleService $roles,
        private readonly NationMembershipCooldownService $cooldowns,
        private readonly NationActivityLogService $activityLogs,
    ) {}

    public function request(Character $actor, string $confirmationName): Nation
    {
        return DB::transaction(function () use ($actor, $confirmationName): Nation {
            $lockedActor = Character::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $membership = NationMembership::where('character_id', $lockedActor->id)->lockForUpdate()->first();
            throw_unless($membership, \DomainException::class, '国家へ所属していません。');
            $nation = Nation::whereKey($membership->nation_id)->lockForUpdate()->firstOrFail();
            $this->roles->authorize($membership, 'dissolve_nation');
            throw_unless($nation->status === Nation::STATUS_ACTIVE, \DomainException::class, 'この国家はすでに解散手続き中です。');
            throw_unless(hash_equals($nation->display_name, trim($confirmationName)), \DomainException::class, '確認用の国家名が一致しません。');
            throw_if($this->hasLiveWar($nation), \DomainException::class, '国家戦の準備・進行・次戦予約があるため解散できません。');

            $effectiveAt = now()->addHours($this->settings->dissolutionWaitHours());
            $nation->update([
                'status' => Nation::STATUS_DISBAND_PENDING,
                'dissolution_requested_at' => now(),
                'dissolution_effective_at' => $effectiveAt,
                'dissolution_requested_by_character_id' => $lockedActor->id,
                'dissolution_recruitment_was_enabled' => (bool) $nation->recruitment_enabled,
                'recruitment_enabled' => false,
            ]);

            $pendingApplications = NationJoinApplication::where('nation_id', $nation->id)
                ->where('status', NationJoinApplication::STATUS_PENDING)
                ->lockForUpdate()
                ->get();
            foreach ($pendingApplications as $application) {
                $applicant = $application->character;
                $application->update([
                    'status' => NationJoinApplication::STATUS_CANCELED,
                    'reviewed_at' => now(),
                    'reviewed_by_character_id' => $lockedActor->id,
                    'retry_after' => null,
                ]);
                $this->activityLogs->record(
                    $nation,
                    'join_application_canceled',
                    $applicant,
                    $applicant,
                    ['reason' => 'dissolution'],
                );
            }
            $this->activityLogs->record($nation, 'dissolution_requested', $lockedActor, null, ['effective_at' => $effectiveAt->toIso8601String()]);

            return $nation->fresh();
        }, 3);
    }

    public function cancel(Character $actor): Nation
    {
        return DB::transaction(function () use ($actor): Nation {
            $lockedActor = Character::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $membership = NationMembership::where('character_id', $lockedActor->id)->lockForUpdate()->first();
            throw_unless($membership, \DomainException::class, '国家へ所属していません。');
            $nation = Nation::whereKey($membership->nation_id)->lockForUpdate()->firstOrFail();
            $this->roles->authorize($membership, 'dissolve_nation');
            throw_unless($nation->status === Nation::STATUS_DISBAND_PENDING, \DomainException::class, '解散申請は行われていません。');
            throw_if($nation->dissolution_effective_at?->isPast(), \DomainException::class, '解散の取消期限を過ぎています。');

            $nation->update([
                'status' => Nation::STATUS_ACTIVE,
                'recruitment_enabled' => (bool) $nation->dissolution_recruitment_was_enabled,
                'dissolution_requested_at' => null,
                'dissolution_effective_at' => null,
                'dissolution_requested_by_character_id' => null,
                'dissolution_recruitment_was_enabled' => null,
            ]);
            $this->activityLogs->record($nation, 'dissolution_canceled', $lockedActor);

            return $nation->fresh();
        }, 3);
    }

    public function processDue(): int
    {
        $processed = 0;
        $ids = Nation::where('status', Nation::STATUS_DISBAND_PENDING)
            ->where('dissolution_effective_at', '<=', now())
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $nationId) {
            $didProcess = DB::transaction(function () use ($nationId): bool {
                $nation = Nation::whereKey($nationId)->lockForUpdate()->first();
                if (! $nation
                    || $nation->status !== Nation::STATUS_DISBAND_PENDING
                    || ! $nation->dissolution_effective_at?->isPast()) {
                    return false;
                }
                if ($this->hasLiveWar($nation)) {
                    return false;
                }

                $requester = $nation->dissolution_requested_by_character_id
                    ? Character::whereKey($nation->dissolution_requested_by_character_id)->lockForUpdate()->first()
                    : null;
                $memberships = NationMembership::where('nation_id', $nation->id)->lockForUpdate()->get();
                $memberships->each->delete();
                $nation->update([
                    'status' => Nation::STATUS_DISBANDED,
                    'recruitment_enabled' => false,
                    'disbanded_at' => now(),
                ]);
                if ($requester) {
                    $this->cooldowns->applyRulerRefoundBlock($requester);
                }
                $this->activityLogs->record($nation, 'nation_disbanded', $requester);

                return true;
            }, 3);

            if ($didProcess) {
                $processed++;
            }
        }

        return $processed;
    }

    public function hasLiveWar(Nation $nation): bool
    {
        return NationWar::whereIn('status', NationWar::LIVE_STATUSES)
            ->where(function ($query) use ($nation): void {
                $query->where('declaring_nation_id', $nation->id)
                    ->orWhere('defending_nation_id', $nation->id);
            })
            ->exists();
    }
}
