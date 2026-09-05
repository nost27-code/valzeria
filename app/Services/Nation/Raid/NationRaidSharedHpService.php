<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBossCycle;
use App\Models\NationRaidEvent;

/** 呼出元がcoordinator→event→current cycleをlock済みの同一transactionでのみ使用する。 */
final readonly class NationRaidSharedHpService
{
    public function __construct(private NationRaidEventService $events, private NationRaidRules $rules) {}

    /** @return array{cycle:NationRaidBossCycle,segments:list<array<string,mixed>>} */
    public function apply(NationRaidEvent $event, NationRaidBossCycle $cycle, int $damage, string $source): array
    {
        throw_if($damage < 0 || ! in_array($source, ['personal', 'coordination'], true), \LogicException::class, 'Invalid raid damage.');
        $segments = [];
        while ($damage > 0) {
            throw_if($cycle->current_hp < 1 || $cycle->max_hp < 1, \LogicException::class, 'Current raid cycle is not alive.');
            $applied = min($damage, (int) $cycle->current_hp);
            $before = (int) $cycle->current_hp;
            $cycle->current_hp -= $applied;
            $damage -= $applied;
            $segments[] = [
                'cycle_no' => $cycle->cycle_no, 'cycle_kind' => $cycle->cycle_kind,
                'stage_no' => $cycle->stage_no, 'echo_no' => $cycle->echo_no,
                'source' => $source, 'damage' => $applied,
                'hp_before' => $before, 'hp_after' => $cycle->current_hp,
            ];
            if ($cycle->current_hp > 0) {
                $cycle->current_form = $this->rules->formForHp($cycle->current_hp, $cycle->max_hp);
                $cycle->save();
                break;
            }

            $cycle->defeated_at = now();
            $cycle->save();
            if ($cycle->cycle_kind === NationRaidBossCycle::KIND_MAIN && $cycle->stage_no === $event->stage_count) {
                $event->completed_at ??= now();
            } elseif ($cycle->cycle_kind === NationRaidBossCycle::KIND_ECHO) {
                $event->echo_defeated_count++;
            }

            // 確定damageは次再臨の防御・形態・乱数で再計算しない。完全撃破時も次個体を作る。
            $nextNo = $cycle->cycle_no + 1;
            $isMain = $nextNo <= $event->stage_count;
            $stage = $isMain ? $nextNo : $event->stage_count;
            $snapshot = $this->events->cycleParameterSnapshot($stage, $event);
            $cycle = NationRaidBossCycle::query()->create([
                'event_id' => $event->id, 'cycle_no' => $nextNo,
                'cycle_kind' => $isMain ? NationRaidBossCycle::KIND_MAIN : NationRaidBossCycle::KIND_ECHO,
                'stage_no' => $isMain ? $stage : null,
                'echo_no' => $isMain ? null : $nextNo - $event->stage_count,
                'max_hp' => $snapshot['boss']['max_hp'], 'current_hp' => $snapshot['boss']['max_hp'],
                'current_form' => NationRaidRules::FORM_SEALED_SCALE,
                'boss_species_key' => $snapshot['boss']['species_key'],
                'parameter_snapshot' => $snapshot, 'started_at' => now(),
            ]);
            $event->current_cycle_no = $nextNo;
            if ($isMain && $stage === 10) {
                $event->stage10_reached_at ??= now();
            }
        }

        return ['cycle' => $cycle, 'segments' => $segments];
    }
}
