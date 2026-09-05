<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidEvent;
use Illuminate\Support\Facades\DB;

/** 保存済み実適用damageと開始時帰属だけから順位を導出する。報酬を付与しない。 */
final class NationRaidRankingService
{
    public function standings(NationRaidEvent $event): array
    {
        if ($event->status === NationRaidEvent::STATUS_COMPLETED) {
            $snapshot = $event->final_standings_snapshot;
            throw_unless(is_array($snapshot) && is_string($event->final_standings_hash)
                && hash_equals($event->final_standings_hash, app(NationRaidRewardPolicy::class)->hash($snapshot)),
                \DomainException::class, '確定済みレイド順位の保存内容を確認できません。');
            return $snapshot;
        }
        // 一つのSELECTで帰属と結果を読む。現在所属・表示名や参加者集計cacheへ追随しない。
        $rows = DB::table('nation_raid_battle_results as b')
            ->join('nation_raid_participations as p', 'p.id', '=', 'b.participation_id')
            ->where('b.event_id', $event->id)->whereColumn('p.event_id', 'b.event_id')
            ->where('b.status', NationRaidBattleResult::STATUS_RESOLVED)
            ->orderBy('b.resolved_at')->orderBy('b.id')
            ->get(['p.id as participation_id', 'p.account_id', 'p.character_id', 'p.nation_id', 'p.is_nation_eligible',
                'p.reference_active_count', 'p.character_name_snapshot', 'p.nation_name_snapshot',
                'p.character_id_snapshot', 'p.nation_id_snapshot',
                'b.applied_damage_total', 'b.coordination_damage_total', 'b.nation_damage_total', 'b.max_action_damage', 'b.resolved_at']);
        $players = [];
        $nations = [];
        $outside = 0;
        $total = 0;
        $coordination = 0;
        foreach ($rows as $row) {
            $personal = $this->nonNegativeInt($row->applied_damage_total);
            $bonus = $this->nonNegativeInt($row->coordination_damage_total);
            $nationDamage = $this->nonNegativeInt($row->nation_damage_total);
            $max = $this->nonNegativeInt($row->max_action_damage);
            $frozenNationId = $row->nation_id_snapshot ?? $row->nation_id;
            $eligible = (bool) $row->is_nation_eligible && $frozenNationId !== null;
            throw_unless($row->resolved_at !== null && $max <= $personal
                && $nationDamage === ($eligible ? $this->add($personal, $bonus) : 0),
                \DomainException::class, 'レイド戦績の実適用値を確認できません。');
            $id = (int) $row->participation_id;
            $players[$id] ??= ['participation_id' => $id, 'account_id' => (int) $row->account_id,
                'character_id' => $row->character_id_snapshot ?? $row->character_id, 'nation_id' => $frozenNationId,
                'name' => $row->character_name_snapshot, 'damage' => 0, 'resolved_sorties' => 0,
                'max_action_damage' => 0, 'max_attained_at' => $row->resolved_at, 'attained_at' => $row->resolved_at,
                'is_nation_eligible' => $eligible];
            $players[$id]['damage'] = $this->add($players[$id]['damage'], $personal);
            $players[$id]['resolved_sorties']++;
            if ($personal > 0) {
                $players[$id]['attained_at'] = $row->resolved_at;
            }
            if ($max > $players[$id]['max_action_damage']) {
                $players[$id]['max_action_damage'] = $max;
                $players[$id]['max_attained_at'] = $row->resolved_at;
            }
            $total = $this->add($total, $this->add($personal, $bonus));
            $coordination = $this->add($coordination, $bonus);
            if (! $eligible) {
                $outside = $this->add($outside, $personal);
                continue;
            }
            $nationId = (int) $frozenNationId;
            $denominator = $this->nonNegativeInt($row->reference_active_count);
            $nations[$nationId] ??= ['nation_id' => $nationId, 'name' => $row->nation_name_snapshot,
                'damage' => 0, 'personal_damage' => 0, 'coordination_damage' => 0, 'denominator' => $denominator,
                'eligible_participant_count' => 0, 'participant_count' => 0, 'attained_at' => $row->resolved_at,
                'personal_attained_at' => $row->resolved_at];
            throw_unless($nations[$nationId]['denominator'] === $denominator, \DomainException::class, '国家基準人数が出撃記録間で一致しません。');
            $nations[$nationId]['damage'] = $this->add($nations[$nationId]['damage'], $nationDamage);
            $nations[$nationId]['personal_damage'] = $this->add($nations[$nationId]['personal_damage'], $personal);
            $nations[$nationId]['coordination_damage'] = $this->add($nations[$nationId]['coordination_damage'], $bonus);
            if ($nationDamage > 0) {
                $nations[$nationId]['attained_at'] = $row->resolved_at;
            }
            if ($personal > 0) {
                $nations[$nationId]['personal_attained_at'] = $row->resolved_at;
            }
        }
        $minimum = (int) ($event->reward_policy_snapshot !== null
            ? app(NationRaidRewardPolicy::class)->forEvent($event)['minimum_resolved_sorties']
            : config('nation_raid.qualification.minimum_resolved_sorties', 15));
        foreach ($players as &$player) {
            $player['qualified'] = $player['resolved_sorties'] >= $minimum;
            if ($player['is_nation_eligible']) {
                $nations[$player['nation_id']]['participant_count']++;
                $nations[$player['nation_id']]['eligible_participant_count'] += (int) $player['qualified'];
            }
        }
        unset($player);
        $maxRows = array_map(fn ($row) => [...$row, 'damage' => $row['max_action_damage'], 'attained_at' => $row['max_attained_at']], array_values($players));
        $perCapita = array_map(fn ($row) => [...$row, 'damage' => $row['personal_damage'], 'attained_at' => $row['personal_attained_at']], array_values($nations));

        return [
            'is_final' => $event->status === NationRaidEvent::STATUS_COMPLETED,
            'personal_total' => $this->rank(array_values($players)), 'max_action' => $this->rank($maxRows),
            'nation_total' => $this->rank(array_values($nations)), 'nation_per_capita' => $this->rank($perCapita, true),
            'unaffiliated_damage' => $outside, 'boss_damage' => $total, 'coordination_damage' => $coordination,
            'resolved_sorties' => $rows->count(),
        ];
    }

