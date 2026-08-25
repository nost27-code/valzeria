<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Support\Facades\DB;

final class NationProfileService
{
    public function __construct(
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationEmblemCatalog $emblems,
    ) {}

    public function update(
        NationMembership $actor,
        ?string $description,
        bool $recruitmentEnabled,
        ?string $recruitmentMessage,
        string $emblemKey,
    ): Nation {
        $description = trim((string) $description);
        $recruitmentMessage = trim((string) $recruitmentMessage);
        throw_if(mb_strlen($description) > 200, \DomainException::class, '国家紹介は200文字以内で入力してください。');
        throw_if(mb_strlen($recruitmentMessage) > 100, \DomainException::class, '募集文は100文字以内で入力してください。');
        throw_unless($this->emblems->exists($emblemKey), \DomainException::class, '選択した国家紋章は使用できません。');

        return DB::transaction(function () use ($actor, $description, $recruitmentEnabled, $recruitmentMessage, $emblemKey): Nation {
            $nation = Nation::whereKey($actor->nation_id)->lockForUpdate()->firstOrFail();
            throw_unless($nation->status === Nation::STATUS_ACTIVE, \DomainException::class, '解散手続き中の国家設定は変更できません。');
            $lockedActor = NationMembership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->roles->authorize($lockedActor, 'manage_profile');

            $nextDescription = $description !== '' ? $description : null;
            $nextRecruitmentMessage = $recruitmentMessage !== '' ? $recruitmentMessage : null;
            $changes = [
                'description' => $nextDescription,
                'recruitment_enabled' => $recruitmentEnabled,
                'recruitment_message' => $nextRecruitmentMessage,
                'emblem_key' => $emblemKey,
            ];
            $previous = $nation->only(array_keys($changes));
            $nation->update($changes);
            $actorCharacter = $lockedActor->character;

            if ($previous['description'] !== $nextDescription) {
                $this->activityLogs->record($nation, 'description_changed', $actorCharacter);
            }
            if ((bool) $previous['recruitment_enabled'] !== $recruitmentEnabled) {
                $this->activityLogs->record($nation, $recruitmentEnabled ? 'recruitment_enabled' : 'recruitment_disabled', $actorCharacter);
            }
            if ($previous['recruitment_message'] !== $nextRecruitmentMessage) {
                $this->activityLogs->record($nation, 'recruitment_message_changed', $actorCharacter);
            }
            if ($previous['emblem_key'] !== $emblemKey) {
                $this->activityLogs->record($nation, 'emblem_changed', $actorCharacter, null, ['emblem_key' => $emblemKey]);
            }

            return $nation->fresh();
        }, 3);
    }
}
