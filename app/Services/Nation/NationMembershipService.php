<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationMembership;
use Illuminate\Support\Facades\DB;

final class NationMembershipService
{
    public const MAX_MEMBERS = 100;

    public function join(Character $character, Nation $nation): NationMembership
    {
        return DB::transaction(function () use ($character, $nation): NationMembership {
            $lockedNation = Nation::whereKey($nation->id)->lockForUpdate()->firstOrFail();
            throw_if(NationMembership::where('character_id', $character->id)->exists(), \DomainException::class, 'すでに国家へ所属しています。');
            throw_if($lockedNation->memberships()->count() >= app(NationWarSettingsService::class)->maxMembers(), \DomainException::class, 'この国家は定員に達しています。');

            return NationMembership::create(['nation_id' => $lockedNation->id, 'character_id' => $character->id, 'role' => 'citizen', 'joined_at' => now()]);
        }, 3);
    }

    public function changeRole(NationMembership $actor, NationMembership $target, string $role): void
    {
        app(NationRoleService::class)->authorize($actor, 'manage_roles');
        throw_unless($actor->nation_id === $target->nation_id, \DomainException::class, '同じ国家の国民ではありません。');
        throw_unless(in_array($role, NationMembership::ROLES, true), \DomainException::class, '指定された役職は存在しません。');
        throw_if($target->role === 'king' || $role === 'king', \DomainException::class, '国王の交代は専用手続きが必要です。');
        $target->update(['role' => $role]);
    }
}
