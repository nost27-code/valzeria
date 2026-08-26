<?php

namespace App\Services\Nation;

use App\Models\Nation;
use App\Models\NationActivityLog;
use App\Models\NationResourceTransaction;
use App\Models\NationWarHistory;

final class NationTimelineBackfillService
{
    public function __construct(
        private readonly NationDevelopmentLevelService $levels,
        private readonly NationDevelopmentService $development,
    ) {}

    /** @return array{created:int,skipped:int} */
    public function backfill(Nation $nation): array
    {
        $ledgerTotal = $this->development->ledgerTotal($nation);
        throw_unless(
            $ledgerTotal === (int) $nation->development_exp,
            \RuntimeException::class,
            "国家ID {$nation->id} の国家発展EXPが台帳と一致しません。先にnation:audit-developmentを実行してください。",
        );

        $created = 0;
        $skipped = 0;
        $create = function (string $event, array $metadata, $at) use ($nation, &$created, &$skipped): void {
            $exists = NationActivityLog::where('nation_id', $nation->id)
                ->where('event_type', $event)
                ->get()
                ->contains(function (NationActivityLog $log) use ($metadata): bool {
                    foreach ($metadata as $key => $value) {
                        if (($log->metadata[$key] ?? null) != $value) {
                            return false;
                        }
                    }

                    return true;
                });
            if ($exists) {
                $skipped++;

                return;
            }
            NationActivityLog::create([
                'nation_id' => $nation->id,
                'event_type' => $event,
                'metadata' => [...$metadata, 'backfilled' => true],
                'created_at' => $at,
            ]);
            $created++;
        };

        if (! NationActivityLog::where('nation_id', $nation->id)->where('event_type', 'nation_created')->exists()) {
            $create('nation_created', [], $nation->founded_at ?? $nation->created_at);
        }

        $experience = 0;
        $previousLevel = 1;
        foreach (NationResourceTransaction::where('nation_id', $nation->id)->orderBy('id')->get() as $transaction) {
            $experience += (int) $transaction->development_exp_delta;
            $currentLevel = $this->levels->levelFor($experience);
            for ($level = $previousLevel + 1; $level <= $currentLevel; $level++) {
                $create('development_level_up', [
                    'level' => $level,
                    'cumulative_exp' => $this->levels->cumulativeExpForLevel($level),
                ], $transaction->created_at);
            }
            if ($previousLevel < 50 && $currentLevel === 50) {
                $create('max_level_reached', ['level' => 50], $transaction->created_at);
            }
            $previousLevel = $currentLevel;
        }

        $memberCount = 1;
        $memberMaximum = 1;
        $memberEvents = NationActivityLog::where('nation_id', $nation->id)
            ->whereIn('event_type', ['member_joined', 'member_left', 'member_expelled'])
            ->orderBy('id')
            ->get();
        foreach ($memberEvents as $event) {
            $memberCount += $event->event_type === 'member_joined' ? 1 : -1;
            $memberCount = max(0, $memberCount);
            if ($memberCount <= $memberMaximum) {
                continue;
            }
            $memberMaximum = $memberCount;
            $create('member_count_milestone', ['member_count' => $memberCount], $event->created_at);
        }

        foreach (NationResourceTransaction::where('nation_id', $nation->id)
            ->where('transaction_type', 'facility_upgrade')->orderBy('id')->get() as $transaction) {
            $fromLevel = (int) ($transaction->metadata['from_level'] ?? 0);
            $facilityType = (string) ($transaction->metadata['facility_type'] ?? '');
            if ($fromLevel < 1 || $facilityType === '') {
                continue;
            }
            $create('facility_level_milestone', [
                'facility_type' => $facilityType,
                'level' => $fromLevel + 1,
            ], $transaction->created_at);
        }

        if (class_exists(NationWarHistory::class)) {
            $firstWar = NationWarHistory::where(function ($query) use ($nation): void {
                $query->where('declaring_nation_id', $nation->id)->orWhere('defending_nation_id', $nation->id);
            })->orderBy('resolved_at')->orderBy('id')->first();
            if ($firstWar) {
                $create('war_first_participation', ['nation_war_history_id' => $firstWar->id], $firstWar->resolved_at);
            }
            $firstWin = NationWarHistory::where('winner_nation_id', $nation->id)->orderBy('resolved_at')->orderBy('id')->first();
            if ($firstWin) {
                $create('war_first_win', ['nation_war_history_id' => $firstWin->id], $firstWin->resolved_at);
            }
        }

        return compact('created', 'skipped');
    }
}
