<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\NationRaidEvent;
use App\Models\NationRaidPersonalReward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Read-only, owner-scoped archive. Viewing never finalizes an event or grants a reward. */
final class NationRaidHistoryService
{
    public function __construct(private readonly NationRaidFinalResultService $results) {}

    public function forCharacter(Character $character): array
    {
        $owner = fn (Builder|Relation $query) => $query->where('account_id', $character->user_id)
            ->where('character_id_snapshot', $character->id);
        $history = NationRaidEvent::query()->where('status', NationRaidEvent::STATUS_COMPLETED)
            ->whereHas('participations', $owner)
            ->with(['participations' => $owner])
            ->select(['id', 'name', 'status', 'starts_at', 'ends_at', 'final_standings_hash'])
            ->orderByDesc('ends_at')->orderByDesc('id')->paginate(10, ['*'], 'history_page');
        $history->through(function (NationRaidEvent $event) use ($character): array {
            $record = null;
            $unavailable = false;
            try {
                $participant = $event->participations->first();
                throw_unless($participant, \DomainException::class, '参加記録を確認できません。');
                $record = $this->results->forParticipant($event, $participant);
            } catch (\DomainException $exception) {
                report($exception);
                $unavailable = true; // Do not replace a corrupt final snapshot with live totals or zero.
            }

            return [
                'event' => $event,
                'record' => $record,
                'record_unavailable' => $unavailable,
                'nation_name' => $event->participations->first()?->nation_name_snapshot ?? '無所属',
            ];
        });
        $pending = NationRaidPersonalReward::query()->where('account_id_snapshot', $character->user_id)
            ->where('character_id_snapshot', $character->id)->where('status', NationRaidPersonalReward::STATUS_PENDING)
            ->whereHas('event', fn (Builder $query) => $query->where('status', NationRaidEvent::STATUS_COMPLETED))
            ->with('event:id,name,starts_at,ends_at')->orderByDesc('id')->paginate(10, ['*'], 'rewards_page');
        // Preserve only the other known page number, never caller-supplied identity/filter parameters.
        $history->appends(['rewards_page' => $pending->currentPage()])->fragment('past-results');
        $pending->appends(['history_page' => $history->currentPage()])->fragment('pending-rewards');

        return ['history' => $history, 'pendingRewards' => $pending];
    }
}
