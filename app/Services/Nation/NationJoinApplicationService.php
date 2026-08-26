<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use App\Services\CharacterNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class NationJoinApplicationService
{
    public function __construct(
        private readonly NationCommunitySettingsService $settings,
        private readonly NationMembershipCooldownService $cooldowns,
        private readonly NationMembershipService $memberships,
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
        private readonly CharacterNotificationService $notifications,
    ) {}

    public function submit(Character $character, Nation $nation, ?string $message = null): NationJoinApplication
    {
        $message = trim((string) $message);
        throw_if(mb_strlen($message) > 100, \DomainException::class, '加入申請の一言は100文字以内で入力してください。');

        return DB::transaction(function () use ($character, $nation, $message): NationJoinApplication {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $lockedNation = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
            $this->assertAcceptingApplications($lockedNation);
            throw_if(NationMembership::where('character_id', $lockedCharacter->id)->exists(), \DomainException::class, 'すでに国家へ所属しています。');

            $pending = NationJoinApplication::where('character_id', $lockedCharacter->id)
                ->where('status', NationJoinApplication::STATUS_PENDING)
                ->lockForUpdate()
                ->first();
            throw_if($pending, \DomainException::class, 'すでに別の加入申請が進行中です。');

            $this->cooldowns->assertCanJoin($lockedCharacter, $lockedNation);
            $application = NationJoinApplication::where('nation_id', $lockedNation->id)
                ->where('character_id', $lockedCharacter->id)
                ->lockForUpdate()
                ->first();
            if ($application?->retry_after?->isFuture()) {
                throw new \DomainException('この国家へは再申請待機期間中です。 残り '.$this->cooldowns->remainingLabel($application->retry_after));
            }

            $values = [
                'status' => NationJoinApplication::STATUS_PENDING,
                'message' => $message !== '' ? $message : null,
                'requested_at' => now(),
                'reviewed_at' => null,
                'reviewed_by_character_id' => null,
                'retry_after' => null,
            ];
            if ($application) {
                $application->update($values);
            } else {
                $application = NationJoinApplication::create([
                    'nation_id' => $lockedNation->id,
                    'character_id' => $lockedCharacter->id,
                    ...$values,
                ]);
            }

            $this->activityLogs->record($lockedNation, 'join_application_submitted', $lockedCharacter, $lockedCharacter);
            $this->notifyRulerOfApplication($lockedNation, $lockedCharacter, $application, $message);

            return $application->fresh(['nation', 'character']);
        }, 3);
    }

    public function cancel(Character $character, NationJoinApplication $application): void
    {
        DB::transaction(function () use ($character, $application): void {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            $lockedApplication = NationJoinApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            throw_unless((int) $lockedApplication->character_id === (int) $lockedCharacter->id, \DomainException::class, 'この加入申請を取り消す権限がありません。');
            throw_unless($lockedApplication->status === NationJoinApplication::STATUS_PENDING, \DomainException::class, 'この加入申請はすでに処理されています。');
            $nation = Nation::whereKey($lockedApplication->nation_id)->lockForUpdate()->firstOrFail();
            $retryAfter = now()->addHours($this->settings->applicationRetryHours());

            $lockedApplication->update([
                'status' => NationJoinApplication::STATUS_CANCELED,
                'reviewed_at' => now(),
                'reviewed_by_character_id' => null,
                'retry_after' => $retryAfter,
            ]);
            $this->activityLogs->record($nation, 'join_application_canceled', $lockedCharacter, $lockedCharacter);
        }, 3);
    }

    public function approve(Character $actor, NationJoinApplication $application): NationMembership
    {
        return DB::transaction(function () use ($actor, $application): NationMembership {
            $lockedApplication = NationJoinApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            throw_unless($lockedApplication->status === NationJoinApplication::STATUS_PENDING, \DomainException::class, 'この加入申請はすでに処理されています。');
            $nation = Nation::whereKey($lockedApplication->nation_id)->lockForUpdate()->firstOrFail();
            $actorMembership = NationMembership::where('character_id', $actor->id)->lockForUpdate()->first();
            throw_unless($actorMembership && (int) $actorMembership->nation_id === (int) $nation->id, \DomainException::class, 'この加入申請を処理する権限がありません。');
            $this->roles->authorize($actorMembership, 'manage_members');
            throw_unless($nation->status === Nation::STATUS_ACTIVE, \DomainException::class, 'この国家は加入を受け付けられません。');
            throw_if($nation->is_hidden, \DomainException::class, 'この国家は加入を受け付けられません。');

            $applicant = Character::whereKey($lockedApplication->character_id)->lockForUpdate()->firstOrFail();
            throw_if(NationMembership::where('character_id', $applicant->id)->exists(), \DomainException::class, '申請者はすでに国家へ所属しています。');
            $this->cooldowns->assertCanJoin($applicant, $nation);
            throw_if($nation->memberships()->count() >= $this->settings->maxMembersFor($nation), \DomainException::class, 'この国家は定員に達しています。');

            $membership = $this->memberships->createApprovedMembership($applicant, $nation);
            $lockedApplication->update([
                'status' => NationJoinApplication::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by_character_id' => $actor->id,
                'retry_after' => null,
            ]);
            NationJoinApplication::where('character_id', $applicant->id)
                ->where('status', NationJoinApplication::STATUS_PENDING)
                ->whereKeyNot($lockedApplication->id)
                ->update([
                    'status' => NationJoinApplication::STATUS_CANCELED,
                    'reviewed_at' => now(),
                    'reviewed_by_character_id' => $actor->id,
                ]);

            $this->activityLogs->record($nation, 'join_application_approved', $actor, $applicant);
            $this->activityLogs->record($nation, 'member_joined', $actor, $applicant);
            if (app(NationLevelBenefitSettingsService::class)->enabled()) {
                app(NationTimelineService::class)->recordMemberCountMilestone($nation, $actor, $applicant);
                app(NationAchievementService::class)->recordMemberJoined($nation);
            }
            $this->notifyApplicantOfApproval($nation, $applicant, $lockedApplication);

            return $membership->load(['nation', 'character']);
        }, 3);
    }

    public function reject(Character $actor, NationJoinApplication $application): void
    {
        DB::transaction(function () use ($actor, $application): void {
            $lockedApplication = NationJoinApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            throw_unless($lockedApplication->status === NationJoinApplication::STATUS_PENDING, \DomainException::class, 'この加入申請はすでに処理されています。');
            $nation = Nation::whereKey($lockedApplication->nation_id)->lockForUpdate()->firstOrFail();
            $actorMembership = NationMembership::where('character_id', $actor->id)->lockForUpdate()->first();
            throw_unless($actorMembership && (int) $actorMembership->nation_id === (int) $nation->id, \DomainException::class, 'この加入申請を処理する権限がありません。');
            $this->roles->authorize($actorMembership, 'manage_members');
            $applicant = Character::whereKey($lockedApplication->character_id)->lockForUpdate()->firstOrFail();

            $lockedApplication->update([
                'status' => NationJoinApplication::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by_character_id' => $actor->id,
                'retry_after' => now()->addHours($this->settings->applicationRetryHours()),
            ]);
            $this->activityLogs->record($nation, 'join_application_rejected', $actor, $applicant);
        }, 3);
    }

    /** @return array{allowed:bool,reason:?string,blocked_until:?CarbonInterface,pending:?NationJoinApplication} */
    public function eligibility(Character $character, Nation $nation): array
    {
        if (NationMembership::where('character_id', $character->id)->exists()) {
            return ['allowed' => false, 'reason' => 'すでに国家へ所属しています。', 'blocked_until' => null, 'pending' => null];
        }

        $pending = NationJoinApplication::where('character_id', $character->id)
            ->where('status', NationJoinApplication::STATUS_PENDING)
            ->first();
        if ($pending) {
            return [
                'allowed' => false,
                'reason' => (int) $pending->nation_id === (int) $nation->id ? 'この国家へ申請中です。' : '別の国家へ申請中です。',
                'blocked_until' => null,
                'pending' => $pending,
            ];
        }

        if ($nation->status !== Nation::STATUS_ACTIVE || $nation->is_hidden) {
            return ['allowed' => false, 'reason' => 'この国家は現在加入を受け付けていません。', 'blocked_until' => null, 'pending' => null];
        }
        if (! $nation->recruitment_enabled) {
            return ['allowed' => false, 'reason' => 'この国家は国民募集を停止しています。', 'blocked_until' => null, 'pending' => null];
        }
        if ($nation->memberships()->count() >= $this->settings->maxMembersFor($nation)) {
            return ['allowed' => false, 'reason' => 'この国家は定員に達しています。', 'blocked_until' => null, 'pending' => null];
        }

        $previous = NationJoinApplication::where('nation_id', $nation->id)
            ->where('character_id', $character->id)
            ->first();
        if ($previous?->retry_after?->isFuture()) {
            return ['allowed' => false, 'reason' => 'この国家へは再申請待機期間中です。', 'blocked_until' => $previous->retry_after, 'pending' => null];
        }

        $cooldown = $this->cooldowns->joinEligibility($character, $nation);

        return [...$cooldown, 'pending' => null];
    }

    private function assertAcceptingApplications(Nation $nation): void
    {
        throw_unless($nation->status === Nation::STATUS_ACTIVE, \DomainException::class, 'この国家は現在加入を受け付けていません。');
        throw_if($nation->is_hidden, \DomainException::class, 'この国家は現在加入を受け付けていません。');
        throw_unless($nation->recruitment_enabled, \DomainException::class, 'この国家は国民募集を停止しています。');
        throw_if($nation->memberships()->count() >= $this->settings->maxMembersFor($nation), \DomainException::class, 'この国家は定員に達しています。');
    }

    private function notifyRulerOfApplication(
        Nation $nation,
        Character $applicant,
        NationJoinApplication $application,
        string $message,
    ): void {
        $ruler = Character::query()
            ->whereHas('nationMembership', fn ($query) => $query
                ->where('nation_id', $nation->id)
                ->where('role', 'ruler'))
            ->sole();
        $body = "{$applicant->name}さんから{$nation->display_name}への加入申請が届きました。";
        if ($message !== '') {
            $body .= "\n一言：{$message}";
        }

        $this->notifications->create(
            character: $ruler,
            category: 'nation',
            type: 'nation_join_application_submitted',
            title: '【国家】加入申請が届きました',
            body: $body,
            actionLabel: '加入申請を見る',
            actionUrl: route('nation.applications'),
            payload: [
                'nation_id' => $nation->id,
                'nation_join_application_id' => $application->id,
                'applicant_character_id' => $applicant->id,
            ],
        );
    }

    private function notifyApplicantOfApproval(
        Nation $nation,
        Character $applicant,
        NationJoinApplication $application,
    ): void {
        $this->notifications->create(
            character: $applicant,
            category: 'nation',
            type: 'nation_join_application_approved',
            title: '【国家】加入申請が承認されました',
            body: "{$nation->display_name}への加入申請が承認され、国民になりました。",
            payload: [
                'nation_id' => $nation->id,
                'nation_join_application_id' => $application->id,
            ],
        );
    }
}
