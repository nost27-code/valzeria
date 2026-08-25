<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use App\Models\NationWar;
use App\Models\NationWarParticipant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class NationMembershipService
{
    public const MAX_MEMBERS = 100;

    public function __construct(
        private readonly NationCommunitySettingsService $settings,
        private readonly NationRoleService $roles,
        private readonly NationMembershipCooldownService $cooldowns,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationChatService $nationChat,
    ) {}

    public function join(Character $character, Nation $nation): NationMembership
    {
        throw new \DomainException('国家への加入には統治者による加入申請の承認が必要です。');
    }

    /**
     * NationJoinApplicationServiceの承認transaction内からだけ使用する。
     */
    public function createApprovedMembership(Character $character, Nation $nation): NationMembership
    {
        throw_if(NationMembership::where('character_id', $character->id)->exists(), \DomainException::class, '申請者はすでに国家へ所属しています。');
        throw_if($nation->memberships()->count() >= $this->settings->maxMembers(), \DomainException::class, 'この国家は定員に達しています。');

        $attributes = [
            'nation_id' => $nation->id,
            'character_id' => $character->id,
            'role' => 'citizen',
            'joined_at' => now(),
        ];
        $attributes[NationChatService::READ_STATE_COLUMN] = $this->nationChat
            ->latestMessageIdForNation((int) $nation->id);

        return NationMembership::create($attributes);
    }

    public function changeRole(NationMembership $actor, NationMembership $target, string $role): void
    {
        DB::transaction(function () use ($actor, $target, $role): void {
            $lockedNation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            throw_unless($lockedNation->status === Nation::STATUS_ACTIVE, \DomainException::class, '解散手続き中の国家では役職を変更できません。');
            $lockedActor = NationMembership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = NationMembership::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->roles->authorize($lockedActor, 'manage_roles');
            throw_unless($lockedActor->nation_id === $lockedTarget->nation_id, \DomainException::class, '同じ国家の国民ではありません。');
            throw_unless(in_array($role, NationMembership::ASSIGNABLE_ROLES, true), \DomainException::class, '指定された役職は存在しません。');
            throw_if($lockedTarget->isRuler(), \DomainException::class, '統治者の交代は専用手続きが必要です。');

            $previousRole = $lockedTarget->role;
            if ($previousRole === $role) {
                return;
            }

            $lockedTarget->update(['role' => $role]);
            $event = $role === 'citizen' ? 'role_removed' : 'role_assigned';
            $this->activityLogs->record(
                $lockedNation,
                $event,
                $lockedActor->character,
                $lockedTarget->character,
                ['role' => $role, 'role_label' => $lockedTarget->fresh()->roleLabel($lockedNation)],
            );
        }, 3);
    }

    /** @return array{allowed:bool,reason:?string,blocked_until:?CarbonInterface} */
    public function leaveEligibility(NationMembership $membership): array
    {
        if ($membership->isRuler()) {
            return ['allowed' => false, 'reason' => '統治者は、地位の譲渡または国家解散を先に行ってください。', 'blocked_until' => null];
        }

        if (Nation::whereKey($membership->nation_id)->where('status', Nation::STATUS_DISBAND_PENDING)->exists()) {
            return [
                'allowed' => false,
                'reason' => '国家解散の待機中は自主脱退できません。解散完了時に待機時間なしで自動的に無所属になります。',
                'blocked_until' => null,
            ];
        }

        $availableAt = $membership->joined_at?->copy()->addHours($this->settings->minimumMembershipHours());
        if ($availableAt?->isFuture()) {
            return [
                'allowed' => false,
                'reason' => '加入から'.$this->settings->minimumMembershipHours().'時間は脱退できません。',
                'blocked_until' => $availableAt,
            ];
        }

        if ($this->isFrozenWarParticipant($membership)) {
            return ['allowed' => false, 'reason' => '国家戦の参加者として確定しているため、終戦まで脱退できません。', 'blocked_until' => null];
        }

        return ['allowed' => true, 'reason' => null, 'blocked_until' => null];
    }

    public function leave(Character $character): void
    {
        DB::transaction(function () use ($character): void {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $membership = NationMembership::where('character_id', $lockedCharacter->id)->lockForUpdate()->first();
            throw_unless($membership, \DomainException::class, '国家へ所属していません。');
            $nation = Nation::whereKey($membership->nation_id)->lockForUpdate()->firstOrFail();
            $eligibility = $this->leaveEligibility($membership);
            throw_unless($eligibility['allowed'], \DomainException::class, $eligibility['reason']);

            $this->activityLogs->record($nation, 'member_left', $lockedCharacter, $lockedCharacter);
            $this->cooldowns->applyVoluntaryLeave($lockedCharacter);
            $membership->delete();
        }, 3);
    }

    public function expel(NationMembership $actor, NationMembership $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            throw_unless($nation->status === Nation::STATUS_ACTIVE, \DomainException::class, '解散手続き中の国家では追放できません。');
            $lockedActor = NationMembership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = NationMembership::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->roles->authorize($lockedActor, 'manage_members');
            throw_unless($lockedActor->nation_id === $lockedTarget->nation_id, \DomainException::class, '同じ国家の国民ではありません。');
            throw_if($lockedTarget->isRuler(), \DomainException::class, '統治者を追放できません。');
            throw_if($this->isFrozenWarParticipant($lockedTarget), \DomainException::class, '国家戦の参加者として確定している国民は、終戦まで追放できません。');

            $targetCharacter = Character::whereKey($lockedTarget->character_id)->lockForUpdate()->firstOrFail();
            $sameNationRetryAt = now()->addDays($this->settings->expelSameNationCooldownDays());
            $application = NationJoinApplication::where('nation_id', $nation->id)
                ->where('character_id', $targetCharacter->id)
                ->lockForUpdate()
                ->first();
            if ($application) {
                $application->update([
                    'status' => NationJoinApplication::STATUS_CANCELED,
                    'reviewed_at' => now(),
                    'reviewed_by_character_id' => $lockedActor->character_id,
                    'retry_after' => $application->retry_after?->gt($sameNationRetryAt)
                        ? $application->retry_after
                        : $sameNationRetryAt,
                ]);
            }

            $this->activityLogs->record($nation, 'member_expelled', $lockedActor->character, $targetCharacter);
            $this->cooldowns->applyExpulsion($targetCharacter, $nation);
            $lockedTarget->delete();
        }, 3);
    }

    public function isFrozenWarParticipant(NationMembership $membership): bool
    {
        return NationWarParticipant::query()
            ->where('nation_id', $membership->nation_id)
            ->where('character_id', $membership->character_id)
            ->whereIn('nation_war_id', NationWar::query()->whereIn('status', ['preparing', 'active'])->select('id'))
            ->exists();
    }
}
