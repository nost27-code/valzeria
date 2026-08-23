<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\NationMembership;
use App\Models\NationWar;
use App\Models\NationWarDailySortie;
use App\Models\NationWarFacility;
use App\Models\NationWarParticipant;
use App\Models\NationWarSide;
use App\Models\NationWarSortieLog;
use App\Services\ExplorationStaminaService;
use Illuminate\Support\Facades\DB;

final class NationWarBattleService
{
    /** @return array{log:NationWarSortieLog,battle:mixed,damage_applied:int} */
    public function sortie(Character $character, NationWar $war, int $targetFacilityId, int $retreatLine = 20): array
    {
        $settings = app(NationWarSettingsService::class);
        throw_unless($settings->featureEnabled(), \DomainException::class, '国家機能は準備中です。');
        throw_unless($war->status === 'active' && $war->starts_at->lte(now()) && $war->ends_at->gt(now()), \DomainException::class, '現在は出撃できません。');
        $membership = NationMembership::where('character_id', $character->id)->first();
        throw_unless($membership && NationWarParticipant::where('nation_war_id', $war->id)->where('character_id', $character->id)->exists(), \DomainException::class, 'この国家戦の参加者ではありません。');
        $target = NationWarFacility::whereKey($targetFacilityId)->where('nation_war_id', $war->id)->firstOrFail();
        throw_if($target->nation_id === $membership->nation_id, \DomainException::class, '自国の施設は攻撃できません。');
        $this->assertTargetOpen($war, $target);
        $targetHpAtStart = max(0, (int) $target->current_hp);
        throw_if($targetHpAtStart < 1 || $target->status !== 'active', \DomainException::class, 'その施設はすでに破壊されています。');

        $daily = DB::transaction(function () use ($character, $war, $settings): NationWarDailySortie {
            $daily = NationWarDailySortie::firstOrCreate(
                ['nation_war_id' => $war->id, 'character_id' => $character->id, 'sortie_date' => today()],
                ['sortie_count' => 0, 'death_count' => 0],
            );
            $daily = NationWarDailySortie::whereKey($daily->id)->lockForUpdate()->firstOrFail();
            throw_if($daily->sortie_count >= $settings->sortiesPerDay(), \DomainException::class, '本日の出撃回数を使い切りました。');
            $stamina = app(ExplorationStaminaService::class)->consumeRequired($character, $settings->sortieStaminaCost(), '探索力が足りないため出撃できません。');
            throw_unless($stamina['ok'], \DomainException::class, $stamina['error'] ?? '探索力を消費できませんでした。');
            $daily->increment('sortie_count');
            return $daily->refresh();
        }, 3);

        $cannon = NationWarFacility::where('nation_war_id', $war->id)->where('nation_id', $target->nation_id)->where('facility_type', 'magic_cannon')->first();
        $battle = app(NationWarBattleEngine::class)->fight(
            $character, $targetHpAtStart, (bool) ($cannon && $cannon->status === 'active' && $cannon->current_hp > 0),
            (int) ($cannon?->level ?? 1), $retreatLine,
        );

        [$log, $applied, $ko] = DB::transaction(function () use ($character, $war, $membership, $target, $targetHpAtStart, $retreatLine, $battle, $daily): array {
            $locked = NationWarFacility::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $before = (int) $locked->current_hp;
            $applied = min($before, max(0, (int) $battle['result']->damageDealt));
            $after = $before - $applied;
            $destroyed = $after === 0 && $before > 0;
            $locked->update([
                'current_hp' => $after, 'min_hp' => min((int) $locked->min_hp, $after),
                'status' => $destroyed ? 'destroyed' : $locked->status,
                'destroyed_at' => $destroyed ? now() : $locked->destroyed_at,
            ]);
            if ($battle['died']) {
                $lockedDaily = NationWarDailySortie::whereKey($daily->id)->lockForUpdate()->firstOrFail();
                $lockedDaily->increment('death_count');
                $extra = app(NationWarSettingsService::class)->deathExtraSorties();
                if ($extra > 0 && $lockedDaily->sortie_count < app(NationWarSettingsService::class)->sortiesPerDay()) {
                    $lockedDaily->increment('sortie_count', min($extra, app(NationWarSettingsService::class)->sortiesPerDay() - $lockedDaily->sortie_count));
                }
            }
            $log = NationWarSortieLog::create([
                'nation_war_id' => $war->id, 'attacking_nation_id' => $membership->nation_id,
                'defending_nation_id' => $locked->nation_id, 'character_id' => $character->id,
                'target_facility_type' => $locked->facility_type, 'damage_applied' => $applied,
                'turn_count' => $battle['result']->turnCount, 'cannon_hit_count' => $battle['cannon_hits'],
                'cannon_direct_hit' => $battle['direct_hit'], 'died' => $battle['died'],
                'retreat_line' => in_array($retreatLine, [0,12,20,30], true) ? $retreatLine : 20,
                'target_hp_before' => $before, 'target_hp_after' => $after,
                'summary' => ['result' => $battle['result']->result, 'retreated' => $battle['retreated'], 'simulated_target_hp' => $targetHpAtStart],
            ]);
            return [$log, $applied, $destroyed && $locked->facility_type === 'headquarters'];
        }, 3);

        if ($ko) app(NationWarJudgmentService::class)->resolve($war, 'ko');
        else $this->runAutomaticOperations($war, $target->refresh());

        return ['log' => $log, 'battle' => $battle['result'], 'damage_applied' => $applied];
    }

    private function assertTargetOpen(NationWar $war, NationWarFacility $target): void
    {
        if ($target->facility_type === 'wall') return;
        $wall = NationWarFacility::where('nation_war_id', $war->id)->where('nation_id', $target->nation_id)->where('facility_type', 'wall')->first();
        throw_if($wall && $wall->status === 'active' && $wall->current_hp > 0, \DomainException::class, '城壁が内部施設への攻撃を阻んでいます。');
    }

    private function runAutomaticOperations(NationWar $war, NationWarFacility $facility): void
    {
        $side = NationWarSide::where('nation_war_id', $war->id)->where('nation_id', $facility->nation_id)->first();
        if (! $side) return;
        try {
            if ($facility->current_hp === 0 && in_array($facility->facility_type, ['wall','magic_cannon','logistics'], true)) {
                $auto = DB::table('nation_war_auto_rebuild_settings')->where('nation_war_id', $war->id)->where('nation_id', $facility->nation_id)->where('facility_type', $facility->facility_type)->where('enabled', true)->exists();
                if ($auto) app(NationWarRebuildService::class)->start($side, $facility);
                return;
            }
            $auto = DB::table('nation_war_auto_repair_settings')->where('nation_war_id', $war->id)->where('nation_id', $facility->nation_id)->where('facility_type', $facility->facility_type)->where('enabled', true)->first();
            if (! $auto || $facility->max_hp < 1) return;
            $bps = (int) floor(($facility->current_hp / $facility->max_hp) * 10000);
            if ($bps > (int) $auto->trigger_bps) return;
            $targetHp = (int) floor($facility->max_hp * ((int) $auto->target_bps / 10000));
            app(NationWarRepairService::class)->repair($side, $facility, max(0, $targetHp - $facility->current_hp));
        } catch (\DomainException) {
            // 自動処理は資材不足・施設停止時に何もせず、成立済み攻撃を巻き戻さない。
        }
    }
}
