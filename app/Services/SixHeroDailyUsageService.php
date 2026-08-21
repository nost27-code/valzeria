<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Models\SixHeroBattleLog;
use App\Models\SixHeroDailyUsage;
use App\Support\SixHeroCompetitionRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SixHeroDailyUsageService
{
    public function officialAttemptsUsed(
        Character $character,
        SixHeroRoomKey $room,
        ?CarbonInterface $at = null,
    ): int {
        $current = $this->inAppTimezone($at);
        $usage = SixHeroDailyUsage::query()
            ->where('character_id', $character->id)
            ->where('usage_date', $current->toDateString())
            ->first();

        if ($usage === null) {
            return 0;
        }

        return $this->attemptsByRoom($usage)[$room->value];
    }

    public function consumeOfficialAttempt(
        int $characterId,
        SixHeroRoomKey $room,
        CarbonInterface $at,
    ): int {
        $current = $this->inAppTimezone($at);
        $usageDate = $current->toDateString();

        DB::table('six_hero_daily_usages')->insertOrIgnore([
            'character_id' => $characterId,
            'usage_date' => $usageDate,
            'official_attempts' => 0,
            'official_attempts_by_room' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $current,
            'updated_at' => $current,
        ]);

        $usage = SixHeroDailyUsage::query()
            ->where('character_id', $characterId)
            ->where('usage_date', $usageDate)
            ->lockForUpdate()
            ->firstOrFail();
        $attemptsByRoom = $this->attemptsByRoom($usage);
        $used = $attemptsByRoom[$room->value];

        if ($used >= SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT) {
            throw new DomainException('The daily official battle limit for this room has been reached.');
        }

        $used++;
        $attemptsByRoom[$room->value] = $used;
        $usage->forceFill([
            'official_attempts' => array_sum($attemptsByRoom),
            'official_attempts_by_room' => $attemptsByRoom,
        ])->save();

        return $used;
    }

    /** @return array<string, int> */
    public function attemptsByRoom(SixHeroDailyUsage $usage): array
    {
        $stored = $usage->official_attempts_by_room;
        $attempts = $stored === null
            ? $this->reconstructFromBattleLogs($usage)
            : $this->normalizeStoredAttempts($stored);

        if (array_sum($attempts) !== (int) $usage->official_attempts) {
            throw new DomainException('The daily official battle usage total is inconsistent.');
        }

        return $attempts;
    }

    /** @param array<mixed> $stored @return array<string, int> */
    private function normalizeStoredAttempts(array $stored): array
    {
        $attempts = $this->emptyAttempts();

        foreach ($stored as $roomKey => $value) {
            if (! is_string($roomKey) || SixHeroRoomKey::tryFrom($roomKey) === null) {
                throw new DomainException('The daily official battle usage contains an unknown room.');
            }
            if (! is_int($value) || $value < 0
                || $value > SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT
            ) {
                throw new DomainException('The daily official battle usage contains an invalid room count.');
            }

            $attempts[$roomKey] = $value;
        }

        return $attempts;
    }

    /** @return array<string, int> */
    private function reconstructFromBattleLogs(SixHeroDailyUsage $usage): array
    {
        $attempts = $this->emptyAttempts();
        $start = CarbonImmutable::parse(
            $usage->usage_date->toDateString(),
            $this->timezone(),
        )->startOfDay();
        $end = $start->addDay();
        $counts = DB::table('six_hero_battle_logs')
            ->where('battle_mode', SixHeroBattleLog::MODE_OFFICIAL)
            ->where('attacker_id', $usage->character_id)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->groupBy('room_key')
            ->selectRaw('room_key, COUNT(*) as aggregate')
            ->pluck('aggregate', 'room_key');

        foreach ($counts as $roomKey => $count) {
            if (! is_string($roomKey) || SixHeroRoomKey::tryFrom($roomKey) === null) {
                throw new DomainException('The legacy daily usage contains an unknown room.');
            }

            $roomAttempts = (int) $count;
            if ($roomAttempts > SixHeroCompetitionRules::DAILY_OFFICIAL_ATTEMPT_LIMIT) {
                throw new DomainException('The legacy daily usage exceeds a room limit.');
            }
            $attempts[$roomKey] = $roomAttempts;
        }

        return $attempts;
    }

    /** @return array<string, int> */
    private function emptyAttempts(): array
    {
        return array_fill_keys(
            array_map(
                static fn (SixHeroRoomKey $room): string => $room->value,
                SixHeroRoomKey::cases(),
            ),
            0,
        );
    }

    private function inAppTimezone(?CarbonInterface $at): CarbonImmutable
    {
        return $at === null
            ? CarbonImmutable::now($this->timezone())
            : CarbonImmutable::instance($at)->setTimezone($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
