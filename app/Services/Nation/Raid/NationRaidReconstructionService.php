<?php

namespace App\Services\Nation\Raid;

use App\Models\BattleLog;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationRaidInvasionDamage;
use App\Models\NationRaidReconstructionContribution;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/** 通常探索勝利を、現在所属国の最古の未復興被害へ1点ずつ充当する。 */
final class NationRaidReconstructionService
{
    /** @return array{recorded:bool,recovered:bool,completed:?int,required:?int} */
    public function recordNormalExplorationVictory(Character $character, BattleLog $battleLog, ?DateTimeInterface $at = null): array
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return DB::transaction(function () use ($character, $battleLog, $at): array {
            if ((int) $battleLog->character_id !== (int) $character->id
                || $battleLog->battle_type !== 'normal'
                || ! in_array($battleLog->result, BattleLog::WIN_RESULTS, true)) {
                return ['recorded' => false, 'recovered' => false, 'completed' => null, 'required' => null];
            }
            $lockedCharacter = Character::query()->whereKey($character->id)->lockForUpdate()->firstOrFail();
            if (NationRaidReconstructionContribution::query()->where('battle_log_id', $battleLog->id)->exists()) {
                return ['recorded' => false, 'recovered' => false, 'completed' => null, 'required' => null];
            }
            $lockedCharacter->load('nationMembership.nation');
            $nation = $lockedCharacter->nationMembership?->nation;
            if (! $nation instanceof Nation || $nation->status !== Nation::STATUS_ACTIVE) {
                return ['recorded' => false, 'recovered' => false, 'completed' => null, 'required' => null];
            }

            $damage = NationRaidInvasionDamage::query()->where('nation_id', $nation->id)
                ->outstanding()->orderBy('event_id')->orderBy('id')->lockForUpdate()->first();
            if (! $damage) {
                return ['recorded' => false, 'recovered' => false, 'completed' => null, 'required' => null];
            }

            $dailyCap = (int) ($damage->result_snapshot['reconstruction_daily_cap']
                ?? config('nation_raid.outcome.reconstruction_daily_cap', 3));
            $usedToday = NationRaidReconstructionContribution::query()
                ->where('nation_id_snapshot', $nation->id)
                ->where('character_id_snapshot', $lockedCharacter->id)
                ->where('contributed_on', $at->toDateString())
                ->count();
            if ($usedToday >= $dailyCap) {
                return ['recorded' => false, 'recovered' => false, 'completed' => null, 'required' => null];
            }

            NationRaidReconstructionContribution::query()->create([
                'invasion_damage_id' => $damage->id,
                'nation_id_snapshot' => $nation->id,
                'character_id_snapshot' => $lockedCharacter->id,
                'character_id' => $lockedCharacter->id,
                'battle_log_id' => $battleLog->id,
                'amount' => 1,
                'contributed_on' => $at->toDateString(),
            ]);
            $damage->reconstruction_completed = min(
                (int) $damage->reconstruction_required,
                (int) $damage->reconstruction_completed + 1,
            );
            $recovered = $damage->reconstruction_completed >= $damage->reconstruction_required;
            if ($recovered) {
                $damage->status = NationRaidInvasionDamage::STATUS_RECOVERED;
                $damage->recovered_at = $at;
            }
            $damage->save();

            return [
                'recorded' => true,
                'recovered' => $recovered,
                'completed' => (int) $damage->reconstruction_completed,
                'required' => (int) $damage->reconstruction_required,
            ];
        }, 3);
    }

    public function outstandingForCharacter(Character $character): ?array
    {
        $character->loadMissing('nationMembership.nation');
        $nation = $character->nationMembership?->nation;
        if (! $nation instanceof Nation || $nation->status !== Nation::STATUS_ACTIVE) {
            return null;
        }
        $rows = NationRaidInvasionDamage::query()->where('nation_id', $nation->id)
            ->outstanding()->orderBy('event_id')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'nation_name' => $nation->display_name,
            'damage' => (int) $rows->sum('final_damage'),
            'completed' => (int) $rows->sum('reconstruction_completed'),
            'required' => (int) $rows->sum('reconstruction_required'),
            'daily_cap' => (int) ($rows->first()->result_snapshot['reconstruction_daily_cap']
                ?? config('nation_raid.outcome.reconstruction_daily_cap', 3)),
            'events' => $rows->count(),
        ];
    }
}
