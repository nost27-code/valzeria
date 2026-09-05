<?php

namespace App\Services\Nation;

use App\Models\CompetitionEventCoordinator;
use App\Models\NationRaidEvent;
use App\Models\NationWar;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * 国家戦と国家対抗レイドの受付を直列化する共通mutex。
 *
 * coordinator行はlock順の先頭に置き、開催可否の正本は各domain tableの
 * 期間とstatusから毎回再計算する。表示用のactive_*列はそのread modelである。
 */
final class CompetitionEventCoordinatorService
{
    public function lock(): CompetitionEventCoordinator
    {
        return CompetitionEventCoordinator::query()
            ->where('slot_key', CompetitionEventCoordinator::GLOBAL_SLOT)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function assertRaidWindowAvailable(
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?int $exceptRaidEventId = null,
    ): void {
        [$start, $end] = $this->window($startsAt, $endsAt);

        $otherRaids = NationRaidEvent::query()
            ->reserved()
            ->when($exceptRaidEventId !== null, fn ($query) => $query->whereKeyNot($exceptRaidEventId))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        throw_if($otherRaids->isNotEmpty(), \DomainException::class, '同じ期間に別の国家対抗レイドが予約されています。');

        $wars = NationWar::query()
            ->whereIn('status', NationWar::LIVE_STATUSES)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        throw_if($wars->isNotEmpty(), \DomainException::class, '国家戦の予定期間と重なるため、国家対抗レイドを予約できません。');
    }

    public function assertNationWarWindowAvailable(
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): void {
        [$start, $end] = $this->window($startsAt, $endsAt);

        $raids = NationRaidEvent::query()
            ->reserved()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        throw_if($raids->isNotEmpty(), \DomainException::class, '国家対抗レイドの予定期間と重なるため、宣戦布告できません。');
    }

    /** coordinator行をlock済みの同じtransaction内で呼ぶ。 */
    public function refreshLocked(CompetitionEventCoordinator $coordinator): void
    {
        $raid = NationRaidEvent::query()
            ->reserved()
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'finalizing' THEN 1 ELSE 2 END")
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();

        if ($raid) {
            $coordinator->update([
                'active_type' => 'nation_raid',
                'active_reference_id' => $raid->id,
                'reserved_from' => $raid->starts_at,
                'reserved_until' => $raid->ends_at,
                'lock_version' => (int) $coordinator->lock_version + 1,
            ]);

            return;
        }

        $wars = NationWar::query()
            ->whereIn('status', NationWar::LIVE_STATUSES)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'starts_at', 'ends_at']);

        $coordinator->update([
            'active_type' => $wars->isEmpty() ? null : 'nation_war',
            'active_reference_id' => $wars->count() === 1 ? $wars->first()->id : null,
            'reserved_from' => $wars->isEmpty() ? null : $wars->min('starts_at'),
            'reserved_until' => $wars->isEmpty() ? null : $wars->max('ends_at'),
            'lock_version' => (int) $coordinator->lock_version + 1,
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function window(DateTimeInterface $startsAt, DateTimeInterface $endsAt): array
    {
        $start = CarbonImmutable::instance($startsAt);
        $end = CarbonImmutable::instance($endsAt);
        throw_unless($start->lt($end), \InvalidArgumentException::class, '競技イベントの終了時刻は開始時刻より後である必要があります。');

        return [$start, $end];
    }
}