    /** 正の分母による分数の厳密比較。交差積と同じ順序だが64bit積のoverflowとfloat丸めを避ける。 */
    public function compareRatios(int $a, int $b, int $c, int $d): int
    {
        throw_unless($a >= 0 && $c >= 0 && $b > 0 && $d > 0, \InvalidArgumentException::class, 'Invalid raid ranking ratio.');
        $direction = 1;
        while (true) {
            $comparison = intdiv($a, $b) <=> intdiv($c, $d);
            if ($comparison !== 0) {
                return $direction * $comparison;
            }
            $left = $a % $b;
            $right = $c % $d;
            if ($left === 0 || $right === 0) {
                return $direction * (($left > 0) <=> ($right > 0));
            }
            [$a, $b, $c, $d] = [$b, $left, $d, $right];
            $direction *= -1;
        }
    }

    private function rank(array $rows, bool $ratio = false): array
    {
        $compare = fn ($a, $b) => $ratio
            ? $this->compareRatios($a['damage'], $a['denominator'], $b['damage'], $b['denominator'])
            : ($a['damage'] <=> $b['damage']);
        usort($rows, function ($a, $b) use ($compare, $ratio): int {
            if ($ratio && ($a['denominator'] === 0 || $b['denominator'] === 0)) {
                return ($b['denominator'] > 0) <=> ($a['denominator'] > 0);
            }
            return -$compare($a, $b) ?: strcmp($a['attained_at'], $b['attained_at']) ?: strcmp($a['name'], $b['name']);
        });
        $previous = null;
        $rank = 0;
        foreach ($rows as $index => &$row) {
            if ($ratio && $row['denominator'] === 0) {
                $row['rank'] = null;
                continue;
            }
            // 週間勝利数番付と同じcompetition rank。同点1位が2人なら次は3位。
            if ($previous === null || $compare($row, $previous) !== 0) {
                $rank = $index + 1;
            }
            $row['rank'] = $rank;
            $previous = $row;
        }
        unset($row);
        return $rows;
    }

    private function nonNegativeInt(mixed $value): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        throw_unless($int !== null && $int >= 0, \DomainException::class, 'レイド戦績の整数値を確認できません。');
        return $int;
    }

    private function add(int $a, int $b): int
    {
        throw_if($b > PHP_INT_MAX - $a, \DomainException::class, 'レイド戦績が集計可能な範囲を超えています。');
        return $a + $b;
    }
}
