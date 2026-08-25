<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Support\Facades\DB;

final class NationRulerTransferService
{
    public function __construct(
        private readonly NationRoleService $roles,
        private readonly NationActivityLogService $activityLogs,
    ) {}

    public function transfer(Character $actor, NationMembership $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $lockedActorCharacter = Character::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actorMembership = NationMembership::where('character_id', $lockedActorCharacter->id)->lockForUpdate()->first();
            throw_unless($actorMembership, \DomainException::class, '国家へ所属していません。');
            $nation = Nation::whereKey($actorMembership->nation_id)->lockForUpdate()->firstOrFail();
            throw_unless($nation->status === Nation::STATUS_ACTIVE, \DomainException::class, '解散手続き中は統治者を譲渡できません。');
            $this->roles->authorize($actorMembership, 'transfer_rulership');

            $lockedTarget = NationMembership::whereKey($target->id)->lockForUpdate()->firstOrFail();
            throw_unless((int) $lockedTarget->nation_id === (int) $nation->id, \DomainException::class, '同じ国家の国民だけを譲渡先に選べます。');
            throw_if((int) $lockedTarget->id === (int) $actorMembership->id, \DomainException::class, '自分自身へ統治者を譲渡できません。');
            throw_if($lockedTarget->isRuler(), \DomainException::class, '選択した国民はすでに統治者です。');

            $rulers = NationMembership::where('nation_id', $nation->id)->where('role', 'ruler')->lockForUpdate()->get();
            throw_unless($rulers->count() === 1 && (int) $rulers->first()->id === (int) $actorMembership->id, \DomainException::class, '統治者の所属状態が不正なため譲渡を中止しました。');

            $targetCharacter = Character::whereKey($lockedTarget->character_id)->lockForUpdate()->firstOrFail();
            $actorMembership->update(['role' => 'citizen']);
            $lockedTarget->update(['role' => 'ruler']);

            $this->activityLogs->record($nation, 'ruler_transferred', $lockedActorCharacter, $targetCharacter);
        }, 3);
    }
}
