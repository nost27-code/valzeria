<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationWar;
use App\Models\NationWarHistory;
use Illuminate\Support\Facades\DB;

final class NationWarJudgmentService
{
    public function resolve(NationWar $war, string $reason = 'time'): NationWar
    {
        return DB::transaction(function () use ($war, $reason): NationWar {
            $locked = NationWar::whereKey($war->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'resolved') return $locked;
            $facilities = $locked->facilities()->lockForUpdate()->get()->groupBy('nation_id');
            $attacker = $this->score($facilities->get($locked->declaring_nation_id, collect()));
            $defender = $this->score($facilities->get($locked->defending_nation_id, collect()));
            $winnerId = $this->winner($locked, $attacker, $defender);
            $resolution = $winnerId ? ($reason === 'ko' ? 'ko' : 'judgment') : 'draw';
            $snapshot = ['attacker' => $attacker, 'defender' => $defender];
            $locked->update(['status' => 'resolved', 'winner_nation_id' => $winnerId, 'resolution_type' => $resolution, 'resolution_snapshot' => $snapshot, 'resolved_at' => now()]);
            $history = NationWarHistory::updateOrCreate(['nation_war_id' => $locked->id], [
                'declaring_nation_id' => $locked->declaring_nation_id, 'defending_nation_id' => $locked->defending_nation_id,
                'winner_nation_id' => $winnerId, 'resolution_type' => $resolution, 'summary' => $snapshot, 'resolved_at' => now(),
            ]);
            $this->applyNationResults($locked, $winnerId);
            $this->persistConditions($facilities);
            if (app(NationLevelBenefitSettingsService::class)->enabled()) {
                app(NationTimelineService::class)->recordWarResolved($history);
                app(NationAchievementService::class)->recordWarResolved($history);
            }
            foreach ($locked->sides()->get() as $side) app(NationWarService::class)->refundUnusedPool($side);
            return $locked->refresh();
        }, 3);
    }

    /** @return array{headquarters:float,internal:float,all:float} */
    private function score($facilities): array
    {
        $ratio = fn ($f): float => $f && $f->opening_max_hp > 0 ? min(1, max(0, ($f->opening_max_hp - $f->min_hp) / $f->opening_max_hp)) : 0.0;
        $hq = $facilities->firstWhere('facility_type', 'headquarters');
        $internal = $facilities->whereIn('facility_type', ['magic_cannon','logistics','arsenal']);
        return [
            'headquarters' => $ratio($hq),
            'internal' => $internal->count() ? (float) $internal->avg($ratio) : 0.0,
            'all' => $facilities->count() ? (float) $facilities->avg($ratio) : 0.0,
        ];
    }

    private function winner(NationWar $war, array $attacker, array $defender): ?int
    {
        foreach (['headquarters','internal','all'] as $key) {
            if (abs($attacker[$key] - $defender[$key]) > 0.000001) return $attacker[$key] > $defender[$key] ? $war->declaring_nation_id : $war->defending_nation_id;
        }
        return null;
    }

    private function applyNationResults(NationWar $war, ?int $winnerId): void
    {
        foreach ([$war->declaring_nation_id, $war->defending_nation_id] as $nationId) {
            $nation = Nation::whereKey($nationId)->lockForUpdate()->firstOrFail();
            if ($winnerId === null) { $nation->increment('war_draws'); continue; }
            if ($nationId === $winnerId) { $nation->increment('war_wins'); continue; }
            $nation->increment('war_losses');
            $nation->update(['loss_protected_until' => now()->addDays(app(NationWarSettingsService::class)->lossProtectionDays())]);
            NationWar::where('status', 'reserved')->where(fn ($q) => $q->where('declaring_nation_id', $nationId)->orWhere('defending_nation_id', $nationId))->update(['status' => 'cancelled', 'resolved_at' => now(), 'resolution_type' => 'loss_reservation_cancelled']);
        }
    }

    private function persistConditions($grouped): void
    {
        foreach ($grouped->flatten() as $warFacility) {
            $condition = $warFacility->max_hp > 0 ? (int) floor(($warFacility->current_hp / $warFacility->max_hp) * 10000) : 0;
            NationFacility::where('nation_id', $warFacility->nation_id)->where('facility_type', $warFacility->facility_type)->update(['condition_bps' => max(0, min(10000, $condition))]);
        }
    }
}
