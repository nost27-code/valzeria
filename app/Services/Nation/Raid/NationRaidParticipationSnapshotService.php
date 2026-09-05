<?php

namespace App\Services\Nation\Raid;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationRaidEvent;
use App\Models\NationRaidParticipation;
use App\Services\AuthService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/** event公開時の国家人数と開始時の参加者帰属を同じ資格規則で凍結する。 */
final class NationRaidParticipationSnapshotService
{
    public function __construct(private readonly AuthService $authService) {}

    /** @return array<string, array{nation_name:string,active_count:int}> */
    public function nationCountsAt(DateTimeInterface $at): array
    {
        $at = CarbonImmutable::instance($at);
        $activeSince = $at->subDays((int) config('nation_raid.event.active_window_days', 7));
        $counts = [];

        foreach ($this->normalCharacters() as $character) {
            $nation = $this->activeNation($character);
            if (! $nation || ! $character->last_battle_at || $character->last_battle_at->lt($activeSince)) {
                continue;
            }

            $key = (string) $nation->id;
            $counts[$key] ??= ['nation_name' => $nation->display_name, 'active_count' => 0];
            $counts[$key]['active_count']++;
        }

        ksort($counts, SORT_NUMERIC);

        return $counts;
    }

    public function freezeAtStart(NationRaidEvent $event, DateTimeInterface $at): int
    {
        $at = CarbonImmutable::instance($at);
        $publishedCounts = $event->published_nation_counts_snapshot ?? [];
        $startedCounts = $this->nationCountsAt($at);
        $activeSince = $at->subDays((int) config('nation_raid.event.active_window_days', 7));
        $created = 0;

        foreach ($this->normalCharacters() as $character) {
            $nation = $this->activeNation($character);
            $nationKey = $nation ? (string) $nation->id : null;
            $published = $nationKey ? (int) ($publishedCounts[$nationKey]['active_count'] ?? 0) : 0;
            $started = $nationKey ? (int) ($startedCounts[$nationKey]['active_count'] ?? 0) : 0;
            $eligible = $nation !== null
                && $character->last_battle_at !== null
                && $character->last_battle_at->gte($activeSince);

            $row = NationRaidParticipation::query()->firstOrCreate(
                ['event_id' => $event->id, 'account_id' => $character->user_id],
                [
                    'character_id' => $character->id,
                    'character_id_snapshot' => $character->id,
                    'nation_id_snapshot' => $nation?->id,
                    'nation_id' => $nation?->id,
                    'is_nation_eligible' => $eligible,
                    'is_late_entry' => false,
                    'published_active_count' => $published,
                    'started_active_count' => $started,
                    'reference_active_count' => max($published, $started),
                    'character_name_snapshot' => $character->name,
                    'nation_name_snapshot' => $nation?->display_name,
                ],
            );
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * event開始後に初めてCharacterを作った通常account用。国家帰属は必ず対象外にする。
     * 呼び出し側はeventとaccountをlockした開始transaction内で使用する。
     */
    public function createLateEntry(NationRaidEvent $event, Character $character): NationRaidParticipation
    {
        $character->loadMissing('user');
        throw_unless($event->status === NationRaidEvent::STATUS_ACTIVE, \DomainException::class, '開催中ではない国家対抗レイドには参加できません。');
        throw_unless($this->isNormalCharacter($character), \DomainException::class, 'このアカウントは国家対抗レイドへ参加できません。');

        $existing = NationRaidParticipation::query()
            ->where('event_id', $event->id)
            ->where('account_id', $character->user_id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            throw_unless((int) $existing->character_id === (int) $character->id, \DomainException::class, 'イベント開始時の参加Characterと一致しません。');

            return $existing;
        }

        return NationRaidParticipation::query()->create([
            'event_id' => $event->id,
            'account_id' => $character->user_id,
            'character_id' => $character->id,
            'character_id_snapshot' => $character->id,
            'nation_id' => null,
            'is_nation_eligible' => false,
            'is_late_entry' => true,
            'published_active_count' => 0,
            'started_active_count' => 0,
            'reference_active_count' => 0,
            'character_name_snapshot' => $character->name,
            'nation_name_snapshot' => null,
        ]);
    }

    /** @return Collection<int, Character> */
    private function normalCharacters(): Collection
    {
        $characters = Character::query()
            ->with(['user', 'nationMembership.nation'])
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Character $character): bool => $this->isNormalCharacter($character))
            ->values();

        foreach ($characters->groupBy('user_id') as $accountCharacters) {
            throw_if(
                $accountCharacters->count() !== 1,
                \DomainException::class,
                '1アカウントに複数Characterが存在するため、レイド参加snapshotを確定できません。',
            );
        }

        return $characters;
    }

    private function isNormalCharacter(Character $character): bool
    {
        return $character->user !== null
            && ! $character->isExcludedFromPublicLogs()
            && ! $this->authService->isGuestUser($character->user)
            && ! (bool) $character->is_frozen;
    }

    private function activeNation(Character $character): ?Nation
    {
        $nation = $character->nationMembership?->nation;

        return $nation instanceof Nation && $nation->status === Nation::STATUS_ACTIVE ? $nation : null;
    }
}
