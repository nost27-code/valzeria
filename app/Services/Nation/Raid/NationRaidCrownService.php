<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use App\Services\SchemaStateService;
use App\Support\NationRaidUiCatalog;
use Illuminate\Support\Facades\Log;

/** レイド順位を変更せず、オンライン名簿へ表示する現行1位の印だけを導出する。 */
final class NationRaidCrownService
{
    public function __construct(
        private readonly NationRaidRankingService $rankings,
        private readonly SchemaStateService $schema,
    ) {}

    /**
     * @return array<int, array{event_id:int,event_key:string,event_name:string,status_label:string,is_final:bool,asset_url:string}>
     */
    public function leaders(): array
    {
        $leaders = [];
        foreach ($this->leaderMarks() as $characterId => $marks) {
            if (isset($marks['crown'])) {
                $leaders[$characterId] = $marks['crown'];
            }
        }

        return $leaders;
    }

    /**
     * @return array<int, array{
     *   crown?:array{event_id:int,event_key:string,event_name:string,status_label:string,is_final:bool,asset_url:string},
     *   max_action?:array{event_id:int,event_key:string,event_name:string,status_label:string,is_final:bool,asset_url:string}
     * }>
     */
    public function leaderMarks(): array
    {
        if (
            ! (bool) config('features.nation_competitive_raid_enabled', false)
            || ! $this->schema->hasTable('nation_raid_events')
            || ! $this->schema->hasTable('nation_raid_participations')
            || ! $this->schema->hasTable('nation_raid_battle_results')
        ) {
            return [];
        }

        $event = $this->displayEvent();
        if (! $event) {
            return [];
        }

        try {
            $standings = $this->rankings->standings($event);
        } catch (\DomainException $exception) {
            Log::warning('国家対抗レイド首位マークの順位を確認できないため、表示を停止しました。', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        $isFinal = $event->status === NationRaidEvent::STATUS_COMPLETED;
        $base = [
            'event_id' => (int) $event->id,
            'event_key' => (string) $event->event_key,
            'event_name' => (string) $event->name,
            'status_label' => $isFinal ? '最終1位' : '現在首位',
            'is_final' => $isFinal,
        ];

        $marks = [];
        foreach ($this->qualifiedFirstCharacterIds($standings['personal_total'] ?? []) as $characterId) {
            $marks[$characterId]['crown'] = [
                ...$base,
                'asset_url' => NationRaidUiCatalog::championCrownUrl(),
            ];
        }
        foreach ($this->qualifiedFirstCharacterIds($standings['max_action'] ?? []) as $characterId) {
            $marks[$characterId]['max_action'] = [
                ...$base,
                'asset_url' => NationRaidUiCatalog::maxActionEmblemUrl(),
            ];
        }

        return $marks;
    }

    /** @return list<int> */
    private function qualifiedFirstCharacterIds(array $rows): array
    {
        return collect($rows)
            ->filter(static fn (array $row): bool => ($row['rank'] ?? null) === 1
                && ($row['qualified'] ?? false) === true
                && filter_var($row['character_id'] ?? null, FILTER_VALIDATE_INT) !== false
                && (int) $row['character_id'] > 0)
            ->map(static fn (array $row): int => (int) $row['character_id'])
            ->values()
            ->all();
    }

    private function displayEvent(): ?NationRaidEvent
    {
        $started = NationRaidEvent::query()
            ->whereIn('status', [NationRaidEvent::STATUS_ACTIVE, NationRaidEvent::STATUS_FINALIZING])
            ->where('starts_at', '<=', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();

        if ($started) {
            return $started;
        }

        return NationRaidEvent::query()
            ->where('status', NationRaidEvent::STATUS_COMPLETED)
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();
    }
}
