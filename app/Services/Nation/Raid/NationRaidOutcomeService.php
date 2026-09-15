<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;
use App\Models\NationRaidInvasionDamage;
use App\Models\NationRaidNationPreparation;
use App\Models\NationRaidParticipation;
use Illuminate\Support\Facades\DB;

/** 主再臨の削減率から討滅・撃退・侵攻を確定し、国家別の復興目標を固定する。 */
final readonly class NationRaidOutcomeService
{
    public function __construct(private NationRaidRules $rules, private NationRaidRewardPolicy $hashes) {}

    public function finalizeLocked(NationRaidEvent $event): ?array
    {
        throw_unless(DB::transactionLevel() > 0 && $event->status === NationRaidEvent::STATUS_FINALIZING,
            \LogicException::class, 'Raid outcome requires a locked finalizing event.');
        if (! $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)) {
            return null;
        }

        $progressBps = $this->progressBps($event);
        $outcomeRules = $event->ruleset_snapshot['raid_cycle']['outcome'];
        $repelledBps = (int) $outcomeRules['repelled_threshold_percent'] * 100;
        $resultType = match (true) {
            $event->completed_at !== null || $progressBps >= 10_000 => NationRaidEvent::RESULT_EXTERMINATED,
            $progressBps >= $repelledBps => NationRaidEvent::RESULT_REPELLED,
            default => NationRaidEvent::RESULT_INVASION,
        };
        $snapshot = [
            'version' => 1,
            'event_id' => (int) $event->id,
            'result_type' => $resultType,
            'progress_bps' => $progressBps,
            'total_target_hp' => (int) $event->total_target_hp,
            'resolved_at' => $event->finalization_started_at?->toIso8601String()
                ?? $event->ends_at->toIso8601String(),
            'ruleset_hash' => $event->ruleset_hash,
        ];
        $hash = $this->hashes->hash($snapshot);
        if ($event->result_snapshot !== null || $event->result_hash !== null) {
            throw_unless($event->result_snapshot === $snapshot && hash_equals((string) $event->result_hash, $hash),
                \DomainException::class, '保存済みのレイド結果区分が一致しません。');
        } else {
            $event->fill([
                'result_type' => $resultType,
                'result_progress_bps' => $progressBps,
                'result_snapshot' => $snapshot,
                'result_hash' => $hash,
            ])->save();
        }

        if ($resultType === NationRaidEvent::RESULT_INVASION) {
            $this->createInvasionDamageLocked($event, $progressBps, $outcomeRules);
        }

        return $snapshot;
    }

    public function progressBps(NationRaidEvent $event): int
    {
        $target = (int) $event->total_target_hp;
        throw_if($target < 1, \DomainException::class, 'レイド総HPを確認できません。');
        $dealt = NationRaidBossCycle::query()->where('event_id', $event->id)
            ->where('cycle_kind', NationRaidBossCycle::KIND_MAIN)
            ->get(['max_hp', 'current_hp'])
            ->sum(fn (NationRaidBossCycle $cycle): int => max(0, (int) $cycle->max_hp - (int) $cycle->current_hp));

        return min(10_000, intdiv((int) $dealt * 10_000, $target));
    }

    public function presentation(NationRaidEvent $event): ?array
    {
        $progress = $event->result_progress_bps;
        if ($progress === null && $this->rules->supportsNextCycleSystems($event->ruleset_snapshot)
            && in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING], true)) {
            $progress = $this->progressBps($event);
        }
        if ($progress === null) {
            return null;
        }
        $label = match ($event->result_type) {
            NationRaidEvent::RESULT_EXTERMINATED => '討滅',
            NationRaidEvent::RESULT_REPELLED => '撃退',
            NationRaidEvent::RESULT_INVASION => '侵攻',
            default => '交戦中',
        };

        return ['type' => $event->result_type, 'label' => $label, 'progress_bps' => (int) $progress,
            'progress_percent' => round((int) $progress / 100, 2)];
    }

    private function createInvasionDamageLocked(NationRaidEvent $event, int $progressBps, array $rules): void
    {
        $baseDamage = $this->thresholdValue($rules['invasion_damage'], intdiv($progressBps, 100), 'minimum_progress_percent', 'damage');
        $minimum = (int) $rules['minimum_invasion_damage'];
        $effectiveSorties = (int) $rules['effective_participation_sorties'];
        $divisor = (int) $rules['reconstruction_damage_divisor'];
        throw_if($divisor < 1, \DomainException::class, '復興目標の算出規則が不正です。');

        foreach (NationRaidNationPreparation::query()->where('event_id', $event->id)->orderBy('nation_id_snapshot')->lockForUpdate()->get() as $preparation) {
            $active = (int) $preparation->reference_active_count;
            if ($active < 1) {
                continue;
            }
            $effectiveCount = NationRaidParticipation::query()->where('event_id', $event->id)
                ->where('nation_id_snapshot', $preparation->nation_id_snapshot)
                ->where('is_nation_eligible', true)
                ->where('resolved_sorties', '>=', $effectiveSorties)
                ->count();
            $participationPercent = min(100, intdiv($effectiveCount * 100, $active));
            $readinessMitigation = $this->steppedValue($rules['readiness_mitigation'], (int) $preparation->readiness_percent);
            $participationMitigation = $this->steppedValue($rules['participation_mitigation'], $participationPercent);
            $finalDamage = max($minimum, $baseDamage - $readinessMitigation - $participationMitigation);
            $required = intdiv($active * $finalDamage + $divisor - 1, $divisor);
            $snapshot = [
                'version' => 1,
                'event_id' => (int) $event->id,
                'nation_id_snapshot' => (int) $preparation->nation_id_snapshot,
                'progress_bps' => $progressBps,
                'reference_active_count' => $active,
                'readiness_percent' => (int) $preparation->readiness_percent,
                'effective_participant_count' => $effectiveCount,
                'participation_percent' => $participationPercent,
                'base_damage' => $baseDamage,
                'readiness_mitigation' => $readinessMitigation,
                'participation_mitigation' => $participationMitigation,
                'final_damage' => $finalDamage,
                'reconstruction_required' => $required,
                'reconstruction_daily_cap' => (int) $rules['reconstruction_daily_cap'],
            ];
            $hash = $this->hashes->hash($snapshot);
            $row = NationRaidInvasionDamage::query()->firstOrCreate([
                'event_id' => $event->id,
                'nation_id_snapshot' => $preparation->nation_id_snapshot,
            ], [
                'preparation_id' => $preparation->id,
                'nation_id' => $preparation->nation_id,
                'nation_name_snapshot' => $preparation->nation_name_snapshot,
                'reference_active_count' => $active,
                'result_progress_bps' => $progressBps,
                'readiness_percent' => $preparation->readiness_percent,
                'effective_participant_count' => $effectiveCount,
                'participation_percent' => $participationPercent,
                'base_damage' => $baseDamage,
                'readiness_mitigation' => $readinessMitigation,
                'participation_mitigation' => $participationMitigation,
                'final_damage' => $finalDamage,
                'reconstruction_required' => $required,
                'result_snapshot' => $snapshot,
                'result_hash' => $hash,
            ]);
            throw_unless($row->result_snapshot === $snapshot && hash_equals((string) $row->result_hash, $hash),
                \DomainException::class, '保存済みの国家侵攻被害が一致しません。');
        }
    }

    private function steppedValue(array $steps, int $value): int
    {
        $result = 0;
        foreach ($steps as $threshold => $candidate) {
            if ($value >= (int) $threshold) {
                $result = max($result, (int) $candidate);
            }
        }

        return $result;
    }

    private function thresholdValue(array $steps, int $value, string $thresholdKey, string $valueKey): int
    {
        foreach ($steps as $step) {
            if ($value >= (int) $step[$thresholdKey]) {
                return (int) $step[$valueKey];
            }
        }

        throw new \DomainException('侵攻被害の段階規則を確認できません。');
    }
}
