<?php

namespace App\Services;

use App\Enums\SixHeroRoomKey;
use App\Models\SixHeroChampion;
use App\Support\SixHeroRoomUiCatalog;
use LogicException;

final class SixHeroHallPresenter
{
    private const CROWN_HISTORY_LIMIT = 10;

    private const CROWN_LABELS = [
        1 => '一冠',
        2 => '二冠',
        3 => '三冠',
        4 => '四冠',
        5 => '五冠',
        6 => '六冠',
    ];

    /**
     * @return array<string, mixed>
     */
    public function champion(SixHeroChampion $champion): array
    {
        $room = $champion->room_key;
        $liveCharacterId = ! $champion->is_vacant
            ? $champion->character?->getKey()
            : null;
        $heroIconPath = $liveCharacterId !== null
            ? $champion->character?->icon_path
            : null;

        return [
            'roomKey' => $room->value,
            'roomLabel' => $room->label(),
            'roomShortLabel' => $this->roomShortLabel($room),
            'heroTitle' => $this->roomHeroTitle($room),
            'accentClasses' => SixHeroRoomUiCatalog::accentClasses($room),
            'seasonKey' => (string) $champion->season->season_key,
            'seasonLabel' => $this->seasonLabel((string) $champion->season->season_key),
            'isVacant' => (bool) $champion->is_vacant,
            'vacancyReasonLabel' => $champion->is_vacant
                ? $this->vacancyReasonLabel($champion->vacancy_reason)
                : null,
            'heroName' => $champion->is_vacant
                ? null
                : (string) $champion->character_name_snapshot,
            'liveCharacterId' => $liveCharacterId !== null
                ? (int) $liveCharacterId
                : null,
            'heroIconPath' => $heroIconPath !== null
                ? (string) $heroIconPath
                : null,
            'registeredCount' => (int) $champion->registered_count,
            'officialBattleCount' => (int) $champion->official_battle_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function characterSummary(SixHeroCharacterHeroSummary $summary): array
    {
        $rooms = collect(SixHeroRoomKey::cases())
            ->map(function (SixHeroRoomKey $room) use ($summary): array {
                $heroCount = (int) ($summary->heroCountsByRoom[$room->value] ?? 0);
                $currentStreak = (int) ($summary->currentStreaksByRoom[$room->value] ?? 0);
                $longestStreak = (int) ($summary->longestStreaksByRoom[$room->value] ?? 0);

                return [
                    'key' => $room->value,
                    'label' => $room->label(),
                    'shortLabel' => $this->roomShortLabel($room),
                    'heroTitle' => $this->roomHeroTitle($room),
                    'accentClasses' => SixHeroRoomUiCatalog::accentClasses($room),
                    'heroCount' => $heroCount,
                    'currentStreak' => $currentStreak,
                    'currentStreakLabel' => $currentStreak >= 2
                        ? "現在{$currentStreak}連覇"
                        : null,
                    'longestStreak' => $longestStreak,
                    'longestStreakLabel' => $longestStreak >= 2
                        ? "最長{$longestStreak}連覇"
                        : null,
                ];
            })
            ->all();

        $crownSeasons = $summary->crownSeasons
            ->take(self::CROWN_HISTORY_LIMIT)
            ->map(function (SixHeroCrownSeasonSummary $crown): array {
                $label = $this->crownLabel($crown->crownCount);

                return [
                    'seasonKey' => $crown->seasonKey,
                    'seasonLabel' => $this->seasonLabel($crown->seasonKey),
                    'crownCount' => $crown->crownCount,
                    'crownLabel' => $label,
                    'isMultiCrown' => $crown->crownCount >= 2,
                    'isSixCrown' => $crown->isSixCrown,
                    'rooms' => collect($crown->rooms)
                        ->map(fn (SixHeroRoomKey $room): string => $this->roomShortLabel($room))
                        ->all(),
                ];
            })
            ->all();

        return [
            'heroCount' => $summary->heroCount,
            'conqueredRoomCount' => $summary->conqueredRoomCount,
            'totalRoomCount' => count(SixHeroRoomKey::cases()),
            'maxCrownsInSeason' => $summary->maxCrownsInSeason,
            'maxCrownLabel' => $summary->maxCrownsInSeason > 0
                ? $this->crownLabel($summary->maxCrownsInSeason)
                : 'なし',
            'hasHeroHistory' => $summary->heroCount > 0,
            'rooms' => $rooms,
            'crownSeasons' => $crownSeasons,
            'latestHeroSeasonKey' => $summary->latestHeroSeasonKey,
        ];
    }

    public function crownLabel(int $crownCount): string
    {
        if (! isset(self::CROWN_LABELS[$crownCount])) {
            throw new LogicException("Invalid Six Heroes crown count: {$crownCount}.");
        }

        return self::CROWN_LABELS[$crownCount];
    }

    public function roomHeroTitle(SixHeroRoomKey $room): string
    {
        return $this->roomShortLabel($room).'の英雄';
    }

    private function roomShortLabel(SixHeroRoomKey $room): string
    {
        return str_replace('の間', '', $room->label());
    }

    private function seasonLabel(string $seasonKey): string
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $seasonKey, $matches) !== 1) {
            throw new LogicException("Invalid Six Heroes season key: {$seasonKey}.");
        }

        return sprintf('%d年%d月期', (int) $matches[1], (int) $matches[2]);
    }

    private function vacancyReasonLabel(?string $reason): string
    {
        return match ($reason) {
            SixHeroChampion::VACANCY_INSUFFICIENT_PARTICIPANTS => '参加者数が条件未達',
            SixHeroChampion::VACANCY_INSUFFICIENT_ACTIVITY => '公式戦数が条件未達',
            default => '英雄成立条件が未達',
        };
    }
}
