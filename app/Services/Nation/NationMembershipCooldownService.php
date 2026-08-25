<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationMembershipCooldown;
use Carbon\CarbonInterface;

final class NationMembershipCooldownService
{
    public function __construct(private readonly NationCommunitySettingsService $settings) {}

    /** @return array{allowed:bool,reason:?string,blocked_until:?CarbonInterface} */
    public function joinEligibility(Character $character, Nation $nation): array
    {
        $cooldown = NationMembershipCooldown::where('character_id', $character->id)->first();
        if (! $cooldown) {
            return ['allowed' => true, 'reason' => null, 'blocked_until' => null];
        }

        if ($cooldown->global_join_blocked_until?->isFuture()) {
            $reason = $cooldown->reason === 'left'
                ? '自主脱退後の待機期間中です。'
                : '追放後の待機期間中です。';

            return [
                'allowed' => false,
                'reason' => $reason,
                'blocked_until' => $cooldown->global_join_blocked_until,
            ];
        }

        if ((int) $cooldown->same_nation_id === (int) $nation->id
            && $cooldown->same_nation_blocked_until?->isFuture()) {
            return [
                'allowed' => false,
                'reason' => 'この国家へは再申請待機期間中です。',
                'blocked_until' => $cooldown->same_nation_blocked_until,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'blocked_until' => null];
    }

    public function assertCanJoin(Character $character, Nation $nation): void
    {
        $eligibility = $this->joinEligibility($character, $nation);
        if ($eligibility['allowed']) {
            return;
        }

        throw new \DomainException($eligibility['reason'].' 残り '.$this->remainingLabel($eligibility['blocked_until']));
    }

    public function assertCanFound(Character $character): void
    {
        $cooldown = NationMembershipCooldown::where('character_id', $character->id)->first();
        if ($cooldown?->ruler_refound_blocked_until?->isFuture()) {
            throw new \DomainException('国家解散後の再建国待機期間中です。 残り '.$this->remainingLabel($cooldown->ruler_refound_blocked_until));
        }
    }

    public function applyVoluntaryLeave(Character $character): NationMembershipCooldown
    {
        return $this->updateLocked($character, function (NationMembershipCooldown $cooldown): void {
            $until = now()->addHours($this->settings->leaveJoinCooldownHours());
            $cooldown->global_join_blocked_until = $this->later($cooldown->global_join_blocked_until, $until);
            $cooldown->reason = 'left';
        });
    }

    public function applyExpulsion(Character $character, Nation $nation): NationMembershipCooldown
    {
        return $this->updateLocked($character, function (NationMembershipCooldown $cooldown) use ($nation): void {
            $globalUntil = now()->addHours($this->settings->expelJoinCooldownHours());
            $sameNationUntil = now()->addDays($this->settings->expelSameNationCooldownDays());
            $cooldown->global_join_blocked_until = $this->later($cooldown->global_join_blocked_until, $globalUntil);
            $cooldown->same_nation_id = $nation->id;
            $cooldown->same_nation_blocked_until = $this->later($cooldown->same_nation_blocked_until, $sameNationUntil);
            $cooldown->reason = 'expelled';
        });
    }

    public function applyRulerRefoundBlock(Character $character): NationMembershipCooldown
    {
        return $this->updateLocked($character, function (NationMembershipCooldown $cooldown): void {
            $until = now()->addDays($this->settings->rulerRefoundCooldownDays());
            $cooldown->ruler_refound_blocked_until = $this->later($cooldown->ruler_refound_blocked_until, $until);
        });
    }

    public function remainingLabel(?CarbonInterface $until): string
    {
        if (! $until || $until->isPast()) {
            return '0分';
        }

        $minutes = max(1, (int) ceil(now()->diffInMinutes($until)));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $remainingMinutes = $minutes % 60;

        if ($days > 0) {
            return $days.'日'.($hours > 0 ? ' '.$hours.'時間' : '');
        }

        if ($hours > 0) {
            return $hours.'時間'.($remainingMinutes > 0 ? $remainingMinutes.'分' : '');
        }

        return $remainingMinutes.'分';
    }

    private function updateLocked(Character $character, \Closure $mutator): NationMembershipCooldown
    {
        $cooldown = NationMembershipCooldown::where('character_id', $character->id)->lockForUpdate()->first();
        if (! $cooldown) {
            $cooldown = new NationMembershipCooldown(['character_id' => $character->id]);
        }

        $mutator($cooldown);
        $cooldown->save();

        return $cooldown;
    }

    private function later(?CarbonInterface $current, CarbonInterface $candidate): CarbonInterface
    {
        return $current && $current->gt($candidate) ? $current : $candidate;
    }
}
