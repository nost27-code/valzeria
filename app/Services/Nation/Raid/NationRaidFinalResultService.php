<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use Illuminate\Support\Facades\DB;

/** 本人用の小さい確定記録。全順位の検証は終了CLI、履歴GETはこの記録だけを検証する。 */
final readonly class NationRaidFinalResultService
{
    public function __construct(private NationRaidRewardPolicy $policy) {}

    /** Eventをlockした終了transactionのみ。既存の正しい記録は更新せず、欠損だけ復元可能。 */
    public function storeLocked(NationRaidEvent $event, array $standings): void
    {
        throw_unless(DB::transactionLevel() > 0
            && in_array($event->status, [NationRaidEvent::STATUS_FINALIZING, NationRaidEvent::STATUS_COMPLETED], true),
            \LogicException::class, 'Final result projection requires a locked event.');
        $sourceHash = $this->policy->hash($standings);
        $records = [];
        foreach ($standings['personal_total'] as $record) {
            throw_if(isset($records[$record['participation_id']]), \DomainException::class, '確定戦果の参加者が重複しています。');
            $records[$record['participation_id']] = $record;
        }
        NationRaidParticipation::where('event_id', $event->id)->chunkById(200, function ($participants) use ($event, $sourceHash, &$records): void {
            foreach ($participants as $participant) {
                $record = $records[$participant->id] ?? null;
                $characterId = $participant->character_id_snapshot ?? $participant->character_id;
                throw_if($record !== null && ((int) $record['account_id'] !== $participant->account_id
                    || (int) $record['character_id'] !== (int) $characterId),
                    \DomainException::class, '確定戦果の受取人が一致しません。');
                $snapshot = ['version' => 1, 'event_id' => (int) $event->id, 'participation_id' => (int) $participant->id,
                    'account_id' => $participant->account_id, 'character_id' => $characterId === null ? null : (int) $characterId,
                    'source_standings_hash' => $sourceHash, 'record' => $record];
                $hash = $this->policy->hash($snapshot);
                if ($participant->final_result_snapshot !== null || $participant->final_result_hash !== null) {
                    throw_unless($participant->final_result_snapshot === $snapshot && $participant->final_result_hash === $hash,
                        \DomainException::class, '既存の個人確定戦果が一致しません。運営による確認が必要です。');
                } else {
                    $participant->update(['final_result_snapshot' => $snapshot, 'final_result_hash' => $hash]);
                }
                unset($records[$participant->id]);
            }
        });
        throw_if($records !== [], \DomainException::class, '確定戦果に対応する参加者が存在しません。');
    }

    public function forParticipant(NationRaidEvent $event, NationRaidParticipation $participant): ?array
    {
        $snapshot = $participant->final_result_snapshot;
        throw_unless(is_array($snapshot) && is_string($participant->final_result_hash)
            && hash_equals($participant->final_result_hash, $this->policy->hash($snapshot))
            && ($snapshot['version'] ?? null) === 1
            && ($snapshot['source_standings_hash'] ?? null) === $event->final_standings_hash
            && ($snapshot['event_id'] ?? null) === (int) $event->id
            && ($snapshot['participation_id'] ?? null) === (int) $participant->id
            && ($snapshot['account_id'] ?? null) === $participant->account_id
            && ($snapshot['character_id'] ?? null) === $participant->character_id_snapshot
            && array_key_exists('record', $snapshot),
            \DomainException::class, '個人の確定戦果を確認できません。');

        return $snapshot['record'];
    }
}
