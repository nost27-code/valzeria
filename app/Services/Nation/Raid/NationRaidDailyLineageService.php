<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidBattleResult;
use App\Models\NationRaidDailyLineageSnapshot;
use App\Models\NationRaidEvent;
use App\Services\Nation\CompetitionEventCoordinatorService;
use App\Services\Nation\Raid\Simulation\NationRaidSimulationLineageAdapter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/** 前日の最初の確定済み編成だけを採用する。可変slot・現在所属・現在装備は読まない。 */
final readonly class NationRaidDailyLineageService
{
    public function __construct(
        private CompetitionEventCoordinatorService $coordinator,
        private NationRaidTransactionRunner $transactions,
        private NationRaidSimulationLineageAdapter $lineages,
        private NationRaidLineageVoteResolver $voteResolver,
    ) {}

    /** 新規draft作成transaction内、または既存draftのevent lock内だけで呼ぶ。 */
    public function initializeDraft(NationRaidEvent $event): void
    {
        throw_unless($event->status === NationRaidEvent::STATUS_DRAFT, \DomainException::class, '開催後に系譜の同票順を作り直せません。');
        if (NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->exists()) {
            $this->seedFor($event);
            return;
        }
        $seed = bin2hex(random_bytes(32));
        foreach (range(1, 7) as $day) {
            NationRaidDailyLineageSnapshot::query()->create([
                'event_id' => $event->id, 'raid_day' => $day, 'tie_break_seed' => $seed,
            ]);
        }
    }

    /** 呼び出し元がcoordinator → eventをlock済み。開始と初日の観測記録を原子的にする。 */
    public function recordObservationDay(NationRaidEvent $event, CarbonInterface $at): void
    {
        $seed = $this->seedFor($event);
        $row = NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', 1)->firstOrFail();
        if ($row->determined_at === null) {
            $this->saveResult($row, [], $seed, $at);
        }
    }

    /** nullは切替前または前日出撃の精算/返却待ち。欠損や不正なsnapshotとは区別する。 */
    public function finalizeDay(NationRaidEvent $reference, int $day): ?NationRaidDailyLineageSnapshot
    {
        throw_unless($day >= 1 && $day <= 7, \InvalidArgumentException::class, 'レイドの日数が範囲外です。');
        return $this->transactions->run(function () use ($reference, $day): ?NationRaidDailyLineageSnapshot {
            $this->coordinator->lock();
            $event = NationRaidEvent::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            throw_unless(in_array($event->status, [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING, NationRaidEvent::STATUS_COMPLETED], true),
                \DomainException::class, '開始前の系譜は確定できません。');
            $seed = $this->seedFor($event);
            $row = NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->where('raid_day', $day)->firstOrFail();
            if ($row->determined_at !== null) {
                return $row;
            }
            throw_if($event->status === NationRaidEvent::STATUS_COMPLETED, \DomainException::class, '確定済みイベントの未記録系譜を後付けできません。');
            if (now()->lt($event->starts_at->copy()->addDays($day - 1))) {
                return null;
            }
            $source = NationRaidBattleResult::query()->where('event_id', $event->id)->where('raid_day', $day - 1);
            // event lockにより同時精算と直列化。期限を過ぎても返却確定前には票を閉じない。
            if ((clone $source)->whereIn('status', [NationRaidBattleResult::STATUS_STARTED, NationRaidBattleResult::STATUS_ABORTED])->exists()) {
                return null;
            }
            $sets = [];
            foreach ((clone $source)->where('status', NationRaidBattleResult::STATUS_RESOLVED)->orderBy('account_id')->orderBy('id')
                ->get(['id', 'account_id', 'participation_id', 'job_art_slots_snapshot', 'summary->daily_resolution_no as daily_resolution_no', 'resolved_at'])->groupBy('account_id') as $battles) {
                // 全20turnログを含むsummary本体をロードせず、DB grammar経由で必要なscalarだけ取得する。
                $ordinals = $battles->map(fn ($battle) => filter_var($battle->daily_resolution_no, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE))->all();
                $sorted = $ordinals;
                sort($sorted, SORT_NUMERIC);
                throw_unless($sorted === range(1, count($battles)), \DomainException::class, '前日の出撃確定順を確認できません。');
                $first = $battles->first(fn ($battle) => (int) $battle->daily_resolution_no === 1);
                throw_unless($first->resolved_at !== null, \DomainException::class, '前日の出撃確定時刻を確認できません。');
                $votes = $this->votesForSlots($first->job_art_slots_snapshot);
                $sets[] = [
                    'account_id' => $first->account_id, 'participation_id' => $first->participation_id,
                    'battle_result_id' => $first->id, 'resolved_at' => $first->resolved_at->toIso8601String(),
                    'slots' => $first->job_art_slots_snapshot, 'lineages' => $votes,
                ];
            }
            $this->saveResult($row, $sets, $seed, now());
            return $row;
        });
    }

    /** gate OFFでも開催済みの記録を確定する。新規eventを作成・承認・開始しない。 */
    public function finalizeDue(): array
    {
        $counts = ['finalized' => 0, 'waiting' => 0, 'failed' => 0];
        foreach (NationRaidEvent::query()->whereIn('status', [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING])
            ->where('starts_at', '<=', now())->orderBy('id')->cursor() as $event) {
            $last = min(7, intdiv((int) $event->starts_at->diffInSeconds(now()), 86_400) + 1);
            foreach (range(1, $last) as $day) {
                try {
                    $row = $this->finalizeDay($event, $day);
                    if ($row === null) {
                        $counts['waiting']++;
                        break;
                    }
                    $counts['finalized'] += (int) $row->wasChanged('determined_at');
                } catch (\Throwable $exception) {
                    $counts['failed']++;
                    Log::error('Raid daily lineage finalization failed', ['event_id' => $event->id, 'raid_day' => $day, 'error_class' => $exception::class]);
                    break;
                }
            }
        }
        return $counts;
    }

    private function seedFor(NationRaidEvent $event): string
    {
        $rows = NationRaidDailyLineageSnapshot::query()->where('event_id', $event->id)->orderBy('raid_day')->get(['raid_day', 'tie_break_seed']);
        $seed = (string) ($rows->first()?->tie_break_seed ?? '');
        throw_unless($rows->pluck('raid_day')->all() === range(1, 7)
            && $rows->pluck('tie_break_seed')->unique()->count() === 1 && preg_match('/\A[a-f0-9]{64}\z/', $seed),
            \DomainException::class, 'イベント作成時の系譜同票順を確認できません。');
        return $seed;
    }

    private function votesForSlots(?array $slots): array
    {
        throw_unless(is_array($slots) && count($slots) === 5 && array_column($slots, 'slot') === range(1, 5),
            \DomainException::class, '投票元の5枠編成を確認できません。');
        $votes = [];
        $mapping = $this->lineages->mappings();
        foreach ($slots as $slot) {
            foreach (['exact_identity', 'canonical_lineage', 'raid_lineage'] as $key) {
                throw_unless(array_key_exists($key, $slot), \DomainException::class, '投票元の編成情報が欠けています。');
            }
            $identity = $slot['exact_identity'];
            $canonical = $slot['canonical_lineage'];
            $raid = $slot['raid_lineage'];
            throw_unless(($identity === null || (is_string($identity) && $identity !== ''))
                && (($canonical === null && $raid === null) || (is_string($canonical) && isset($mapping[$canonical]) && $mapping[$canonical] === $raid && $identity !== null)),
                \DomainException::class, '投票元の編成系譜が一致しません。');
            if ($raid !== null) {
                $votes[$raid] = true;
            }
        }
        $keys = array_keys($votes);
        sort($keys, SORT_STRING);
        return $keys;
    }

    private function saveResult(NationRaidDailyLineageSnapshot $row, array $sets, string $seed, CarbonInterface $at): void
    {
        $keys = array_values($this->lineages->mappings());
        sort($keys, SORT_STRING);
        $counts = array_fill_keys($keys, 0);
        foreach ($sets as $set) {
            foreach ($set['lineages'] as $lineage) {
                $counts[$lineage]++;
            }
        }
        $result = $this->voteResolver->resolve($counts, $seed);
        $evidence = ['contract' => 'nation-raid-daily-lineage-v1', 'source_day' => max(0, $row->raid_day - 1),
            'adopted_set_count' => count($sets), 'tie_break_order' => $result['order'],
            'vote_contract_hash' => $this->voteResolver->contractHash(),
            'input_hash' => hash('sha256', NationRaidJson::encode([$sets, $counts, $seed], JSON_UNESCAPED_UNICODE))];
        $row->fill(['selected_lineage' => $result['selected'], 'adopted_sets_snapshot' => $sets, 'vote_counts' => $counts,
            'votes_snapshot' => $evidence, 'determined_at' => $at])->save();
    }
}
